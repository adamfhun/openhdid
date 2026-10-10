{{-- On every page that uses App\Filament\Concerns\GuardsUnsavedChanges; the logic is in unsaved-guard-script. --}}
<div x-data="hdidUnsavedGuard()" x-effect="markTabs()" class="hdid-unsaved-guard">
    <x-filament::modal id="hdid-unsaved-changes" width="2xl" icon="heroicon-o-exclamation-triangle" icon-color="warning" :close-by-clicking-away="false">
        <x-slot name="heading">{{ __('Unsaved changes') }}</x-slot>
        <x-slot name="description">{{ __('If you go on without saving, these changes are lost:') }}</x-slot>

        <ul class="list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200">
            <template x-for="item in changed" :key="item">
                <li x-text="item"></li>
            </template>
        </ul>

        <x-slot name="footerActions">
            <x-filament::button x-on:click="saveAndGo()">{{ __('Save and go on') }}</x-filament::button>
            <x-filament::button color="danger" outlined x-on:click="discardAndGo()">{{ __('Discard and go on') }}</x-filament::button>
            <x-filament::button color="gray" x-on:click="stay()">{{ __('Stay on the page') }}</x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
