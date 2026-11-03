<?php

namespace App\Auth\Passwordless;

use App\Enums\OneTimeCodePurpose;
use App\Models\Client;
use App\Models\OneTimeCode;
use App\System\Installation;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issues and verifies short-lived single-use secrets: numeric codes for SMS,
 * dictation codes for the IVR / agent, opaque tokens for magic links.
 * Issuing a new secret invalidates older ones of the same purpose.
 *
 * Every secret gets a keyed-hash `lookup` so that a record can be found in
 * constant time without scanning; the bcrypt hash still guards the value.
 * The lookup is unique among live secrets: it is cleared the moment a secret
 * is consumed or invalidated, and a collision on insert (two clients drawing
 * the same code at the same instant) makes the issuer draw again.
 *
 * A client has at most one live secret per purpose: replacing the old ones
 * and inserting the new one run under a per-client lock. The hashing is done
 * before the lock; when the lock cannot be had, the issue throws
 * LockTimeoutException and the caller treats it as not issued.
 */
class OneTimeCodes
{
    /** An opaque token (a login link's) is this many ASCII letters and digits. */
    public const TOKEN_LENGTH = 64;

    /** Lock around one client's replace-and-insert of a purpose. */
    private const ISSUE_LOCK_PREFIX = 'hdid:otc:';

    /**
     * @return array{code: string, record: OneTimeCode}
     */
    public function issueNumeric(Client $client, OneTimeCodePurpose $purpose, int $length, int $ttlMinutes, int $maxAttempts, ?string $destination = null): array
    {
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        // Verified per client by hash, so no system-wide lookup (which would
        // have to be unique) is kept for a short numeric code.
        return ['code' => $code, 'record' => $this->store($client, $purpose, $code, $ttlMinutes, $maxAttempts, $destination, withLookup: false)];
    }

    /**
     * A secret that must be unique among all usable secrets of its purpose,
     * and of the purposes looked up together with it, because it is later
     * looked up without knowing the client. The plain value is kept
     * encrypted so that it can be shown again while it lives.
     *
     * @param  callable(): string  $generator
     * @return array{code: string, record: OneTimeCode}
     */
    public function issueUnique(Client $client, OneTimeCodePurpose $purpose, callable $generator, int $ttlMinutes, int $maxAttempts): array
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $generator();

            foreach ($purpose->sharedCodeSpace() as $sibling) {
                if ($this->findUsable($sibling, $code) !== null) {
                    continue 2;
                }
            }

