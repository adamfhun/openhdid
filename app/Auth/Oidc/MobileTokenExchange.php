<?php

namespace App\Auth\Oidc;

use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use App\Enums\PrincipalType;
use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The mobile app's Entra sign-in: a fresh ID token, presented once, is
 * exchanged for an API token. Only clients have a mobile app. A token older
 * than the configured age (counted from its issue time) or presented a
 * second time is refused, so a leaked ID token cannot be cashed in later.
 * Every refusal is audited.
 */
class MobileTokenExchange
{
    public const METHOD = 'sso.entra.mobile';

    public function __construct(
        private readonly OidcClient $oidc,
        private readonly AccountLogin $login,
        private readonly SsoAccess $access,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array{token: string, account: Client}
     *
     * @throws LoginRejectedException
     */
    public function exchange(string $idToken, string $deviceName): array
    {
        $principal = PrincipalType::Client;
        $this->access->assertEnabled($principal, OidcProvider::Entra, self::METHOD);
        $config = OidcProvider::Entra->configFor($principal);

        try {
            $claims = $this->oidc->validateIdToken($config, $idToken, $config->mobileClientId ?? $config->clientId);
        } catch (OidcUnavailableException $e) {
            Log::warning('SSO provider unavailable', ['principal' => $principal->value, 'provider' => OidcProvider::Entra->value, 'error' => $e->getMessage()]);

            throw new LoginRejectedException(LoginRejection::ProviderUnavailable);
        } catch (OidcException $e) {
            $this->reject('invalid_token', ['error' => $e->getMessage()]);
        }

        $maxAgeSeconds = $this->settings->int(SettingKey::SsoMobileTokenMaxAgeMinutes) * 60;
        $issuedAt = $claims['iat'] ?? null;
        if (! is_numeric($issuedAt) || now()->getTimestamp() - (int) $issuedAt > $maxAgeSeconds) {
            $this->reject('token_too_old', ['issued_at' => is_numeric($issuedAt) ? (int) $issuedAt : null]);
        }

        // One exchange per token: the first presentation claims it for as long
        // as the age limit could still accept it.
        if (! Cache::add('oidc.mobile.used.'.hash('sha256', $idToken), true, $maxAgeSeconds + OidcClient::CLOCK_SKEW_SECONDS)) {
            $this->reject('token_replayed', ['email' => $this->oidc->email($claims, $config)]);
        }

        $email = $this->oidc->email($claims, $config);
        if ($email === null) {
            $this->reject('no_email_claim');
        }

        /** @var Client $account */
        $account = $this->login->resolveByEmail($principal, $email, self::METHOD);
        $this->access->assertEnabled($principal, OidcProvider::Entra, self::METHOD, $account);

        return ['token' => $this->login->issueToken($account, self::METHOD, $deviceName), 'account' => $account];
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws LoginRejectedException
     */
    private function reject(string $detail, array $context = []): never
    {
        $this->login->recordRejection(PrincipalType::Client, self::METHOD, LoginRejection::InvalidCredentials, ['detail' => $detail] + $context);

        throw new LoginRejectedException(LoginRejection::InvalidCredentials);
    }
}
