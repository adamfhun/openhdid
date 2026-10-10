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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Keeps local accounts in step with an external record: clients are created
 * automatically, users are only linked (admins create them), sync-sourced
 * phone numbers are replaced, closed-by-sync accounts are reopened.
 */
class AccountProvisioner
{
    public const CLOSE_REASON_MISSING = 'sync.missing';

    /** The record's classification (staff/client) changed; the old kind's account closed. */
    public const CLOSE_REASON_KIND_CHANGED = 'sync.kind_changed';

    /**
     * E-mail changes the directory asked for that could not be applied,
     * because another account already holds the address (one e-mail, one
     * account). Drained by the run into its report.
     *
     * @var list<array{external_id: int, kind: string, email: string, kept: string}>
     */
    private array $emailConflicts = [];

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
     * The record's classification changed for good: the account of the old
     * kind closes (reopened by reopenIfClosedBySync() should the kind flip
     * back), the new kind is provisioned by the usual syncAccount() call.
     */
    public function closeAccountOfKind(ExternalRecord $record, PrincipalType $oldKind): int
    {
        $principal = $oldKind === PrincipalType::Client ? $record->client : $record->user;

        if (! $principal instanceof Principal || $principal->isClosed()) {
            return 0;
        }

        $principal->close(self::CLOSE_REASON_KIND_CHANGED);
        $this->auditor->record('account.closed', $principal, ['reason' => self::CLOSE_REASON_KIND_CHANGED, 'kind' => $record->kind->value]);

        return 1;
    }

    /**
     * @return list<array{external_id: int, kind: string, email: string, kept: string}>
     */
    public function takeEmailConflicts(): array
    {
        $conflicts = $this->emailConflicts;
        $this->emailConflicts = [];

        return $conflicts;
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

        $identityChange = null;

        if ($client !== null && $client->external_record_id !== null && $client->external_record_id !== $record->id) {
            $identityChange = 'rebound';
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
            // The directory may rename the address (same external id): the
            // account follows it, unless another account already holds the
            // new address. One e-mail belongs to one account at a time, so
            // the old address stays and the conflict is reported; the login
            // rule's e-mail match then refuses this client until it is sorted out.
            $previousEmail = $client->email;
            $emailTaken = ! self::sameEmail($client->email, $record->email)
                && Client::withTrashed()->where('email', $record->email)->whereKeyNot($client->id)->exists();

            $client->fill([
                'name' => $record->name,
                'email' => $emailTaken ? $client->email : $record->email,
                'implicit_package' => $record->implicit_package,
                'explicit_package' => $record->explicit_package,
            ]);
            $client->external_record_id = $record->id;
            $client->save();
            $this->overrides->endIfDirectoryCaughtUp($client);

            if ($emailTaken) {
                $this->noteEmailConflict($client, $record);
                $identityChange = 'email_conflict';
            } elseif (! self::sameEmail($previousEmail, $client->email)) {
                $identityChange ??= 'email_changed';
            }
        }

        if ($identityChange !== null) {
            $this->revokeAfterIdentityChange($client, $record, $identityChange);
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

        // Admins own the staff account, but the login rule compares its e-mail
        // with the record's: a renamed address would lock the colleague out,
        // so the sync follows the directory here too (same conflict rule as
        // for clients).
        if (! self::sameEmail($user->email, $record->email)) {
            if (User::withTrashed()->where('email', $record->email)->whereKeyNot($user->id)->exists()) {
                $this->noteEmailConflict($user, $record);
                $this->revokeAfterIdentityChange($user, $record, 'email_conflict');
            } else {
                $from = $user->email;
                $user->forceFill(['email' => $record->email])->save();
                $this->auditor->record('account.email_changed', $user, ['from' => $from, 'to' => $record->email, 'external_id' => $record->external_id]);
                $this->revokeAfterIdentityChange($user, $record, 'email_changed');
            }
        }

        $this->reopenIfClosedBySync($user);

        return $user;
    }

    private function reopenIfClosedBySync(Principal $principal): void
    {
        if (! $principal->isClosed()) {
            return;
        }

        $why = match ($principal->closed_reason) {
            self::CLOSE_REASON_MISSING => 'sync.reappeared',
            self::CLOSE_REASON_KIND_CHANGED => 'sync.kind_restored',
            default => null,
        };

        if ($why !== null) {
            $principal->reopen();
            $this->auditor->record('account.reopened', $principal, ['reason' => $why]);
        }
    }

    private static function sameEmail(?string $a, ?string $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    /**
     * Audited on the account, logged for the operator and reported on the
     * run; the account keeps its current address.
     */
    /**
     * The e-mail is the account's identity: when the directory renames it,
     * reissues the record behind it, or gives the address to someone else,
     * whatever was signed in or sent under the old identity (sessions,
     * remember cookies, API tokens, login links and codes) ends here. The
     * person signs in again under the identity the directory holds now.
     */
    private function revokeAfterIdentityChange(Principal&Model $account, ExternalRecord $record, string $reason): void
    {
        $account->revokeAccess();
        $this->auditor->record('account.access_revoked', $account, ['reason' => $reason, 'external_id' => $record->external_id]);
    }

    private function noteEmailConflict(Principal $principal, ExternalRecord $record): void
    {
        $context = ['external_id' => $record->external_id, 'kind' => $record->kind->value, 'email' => $record->email, 'kept' => $principal->getEmail()];
        $this->emailConflicts[] = $context;
        $this->auditor->record('sync.email_conflict', $principal, $context);
        Log::warning('EMD sync: the directory renamed an account e-mail to an address another account already holds; the old address was kept.', $context);
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
