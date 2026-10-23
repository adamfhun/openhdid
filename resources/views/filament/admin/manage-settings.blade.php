<x-filament-panels::page>
    {{-- Search across every tab: the label, the help and the details, the block and the tab, the key
         (as the audit log names it), the earlier label and tab, the .env keys a setting depends on.
         The values of the settings are not searched. A pick opens the tab and scrolls to the setting;
         one hidden under its switch is reached through the switch. --}}
    <div
        class="hdid-settings-search"
        x-data="{
            q: '',
            open: false,
            index: @js($this->searchIndex()),
            norm(text) { return (text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); },
            get results() {
                const words = this.norm(this.q).split(/\s+/).filter((w) => w.length > 0);
                if (this.q.trim().length < 2) return [];
                return this.index.filter((item) => { const text = this.norm(item.text); return words.every((w) => text.includes(w)); }).slice(0, 12);
            },
            go(item) {
                this.open = false;
                this.q = '';
                const tab = [...document.querySelectorAll('[role=tab]')].find((el) => el.textContent.trim() === item.tab);
                tab?.click();
                setTimeout(() => {
                    const find = (key) => key ? document.querySelector(`[data-setting='${key}']`) : null;
                    const target = find(item.key) || find(item.master);
                    if (! target) return;
                    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    target.classList.remove('hdid-setting-found');
                    void target.offsetWidth;
                    target.classList.add('hdid-setting-found');
                }, 150);
            },
        }"
        x-on:click.outside="open = false"
        x-on:keydown.escape="open = false"
    >
        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
            <x-filament::input
                type="search"
                x-model="q"
                x-on:input="open = true"
                x-on:focus="open = true"
                autocomplete="off"
                :placeholder="__('Search the settings, e.g. lockout, PIN, logo, SSO…')"
                :aria-label="__('Search the settings')"
            />
        </x-filament::input.wrapper>
        <div class="hdid-settings-search-results" x-show="open && q.trim().length >= 2" x-cloak>
            <template x-for="item in results" :key="item.key">
                <button type="button" x-on:click="go(item)">
                    <span x-text="item.label"></span>
                    <small x-text="item.tab + ' › ' + item.section + (item.master ? ' · ' + {{ \Illuminate\Support\Js::from(__('appears when its switch is on')) }} : '')"></small>
                </button>
            </template>
            <div class="hdid-settings-search-empty" x-show="results.length === 0">{{ __('No setting matches.') }}</div>
        </div>
    </div>

    <form wire:submit="mountAction('save')" class="space-y-6">
        {{ $this->form }}

        <x-filament::actions :actions="[$this->saveAction]" />
    </form>
</x-filament-panels::page>
