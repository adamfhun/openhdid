{{-- Leave guard of the identified client's pages (AZO-07): a link, the menu, Ctrl+K or a panel form
     asks first; the logic is in identified-guard-script (head). Buttons inside [data-hdid-no-guard]
     and Livewire's own forms pass. --}}
<div x-data="hdidIdentifiedGuard()" class="hdid-identified-guard" data-hdid-identified-guard>
    <x-filament::modal id="hdid-identified-leave" width="lg" icon="heroicon-o-identification" icon-color="success" :close-by-clicking-away="false">
        <x-slot name="heading">{{ $heading }}</x-slot>
        <x-slot name="description">{{ $message }}</x-slot>

        <x-slot name="footerActions">
            <x-filament::button color="gray" x-on:click="stay()">{{ __('Stay') }}</x-filament::button>
            <x-filament::button color="gray" outlined x-on:click="go()">{{ __('Go on') }}</x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
