<?php

namespace App\Filament\Concerns;

use App\Enums\DirectoryLinkOutcome;
use App\Enums\MfaMethod;
use App\Filament\Admin\Resources\SyncRuns\SyncRunResource;
use App\Models\Client;
use App\Models\User;
use App\Support\HuDate;
use App\Sync\AccountProvisioner;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;

/**
 * Create and edit pages of users and clients: an account not linked to an
 * EMD record yet is offered to the stored records on save, and the
 * notification tells the outcome (linked, or why not).
 *
 * The e-mail is the account's identity. On an account linked to its EMD
 * record or bound to a company sign-in it cannot be changed here (the field
 * is locked with the reason, and the save drops it); on a free account the
 * Save button first names what a new address ends, the save then signs the
 * account out everywhere and tells what ended (AccountProvisioner::
 * adminEmailChange() and applyAdminEmailChange()). A new address that
 * hands the account to another person's EMD record also removes what the
 * previous holder set up: a staff member's password, second factors and
 * sign-in binding, a client's PIN, security answers and phone numbers
 * added by hand (ClientHolderData).
 */
trait LinksToDirectory
{
    private ?DirectoryLinkOutcome $directoryLink = null;

    /** The address the record had before this save, to tell an e-mail change. */
    private ?string $emailBeforeSave = null;

    /** Whether this save sets a new password: it then stays when the account changes hands. */
    private bool $passwordSetBySave = false;

    /**
     * What the e-mail change of this save ended.
     *
     * @var array{tokens: int, sessions: ?int, secrets: int, removed: list<string>, takes_over_external_id: ?int, wiped: array{pin: bool, answers: int, phones: int}|null}|null
     */
    private ?array $emailChangeEnded = null;

    /**
     * The e-mail change as pendingEmailChange() computed it in this request,
     * with the form state it was computed for.
     *
     * @var array{key: string, change: ?array<string, mixed>}|null
     */
    private ?array $pendingEmailChangeMemo = null;

    /**
     * The e-mail change the confirmation showed, set when the Save action
     * is mounted; the save stops if what it is about to do differs.
     */
    #[Locked]
    public ?string $confirmedEmailChange = null;

    protected function afterCreate(): void
    {
        $this->linkToDirectory();
    }

    protected function beforeSave(): void
    {
        $record = $this->getRecord();
        $this->emailBeforeSave = $record instanceof User || $record instanceof Client ? $record->email : null;
        $this->passwordSetBySave = filled($this->data['password'] ?? null);
    }

    /**
     * The field of a locked e-mail is neither editable nor saved; this is
     * the server-side check behind it, whatever the form sends. A change
     * typed while the account was still free (it got linked or bound in
     * the meantime) is dropped, and the page says why.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if (! ($record instanceof User || $record instanceof Client) || ($reason = static::emailLockText($record)) === null) {
            return $data;
        }

        unset($data['email']);
        $typed = $this->data['email'] ?? null;

        if (is_string($typed) && AccountProvisioner::emailKey($typed) !== AccountProvisioner::emailKey($record->email)) {
            Notification::make()->title(__('The e-mail was not changed'))->body($reason)->warning()->persistent()->send();
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        $this->emailChangeEnded = $this->emailBeforeSave !== null && ($record instanceof User || $record instanceof Client)
            ? app(AccountProvisioner::class)->applyAdminEmailChange($record, $this->emailBeforeSave, $this->passwordSetBySave)
            : null;

        $this->linkToDirectory();

        if ($this->emailChangeEnded !== null) {
            $this->signedOutNotification($this->emailBeforeSave, $record->email, $this->emailChangeEnded)->send();
        }
    }

    /**
     * Why the e-mail of the account cannot be changed here, under the field
     * and in the refusal; null for a free account.
     */
    public static function emailLockText(User|Client $record): ?string
    {
        return match ($record->manualEmailLock()) {
            $record::EMAIL_LOCK_DIRECTORY => __('Comes from EMD; change it there. Last EMD sync: :when', ['when' => $record->externalRecord?->last_seen_at?->diffForHumans() ?? '-']),
            $record::EMAIL_LOCK_SSO_BINDING => __('Cannot be changed while the company sign-in is bound to a directory identity: the address belongs to that identity. Clear the sign-in binding first if it really has to change.'),
            default => null,
        };
    }

