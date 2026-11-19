<?php

namespace App\Filament\Admin\Widgets;

use App\Auth\Permission;
use App\Enums\PrincipalType;
use App\Filament\Admin\Widgets\Concerns\AuthorizesEveryRequest;
use App\Models\ExternalRecord;
use App\Settings\SettingKey;
use App\Settings\SettingRules;
use App\Settings\Settings;
use Filament\Widgets\Widget;

/**
 * The e-mail domains of the EMD sync, for information on the sync runs page:
 * the ones set for staff and for clients (Settings › EMD), next to how many
 * records the sync really holds of each, so a set domain that brings nothing
 * and a domain that came without being set are both seen at a glance.
 */
class SyncDomainsWidget extends Widget
{
    use AuthorizesEveryRequest;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.admin.widgets.sync-domains';

    public static function canView(): bool
    {
        return auth()->user()?->can(Permission::SyncManage->value) ?? false;
    }

    /**
     * One row per domain and kind: whether it is set, and the records present and missing.
     *
     * @return list<array{domain: string, kind: PrincipalType, configured: bool, present: int, missing: int}>
     */
    public function getRows(): array
    {
        $settings = app(Settings::class);
        $configured = [
            PrincipalType::User->value => SettingRules::normalizeDomains($settings->array(SettingKey::SyncUserDomains)),
            PrincipalType::Client->value => SettingRules::normalizeDomains($settings->array(SettingKey::SyncClientDomains)),
        ];

        $rows = [];
        foreach ($configured as $kind => $domains) {
            foreach ($domains as $domain) {
                $rows[$kind.'|'.$domain] = ['domain' => $domain, 'kind' => PrincipalType::from($kind), 'configured' => true, 'present' => 0, 'missing' => 0];
            }
        }

        $synced = ExternalRecord::query()
            ->whereNotNull('email_domain')
            ->selectRaw('kind, email_domain, SUM(CASE WHEN missing_since IS NULL THEN 1 ELSE 0 END) AS present, SUM(CASE WHEN missing_since IS NULL THEN 0 ELSE 1 END) AS missing')
            ->groupBy('kind', 'email_domain')
            ->toBase()
            ->get();

        foreach ($synced as $row) {
            $key = $row->kind.'|'.mb_strtolower((string) $row->email_domain);
            $rows[$key] ??= ['domain' => mb_strtolower((string) $row->email_domain), 'kind' => PrincipalType::from((string) $row->kind), 'configured' => false, 'present' => 0, 'missing' => 0];
            $rows[$key]['present'] += (int) $row->present;
            $rows[$key]['missing'] += (int) $row->missing;
        }

        usort($rows, fn (array $a, array $b): int => [$a['kind']->value, $a['domain']] <=> [$b['kind']->value, $b['domain']]);

        return array_values($rows);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['rows' => $this->getRows()];
    }
}
