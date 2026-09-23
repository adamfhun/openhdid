<?php

namespace App\Auth\Passwordless;

use App\Enums\OneTimeCodePurpose;
use App\Models\Client;
use App\Models\OneTimeCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
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
 */
class OneTimeCodes
{
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
     * because it is later looked up without knowing the client. The plain
     * value is kept encrypted so that it can be shown again while it lives.
     *
     * @param  callable(): string  $generator
     * @return array{code: string, record: OneTimeCode}
     */
    public function issueUnique(Client $client, OneTimeCodePurpose $purpose, callable $generator, int $ttlMinutes, int $maxAttempts): array
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $generator();

            if ($this->findUsable($purpose, $code) !== null) {
                continue;
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
        $token = Str::random(64);

        return ['token' => $token, 'record' => $this->store($client, $purpose, $token, $ttlMinutes, 1, $destination)];
    }

    /**
     * Verify a code for a known client; counts failed attempts.
     */
    public function verify(Client $client, OneTimeCodePurpose $purpose, string $code): bool
    {
        $record = OneTimeCode::query()
            ->where('client_id', $client->id)
            ->where('purpose', $purpose)
            ->usable()
            ->latest('id')
            ->first();

        if ($record === null) {
            return false;
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            if ($record->attempts >= $record->max_attempts) {
                $record->forceFill(['lookup' => null])->save();
            }

            return false;
        }

        return $this->markConsumed($record);
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
        if (strlen($token) !== 64) {
            return null;
        }

        return $this->consume($purpose, $token)?->client;
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
     * Consume the secret only if nobody else did in the meantime: a
     * conditional update, so that two overlapping requests redeeming the same
     * secret cannot both win. Returns whether this call was the one to win.
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
            ->update($consumed) === 1;

        if ($won) {
            $record->forceFill($consumed)->syncOriginal();
        }

        return $won;
    }

    private function store(Client $client, OneTimeCodePurpose $purpose, string $secret, int $ttlMinutes, int $maxAttempts, ?string $destination, bool $keepPlain = false, bool $withLookup = true): OneTimeCode
    {
        OneTimeCode::query()
            ->where('client_id', $client->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now(), 'lookup' => null, 'secret_encrypted' => null, 'updated_at' => now()]);

        return OneTimeCode::query()->create([
            'client_id' => $client->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make($secret),
            'lookup' => $withLookup ? $this->lookupOf($purpose, $secret) : null,
            'secret_encrypted' => $keepPlain ? Crypt::encryptString($secret) : null,
            'destination' => $destination,
            'expires_at' => now()->addMinutes($ttlMinutes),
            'max_attempts' => $maxAttempts,
        ]);
    }
}
