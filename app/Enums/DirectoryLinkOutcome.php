<?php

namespace App\Enums;

use App\Auth\AccountLogin;
use App\Auth\Contracts\Principal;
use App\Models\Client;
use App\Models\User;
use App\Sync\AccountProvisioner;

/**
 * What happened when an account an admin created or saved was offered to
 * the EMD records at once (AccountProvisioner::linkNow), told on the page.
 */
enum DirectoryLinkOutcome: string
{
    case Linked = 'linked';
    case NoRecord = 'no_record';
    case OtherKind = 'other_kind';
    case Missing = 'missing';
    case Taken = 'taken';
    case Ambiguous = 'ambiguous';

    public function isLinked(): bool
    {
        return $this === self::Linked;
    }

    public function message(Principal $account): string
    {
        $id = $account instanceof User || $account instanceof Client ? $account->externalRecord?->external_id : null;
        $meanwhile = self::signsInMeanwhile($account);

        if ($meanwhile !== null) {
            return match ($this) {
                self::NoRecord => __('Not linked to EMD yet: no EMD record carries this e-mail. Meanwhile :how; an EMD sync that brings a record with this e-mail links the account.', ['how' => $meanwhile]),
                self::Missing => __('Not linked to EMD yet: the EMD record with this e-mail is missing from the recent syncs. The sync links the account when the record returns; meanwhile :how.', ['how' => $meanwhile]),
                self::OtherKind => $account instanceof Client
                    ? __('Not linked to EMD: the EMD record with this e-mail is classified as staff, not as a client (the e-mail domain lists under Settings › Enterprise Master Data decide). Meanwhile :how.', ['how' => $meanwhile])
                    : __('Not linked to EMD: the EMD record with this e-mail is classified as a client, not as staff (the e-mail domain lists under Settings › Enterprise Master Data decide). Meanwhile :how.', ['how' => $meanwhile]),
                default => $this->strictMessage($account, $id),
            };
        }

        return $this->strictMessage($account, $id);
    }

    /**
     * How the account signs in while it is not linked, when it can: a staff
     * account with its local record (hdid:make-admin; the record follows an
     * e-mail change), a client while CLIENT_LOGIN_REQUIRE_EMD is off. Null
     * when the login rule needs the EMD record.
     */
    private static function signsInMeanwhile(Principal $account): ?string
    {
        if ($account instanceof Client && ! AccountLogin::clientsNeedEmd()) {
            return __('the client signs in without an EMD record (CLIENT_LOGIN_REQUIRE_EMD is off)');
        }

        if (($account instanceof User || $account instanceof Client) && $account->externalRecord?->isLocal()
            && AccountProvisioner::emailKey($account->externalRecord->email) === AccountProvisioner::emailKey($account->email)) {
            return __('the account signs in with its local record');
        }

        return null;
    }

    private function strictMessage(Principal $account, mixed $id): string
    {
        return match ($this) {
            self::Linked => $account instanceof Client
                ? __('Linked to EMD record :id at once: the name, e-mail, packages and phone numbers now come from EMD.', ['id' => $id])
                : __('Linked to EMD record :id at once.', ['id' => $id]),
            self::NoRecord => __('Not linked to EMD yet: no EMD record carries this e-mail. The account cannot sign in until an EMD sync brings a record with the same e-mail and links it.'),
            self::OtherKind => $account instanceof Client
                ? __('Not linked to EMD: the EMD record with this e-mail is classified as staff, not as a client (the e-mail domain lists under Settings › Enterprise Master Data decide). The account cannot sign in until it is linked.')
                : __('Not linked to EMD: the EMD record with this e-mail is classified as a client, not as staff (the e-mail domain lists under Settings › Enterprise Master Data decide). The account cannot sign in until it is linked.'),
            self::Missing => __('Not linked to EMD yet: the EMD record with this e-mail is missing from the recent syncs. The sync links the account when the record returns; until then it cannot sign in.'),
            self::Taken => __('Not linked to EMD: the EMD record with this e-mail is already linked to another account. Check the accounts and the EMD record.'),
            self::Ambiguous => __('Not linked to EMD yet: several EMD records carry this e-mail. The next EMD sync decides which one the account belongs to.'),
        };
    }
}
