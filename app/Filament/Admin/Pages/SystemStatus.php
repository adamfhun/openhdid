<?php

namespace App\Filament\Admin\Pages;

use App\Auth\Permission;
use App\Filament\Admin\Clusters\System;
use App\Filament\Admin\Resources\ApiKeys\ApiKeyResource;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Filament\Admin\Resources\SyncRuns\SyncRunResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\System\Check;
use App\System\CheckStatus;
use App\System\HealthChecks;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * One screen for "is everything running": workers, transports, interfaces.
 */
class SystemStatus extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?string $cluster = System::class;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'status';

    protected string $view = 'filament.admin.system-status';

    public static function getNavigationLabel(): string
    {
        return __('System status');
    }

    public function getTitle(): string
    {
        return __('System status');
    }

    /**
     * The release version is shown here only, never on public responses.
     */
    public function getSubheading(): ?string
    {
        $version = config('hdid.version');

        return $version ? __('Version :version', ['version' => $version]) : null;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::SettingsManage->value) ?? false;
    }

    /**
     * @return list<Check>
     */
    public function getChecks(): array
    {
        return app(HealthChecks::class)->all();
    }

    /**
     * The two blocks of the page: operation first, then modes and settings.
     *
     * @return array<string, list<Check>> keyed by CheckGroup value
     */
    public function getGroupedChecks(): array
    {
        return app(HealthChecks::class)->grouped();
    }

    /**
     * Where a tile that is not green can be acted on inside the panel; null
     * when the remedy lives in the environment or on the server.
     *
     * @return array{url: string, label: string}|null
     */
    public function linkFor(Check $check): ?array
    {
        if ($check->status === CheckStatus::Ok) {
            return null;
        }

        return match ($check->key) {
            'queue' => ['url' => Queues::getUrl(), 'label' => Queues::getNavigationLabel()],
            'messages' => ['url' => OutboundMessageResource::getUrl(), 'label' => OutboundMessageResource::getNavigationLabel()],
            'sync', 'sync_auth' => ['url' => SyncRunResource::getUrl(), 'label' => SyncRunResource::getNavigationLabel()],
            'api_keys' => ['url' => ApiKeyResource::getUrl(), 'label' => ApiKeyResource::getNavigationLabel()],
            'mfa', 'sso_bindings' => ['url' => UserResource::getUrl(), 'label' => UserResource::getNavigationLabel()],
            'audit' => ['url' => AuditArchives::getUrl(), 'label' => AuditArchives::getNavigationLabel()],
            'package_overrides' => ['url' => ClientResource::getUrl(), 'label' => ClientResource::getNavigationLabel()],
            'shared_numbers' => ['url' => SharedPhoneNumbers::getUrl(), 'label' => SharedPhoneNumbers::getNavigationLabel()],
            'premium_only', 'login_link_preview', 'retention' => ['url' => ManageSettings::getUrl(), 'label' => ManageSettings::getNavigationLabel()],
            default => null,
        };
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label(__('Refresh'))->icon('heroicon-o-arrow-path')->action(fn () => null),
        ];
    }
}
