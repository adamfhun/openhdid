<?php

namespace App\Models\Concerns;

use App\Auth\AccountLogin;
use App\Auth\Passwordless\OneTimeCodes;
use App\Enums\PrincipalType;
use App\Identification\QaSessionEngine;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\OneTimeCode;
use App\Models\SsoIdentity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Shared account state for User and Client: external record link and closure.
 */
trait HasAccountLifecycle
{
    /** The e-mail comes from the EMD record the account is linked to (manualEmailLock()). */
    public const EMAIL_LOCK_DIRECTORY = 'directory';

    /** The company sign-in is bound to a directory identity under this address. */
    public const EMAIL_LOCK_SSO_BINDING = 'sso_binding';

    /** @return BelongsTo<ExternalRecord, $this> */
    public function externalRecord(): BelongsTo
    {
        return $this->belongsTo(ExternalRecord::class);
    }

    /**
     * The directory identities the account's company sign-in is bound to,
     * one per provider (SsoSubjectBinding).
     *
     * @return HasMany<SsoIdentity, $this>
     */
    public function ssoIdentities(): HasMany
    {
        return $this->hasMany(SsoIdentity::class, 'principal_id')->where('principal_type', $this->principalType()->value);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * Decided on the stored row, under its lock, not on this copy: a copy
     * loaded before another path closed the account (a sync chunk loads its
     * accounts up front, and a sponsor's closure closes its linked clients
     * in between) must not overwrite the reason that closed it. Such a copy
     * takes the stored closure over and nothing else happens.
     */
    public function close(string $reason): void
    {
        if ($this->isClosed()) {
            return;
        }

        $closedNow = DB::transaction(function () use ($reason): bool {
            $stored = $this->newQueryWithoutScopes()->whereKey($this->getKey())->lockForUpdate()->first(['closed_at', 'closed_reason']);

            if ($stored?->closed_at !== null) {
                $this->forceFill(['closed_at' => $stored->closed_at, 'closed_reason' => $stored->closed_reason])
                    ->syncOriginalAttributes(['closed_at', 'closed_reason']);

                return false;
            }

            $this->forceFill(['closed_at' => now(), 'closed_reason' => $reason])->save();

            return true;
        });

        if (! $closedNow) {
            return;
        }

        $this->revokeAccess();

        if ($this instanceof Client) {
            app(QaSessionEngine::class)->cancelOpenSessions($this, null, 'account_closed');
        }
    }

    /**
     * Sign the account out everywhere: API tokens, browser sessions, the
     * remember-me cookie and any live one-time secret (magic link, login
     * code, dictated or IVR code).
     *
     * The remember token is rotated here rather than left to the guard's
     * logout(): a bearer request never resolves the session guard, so its
     * logout() would leave a 400-day recaller cookie valid.
     *
     * Returns what ended, for a notification or an audit entry that names
     * it: the API tokens that were still valid, the browser sessions deleted
     * from the store (null where the store cannot tell whose a session is;
     * every session started before ends at its next request through the
     * epoch anyway) and the live one-time secrets.
     *
     * @return array{tokens: int, sessions: ?int, secrets: int}
     */
    public function revokeAccess(): array
    {
        $tokens = $this->liveTokens()->count();
        $this->tokens()->delete();

        // A higher epoch ends every browser session that started before,
        // whatever the session store (AuthSessions checks it on each request);
        // bumped in the database, so overlapping revocations cannot lose one.
        $this->setRememberToken(Str::random(60));
        $this->saveQuietly();
        $this->incrementQuietly('session_epoch');

        $sessions = null;
        if (config('session.driver') === 'database') {
            $deleted = DB::table(config('session.table', 'sessions'))->where('user_id', $this->getKey())->delete();
            $sessions = $this->sessionRowsCarryAccount() ? $deleted : null;
        }

        $secrets = 0;
        if ($this instanceof Client) {
            $secrets = OneTimeCode::query()->where('client_id', $this->getKey())->usable()->count();
            app(OneTimeCodes::class)->revokeAll($this);
        }

        return ['tokens' => $tokens, 'sessions' => $sessions, 'secrets' => $secrets];
    }

    /**
     * What revokeAccess() would end now, so that a confirmation can name
     * it: the API (mobile app) tokens still valid, the live one-time
     * secrets (a client's login links and codes), the open browser sessions
     * where the store tells (staff in the database store; null otherwise),
     * the last time the account was active (any session opened since ends),
     * and whether a remember-me cookie could still sign it in.
     *
     * @return array{tokens: int, sessions: ?int, secrets: int, last_active_at: ?CarbonInterface, remember: bool}
     */
    public function liveAccess(): array
    {
        $sessions = null;
        if (config('session.driver') === 'database' && $this->sessionRowsCarryAccount()) {
            $sessions = DB::table(config('session.table', 'sessions'))
                ->where('user_id', $this->getKey())
                ->where('last_activity', '>', now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp())
                ->count();
        }

        /** @var CarbonInterface|null $lastActive */
        $lastActive = collect([$this->getAttribute('last_seen_at'), $this->getAttribute('last_login_at')])->filter()->max();

        return [
            'tokens' => $this->liveTokens()->count(),
            'sessions' => $sessions,
            'secrets' => $this instanceof Client ? OneTimeCode::query()->where('client_id', $this->getKey())->usable()->count() : 0,
            'last_active_at' => $lastActive,
            'remember' => filled($this->getRememberToken()) && app(AccountLogin::class)->rememberMinutes($this->principalType()) !== null,
        ];
    }

    /**
     * Why an administrator may not change the account's e-mail by hand, or
     * null for a free account. The address is the account's identity: on an
     * account linked to its EMD record only the sync writes it (it follows
     * the record), and a company sign-in binding belongs to the identity
     * under that address (SsoSubjectBinding). A local bootstrap record
     * (hdid:make-admin) does not lock it.
     *
     * @return self::EMAIL_LOCK_*|null
     */
    public function manualEmailLock(): ?string
    {
        if ($this->external_record_id !== null && ! ($this->externalRecord?->isLocal() ?? false)) {
            return self::EMAIL_LOCK_DIRECTORY;
        }

        return $this->ssoIdentities()->exists() ? self::EMAIL_LOCK_SSO_BINDING : null;
    }

    /**
     * API tokens that still sign requests in (not expired by date or by the
     * configured lifetime).
     *
     * @return MorphMany<PersonalAccessToken, $this>
     */
    private function liveTokens(): MorphMany
    {
        $minutes = config('sanctum.expiration');

        return $this->tokens()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($minutes, fn ($query) => $query->where('created_at', '>', now()->subMinutes((int) $minutes)));
    }

    /**
     * Laravel fills the user_id column of stored sessions from the default
     * (staff) guard only, so only staff sessions can be told apart (see
     * Security/SessionLifecycleTest).
     */
    private function sessionRowsCarryAccount(): bool
    {
        return $this->principalType() === PrincipalType::User;
    }

    /**
     * The password hash, or an empty string for an account without one
     * (every client, single sign-on only staff). Since Laravel 13.32 the
     * session guard refuses every remember-me cookie of an account whose
     * password is null, which would silently end the switchable remember-me
     * of single sign-on (user_login/client_login.remember.*); the empty
     * string keeps the earlier behaviour. An empty hash never matches a
     * password (the hasher refuses it), and revokeAccess() still ends every
     * cookie by rotating the remember token.
     */
    public function getAuthPassword(): string
    {
        return (string) $this->getAttribute($this->getAuthPasswordName());
    }

    /**
     * Locked after repeated wrong passwords or SMS codes (LoginLockout).
     */
    public function isLoginLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function reopen(): void
    {
        if (! $this->isClosed()) {
            return;
        }

        $this->forceFill(['closed_at' => null, 'closed_reason' => null])->save();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeClosed(Builder $query): void
    {
        $query->whereNotNull('closed_at');
    }
}
