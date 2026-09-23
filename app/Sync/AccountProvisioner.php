<?php

namespace App\Sync;

use App\Audit\Auditor;
use App\Auth\Contracts\Principal;
use App\Clients\PackageOverrides;
use App\Enums\PhoneNumberSource;
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

    private function linkUser(ExternalRecord $record): ?User
    {
        $user = $record->user
            ?? User::query()->whereNull('external_record_id')->where('email', $record->email)->first();

        if ($user === null) {
            return null;
        }

        if ($user->external_record_id !== $record->id) {
            $user->forceFill(['external_record_id' => $record->id])->save();
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

        foreach ($live->where('source', PhoneNumberSource::Sync) as $phone) {
            if (! in_array($phone->number_e164, $wanted, true)) {
                $phone->delete();
            }
        }

        $known = $live->pluck('number_e164')->all();

        foreach ($wanted as $number) {
            if (in_array($number, $known, true)) {
                continue;
            }

            $isPrimary = $hadNone && $number === $wanted[0] && ! $client->phoneNumbers()->where('is_primary', true)->exists();
            $trashed = $all->first(fn (ClientPhoneNumber $phone) => $phone->trashed() && $phone->number_e164 === $number);

            if ($trashed !== null) {
                $trashed->restore();
                $trashed->forceFill(['source' => PhoneNumberSource::Sync, 'verified_at' => now(), 'is_primary' => $isPrimary || $trashed->is_primary])->save();

                continue;
            }

            $client->phoneNumbers()->create([
                'number_e164' => $number,
                'source' => PhoneNumberSource::Sync,
                'is_primary' => $isPrimary,
                'verified_at' => now(),
            ]);
        }
    }
}
