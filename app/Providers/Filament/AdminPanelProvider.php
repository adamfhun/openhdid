<?php

namespace App\Providers\Filament;

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
use App\Filament\Pages\Auth\Login;
use App\Localization\SetLocale;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup as FilamentNavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

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
    }

    public function panel(Panel $panel): Panel
    {
        return BrandedPanel::apply($panel)
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('web')
            ->globalSearch(false)
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->navigationGroups(array_map(
                fn (NavigationGroup $group) => FilamentNavigationGroup::make(fn () => $group->getLabel())
                    ->collapsible($group->isCollapsible())
                    ->collapsed($group->isCollapsed()),
                NavigationGroup::cases(),
            ))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn (): View => view('filament.admin.tier-switch'))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): View => view('filament.admin.shortcuts', ['searchUrl' => SearchClients::getUrl()]))
            ->renderHook(PanelsRenderHook::FOOTER, fn (): View => view('filament.admin.source-link', ['url' => config('hdid.source_url')]))
            ->userMenuItems([
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
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
