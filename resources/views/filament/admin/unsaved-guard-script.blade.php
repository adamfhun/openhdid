{{-- The unsaved-changes guard (App\Filament\Concerns\GuardsUnsavedChanges): defined once in the head, so the
     guard element on a page can use it as soon as Alpine starts. --}}
<style>
    .fi-tabs-item.hdid-tab-dirty .fi-tabs-item-label::after {
        content: '';
        display: inline-block;
        width: 0.45rem;
        height: 0.45rem;
        margin-inline-start: 0.4rem;
        border-radius: 9999px;
        background: rgb(217 119 6);
        vertical-align: middle;
    }
</style>
<script>
    window.hdidUnsavedGuard = () => ({
        changed: [],
        target: null,
        pendingForm: null,
        leaving: false,

        init() {
            this.handlers = {
                click: (event) => this.onClick(event),
                submit: (event) => this.onSubmit(event),
                beforeunload: (event) => this.onBeforeUnload(event),
            };
            document.addEventListener('click', this.handlers.click, true);
            document.addEventListener('submit', this.handlers.submit, true);
            window.addEventListener('beforeunload', this.handlers.beforeunload);
            window.hdidLeave = (url) => this.attempt(url);
            // Livewire rewrites the tab buttons' classes when it re-renders the page.
            this.unhookMorph = window.Livewire?.hook('morphed', () => this.$nextTick(() => this.markTabs()));
            // A save that needed a confirmation tells when a pending panel form may go on.
            this.handlers.continue = () => this.submitPending();
            window.addEventListener('hdid-leave-continue', this.handlers.continue);
        },

        destroy() {
            document.removeEventListener('click', this.handlers.click, true);
            document.removeEventListener('submit', this.handlers.submit, true);
            window.removeEventListener('beforeunload', this.handlers.beforeunload);
            window.removeEventListener('hdid-leave-continue', this.handlers.continue);
            this.unhookMorph?.();
            delete window.hdidLeave;
        },

        // Numbers typed into a field arrive as text, an emptied field as an empty
        // string, and a value removed and added again goes to the end of a list:
        // none of these counts as a change when the result matches what was saved.
        normalized(value) {
            return JSON.stringify(value ?? null, (key, item) => {
                if (typeof item === 'number') {
                    return String(item);
                }
                if (item === '') {
                    return null;
                }
                if (Array.isArray(item) && item.every((entry) => entry === null || typeof entry !== 'object')) {
                    return [...item].map((entry) => (entry === null ? null : String(entry))).sort();
                }

                return item;
            });
        },

        dirtyKeys() {
            let saved = {};
            try {
                saved = JSON.parse(this.$wire.unsavedBaseline || '{}') ?? {};
            } catch (error) {
                saved = {};
            }
            const current = JSON.parse(JSON.stringify(this.$wire.data ?? {}));
            const labels = this.$wire.unsavedLabels ?? {};
            // Only the form's own fields count: record columns riding along in the
            // data (an update stamp, a timestamp) are not something the user edits.
            const fields = Object.keys(labels).length > 0 ? Object.keys(labels) : [...new Set([...Object.keys(saved), ...Object.keys(current)])];

            return fields.filter((key) => this.normalized(current[key]) !== this.normalized(saved[key]));
        },

        changedLabels() {
            const labels = this.$wire.unsavedLabels ?? {};

            return [...new Set(this.dirtyKeys().map((key) => {
                const item = labels[key];

                return item ? [item.group, item.section, item.label].filter(Boolean).join(' › ') : key;
            }))];
        },

        markTabs() {
            const labels = this.$wire.unsavedLabels ?? {};
            const groups = new Set(this.dirtyKeys().map((key) => labels[key]?.group).filter(Boolean));

            document.querySelectorAll('.fi-tabs-item').forEach((tab) => {
                const label = tab.querySelector('.fi-tabs-item-label')?.textContent.trim();
                tab.classList.toggle('hdid-tab-dirty', groups.has(label));
            });
        },

        ask() {
            this.changed = this.changedLabels();
            this.$dispatch('open-modal', { id: 'hdid-unsaved-changes' });
        },

        close() {
            this.$dispatch('close-modal', { id: 'hdid-unsaved-changes' });
        },

        attempt(url) {
            if (this.dirtyKeys().length === 0) {
                window.location.assign(url);

                return;
            }

            // The server follows only its own paths; behind a proxy the browser's
            // idea of the origin can differ from the server's, so send the path.
            const parsed = new URL(url, window.location.href);
            this.target = parsed.origin === window.location.origin ? parsed.pathname + parsed.search + parsed.hash : url;
            this.pendingForm = null;
            this.ask();
        },

        onClick(event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const link = event.target.closest('a[href]');
            if (! link || (link.target && link.target !== '_self') || link.hasAttribute('download')) {
                return;
            }

            const url = new URL(link.href, window.location.href);
            const samePage = url.pathname === window.location.pathname && url.search === window.location.search;
            // A jump within the page leaves nothing behind; a link to the page itself reloads it.
            if (url.origin !== window.location.origin || (samePage && (url.hash !== '' || link.getAttribute('href').startsWith('#')))) {
                return;
            }

            if (this.dirtyKeys().length === 0) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            this.attempt(url.href);
        },

        onSubmit(event) {
            const form = event.target;
            if (this.leaving || ! (form instanceof HTMLFormElement) || this.$root.closest('[wire\\:id]')?.contains(form) || this.dirtyKeys().length === 0) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            this.pendingForm = form;
            this.target = null;
            this.ask();
        },

        onBeforeUnload(event) {
            if (this.leaving || this.$wire?.__instance?.effects?.redirect || this.dirtyKeys().length === 0) {
                return;
            }

            event.preventDefault();
            event.returnValue = true;
        },

        go() {
            this.leaving = true;

            if (this.pendingForm) {
                this.submitPending();
            } else if (this.target) {
                window.location.assign(this.target);
            }
        },

        // The pending panel form goes once: both the server's event (after a
        // confirmed save) and the save's own answer may ask for it.
        submitPending() {
            const form = this.pendingForm;
            this.pendingForm = null;

            if (form) {
                this.leaving = true;
                form.submit();
            }
        },

        discardAndGo() {
            this.close();
            this.go();
        },

        stay() {
            this.close();
            this.target = null;
            this.pendingForm = null;
        },

        async saveAndGo() {
            this.close();
            const form = this.pendingForm;
            let saved = false;

            try {
                saved = await this.$wire.saveAndLeave(form ? null : this.target, form !== null);
            } catch (error) {
                saved = false;
            }

            // With a link the server follows it after saving; a confirmation or a
            // refused save keeps the page open.
            if (saved && form) {
                this.submitPending();
            }
        },
    });
</script>