    /**
     * Under the e-mail field of a free account whose address an
     * administrator changed by hand: the first link to an EMD record will
     * take the record as another person's. Null otherwise.
     */
    public static function handOverNote(User|Client|null $record): ?string
    {
        if ($record?->email_changed_by_admin_at === null || ($record->externalRecord !== null && ! $record->externalRecord->isLocal())) {
            return null;
        }

        return $record instanceof Client
            ? __('The address was changed by hand (:at). When an EMD record with this address links the account, it is taken as another person\'s: the account is signed out everywhere, and the PIN, the security answers and the phone numbers added by hand are deleted.', ['at' => $record->email_changed_by_admin_at->format(HuDate::DATETIME)])
            : __('The address was changed by hand (:at). When an EMD record with this address links the account, it is taken as another person\'s: the account is signed out everywhere, and the password, the second factors and the company sign-in binding are removed.', ['at' => $record->email_changed_by_admin_at->format(HuDate::DATETIME)]);
    }

    /**
     * The e-mail change the form would save as it stands, or null
     * (AccountProvisioner::adminEmailChange()).
     *
     * @return array<string, mixed>|null
     */
    public function pendingEmailChange(): ?array
    {
        $record = $this->getRecord();

        if (! $record instanceof User && ! $record instanceof Client) {
            return null;
        }

        $to = $this->data['email'] ?? null;
        $keepPassword = filled($this->data['password'] ?? null);
        $key = json_encode([$record->getKey(), $record->email, $to, $keepPassword]);

        if ($this->pendingEmailChangeMemo === null || $this->pendingEmailChangeMemo['key'] !== $key) {
            $this->pendingEmailChangeMemo = [
                'key' => $key,
                'change' => app(AccountProvisioner::class)->adminEmailChange($record, is_string($to) ? $to : null, $keepPassword),
            ];
        }

        return $this->pendingEmailChangeMemo['change'];
    }

    /**
     * The Save button of an edit page asks first when the e-mail changes,
     * naming the old and the new address and what ends.
     */
    protected function confirmingEmailChange(Action $action): Action
    {
        return $action
            ->requiresConfirmation(fn (): bool => $this->pendingEmailChange() !== null)
            // A custom heading or description opens the modal on its own in Filament;
            // modal() keeps it closed unless the e-mail really changes.
            ->modal(fn (): bool => $this->pendingEmailChange() !== null)
            ->modalHeading(__('Change the e-mail address?'))
            ->modalDescription(fn (): ?HtmlString => $this->emailChangeDescription())
            ->modalSubmitActionLabel(__('Save'))
            ->mountUsing(function (): void {
                $this->confirmedEmailChange = $this->emailChangeFingerprint();
            });
    }

    /**
     * The confirmed save recomputes the e-mail change and stops if it no
     * longer is what the question showed (an EMD record with the new address
     * arrived, a second factor was set up, a client's PIN, answers or
     * numbers added by hand changed, the address changed): the
     * question stays open with the new consequences and needs a new
     * confirmation.
     */
    protected function assertEmailChangeAsConfirmed(Action $action): void
    {
        $now = $this->emailChangeFingerprint();

        if ($now === null || $now === $this->confirmedEmailChange) {
            return;
        }

        $this->confirmedEmailChange = $now;
        Notification::make()->title(__('The consequences changed: check them again'))
            ->body($this->getRecord() instanceof Client
                ? __('Something the e-mail change depends on changed while the question was open (an EMD record with the new address, the PIN, the security answers or the phone numbers added by hand, the stored address). The question now shows what saving does; confirm again to save.')
                : __('Something the e-mail change depends on changed while the question was open (an EMD record with the new address, a second factor, the stored address). The question now shows what saving does; confirm again to save.'))
            ->warning()->send();

        $action->halt();
    }

