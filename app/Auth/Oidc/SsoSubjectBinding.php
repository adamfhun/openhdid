<?php

namespace App\Auth\Oidc;

use App\Audit\Auditor;
use App\Auth\AccountLogin;
use App\Auth\Contracts\Principal;
use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use App\Enums\PrincipalType;
use App\Models\SsoIdentity;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Binds an account to the directory identity its company sign-in comes
 * from, and refuses a sign-in that arrives under the same e-mail address
 * (or employee id) from another identity.
 *
 * The EMD rule finds the account and runs first; the subject is a lock on
 * top of it, never a finder. An account without a binding for the current
 * registration is bound by its first eligible sign-in; afterwards the
 * subject must match. One subject binds at most one account of a kind. A
 * refusal neither locks nor closes the account: an administrator clears the
 * binding (panel action or hdid:sso-unbind) once the identity change is
 * legitimate. Sync, closure, MFA reset and access revocation leave the
 * binding alone; only a sign-in of another account takes the subject over
 * from a closed or deleted account (see release()).
 */
class SsoSubjectBinding
{
    public const EVENT_BOUND = 'sso.subject_bound';

    public const EVENT_UNBOUND = 'sso.subject_unbound';

    public function __construct(
        private readonly Auditor $auditor,
        private readonly AccountLogin $login,
    ) {}