            try {
                return ['code' => $code, 'record' => $this->store($client, $purpose, $code, $ttlMinutes, $maxAttempts, null, keepPlain: true)];
            } catch (UniqueConstraintViolationException) {
                // Somebody else drew the same code a moment ago: draw again.
            }
        }

        throw new \RuntimeException('Could not generate a unique one-time code.');
    }

    /**
     * @return array{token: string, record: OneTimeCode}
     */
    public function issueToken(Client $client, OneTimeCodePurpose $purpose, int $ttlMinutes, ?string $destination = null): array
    {
        $token = Str::random(self::TOKEN_LENGTH);

        return ['token' => $token, 'record' => $this->store($client, $purpose, $token, $ttlMinutes, 1, $destination)];
    }

    /**
     * One bcrypt operation's worth of work, spent without storing anything:
     * a request that issues or checks nothing takes as long as one that
     * does, so the answer time tells nothing about the account.
     */
    public function matchHashCost(): void
    {
        Hash::make(Str::random(16));
    }

    /**
     * Whether the client has a code of this purpose that can still be
     * verified (issued, not used up, not expired).
     */
    public function hasUsable(Client $client, OneTimeCodePurpose $purpose): bool
    {
        return OneTimeCode::query()->where('client_id', $client->id)->where('purpose', $purpose)->usable()->exists();
    }

    /**
     * Verify a code for a known client; with a destination, only a code sent
     * there counts (a phone number confirmation). Every guess is counted
     * before the slow hash compare, with a conditional update that only
     * succeeds while attempts are left, so overlapping guesses can never
     * exceed the limit. Always one hash compare, or the same work.
     */
    public function verify(Client $client, OneTimeCodePurpose $purpose, string $code, ?string $destination = null): bool
    {
        return $this->verifyRecord($client, $purpose, $code, $destination) !== null;
    }

    /**
     * As verify(), returning the consumed record (its destination tells
     * which number the code went to), or null.
     */
    public function verifyRecord(Client $client, OneTimeCodePurpose $purpose, string $code, ?string $destination = null): ?OneTimeCode
    {
        // One query: the code that is checked is the one that was sent to
        // the destination, even when another one runs out meanwhile.
        $record = OneTimeCode::query()
            ->where('client_id', $client->id)
            ->where('purpose', $purpose)
            ->when($destination !== null, fn ($query) => $query->where('destination', $destination))
            ->usable()
            ->latest('id')
            ->first();

        if ($record === null) {
            $this->matchHashCost();

            return null;
        }

        $counted = OneTimeCode::query()->whereKey($record->id)
            ->usable()
            ->update(['attempts' => DB::raw('attempts + 1')]);

        if ($counted !== 1) {
            $this->matchHashCost();

            return null;
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->refresh();

            if ($record->attempts >= $record->max_attempts) {
                $record->forceFill(['lookup' => null])->save();
            }

            return null;
        }

        return $this->markConsumed($record) ? $record : null;
    }

    /**
     * Whether a refused guess is the client's own previous code, which a
     * newer request replaced while it still had time left: the owner typing
     * the earlier message, not somebody guessing. Always one hash compare,
     * or the same work.
     */
    public function matchesReplaced(Client $client, OneTimeCodePurpose $purpose, string $code): bool
    {
        $latest = OneTimeCode::query()
            ->where('client_id', $client->id)
            ->where('purpose', $purpose)
            ->unexpired()
            ->latest('id')
            ->limit(2)
            ->get();
        $replaced = $latest->count() === 2 && $latest[1]->consumed_at !== null ? $latest[1] : null;

        if ($replaced === null) {
            $this->matchHashCost();

            return false;
        }

        return Hash::check($code, $replaced->code_hash);
    }

    /**
     * Find and consume a secret without knowing the client (dictation codes,
     * magic links). Returns the record with its client, or null.
     */
    public function consume(OneTimeCodePurpose $purpose, string $secret): ?OneTimeCode
    {
        $record = $this->findUsable($purpose, $secret);

        if ($record === null || ! Hash::check($secret, $record->code_hash) || ! $this->markConsumed($record)) {
            return null;
        }

        return $record->load('client');
    }

    /**
     * Resolve the client behind an opaque token (magic link).
     */
    public function consumeToken(OneTimeCodePurpose $purpose, string $token): ?Client
    {
        return $this->consumeTokenRecord($purpose, $token)?->client;
    }

    /**
     * Consume an opaque token and return its record (with the client), for
     * callers that need to know which issued secret was used.
     */
    public function consumeTokenRecord(OneTimeCodePurpose $purpose, string $token): ?OneTimeCode
    {
        if (strlen($token) !== 64) {
            return null;
        }

        return $this->consume($purpose, $token);
    }

    /**
     * The client behind a still usable token, without consuming it (the
     * login link's landing page picks the client's own look with it).
     */
    public function peekToken(OneTimeCodePurpose $purpose, string $token): ?Client
    {
        if (strlen($token) !== 64) {
            return null;
        }

        $record = $this->findUsable($purpose, $token);

        return $record !== null && Hash::check($token, $record->code_hash) ? $record->client : null;
    }

    /**
     * The client's currently usable secret of a purpose, when its plain
     * value was kept.
     *
     * @return array{code: string, record: OneTimeCode}|null
     */
    public function current(Client $client, OneTimeCodePurpose $purpose): ?array
    {
        $record = OneTimeCode::query()
            ->where('client_id', $client->id)
            ->where('purpose', $purpose)
            ->usable()
            ->whereNotNull('secret_encrypted')
            ->latest('id')
            ->first();

        if ($record === null) {
            return null;
        }

        return ['code' => Crypt::decryptString($record->secret_encrypted), 'record' => $record];
    }

    /**
     * Invalidate every live secret of a client, whatever its purpose: called
     * when the account is closed, loses access, or its PIN changes.
     */
    public function revokeAll(Client $client): int
    {
        return OneTimeCode::query()
            ->where('client_id', $client->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now(), 'lookup' => null, 'secret_encrypted' => null, 'updated_at' => now()]);
    }

    public function lookupOf(OneTimeCodePurpose $purpose, string $secret): string
    {
        return hash_hmac('sha256', $purpose->value.':'.$secret, (string) config('app.key'));
    }

    private function findUsable(OneTimeCodePurpose $purpose, string $secret): ?OneTimeCode
    {
        return OneTimeCode::query()
            ->where('purpose', $purpose)
            ->where('lookup', $this->lookupOf($purpose, $secret))
            ->usable()
            ->latest('id')
            ->first();
    }

    /**
     * Consume the secret only if nobody else did in the meantime and it has
     * not expired during the hash compare: a conditional update, so that two
     * overlapping requests redeeming the same secret cannot both win. Returns
     * whether this call was the one to win.
     */
    private function markConsumed(OneTimeCode $record): bool
    {
        $now = now();
        // The decryptable copy only exists so a live code can be shown
        // again; once used it must not outlive its purpose.
        $consumed = ['consumed_at' => $now, 'lookup' => null, 'secret_encrypted' => null, 'updated_at' => $now];

        $won = OneTimeCode::query()
            ->whereKey($record->id)
            ->whereNull('consumed_at')
            ->unexpired()
            ->update($consumed) === 1;

        if ($won) {
            $record->forceFill($consumed)->syncOriginal();
        }

        return $won;
    }

    /**
     * @throws LockTimeoutException when another request of the client is
     *                              issuing a secret of the purpose right now
     */
    private function store(Client $client, OneTimeCodePurpose $purpose, string $secret, int $ttlMinutes, int $maxAttempts, ?string $destination, bool $keepPlain = false, bool $withLookup = true): OneTimeCode
    {
        // The slow work is done before the lock, so the lock is short and a
        // request that loses it has spent the same time as one that wins.
        $attributes = [
            'client_id' => $client->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($secret),
            'lookup' => $withLookup ? $this->lookupOf($purpose, $secret) : null,
            'secret_encrypted' => $keepPlain ? Crypt::encryptString($secret) : null,
            'destination' => $destination,
            'max_attempts' => $maxAttempts,
        ];

        return Cache::lock(Installation::lockName(self::ISSUE_LOCK_PREFIX.$client->id.':'.$purpose->value), 10)->block(5, function () use ($client, $purpose, $ttlMinutes, $attributes): OneTimeCode {
            OneTimeCode::query()
                ->where('client_id', $client->id)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'lookup' => null, 'secret_encrypted' => null, 'updated_at' => now()]);

            return OneTimeCode::query()->create($attributes + ['expires_at' => now()->addMinutes($ttlMinutes)]);
        });
    }
}