    /**
     * The question of the Save button: old and new address, what ends, and
     * for an account the new address hands to an EMD record, what the
     * previous holder loses (a client's PIN, security answers and numbers
     * added by hand by count, also saying which of them there are none of).
     */
    public function emailChangeDescription(): ?HtmlString
    {
        $change = $this->pendingEmailChange();

        if ($change === null) {
            return null;
        }

        $access = $change['access'];
        $ends = array_values(array_filter([
            match (true) {
                $access['sessions'] !== null => $access['sessions'] > 0 ? __(':n open browser session(s) end.', ['n' => $access['sessions']]) : null,
                $access['last_active_at'] !== null => __('Any open browser session ends (last activity :time).', ['time' => $access['last_active_at']->format(HuDate::DATETIME)]),
                default => null,
            },
            $access['tokens'] > 0 ? __(':n mobile app token(s) are deleted.', ['n' => $access['tokens']]) : null,
            $access['secrets'] > 0 ? __(':n live login link(s) and code(s) can no longer be used.', ['n' => $access['secrets']]) : null,
            $access['remember'] ? __('Remember-me cookies no longer sign the account in.') : null,
        ]));

        $paragraphs = [e(__('The e-mail address is the account\'s identity. Saving changes it from :from to :to and signs the account out everywhere at once:', ['from' => $change['from'], 'to' => $change['to']]))
            .'<br>'.($ends === [] ? e(__('Nothing is signed in or waiting to be used at present, so nothing has to be revoked.')) : self::bullets($ends))
            .'<br>'.e(__('Signing in again needs the new address.'))];

        if ($change['target'] === null && ($change['keeps'] ?? []) !== []) {
            $paragraphs[] = $this->getRecord() instanceof Client
                ? self::clientKeepsText((array) $change['held'])
                : e(__('What the holder set up stays for now: :items. When an EMD record later links the account, it is taken as another person\'s: the account is then signed out everywhere and these are removed. If the account goes to another person sooner, remove them yourself (a new password in this form, Reset the second factor, Clear the sign-in binding).', ['items' => implode(', ', array_map(fn (string $trace): string => match ($trace) {
                    AccountProvisioner::TRACE_PASSWORD => __('the password'),
                    AccountProvisioner::TRACE_SECOND_FACTORS => __('the second factors (:methods)', ['methods' => implode(', ', array_map(fn (MfaMethod $method): string => $method->label(), $change['mfa_methods']))]),
                    default => __('the company sign-in binding'),
                }, $change['keeps']))]));
        }

        if ($change['target'] !== null) {
            $record = ['id' => $change['target']->external_id, 'name' => $change['target']->name];
            $paragraphs[] = match (true) {
                $change['held'] !== null && $change['takes_over'] => self::clientHandOverText($record, $change['held']),
                $change['takes_over'] => e(__('Saving also links the account to EMD record :id (:name). As far as the system can tell that is another person, so what the previous holder set up is removed:', $record))
                    .'<br>'.($change['removes'] === [] ? e(__('The previous holder set up no password, second factor or sign-in binding.')) : self::bullets(array_map(fn (string $trace): string => match ($trace) {
                        AccountProvisioner::TRACE_PASSWORD => __('the password'),
                        AccountProvisioner::TRACE_SECOND_FACTORS => __('the second factors (:methods)', ['methods' => implode(', ', array_map(fn (MfaMethod $method): string => $method->label(), $change['mfa_methods']))]),
                        default => __('the company sign-in binding'),
                    }, $change['removes'])))
                    .(filled($this->data['password'] ?? null) ? '<br>'.e(__('The password typed in this form becomes the new holder\'s password.')) : ''),
                default => e(__('Saving also links the account to EMD record :id (:name): the name, packages and phone numbers then come from EMD, and the e-mail can only change there.', $record)),
            };
        }

        $paragraphs[] = e(__('Recorded in the audit log.'));

        return new HtmlString(implode('<br><br>', $paragraphs));
    }

    /**
     * What a client account handed to another person's EMD record loses:
     * each of the PIN, the security answers and the numbers added by hand,
     * with the count, or that there is none.
     *
     * @param  array{id: mixed, name: mixed}  $record
     * @param  array{pin: bool, answers: int, phones: list<string>}  $held
     */
    private static function clientHandOverText(array $record, array $held): string
    {
        $phones = count($held['phones']);

        return e(__('Saving also links the account to EMD record :id (:name). As far as the system can tell that is another person, so the previous holder\'s identification data is deleted:', $record))
            .'<br>'.self::bullets([
                $held['pin'] ? __('The PIN is deleted, with its lockout state.') : __('No PIN is set.'),
                $held['answers'] > 0 ? __(':n security answer(s) are deleted.', ['n' => $held['answers']]) : __('No security answers are recorded.'),
                $phones > 0
                    ? __(':n phone number(s) added by hand are deleted: :numbers.', ['n' => $phones, 'numbers' => implode(', ', array_slice($held['phones'], 0, 10)).($phones > 10 ? ' …' : '')])
                    : __('No phone number was added by hand.'),
            ])
            .'<br>'.e(__('The name, packages and phone numbers then come from EMD, and the e-mail can only change there.'));
    }

    /**
     * What a client account keeps when the new address hands it to no EMD
     * record: the PIN, the security answers and the numbers added by hand,
     * only those there are.
     *
     * @param  array{pin?: bool, answers?: int, phones?: list<string>}  $held
     */
    private static function clientKeepsText(array $held): string
    {
        $phones = count($held['phones'] ?? []);
        $items = array_values(array_filter([
            ($held['pin'] ?? false) ? __('the PIN') : null,
            ($held['answers'] ?? 0) > 0 ? __(':n security answer(s)', ['n' => $held['answers']]) : null,
            $phones > 0 ? __(':n phone number(s) added by hand', ['n' => $phones]) : null,
        ]));

        return e(__('What the holder set up stays for now: :items. When an EMD record with the new address later links the account, it is taken as another person\'s: the account is then signed out everywhere and these are deleted. If somebody else uses the account sooner, delete them yourself (Remove PIN, the Answers tab, the Phone numbers tab).', ['items' => implode(', ', $items)]));
    }