    /**
     * Checks (or makes) the binding of an eligible account for this sign-in.
     * Returns the binding, or null when the token carries no subject and the
     * deployment allows signing in unbound.
     *
     * @param  array<string, mixed>  $context  what a refusal's audit entry records besides the verdict
     *
     * @throws LoginRejectedException
     */
    public function assert(Principal&Model $account, ?SsoSubject $subject, OidcProviderConfig $config, string $method, array $context = []): ?SsoIdentity
    {
        if ($subject === null) {
            if (! $config->requireSubject) {
                return null;
            }

            $this->reject($account, $method, LoginRejection::NoSubjectClaim, $context + ['subject_claim' => SsoSubject::claimOf($config->provider)]);
        }

        // Two sign-ins may race for the first binding; the unique indexes
        // decide, and the loser re-reads what the winner wrote.
        try {
            return $this->decide($account, $subject, $config, $method, $context, firstAttempt: true);
        } catch (UniqueConstraintViolationException) {
            return $this->decide($account, $subject, $config, $method, $context, firstAttempt: false);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws LoginRejectedException
     * @throws UniqueConstraintViolationException on the first attempt only: a concurrent insert won
     */
    private function decide(Principal&Model $account, SsoSubject $subject, OidcProviderConfig $config, string $method, array $context, bool $firstAttempt): SsoIdentity
    {
        $type = $account->principalType();

        /** @var SsoIdentity|null $current */
        $current = $account->ssoIdentities()->where('provider', $config->provider->value)->first();

        if ($current !== null && $current->isForRegistration($subject)) {
            if (hash_equals($current->subject, $subject->subject)) {
                $current->forceFill(['last_used_at' => now()])->saveQuietly();

                return $current;
            }

            $this->markMismatch($current);
            $this->reject($account, $method, LoginRejection::SsoSubjectMismatch, $context + ['provider' => $config->provider->value, 'subject_claim' => $subject->claim]);
        }

        // No binding for this registration yet (a binding of a replaced
        // registration cannot match again and is renewed below). The
        // subject may still belong to another account of this kind: an open
        // one keeps it, a closed or deleted one yields it to this account.
        $holder = SsoIdentity::query()->where('lookup', $subject->lookup($type))->first();
        if ($holder !== null && ! $this->release($holder, $account)) {
            $this->rejectTaken($account, $holder, $subject, $method, $context);
        }

        if (! $firstAttempt) {
            // Nothing explains the collision any more: the winner's row went
            // away between the two reads. A retry will settle it.
            $this->reject($account, $method, LoginRejection::ProviderUnavailable, $context + ['detail' => 'binding_race']);
        }

        // The stale row of a replaced registration goes first: it can never
        // match again, so losing it to a failed insert costs nothing, and the
        // one-per-account-and-provider index needs it gone.
        $current?->delete();

        $identity = SsoIdentity::query()->create([
            'principal_type' => $type,
            'principal_id' => $account->getKey(),
            'provider' => $subject->provider,
            'issuer' => $subject->issuer,
            'client_id' => $subject->clientId,
            'subject' => $subject->subject,
            'lookup' => $subject->lookup($type),
            'claim' => $subject->claim,
            'bound_via' => $config->matchMode ?? SsoMatchMode::Email,
            'bound_at' => now(),
            'last_used_at' => now(),
        ]);

        $this->auditor->record(self::EVENT_BOUND, $account, [
            'provider' => $subject->provider->value,
            'subject_claim' => $subject->claim,
            'via' => $identity->bound_via->value,
            'registration_replaced' => $current !== null,
        ], $account);

        return $identity;
    }

    /**
     * Removes the account's bindings (all, or one provider's). The next
     * eligible sign-in binds it anew; the account stays signed in.
     *
     * @param  array<string, mixed>  $context  extra facts for the audit entry (a command's via, user and host)
     * @return int the bindings removed
     */
    public function unbind(Principal&Model $account, ?OidcProvider $provider = null, ?User $by = null, array $context = []): int
    {
        $identities = $account->ssoIdentities()
            ->when($provider !== null, fn ($query) => $query->where('provider', $provider->value))
            ->get();

        foreach ($identities as $identity) {
            $identity->delete();
            $this->auditor->record(self::EVENT_UNBOUND, $account, $context + [
                'provider' => $identity->provider->value,
                'bound_at' => $identity->bound_at->toIso8601String(),
            ], $by);
        }

        return $identities->count();
    }

    /**
     * Every binding of a registration (or kind), for a replaced ADFS client
     * registration or a reset of the whole deployment.
     *
     * @param  array<string, mixed>  $context
     * @return int the bindings removed
     */
    public function unbindAll(?PrincipalType $type, ?OidcProvider $provider, ?User $by = null, array $context = []): int
    {
        $identities = SsoIdentity::query()
            ->when($type !== null, fn ($query) => $query->where('principal_type', $type->value))
            ->when($provider !== null, fn ($query) => $query->where('provider', $provider->value))
            ->get();

        foreach ($identities as $identity) {
            $identity->delete();
            $this->auditor->record(self::EVENT_UNBOUND, $identity->principal(), $context + [
                'provider' => $identity->provider->value,
                'bound_at' => $identity->bound_at->toIso8601String(),
            ], $by);
        }

        return $identities->count();
    }

    /**
     * Accounts refused since the given time because the sign-in came from
     * another identity (their binding carries the mismatch stamp).
     */
    public function mismatchesSince(CarbonInterface $since): int
    {
        return SsoIdentity::query()->where('last_mismatch_at', '>=', $since)->count();
    }

    public function boundCount(): int
    {
        return SsoIdentity::query()->count();
    }

    /**
     * The audit context of a sign-in that went through the binding check.
     *
     * @return array<string, string>
     */
    public static function loginContext(?SsoIdentity $identity): array
    {
        return ['sso_subject' => match (true) {
            $identity === null => 'missing',
            $identity->wasRecentlyCreated => 'bound',
            default => 'matched',
        }];
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws LoginRejectedException
     */
    private function rejectTaken(Principal&Model $account, ?SsoIdentity $holder, SsoSubject $subject, string $method, array $context): never
    {
        if ($holder !== null) {
            $this->markMismatch($holder);
        }

        $this->reject($account, $method, LoginRejection::SsoSubjectTaken, $context + [
            'provider' => $subject->provider->value,
            'subject_claim' => $subject->claim,
            'bound_principal_type' => $holder?->principal_type->value,
            'bound_principal_id' => $holder?->principal_id,
        ]);
    }

    /**
     * A binding held by an account that can no longer sign in (closed, or
     * deleted) yields its subject to the account now signing in: a person
     * who left and came back with a new EMD record would otherwise wait for
     * an administrator. The release is audited on the old account; should
     * it be reopened later, its next sign-in finds the subject taken and an
     * administrator decides.
     */
    private function release(SsoIdentity $holder, Principal&Model $to): bool
    {
        $owner = $holder->principal();
        $reason = match (true) {
            $owner === null, $owner->getAttribute('deleted_at') !== null => 'holder_deleted',
            $owner->isClosed() => 'holder_closed',
            default => null,
        };

        if ($reason === null) {
            return false;
        }

        $holder->delete();
        $this->auditor->record(self::EVENT_UNBOUND, $owner, [
            'reason' => $reason,
            'provider' => $holder->provider->value,
            'bound_at' => $holder->bound_at->toIso8601String(),
            'released_to_principal_type' => $to->principalType()->value,
            'released_to_principal_id' => $to->getKey(),
        ], $to);

        return true;
    }

    private function markMismatch(SsoIdentity $identity): void
    {
        $identity->forceFill(['last_mismatch_at' => now()])->saveQuietly();
        $identity->incrementQuietly('mismatch_count');
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws LoginRejectedException
     */
    private function reject(Principal&Model $account, string $method, LoginRejection $reason, array $context): never
    {
        $this->login->recordRejection($account->principalType(), $method, $reason, ['email' => $account->getEmail()] + $context, $account);

        throw new LoginRejectedException($reason);
    }
}
