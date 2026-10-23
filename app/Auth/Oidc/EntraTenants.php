<?php

namespace App\Auth\Oidc;

/**
 * The Entra tenants a sign-in may come from, read from the environment:
 * one tenant id (USER_/CLIENT_ENTRA_TENANT) or, for clients, a list
 * (CLIENT_ENTRA_TENANTS: "id,id:domain|domain,..."). A tenant followed by
 * a colon may only present accounts of the listed e-mail domains; one
 * without is trusted for any address.
 *
 * Both keys filled, or one domain under several tenants, is tolerated but
 * reported (the status tile turns yellow), because it is usually a slip.
 * The shared endpoints (common, organizations, consumers), an id that is
 * not a GUID, or a malformed domain are problems: the provider then counts
 * as not configured, and the status page says why.
 */
final readonly class EntraTenants
{
    /**
     * @param  list<EntraTenant>  $tenants
     * @param  string|null  $problem  why the configuration cannot be used
     * @param  string|null  $note  something worth fixing that does not stop sign-ins
     */
    public function __construct(
        public array $tenants,
        public ?string $problem = null,
        public ?string $note = null,
    ) {}

    public static function parse(mixed $single, mixed $list = null): self
    {
        $single = trim((string) $single);
        $list = trim((string) $list);
        $tenants = [];
        $problems = [];

        foreach (array_filter(array_map('trim', explode(',', $list)), fn (string $entry): bool => $entry !== '') as $entry) {
            [$id, $domains] = array_pad(explode(':', $entry, 2), 2, '');
            $id = trim($id);
            $domainList = array_values(array_filter(array_map(fn (string $d): string => mb_strtolower(trim($d)), explode('|', $domains)), fn (string $d): bool => $d !== ''));

            if (($problem = self::idProblem($id)) !== null) {
                $problems[] = $problem;

                continue;
            }

            foreach ($domainList as $domain) {
                if (preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$/', $domain) !== 1) {
                    $problems[] = __('":domain" is not a domain name', ['domain' => $domain]);
                }
            }

            $tenants[mb_strtolower($id)] = new EntraTenant($id, array_values(array_unique($domainList)));
        }

        $notes = [];

        if ($single !== '') {
            if (($problem = self::idProblem($single)) !== null) {
                $problems[] = $problem;
            } elseif ($tenants !== []) {
                $notes[] = __('Both the single tenant and the tenant list are set; the single tenant counts as part of the list, remove one of the two keys.');
                $tenants[mb_strtolower($single)] ??= new EntraTenant($single);
            } else {
                $tenants[mb_strtolower($single)] = new EntraTenant($single);
            }
        }

        // The same domain under several tenants is usually a slip: each of
        // them may then sign in those accounts, and the first to come binds.
        $byDomain = [];
        foreach ($tenants as $tenant) {
            foreach ($tenant->domains as $domain) {
                $byDomain[$domain][] = $tenant->id;
            }
        }
        foreach ($byDomain as $domain => $ids) {
            if (count($ids) > 1) {
                $notes[] = __('The domain ":domain" is listed under several tenants (:tenants); each of them may sign in those accounts, check the list.', ['domain' => $domain, 'tenants' => implode(', ', $ids)]);
            }
        }

        return new self(array_values($tenants), $problems === [] ? null : implode('; ', array_unique($problems)), $notes === [] ? null : implode(' ', $notes));
    }

    /** The directory (tenant) id as the Entra admin center shows it. */
    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private static function idProblem(string $id): ?string
    {
        if ($id === '') {
            return __('":id" is not a directory (tenant) id: a GUID is expected, as the Entra admin center shows it', ['id' => $id]);
        }

        if (in_array(mb_strtolower($id), OidcProviderConfig::ENTRA_SHARED_TENANTS, true)) {
            return __('":id" is a shared endpoint, not a tenant; give the directory (tenant) id', ['id' => $id]);
        }

        if (preg_match(self::GUID, $id) !== 1) {
            return __('":id" is not a directory (tenant) id: a GUID is expected, as the Entra admin center shows it', ['id' => $id]);
        }

        return null;
    }
}