    /**
     * What the question was about, to tell whether the save still does that.
     */
    private function emailChangeFingerprint(): ?string
    {
        $change = $this->pendingEmailChange();

        return $change === null ? null : hash('sha256', (string) json_encode([
            AccountProvisioner::emailKey($change['from']),
            AccountProvisioner::emailKey($change['to']),
            $change['target']?->getKey(),
            $change['takes_over'],
            $change['removes'],
            $change['keeps'] ?? [],
            array_map(fn (MfaMethod $method): string => $method->value, $change['mfa_methods']),
            $change['held'],
        ]));
    }

    /**
     * @param  array{tokens: int, sessions: ?int, secrets: int, removed: list<string>, takes_over_external_id: ?int, wiped: array{pin: bool, answers: int, phones: int}|null}  $ended
     */
    private function signedOutNotification(string $from, string $to, array $ended): Notification
    {
        $lines = array_values(array_filter([
            __('The account now uses :to instead of :from.', ['from' => $from, 'to' => $to]),
            $ended['sessions'] !== null ? __(':n browser session(s) ended.', ['n' => $ended['sessions']]) : __('Every browser session opened before ended.'),
            $ended['tokens'] > 0 ? __(':n mobile app token(s) deleted.', ['n' => $ended['tokens']]) : null,
            $ended['secrets'] > 0 ? __(':n login link(s) and code(s) revoked.', ['n' => $ended['secrets']]) : null,
            __('Remember-me cookies no longer work.'),
            match (true) {
                ($ended['wiped'] ?? null) !== null => self::clientWipedText($ended['wiped']),
                $ended['removed'] !== [] => __('Removed from the previous holder: :items.', ['items' => implode(', ', array_map(fn (string $trace): string => match ($trace) {
                    AccountProvisioner::TRACE_PASSWORD => __('the password'),
                    AccountProvisioner::TRACE_SECOND_FACTORS => __('the second factors'),
                    default => __('the company sign-in binding'),
                }, $ended['removed']))]),
                default => null,
            },
        ]));

        return Notification::make()->title(__('Signed out everywhere: the e-mail changed'))
            ->body(implode(' ', $lines))
            ->info()->persistent();
    }

    /**
     * What the hand-over of a client account actually deleted of the
     * previous holder.
     *
     * @param  array{pin: bool, answers: int, phones: int}  $wiped
     */
    private static function clientWipedText(array $wiped): string
    {
        $items = array_values(array_filter([
            $wiped['pin'] ? __('the PIN') : null,
            $wiped['answers'] > 0 ? __(':n security answer(s)', ['n' => $wiped['answers']]) : null,
            $wiped['phones'] > 0 ? __(':n phone number(s) added by hand', ['n' => $wiped['phones']]) : null,
        ]));

        return $items === []
            ? __('The previous holder left no PIN, security answers or phone numbers added by hand.')
            : __('Removed from the previous holder: :items.', ['items' => implode(', ', $items)]);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function bullets(array $lines): string
    {
        return implode('<br>', array_map(fn (string $line): string => '• '.e($line), $lines));
    }

    private function linkToDirectory(): void
    {
        $record = $this->getRecord();

        if (! $record instanceof User && ! $record instanceof Client) {
            $this->directoryLink = null;

            return;
        }

        $provisioner = app(AccountProvisioner::class);
        $this->directoryLink = $provisioner->linkNow($record);

        // The first link after a hand-made address change: the record is taken as another person's.
        foreach ($provisioner->takeHolderDataRemovals() as $removal) {
            Notification::make()->title(__('Handed to another person: EMD record :id', ['id' => $removal['external_id']]))
                ->body(__('The address was changed by hand earlier, so the record is taken as another person\'s. The account was signed out everywhere. Removed from the previous holder: :items.', [
                    'items' => $removal['removed'] === [] ? __('nothing was set up') : implode(', ', array_map(fn (string $trace): string => SyncRunResource::traceLabel($trace), $removal['removed'])),
                ]))
                ->warning()->persistent()->send();
        }
    }

    private function withDirectoryLink(?Notification $notification): ?Notification
    {
        if ($notification === null || $this->directoryLink === null) {
            return $notification;
        }

        /** @var User|Client $record */
        $record = $this->getRecord();
        $notification->body($this->directoryLink->message($record));

        return $this->directoryLink->isLinked() ? $notification : $notification->warning()->persistent();
    }
}
