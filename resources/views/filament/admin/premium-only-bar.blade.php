{{-- Premium-only mode is a system-wide decision: every staff page says so, so nobody wonders where the standard clients went. --}}
@if (app(\App\Clients\ClientTiers::class)->premiumOnly())
    <div class="hdid-premium-only-bar border-b border-amber-200 bg-amber-50 px-4 py-1 text-center text-xs font-medium text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100" role="status">
        {{ __('The system currently serves premium clients only.') }}
        @can(\App\Auth\Permission::SettingsManage->value)
            <a href="{{ \App\Filament\Admin\Pages\ManageSettings::getUrl() }}" class="ms-1 underline underline-offset-2">{{ __('Settings') }}</a>
        @endcan
    </div>
@endif
