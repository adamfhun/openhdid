<?php

namespace App\Auth\Oidc;

use App\Auth\AccountLogin;
use App\Auth\Contracts\Principal;
use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use Illuminate\Database\Eloquent\Model;

/**
 * From validated tokens to the account a company sign-in may use, the same
 * way for the browser and the mobile token exchange: the configured claim
 * (e-mail address or employee id) finds the account, the login rule and
 * the provider switch are checked, then the directory identity is bound or
 * verified (SsoSubjectBinding). The returned context goes into the
 * login.succeeded audit entry.
 */
class SsoAccountResolver
{
    public function __construct(
        private readonly AccountLogin $login,
        private readonly SsoAccess $access,
        private readonly SsoSubjectBinding $binding,
        private readonly OidcClient $oidc,
    ) {}

    /**
     * @return array{account: Principal&Model, context: array<string, mixed>}
     *
     * @throws SsoClaimMissingException the tokens carry nothing that could find an account
     * @throws LoginRejectedException
     */
    public function resolve(OidcTokens $tokens, OidcProviderConfig $config, string $method): array
    {
        $principal = $config->principal;

        if ($config->matchMode === SsoMatchMode::EmployeeId) {
            $match = EmployeeIdClaimMatch::resolve($tokens, $config);
            $this->oidc->debug($config, 'employee id claim', ['external_id' => $match->externalId] + $match->diagnosis());

            if ($match->externalId === null) {
                throw new SsoClaimMissingException(LoginRejection::NoEmployeeIdClaim, $match->diagnosis());
            }

            // The token's address plays no part in this mode: the mode exists
            // because addresses change. The EMD record's address still does,
            // through the login rule.
            $context = $match->auditContext();
            $account = $this->login->resolveByExternalId($principal, $match->externalId, $method, $context);
        } else {
            $match = EmailClaimMatch::resolve($tokens, $config);
            $this->oidc->debug($config, 'e-mail claim', ['email' => $match->email] + $match->auditContext() + $match->diagnosis());

            if ($match->email === null) {
                throw new SsoClaimMissingException(LoginRejection::NoEmailClaim, $match->diagnosis());
            }

            $context = $match->auditContext();
            $account = $this->login->resolveByEmail($principal, $match->email, $method, $context);
        }

        $this->access->assertEnabled($principal, $config->provider, $method, $account);

        // A tenant listed with domains may only sign in accounts of those
        // domains; a tenant without is trusted for any address the EMD knows.
        if ($config->provider === OidcProvider::Entra) {
            $tenantId = (string) ($tokens->idClaims['tid'] ?? '');
            $tenant = $config->tenantFor($tenantId);
            // Which directory the person came from, for the operator (a configured id, not a secret).
            $context['tenant'] = $tenantId;

            if ($tenant !== null && ! $tenant->allowsAddress($account->getEmail())) {
                $this->login->recordRejection($principal, $method, LoginRejection::TenantDomainNotAllowed, $context + ['email' => $account->getEmail(), 'tenant' => $tenant->id, 'allowed_domains' => $tenant->domains], $account);

                throw new LoginRejectedException(LoginRejection::TenantDomainNotAllowed);
            }
        }

        $identity = $this->binding->assert($account, SsoSubject::fromClaims($tokens->idClaims, $config), $config, $method, $context);

        return ['account' => $account, 'context' => $context + SsoSubjectBinding::loginContext($identity)];
    }
}
