<?php

namespace App\Auth\Oidc;

use App\Auth\AccountLogin;
use App\Enums\PrincipalType;
use App\Models\ExternalRecord;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\RecordClassifier;

/**
 * The client portal's Entra tenants (CLIENT_ENTRA_TENANTS, CLIENT_ENTRA_TENANT)
 * set against the EMD sync: for each domain a tenant may present, whether
 * the sync files it as a client domain (and how many client records came
 * in), as a staff domain, or skips it. Shown under the client Entra switch
 * on the settings page and summed up on the sign-in status tile; it only
 * reads, the sign-in rule itself stays in SsoAccountResolver.
 */
class EntraTenantOverview
{
    public function __construct(
        private readonly Settings $settings,
        private readonly RecordClassifier $classifier,
    ) {}

    /**
     * @return array{
     *     enabled: bool,
     *     emd_required: bool,
     *     problem: ?string,
     *     note: ?string,
     *     tenants: list<array{id: string, trusted: bool, domains: list<array{domain: string, state: EntraDomainState, records: int, color: string, text: string}>}>,
     *     unreachable: list<array{domain: string, records: int}>,
     *     legend: list<array{color: string, text: string}>,
     * }
     */
    public function clients(): array
    {
        $config = OidcProvider::Entra->configFor(PrincipalType::Client);
        $emdRequired = AccountLogin::clientsNeedEmd();
        $lists = $this->classifier->domainLists();
        // A domain on both lists is staff for the sync, so it is no client domain.
        $clientDomains = array_values(array_diff($lists['clients'], $lists['users']));

        $tenantDomains = array_values(array_unique(array_merge(...array_map(fn (EntraTenant $tenant): array => $tenant->domains, $config->tenants))));
        $records = $this->clientRecordCounts(array_values(array_unique([...$tenantDomains, ...$clientDomains])));

        $tenants = array_map(fn (EntraTenant $tenant): array => [
            'id' => $tenant->id,
            'trusted' => $tenant->isTrustedForAnyDomain(),
            'domains' => array_map(function (string $domain) use ($lists, $records, $emdRequired): array {
                $count = $records[$domain] ?? 0;
                $state = match (true) {
                    in_array($domain, $lists['users'], true) => EntraDomainState::StaffDomain,
                    in_array($domain, $lists['clients'], true) => $count > 0 ? EntraDomainState::ClientDomain : EntraDomainState::ClientDomainWithoutRecords,
                    default => EntraDomainState::Unlisted,
                };

                return ['domain' => $domain, 'state' => $state, 'records' => $count, 'color' => $state->color($emdRequired), 'text' => $state->describe($count, $emdRequired)];
            }, $tenant->domains),
        ], $config->tenants);

        // Only when every tenant filters by domain can a client domain be out of reach.
        $everyTenantFilters = $config->tenants !== [] && array_filter($config->tenants, fn (EntraTenant $tenant): bool => $tenant->isTrustedForAnyDomain()) === [];
        $unreachable = $everyTenantFilters
            ? array_map(fn (string $domain): array => ['domain' => $domain, 'records' => $records[$domain] ?? 0], array_values(array_diff($clientDomains, $tenantDomains)))
            : [];

        return [
            'enabled' => $this->settings->bool(SettingKey::ClientSsoEntraEnabled),
            'emd_required' => $emdRequired,
            'problem' => $config->tenantProblem,
            'note' => $config->tenantNote,
            'tenants' => $tenants,
            'unreachable' => $unreachable,
            'legend' => [
                ['color' => 'success', 'text' => __('client domain in the EMD sync, with records')],
                ['color' => 'warning', 'text' => $emdRequired ? __('client domain without records yet') : __('client domain without records yet, or on no EMD domain list')],
                ['color' => 'danger', 'text' => $emdRequired ? __('staff domain, or on no EMD domain list') : __('staff domain')],
            ],
        ];
    }

    /**
     * For the status tile: the tenant domains the sync brings no client of
     * (filed as staff, or skipped), or null when there is none.
     */
    public function healthNote(): ?string
    {
        $flagged = [];

        foreach ($this->clients()['tenants'] as $tenant) {
            foreach ($tenant['domains'] as $domain) {
                if ($domain['state']->needsAttention()) {
                    $flagged[$domain['domain']] = $domain['domain'].' ('.$domain['state']->label().')';
                }
            }
        }

        return $flagged === [] ? null : __('Tenant domains the EMD sync brings no client of: :list', ['list' => implode(', ', $flagged)]);
    }

    /**
     * Present client records per e-mail domain, in one grouped query.
     *
     * @param  list<string>  $domains
     * @return array<string, int>
     */
    private function clientRecordCounts(array $domains): array
    {
        if ($domains === []) {
            return [];
        }

        return ExternalRecord::query()
            ->fromDirectory()
            ->present()
            ->where('kind', PrincipalType::Client->value)
            ->whereIn('email_domain', $domains)
            ->groupBy('email_domain')
            ->selectRaw('email_domain, count(*) as records')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [mb_strtolower((string) $row->email_domain) => (int) $row->records])
            ->all();
    }
}
