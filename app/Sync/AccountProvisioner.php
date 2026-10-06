<?php

namespace App\Sync;

use App\Audit\Auditor;
use App\Auth\Contracts\Principal;
use App\Clients\PackageOverrides;
use App\Enums\PhoneNumberSource;
use App\Enums\PhoneVerificationSource;
use App\Enums\PrincipalType;
use App\Models\Client;
use App\Models\ClientPhoneNumber;
use App\Models\ExternalRecord;
use App\Models\User;
use App\Support\PhoneNormalizer;

/**
 * Keeps local accounts in step with an external record: clients are created
 * automatically, users are only linked (admins create them), sync-sourced
 * phone numbers are replaced, closed-by-sync accounts are reopened.
 */
class AccountProvisioner
{
    public const CLOSE_REASON_MISSING = 'sync.missing';

    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly Auditor $auditor,
        private readonly PackageOverrides $overrides,
    ) {}

    public function syncAccount(ExternalRecord $record): ?Principal
    {
        return match ($record->kind) {
            PrincipalType::Client => $this->syncClient($record),
            PrincipalType::User => $this->linkUser($record),
        };
    }

    public function closeAccounts(ExternalRecord $record): int
    {
        $closed = 0;

        foreach ([$record->user, $record->client] as $principal) {
            if ($principal instanceof Principal && ! $principal->isClosed()) {
                $principal->close(self::CLOSE_REASON_MISSING);
                $this->auditor->record('account.closed', $principal, ['reason' => self::CLOSE_REASON_MISSING]);
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * The clients.email column is unique, so a directory row whose e-mail is
     * already taken must adopt that client instead of inserting a second
     * one; a plain create would raise a unique violation and fail the run.
     * Soft-deleted clients count too: the index holds their e-mail as well.
     */
    private function syncClient(ExternalRecord $record): Client
    {
        $client = $record->client
            ?? Client::withTrashed()->where('email', $record->email)->first();

        if ($client !== null && $client->trashed()) {
            $client->restore();
        }

        if ($client !== null && $client->external_record_id !== null && $client->external_record_id !== $record->id) {
            $this->auditor->record('account.rebound', $client, [
                'from_external_record_id' => $client->external_record_id,
                'to_external_record_id' => $record->id,
                'external_id' => $record->external_id,
            ]);
        }

        if ($client === null) {
            $client = Client::query()->create([
                'name' => $record->name,
                'email' => $record->email,
                'external_record_id' => $record->id,
                'implicit_package' => $record->implicit_package,
                'explicit_package' => $record->explicit_package,
            ]);
            $this->auditor->record('account.provisioned', $client, ['external_id' => $record->external_id]);
        } else {
            $client->fill([
                'name' => $record->name,
                'email' => $record->email,
                'implicit_package' => $record->implicit_package,
                'explicit_package' => $record->explicit_package,
            ]);
            $client->external_record_id = $record->id;
            $client->save();
            $this->overrides->endIfDirectoryCaughtUp($client);
        }

        $this->reopenIfClosedBySync($client);
        $this->replaceSyncedPhones($client, $record);

        return $client;
    }

    /**
     * A user created before the directory was connected (hdid:make-admin)
     * is bound to a local record; the directory row with the same e-mail
     * takes it over, so the account follows the directory from then on.
     */
    private function linkUser(ExternalRecord $record): ?User
    {
        $user = $record->user
            ?? User::query()
                ->where('email', $record->email)
                ->where(fn ($q) => $q->whereNull('external_record_id')
                    ->orWhereHas('externalRecord', fn ($q) => $q->where('external_id', '>=', ExternalRecord::LOCAL_ID_BASE)))
                ->first();

        if ($user === null) {
            return null;
        }

        if ($user->external_record_id !== $record->id) {
            $local = $user->externalRecord;
            $user->forceFill(['external_record_id' => $record->id])->save();

            if ($local?->isLocal()) {
                $local->delete();
                $this->auditor->record('account.rebound', $user, ['from_external_id' => $local->external_id, 'to_external_id' => $record->external_id]);
            }
        }

        $this->reopenIfClosedBySync($user);

        return $user;
    }

    private function reopenIfClosedBySync(Principal $principal): void
    {
        if ($principal->isClosed() && $principal->closed_reason === self::CLOSE_REASON_MISSING) {
            $principal->reopen();
            $this->auditor->record('account.reopened', $principal, ['reason' => 'sync.reappeared']);
        }
    }

    /**
     * Soft-deleted rows keep the (client, number) unique index busy, so a
     * number that leaves the directory and later returns has to be brought
     * back instead of inserted again; a plain create would raise a unique
     * violation and fail the whole run.
     */
    private function replaceSyncedPhones(Client $client, ExternalRecord $record): void
    {
        $wanted = $this->phones->normalizeMany($record->phones ?? []);

        $all = $client->phoneNumbers()->withTrashed()->get();
        $live = $all->reject(fn (ClientPhoneNumber $phone) => $phone->trashed());
        $hadNone = $live->isEmpty();

        foreach ($wanted as $number) {
            $existing = $live->firstWhere('number_e164', $number);

            if ($existing !== null) {
                if ($existing->source !== PhoneNumberSource::Sync) {
                    // The directory now vouches for a number added by hand: it becomes
                    // a verified directory number, which outranks unverified ones in
                    // the caller lookup and can no longer be removed from the portal.
                    $existing->forceFill(['source' => PhoneNumberSource::Sync, 'verified_at' => $existing->verified_at ?? now(), 'verified_via' => PhoneVerificationSource::Directory])->save();
                }

                continue;
            }

            $isPrimary = $hadNone && $number === $wanted[0] && ! $client->phoneNumbers()->where('is_primary', true)->exists();
            $trashed = $all->first(fn (ClientPhoneNumber $phone) => $phone->trashed() && $phone->number_e164 === $number);

            if ($trashed !== null) {
                $trashed->restore();
                $trashed->forceFill(['source' => PhoneNumberSource::Sync, 'verified_at' => now(), 'verified_via' => PhoneVerificationSource::Directory, 'is_primary' => $isPrimary || $trashed->is_primary])->save();

                continue;
            }

            $client->phoneNumbers()->create([
                'number_e164' => $number,
                'source' => PhoneNumberSource::Sync,
                'is_primary' => $isPrimary,
                'verified_at' => now(),
                'verified_via' => PhoneVerificationSource::Directory,
            ]);
        }

        // The numbers the directory dropped go last: a number change must not
        // leave the client without a number for a moment, because the last
        // number's removal also clears the PIN.
        foreach ($live->where('source', PhoneNumberSource::Sync) as $phone) {
            if (! in_array($phone->number_e164, $wanted, true)) {
                $phone->delete();
            }
        }
    }
}
