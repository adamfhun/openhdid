<?php

namespace App\Models;

use App\Auth\Contracts\Principal;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\SsoMatchMode;
use App\Auth\Oidc\SsoSubject;
use App\Enums\PrincipalType;
use App\Models\Concerns\HasUuidKey;
use Database\Factories\SsoIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The directory identity an account's company sign-in is bound to: the
 * provider's stable subject, with the issuer and client registration it was
 * issued for. Written only by SsoSubjectBinding, which audits every change
 * itself (sso.subject_bound, sso.subject_unbound), so the model is not
 * Auditable.
 */
#[Fillable(['principal_type', 'principal_id', 'provider', 'issuer', 'client_id', 'subject', 'lookup', 'claim', 'bound_via', 'bound_at', 'last_used_at', 'last_mismatch_at', 'mismatch_count'])]
class SsoIdentity extends Model
{
    /** @use HasFactory<SsoIdentityFactory> */
    use HasFactory;

    use HasUuidKey;

    protected function casts(): array
    {
        return [
            'principal_type' => PrincipalType::class,
            'provider' => OidcProvider::class,
            'bound_via' => SsoMatchMode::class,
            'bound_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_mismatch_at' => 'datetime',
            'mismatch_count' => 'integer',
        ];
    }

    /**
     * The account the identity is bound to (User or Client), soft-deleted ones included.
     */
    public function principal(): (Principal&Model)|null
    {
        /** @var (Principal&Model)|null $principal */
        $principal = $this->principal_type->modelClass()::withTrashed()->find($this->principal_id);

        return $principal;
    }

    /**
     * Whether this binding was made for the registration the sign-in now
     * comes from (see SsoSubject::isSameRegistration). An ADFS subject is
     * pairwise per client registration, so a binding of a replaced
     * registration can never match again and is treated as absent.
     */
    public function isForRegistration(SsoSubject $subject): bool
    {
        return (new SsoSubject($this->provider, $this->issuer, $this->client_id, $this->subject, $this->claim))->isSameRegistration($subject->issuer, $subject->clientId);
    }
}
