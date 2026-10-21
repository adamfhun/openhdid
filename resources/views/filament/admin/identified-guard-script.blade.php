{{-- The leave guard of the identified client's pages (view filament.admin.identified-guard): defined
     once in the head, so the guard element can use it as soon as Alpine starts, also when Livewire
     adds it after an update. Unlike the unsaved-changes guard it compares nothing: while it is on the
     page, every internal navigation asks. --}}
<script>
    window.hdidIdentifiedGuard = () => ({
        target: null,
        pendingForm: null,
        leaving: false,

        init() {
            this.handlers = {
                click: (event) => this.onClick(event),
                submit: (event) => this.onSubmit(event),
            };
            document.addEventListener('click', this.handlers.click, true);
            document.addEventListener('submit', this.handlers.submit, true);
            // Ctrl+K and the unsaved-changes guard hand their navigation over through this hook.
            this.previousLeave = window.hdidLeave;
            window.hdidLeave = (url) => this.attempt(url);
        },

        destroy() {
            document.removeEventListener('click', this.handlers.click, true);
            document.removeEventListener('submit', this.handlers.submit, true);
            if (this.previousLeave) {
                window.hdidLeave = this.previousLeave;
            } else {
                delete window.hdidLeave;
            }
        },

        onClick(event) {
            if (this.leaving || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            const link = event.target.closest('a[href]');
            if (! link || (link.target && link.target !== '_self') || link.hasAttribute('download') || link.closest('[data-hdid-no-guard]')) {
                return;
            }

            const url = new URL(link.href, window.location.href);
            const samePage = url.pathname === window.location.pathname && url.search === window.location.search;
            if (url.origin !== window.location.origin || (samePage && (url.hash !== '' || link.getAttribute('href').startsWith('#')))) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            this.attempt(url.href);
        },

        onSubmit(event) {
            const form = event.target;
            // Livewire's own forms (the code input, a modal) are work on this page, not a way out.
            if (this.leaving || ! (form instanceof HTMLFormElement) || form.hasAttribute('wire:submit') || form.closest('[wire\\:id]')) {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();
            this.pendingForm = form;
            this.target = null;
            this.ask();
        },

        attempt(url) {
            // Behind a proxy the browser's idea of the origin can differ from the server's: send the path.
            const parsed = new URL(url, window.location.href);
            this.target = parsed.origin === window.location.origin ? parsed.pathname + parsed.search + parsed.hash : url;
            this.pendingForm = null;
            this.ask();
        },

        ask() {
            this.$dispatch('open-modal', { id: 'hdid-identified-leave' });
        },

        close() {
            this.$dispatch('close-modal', { id: 'hdid-identified-leave' });
        },

        stay() {
            this.close();
            this.target = null;
            this.pendingForm = null;
        },

        go() {
            this.close();
            this.leaving = true;

            if (this.pendingForm) {
                const form = this.pendingForm;
                this.pendingForm = null;
                form.submit();
            } else if (this.target) {
                window.location.assign(this.target);
            }
        },
    });
</script>
