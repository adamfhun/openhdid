<?php

namespace App\Providers\Filament;

use App\Auth\Mfa\StaffMfa;
use App\Branding\Branding;
use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\SearchClients;
use App\Filament\Admin\Widgets\AgentCallsChartWidget;
use App\Filament\Admin\Widgets\AgentCallsTableWidget;
use App\Filament\Admin\Widgets\IdentificationsChartWidget;
use App\Filament\Admin\Widgets\MissedCallsWidget;
use App\Filament\Admin\Widgets\OngoingCallsWidget;
use App\Filament\Admin\Widgets\OverviewStatsWidget;
use App\Filament\BrandedPanel;
use App\Filament\Concerns\GuardsUnsavedChanges;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\MfaChallenge;
use App\Filament\Pages\Auth\MfaSetup;
use App\Http\Middleware\EnforceAuthSessions;
use App\Http\Middleware\EnsureStaffMfa;
use App\Http\Middleware\TouchLastSeen;
use App\Localization\SetLocale;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup as FilamentNavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;

use function Livewire\before;

/**
 * The single staff panel: helpdesk agents and administrators share it, the
 * menu and the actions follow the user's permissions.
 */
class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // Agents leave a list and come back to it all day: keep what they set.
        Table::configureUsing(fn (Table $table) => $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession());

        // The page size of a table is a public Livewire property: an update
        // could ask for every row at once ('all', 100000). Registered before
        // the lifecycle hooks, so the table's own updated hook already sees
        // (and stores in the session) the corrected value.
        before('update', fn (object $component, string $path): ?Closure => static::limitTableRecordsPerPage($component, $path));
    }

    /**
     * After a Livewire update of a table's page size: a size the table does
     * not offer becomes its first offered size ('all' stays only where a
     * table offers it); an offered size sent as text becomes the option itself.
     */
    public static function limitTableRecordsPerPage(object $component, string $path): ?Closure
    {
        if (! $component instanceof HasTable || ! property_exists($component, 'tableRecordsPerPage') || Str::before($path, '.') !== 'tableRecordsPerPage') {
            return null;
        }

        return function () use ($component): void {
            $options = array_values($component->getTable()->getPaginationPageOptions());
            $requested = $component->tableRecordsPerPage;
            $offered = is_int($requested) || is_string($requested)
                ? Arr::first($options, fn (int|string $option): bool => (string) $option === (string) $requested)
                : null;

            $component->tableRecordsPerPage = $offered ?? $options[0] ?? null;
        };
    }

    public function panel(Panel $panel): Panel
    {
        return BrandedPanel::apply($panel)
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('web')
            ->globalSearch(false)
            // The client page is where numbers, answers and links are managed
            // (the manual sends the operator there); the policies of the
            // related models keep the tabs read-only for everyone else.
            ->readOnlyRelationManagersOnResourceViewPagesByDefault(false)
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->navigationGroups(array_map(
                fn (NavigationGroup $group) => FilamentNavigationGroup::make(fn () => $group->getLabel())
                    ->collapsible($group->isCollapsible())
                    ->collapsed($group->isCollapsed()),
                NavigationGroup::cases(),
            ))
            // The call view switch would be refused before the second factor: not offered there.
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): string => auth()->user() instanceof User && ! app(StaffMfa::class)->isSatisfied(auth()->user())
                ? '' : view('filament.admin.identified-pill')->render().view('filament.admin.tier-switch')->render())
            ->renderHook(PanelsRenderHook::TOPBAR_BEFORE, fn (): View => view('filament.admin.premium-only-bar'))
            // Pages with a Save button ask before their unsaved changes are left behind.
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => view('filament.admin.unsaved-guard-script')->render().view('filament.admin.identified-guard-script')->render())
            ->renderHook(PanelsRenderHook::PAGE_END, fn (array $scopes): string => collect($scopes)
                ->contains(fn (string $scope): bool => in_array(GuardsUnsavedChanges::class, class_uses_recursive($scope), true))
                ? view('filament.admin.unsaved-guard')->render() : '')
            ->renderHook(PanelsRenderHook::BODY_END, fn (): View => view('filament.admin.shortcuts', ['searchUrl' => SearchClients::getUrl()]))
            ->renderHook(PanelsRenderHook::FOOTER, fn (): View => view('filament.admin.source-link', ['url' => app(Branding::class)->sourceUrl()]))
            ->userMenuItems([
                Action::make('mfa')->label(fn (): string => __('Second factors'))->icon('heroicon-o-shield-check')
                    ->url(fn (): string => route('filament.admin.mfa.setup'))
                    ->visible(fn (): bool => app(StaffMfa::class)->isEnabled()),
                Action::make('locale_hu')->label('Magyar')->icon('heroicon-o-language')->url(fn () => route('locale.switch', 'hu'))->visible(fn () => app()->getLocale() !== 'hu'),
                Action::make('locale_en')->label('English')->icon('heroicon-o-language')->url(fn () => route('locale.switch', 'en'))->visible(fn () => app()->getLocale() !== 'en'),
            ])
            ->viteTheme('resources/css/filament/panels/theme.css')
            ->login(Login::class)
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->discoverClusters(in: app_path('Filament/Admin/Clusters'), for: 'App\Filament\Admin\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                OverviewStatsWidget::class,
                OngoingCallsWidget::class,
                MissedCallsWidget::class,
                IdentificationsChartWidget::class,
                AgentCallsChartWidget::class,
                AgentCallsTableWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetLocale::class,
                EnforceAuthSessions::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // The second factor on every panel request, Livewire updates included.
                EnsureStaffMfa::class,
                // The panel has its own stack, not the web group: the activity stamp
                // of the capacity tile has to be listed here as well.
                TouchLastSeen::class,
            ], isPersistent: true)
            ->authenticatedRoutes(function (): void {
                Route::get('mfa', MfaChallenge::class)->name('mfa.challenge');
                Route::get('mfa/setup', MfaSetup::class)->name('mfa.setup');
            });
    }
}
