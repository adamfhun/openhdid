<?php

namespace App\Models\Concerns;

use App\Auth\Passwordless\OneTimeCodes;
use App\Identification\QaSessionEngine;
use App\Models\Client;
use App\Models\ExternalRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared account state for User and Client: external record link and closure.
 */
trait HasAccountLifecycle
{
    /** @return BelongsTo<ExternalRecord, $this> */
    public function externalRecord(): BelongsTo
    {
        return $this->belongsTo(ExternalRecord::class);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function close(string $reason): void
    {
        if ($this->isClosed()) {
            return;
        }

        $this->forceFill(['closed_at' => now(), 'closed_reason' => $reason])->save();
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
     */
    public function revokeAccess(): void
    {
        $this->tokens()->delete();

        $this->setRememberToken(Str::random(60));
        $this->saveQuietly();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $this->getKey())->delete();
        }

        if ($this instanceof Client) {
            app(OneTimeCodes::class)->revokeAll($this);
        }
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
