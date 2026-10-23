<?php

namespace Database\Factories;

use App\Auth\Contracts\Principal;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\SsoMatchMode;
use App\Auth\Oidc\SsoSubject;
use App\Enums\PrincipalType;
use App\Models\SsoIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<SsoIdentity>
 */
class SsoIdentityFactory extends Factory
{
    public function definition(): array
    {
        $subject = (string) Str::uuid();

        return [
            'principal_type' => PrincipalType::User,
            'principal_id' => User::factory(),
            'provider' => OidcProvider::Entra,
            'issuer' => 'https://login.microsoftonline.com/tenant-1/v2.0',
            'client_id' => 'user-app',
            'subject' => $subject,
            'lookup' => SsoSubject::lookupOf(PrincipalType::User, OidcProvider::Entra, 'https://login.microsoftonline.com/tenant-1/v2.0', 'user-app', $subject),
            'claim' => 'oid',
            'bound_via' => SsoMatchMode::Email,
            'bound_at' => now(),
        ];
    }

    /**
     * Bound to this account, for this provider's registration.
     */
    public function forAccount(Principal&Model $account, OidcProvider $provider = OidcProvider::Entra, string $issuer = 'https://login.microsoftonline.com/tenant-1/v2.0', string $clientId = 'user-app', ?string $subject = null): static
    {
        $subject ??= (string) Str::uuid();

        return $this->state([
            'principal_type' => $account->principalType(),
            'principal_id' => $account->getKey(),
            'provider' => $provider,
            'issuer' => $issuer,
            'client_id' => $clientId,
            'subject' => $subject,
            'lookup' => SsoSubject::lookupOf($account->principalType(), $provider, $issuer, $clientId, $subject),
            'claim' => $provider === OidcProvider::Entra ? 'oid' : 'sub',
        ]);
    }
}
