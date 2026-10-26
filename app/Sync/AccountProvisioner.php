<?php

namespace App\Sync;

use App\Audit\Auditor;
use App\Auth\Contracts\Principal;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Oidc\SsoSubjectBinding;
use App\Clients\ClientHolderData;
use App\Clients\ClientLinks;
use App\Clients\PackageOverrides;
use App\Enums\DirectoryLinkOutcome;
use App\Enums\MfaMethod;
use App\Enums\PhoneNumberSource;
use App\Enums\PhoneVerificationSource;
use App\Enums\PrincipalType;
use App\Models\Client;
use App\Models\ClientPhoneNumber;
use App\Models\ExternalRecord;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\PhoneNormalizer;
use App\System\DemoData;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /** An administrator saved a free account under another address (applyAdminEmailChange()). */
    public const REASON_EMAIL_CHANGED_BY_ADMIN = 'email_changed_by_admin';

    /** The first link of an account whose address an administrator changed by hand (email_changed_by_admin_at). */
    public const REASON_LINKED_AFTER_EMAIL_CHANGE = 'linked_after_email_change';

    /** The sync moved a client account from one EMD record to another while the old record is still in the source (two people). */
    public const REASON_SYNC_REBOUND = 'sync.rebound';

    /** What a staff account handed to another person's EMD record loses of its previous holder. */
    public const TRACE_PASSWORD = 'password';

    public const TRACE_SECOND_FACTORS = 'second_factors';

    public const TRACE_SSO_BINDING = 'sso_binding';

    /** What a client account handed to another person's EMD record loses of its previous holder (ClientHolderData). */
    public const TRACE_PIN = 'pin';

    public const TRACE_ANSWERS = 'answers';

    public const TRACE_MANUAL_PHONES = 'manual_phones';

    /**
     * E-mail changes the directory asked for that could not be applied,
     * because another account already holds the address (one e-mail, one
     * account), rows whose address only the database collation equates
     * with an account's (look_alike), and, in production, rows that would
     * take over a demo record (demo). Drained by the run into its report.
     *
     * @var list<array{external_id: int, kind: string, email: string, kept: string, look_alike?: true, demo?: true}>
     */
    private array $emailConflicts = [];

    /**
     * Accounts this provisioner handed to another person's EMD record and
     * cleared of the previous holder's data (handOver()), with what went.
     * Drained by the run into its report and by the edit page into its
     * notification.
     *
     * @var list<array{external_id: int, kind: string, email: string, reason: string, removed: list<string>}>
     */
    private array $holderDataRemovals = [];

    /**
     * Clients the sync moved from one EMD record to another in this run
     * (syncClient()), waiting for settleRebounds() to decide at the end of
     * the run whether the old record is still in the source.
     *
     * @var list<array{client: Client, record: ExternalRecord, from_record_id: string}>
     */
    private array $pendingRebounds = [];

    /**
     * Records the directory reissued under a new external id to the same
     * person (settleRebounds()): the account moved over with its data.
     * Drained by the run into its report.
     *
     * @var list<array{external_id: int, from_external_id: ?int, kind: string, email: string}>
     */
    private array $reissues = [];

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

                // A copy loaded before another path closed the account takes
                // that closure over (HasAccountLifecycle::close()); its reason stays.
                if ($principal->closed_reason !== self::CLOSE_REASON_MISSING) {
                    continue;
                }

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
     * Links an account an admin has just created or saved to its EMD record
     * at once, on the path a run takes, instead of leaving it to the next
     * run. Only a present record of the account's kind with the same e-mail
     * and no account of that kind qualifies. Null when the account is linked
     * to a directory record already.
     */
    public function linkNow(Client|User $account): ?DirectoryLinkOutcome
    {
        if ($account->externalRecord !== null && ! $account->externalRecord->isLocal()) {
            return null;
        }

        ['records' => $records, 'of_kind' => $ofKind, 'present' => $present, 'free' => $free] = $this->linkCandidates($account, $account->email);

        $outcome = match (true) {
            $free->count() > 1 => DirectoryLinkOutcome::Ambiguous,
            $free->count() === 1 => DirectoryLinkOutcome::Linked,
            $present->isNotEmpty() => DirectoryLinkOutcome::Taken,
            $ofKind->isNotEmpty() => DirectoryLinkOutcome::Missing,
            $records->isNotEmpty() => DirectoryLinkOutcome::OtherKind,
            default => DirectoryLinkOutcome::NoRecord,
        };

        if ($outcome->isLinked()) {
            $record = $free->sole();

            DB::transaction(function () use ($account, $record): void {
                // The run finds the account by the record's e-mail; a case difference
                // would hide it on a case-sensitive database.
                if ($account->email !== $record->email) {
                    $account->forceFill(['email' => $record->email])->save();
                }

                $this->syncAccount($record);
                // Outside a run no old record can be "still in the source": a reissue at most.
                $this->settleRebounds(null);
                $this->auditor->record('account.linked', $account, ['external_id' => $record->external_id, 'on' => 'admin_save']);
            });

            $account->refresh();
        }

        return $outcome;
    }

    /**
     * The present EMD record of the account's kind that carries exactly
     * this address and has no account of that kind yet: the record
     * linkNow() links the account to once it is saved under the address.
     * Null when none, or several, qualify.
     */
    public function linkTarget(Client|User $account, string $email): ?ExternalRecord
    {
        $free = $this->linkCandidates($account, $email)['free'];

        return $free->count() === 1 ? $free->first() : null;
    }

    /**
     * What saving a free account under another address does, for the
     * confirmation that has to name it before the save and for the save,
     * which recomputes it and stops if it differs from what was confirmed
     * (applyAdminEmailChange() carries it out). The e-mail is the account's
     * identity, so everything signed in or sent under the old address ends
     * (access: see revokeAccess()); an account that the new address links
     * to an EMD record (another person's, as far as anyone can tell) also
     * loses what the previous holder set up (removes): a staff account its
     * password, second factors and sign-in binding, a client account its
     * PIN, security answers and phone numbers added by hand (held: what is
     * on file, named in the question with the counts). Null when the
     * address does not really change (letter case and spaces aside), or may
     * not be changed by hand at all (manualEmailLock()).
     *
     * @return array{from: string, to: string, target: ?ExternalRecord, takes_over: bool, removes: list<string>, mfa_methods: list<MfaMethod>, held: array{pin: bool, answers: int, phones: list<string>}|null, access: array{tokens: int, sessions: ?int, secrets: int, last_active_at: ?CarbonInterface, remember: bool}}|null
     */
    public function adminEmailChange(Client|User $account, ?string $to, bool $keepPassword = false): ?array
    {
        if (! is_string($to) || trim($to) === '' || $account->manualEmailLock() !== null || self::sameEmail($account->email, $to)) {
            return null;
        }

        $target = $this->linkTarget($account, $to);
        $takesOver = $target !== null;
        $methods = $account instanceof User ? $this->mfaMethodsOf($account) : [];
        $held = $account instanceof Client ? app(ClientHolderData::class)->held($account) : null;
        $traces = $account instanceof User ? $this->holderTraces($account, $keepPassword, $methods) : self::clientTraces((array) $held);

        return [
            'from' => $account->email,
            'to' => trim($to),
            'target' => $target,
            'takes_over' => $takesOver,
            'removes' => $takesOver ? $traces : [],
            // Without a record to hand the account to, nothing of the holder is removed:
            // the question says what stays, also when an EMD record links the account later.
            'keeps' => $takesOver ? [] : $traces,
            'mfa_methods' => $methods,
            'held' => $held,
            'access' => $account->liveAccess(),
        ];
    }

    /**
     * Carries out an e-mail change an administrator saved on a free account
     * (adminEmailChange()), right after the save and before linkNow(): the
     * account is signed out everywhere (revokeAccess()); a staff account the
     * new address hands to an EMD record loses the previous holder's
     * password (unless the same save set a new one), second factors
     * (StaffMfa::reset) and company sign-in binding; a client account it
     * hands to an EMD record loses the previous holder's PIN (with its
     * lockout state), security answers and phone numbers added by hand
     * (ClientHolderData::wipe(), audited as client.holder_data_removed with
     * the counts; the EMD numbers follow the sync of linkNow()); a local
     * bootstrap record (hdid:make-admin) follows the new address, since the
     * login rule compares the two. Audited as account.access_revoked, reason
     * email_changed_by_admin, with the addresses and what ended, never a
     * secret. Null when the address did not really change.
     *
     * @return array{tokens: int, sessions: ?int, secrets: int, removed: list<string>, takes_over_external_id: ?int, wiped: array{pin: bool, answers: int, phones: int}|null}|null
     */
    public function applyAdminEmailChange(Client|User $account, string $from, bool $keepPassword): ?array
    {
        if (self::sameEmail($from, $account->email)) {
            return null;
        }

        return DB::transaction(function () use ($account, $from, $keepPassword): array {
            $target = $this->linkTarget($account, $account->email);
            $takesOver = $target !== null;
            $removed = $takesOver && $account instanceof User ? $this->holderTraces($account, $keepPassword, $this->mfaMethodsOf($account)) : [];
            $wiped = null;

            // First, so that the counts are what this change ended.
            $ended = $account->revokeAccess();

            if ($takesOver && $account instanceof Client) {
                $wiped = app(ClientHolderData::class)->wipe($account, self::REASON_EMAIL_CHANGED_BY_ADMIN);
                $removed = self::clientTraces($wiped);
                $this->auditor->record('client.holder_data_removed', $account, [
                    'reason' => self::REASON_EMAIL_CHANGED_BY_ADMIN,
                    'takes_over_external_id' => $target->external_id,
                ] + $wiped);
            } elseif ($takesOver) {
                $this->removeStaffHolder($account, $removed, self::REASON_EMAIL_CHANGED_BY_ADMIN);
            } elseif ($account->externalRecord?->isLocal()) {
                $account->externalRecord->forceFill(['email' => self::emailKey($account->email)])->save();
            }

            // Without a record to hand the account to, nothing of the holder goes now; the
            // mark makes the first later link to an EMD record count as a change of person.
            $account->forceFill(['email_changed_by_admin_at' => $takesOver ? null : now()])->save();

            $this->auditor->record('account.access_revoked', $account, [
                'reason' => self::REASON_EMAIL_CHANGED_BY_ADMIN,
                'from' => $from,
                'to' => $account->email,
            ] + $ended + ($takesOver ? ['takes_over_external_id' => $target->external_id, 'removed' => $removed] : []));

            return $ended + ['removed' => $removed, 'takes_over_external_id' => $takesOver ? $target->external_id : null, 'wiped' => $wiped];
        });
    }

    /**
     * Removes what the previous holder set up on a staff account that now
     * goes to another person: the password (when listed), the second
     * factors (with their lockout) and the company sign-in binding.
     *
     * @param  list<string>  $traces
     */
    private function removeStaffHolder(User $account, array $traces, string $reason): void
    {
        if (in_array(self::TRACE_PASSWORD, $traces, true)) {
            $account->forceFill(['password' => null, 'failed_login_attempts' => 0, 'locked_until' => null])->save();
        }

        if (in_array(self::TRACE_SECOND_FACTORS, $traces, true) || app(StaffMfa::class)->isLocked($account)) {
            app(StaffMfa::class)->reset($account);
        }

        app(SsoSubjectBinding::class)->unbind($account, context: ['reason' => $reason]);
    }

    /**
     * The sync (or an admin save, through linkNow()) links the account to an
     * EMD record that, as far as the system can tell, is another person's:
     * the first link after an administrator changed the address by hand (a
     * client moving from one record to another is settled at the end of the
     * run instead, see settleRebounds()). As on the save that links at once
     * (applyAdminEmailChange()), the account is signed out everywhere and
     * what the previous holder set up goes: a staff member's password,
     * second factors and sign-in binding, a client's PIN, security answers
     * and phone numbers added by hand. Audited as account.access_revoked
     * (and client.holder_data_removed) with the reason and what went, and
     * noted for the run's report.
     */
    private function handOver(Client|User $account, ExternalRecord $record, string $reason): void
    {
        $ended = $account->revokeAccess();

        if ($account instanceof Client) {
            $removed = $this->removeClientHolder($account, $record, $reason);
        } else {
            $removed = $this->holderTraces($account, false, $this->mfaMethodsOf($account));
            $this->removeStaffHolder($account, $removed, $reason);
        }

        $this->auditor->record('account.access_revoked', $account, [
            'reason' => $reason,
            'external_id' => $record->external_id,
            'removed' => $removed,
        ] + $ended);

        $this->noteHolderDataRemoval($record, $reason, $removed);
    }

    /**
     * Deletes what the previous holder set up on a client account that goes
     * to another person (ClientHolderData::wipe()), audited as
     * client.holder_data_removed with the counts; returns the traces removed.
     *
     * @param  array<string, mixed>  $context  more for the audit entry (never a secret)
     * @return list<string>
     */
    private function removeClientHolder(Client $account, ExternalRecord $record, string $reason, array $context = []): array
    {
        $wiped = app(ClientHolderData::class)->wipe($account, $reason);
        $this->auditor->record('client.holder_data_removed', $account, ['reason' => $reason, 'takes_over_external_id' => $record->external_id] + $context + $wiped);

        return self::clientTraces($wiped);
    }

    /**
     * @param  list<string>  $removed
     */
    private function noteHolderDataRemoval(ExternalRecord $record, string $reason, array $removed): void
    {
        $this->holderDataRemovals[] = [
            'external_id' => (int) $record->external_id,
            'kind' => $record->kind->value,
            'email' => (string) $record->email,
            'reason' => $reason,
            'removed' => $removed,
        ];
    }

    /**
     * Decides, once every row of the run is in, what a client's move from one
     * EMD record to another (syncClient()) was (owner's decision of
     * 2026-10-26). If the old record is still in the source, that is, this
     * run saw it too, two people stand behind the two records and the
     * account went to the second one: what the previous holder set up (PIN,
     * security answers, phone numbers added by hand) is deleted
     * (client.holder_data_removed, reason sync.rebound, counted among the
     * run's hand-overs). If the old record is gone from the source, the
     * directory reissued the same person's record under a new external id:
     * nothing is deleted, the account simply follows the new record
     * (account.reissued, counted on the run as a reissue). Either way the
     * sign-ins ended at the move (revokeAfterIdentityChange()). Without a
     * run (an admin save that links at once) a move counts as a reissue.
     */
    public function settleRebounds(?SyncRun $run): void
    {
        $pending = $this->pendingRebounds;
        $this->pendingRebounds = [];

        foreach ($pending as ['client' => $client, 'record' => $record, 'from_record_id' => $fromRecordId]) {
            $old = ExternalRecord::withTrashed()->find($fromRecordId);
            $oldStillInSource = $run !== null && $old !== null && (string) $old->last_seen_run_id === (string) $run->id;
            $client->refresh();

            if ($oldStillInSource) {
                $removed = $this->removeClientHolder($client, $record, self::REASON_SYNC_REBOUND, ['from_external_id' => (int) $old->external_id]);
                $this->noteHolderDataRemoval($record, self::REASON_SYNC_REBOUND, $removed);

                continue;
            }

            $this->auditor->record('account.reissued', $client, [
                'from_external_id' => $old?->external_id,
                'external_id' => $record->external_id,
            ]);
            $this->reissues[] = [
                'external_id' => (int) $record->external_id,
                'from_external_id' => $old !== null ? (int) $old->external_id : null,
                'kind' => $record->kind->value,
                'email' => (string) $record->email,
            ];
        }
    }

    /**
     * Drops the moves a failed run left undecided, so that they cannot be
     * settled against a later run's state.
     */
    public function discardPendingRebounds(): void
    {
        $this->pendingRebounds = [];
    }

    /**
     * @return list<array{external_id: int, kind: string, email: string, reason: string, removed: list<string>}>
     */
    public function takeHolderDataRemovals(): array
    {
        $removals = $this->holderDataRemovals;
        $this->holderDataRemovals = [];

        return $removals;
    }

    /**
     * @return list<array{external_id: int, from_external_id: ?int, kind: string, email: string}>
     */
    public function takeReissues(): array
    {
        $reissues = $this->reissues;
        $this->reissues = [];

        return $reissues;
    }

    /**
     * Records of the account's kind carrying exactly this address: all of
     * them, those of the kind, the present ones and those without an
     * account of the kind yet.
     *
     * @return array{records: Collection<int, ExternalRecord>, of_kind: Collection<int, ExternalRecord>, present: Collection<int, ExternalRecord>, free: Collection<int, ExternalRecord>}
     */
    private function linkCandidates(Client|User $account, string $email): array
    {
        $kind = $account instanceof Client ? PrincipalType::Client : PrincipalType::User;
        $records = ExternalRecord::query()->fromDirectory()->where('email', mb_strtolower(trim($email)))->get()
            ->filter(fn (ExternalRecord $record): bool => self::sameEmail($record->email, $email));
        $ofKind = $records->filter(fn (ExternalRecord $record): bool => $record->kind === $kind);
        $present = $ofKind->reject(fn (ExternalRecord $record): bool => $record->isMissing());
        $free = $present->filter(fn (ExternalRecord $record): bool => ($kind === PrincipalType::Client ? $record->client : $record->user) === null);

        return ['records' => $records, 'of_kind' => $ofKind, 'present' => $present, 'free' => $free];
    }

    /**
     * What a staff account still carries of its holder: the password (kept
     * when the same save sets a new one), the second factors and the
     * company sign-in binding.
     *
     * @param  list<MfaMethod>  $methods
     * @return list<string>
     */
    private function holderTraces(User $account, bool $keepPassword, array $methods): array
    {
        return array_values(array_filter([
            ! $keepPassword && filled($account->getRawOriginal('password')) ? self::TRACE_PASSWORD : null,
            $methods !== [] ? self::TRACE_SECOND_FACTORS : null,
            $account->ssoIdentities()->exists() ? self::TRACE_SSO_BINDING : null,
        ]));
    }

    /**
     * What a client account carries of its holder (ClientHolderData::held()
     * or what wipe() deleted), as the traces the audit entry lists.
     *
     * @param  array{pin: bool, answers: int, phones: list<string>|int}  $data
     * @return list<string>
     */
    private static function clientTraces(array $data): array
    {
        return array_values(array_filter([
            $data['pin'] ? self::TRACE_PIN : null,
            $data['answers'] > 0 ? self::TRACE_ANSWERS : null,
            (is_int($data['phones']) ? $data['phones'] : count($data['phones'])) > 0 ? self::TRACE_MANUAL_PHONES : null,
        ]));
    }

    /**
     * Every second factor the account has set up, whether or not its method
     * is allowed at present (StaffMfa::reset removes them all).
     *
     * @return list<MfaMethod>
     */
    private function mfaMethodsOf(User $account): array
    {
        return array_values(array_filter(MfaMethod::cases(), fn (MfaMethod $method): bool => match ($method) {
            MfaMethod::App => filled($account->getRawOriginal('mfa_app_secret')),
            MfaMethod::Sms => $account->mfa_sms_number !== null,
            MfaMethod::Email => $account->mfa_email_enabled_at !== null,
        }));
    }

    /**
     * Whether the directory row with this address must not take over the
     * record the demo data created under the same external id: in
     * production a demo record (App\System\DemoData) and its account carry
     * published secrets (password, PIN, answers) that would pass to the real
     * person. Reported like an e-mail conflict (the record keeps its demo
     * address), audited as sync.demo_conflict and logged; the caller leaves
     * the row out. Never outside production, where the demo runs as before.
     */
    public function refusesDemoTakeover(ExternalRecord $record, string $email): bool
    {
        if (! DemoData::refusedHere() || ! DemoData::isDemoRecord($record)) {
            return false;
        }

        $account = $record->kind === PrincipalType::Client ? $record->client : $record->user;
        $context = ['external_id' => (int) $record->external_id, 'kind' => $record->kind->value, 'email' => $email, 'kept' => (string) ($account?->getEmail() ?? $record->email), 'demo' => true];
        $this->emailConflicts[] = $context;
        $this->auditor->record('sync.demo_conflict', $account instanceof Model ? $account : $record, $context);
        Log::warning('EMD sync: a directory record carries the external id of a demo record while APP_ENV=production; the demo record (published password, PIN and answers) was not handed over. Start production with an empty database.', $context);

        return true;
    }

    /**
     * @return list<array{external_id: int, kind: string, email: string, kept: string, look_alike?: true, demo?: true}>
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
     * Only the exact address is adopted (see reportedAsLookAlike()); a
     * look-alike holder is reported and the row gets no account.
     */
    private function syncClient(ExternalRecord $record): ?Client
    {
        $client = $record->client;

        if ($client === null) {
            $holder = Client::withTrashed()->where('email', $record->email)->first();

            if ($this->reportedAsLookAlike($holder, $record)) {
                return null;
            }

            $client = $holder;
        }

        if ($client !== null && $client->trashed()) {
            $client->restore();
        }

        $identityChange = null;
        $handOver = null;

        if ($client !== null && $client->external_record_id !== null && $client->external_record_id !== $record->id) {
            // Whether the previous holder's data goes is decided at the end of
            // the run, once it is known if the old record is still in the source
            // (settleRebounds()); the sign-ins end now either way.
            $identityChange = 'rebound';
            $this->pendingRebounds[] = ['client' => $client, 'record' => $record, 'from_record_id' => (string) $client->external_record_id];
            $this->auditor->record('account.rebound', $client, [
                'from_external_record_id' => $client->external_record_id,
                'to_external_record_id' => $record->id,
                'external_id' => $record->external_id,
            ]);
        } elseif ($client !== null && $client->external_record_id === null && $client->email_changed_by_admin_at !== null) {
            $handOver = self::REASON_LINKED_AFTER_EMAIL_CHANGE;
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
            $client->email_changed_by_admin_at = null;
            $client->save();
            $this->overrides->endIfDirectoryCaughtUp($client);

            if ($emailTaken) {
                $this->noteEmailConflict($client, $record);
                $identityChange = 'email_conflict';
            } elseif (! self::sameEmail($previousEmail, $client->email)) {
                $identityChange ??= 'email_changed';
            }
        }

        if ($handOver !== null) {
            // Signs out too, so the plain revocation below is not needed twice.
            $this->handOver($client, $record, $handOver);
        } elseif ($identityChange !== null) {
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
        $user = $record->user ?? $this->userToLink($record);

        if ($user === null) {
            return null;
        }

        if ($user->external_record_id !== $record->id) {
            $local = $user->externalRecord;
            $handedOver = $user->email_changed_by_admin_at !== null;
            $user->forceFill(['external_record_id' => $record->id, 'email_changed_by_admin_at' => null])->save();

            if ($local?->isLocal()) {
                $local->delete();
                $this->auditor->record('account.rebound', $user, ['from_external_id' => $local->external_id, 'to_external_id' => $record->external_id]);
            }

            // An administrator gave the account this address by hand: the record is another person's.
            if ($handedOver) {
                $this->handOver($user, $record, self::REASON_LINKED_AFTER_EMAIL_CHANGE);
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

    /**
     * An account the sync closed reopens when its record is back. A linked
     * client whose sponsor is closed (the link still active) stays closed,
     * now for the sponsor's reason, so that reopening the sponsor brings it
     * back (NYT-06.1): the sponsor's closure passes over an account that is
     * already closed, and which of the two the run closed first must not
     * decide whether the linked client gets back in. Audited as
     * account.kept_closed with the sponsor and the reason it had.
     */
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

        if ($why === null) {
            return;
        }

        if ($principal instanceof Client && ($held = app(ClientLinks::class)->closedSponsorLink($principal)) !== null) {
            $previous = $principal->closed_reason;
            $principal->forceFill(['closed_reason' => ClientLinks::CLOSE_REASON_SPONSOR])->save();
            $this->auditor->record('account.kept_closed', $principal, [
                'reason' => ClientLinks::CLOSE_REASON_SPONSOR,
                'previous_reason' => $previous,
                'sponsor_id' => $held->sponsor_client_id,
            ]);

            return;
        }

        $principal->reopen();
        $this->auditor->record('account.reopened', $principal, ['reason' => $why]);
    }

    /**
     * The not yet linked staff account (none, or a local bootstrap record)
     * holding exactly the row's address. A look-alike holder is reported
     * and not linked.
     */
    private function userToLink(ExternalRecord $record): ?User
    {
        $holder = User::query()->where('email', $record->email)->first();

        if ($holder === null || $this->reportedAsLookAlike($holder, $record)) {
            return null;
        }

        return User::query()->whereKey($holder->getKey())
            ->where(fn ($query) => $query->whereNull('external_record_id')
                ->orWhereHas('externalRecord', fn ($query) => $query->where('external_id', '>=', ExternalRecord::LOCAL_ID_BASE)))
            ->first();
    }

    /**
     * Whether the account the database found for the row's address holds a
     * different address, reported as an e-mail conflict if so. Only the
     * exact address (letter case aside) matches, as in the login rule
     * (AccountLogin::findByEmail): the MariaDB collation is accent-, case-
     * and width-insensitive, so "víctim@…" finds the account of "victim@…",
     * and taking it over would hand that person's account, PIN and numbers
     * to another record. The holder stays as it is (no sign-out either:
     * its own identity did not change).
     */
    private function reportedAsLookAlike(?Principal $holder, ExternalRecord $record): bool
    {
        if ($holder === null || self::sameEmail($holder->getEmail(), $record->email)) {
            return false;
        }

        $this->noteEmailConflict($holder, $record, lookAlike: true);

        return true;
    }

    /**
     * The form two addresses are compared in, here and in the run's
     * repeated-address filter (SyncExternalRecords): exact, letter case and
     * surrounding spaces aside, like the login's match.
     */
    public static function emailKey(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    private static function sameEmail(?string $a, ?string $b): bool
    {
        return self::emailKey($a) === self::emailKey($b);
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

    private function noteEmailConflict(Principal $principal, ExternalRecord $record, bool $lookAlike = false): void
    {
        $context = ['external_id' => $record->external_id, 'kind' => $record->kind->value, 'email' => $record->email, 'kept' => $principal->getEmail()]
            + ($lookAlike ? ['look_alike' => true] : []);
        $this->emailConflicts[] = $context;
        $this->auditor->record('sync.email_conflict', $principal, $context);
        Log::warning($lookAlike
            ? 'EMD sync: a directory record carries an address that equals another account\'s only in the database\'s accent- and case-insensitive comparison; the account was not taken over.'
            : 'EMD sync: the directory renamed an account e-mail to an address another account already holds; the old address was kept.', $context);
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
