<?php

namespace App\Clients;

use App\Enums\PhoneNumberSource;
use App\Identification\PinService;
use App\Models\Client;
use App\Models\ClientAnswer;
use App\Models\ClientPhoneNumber;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What identifies the person behind a client account apart from the
 * e-mail: the PIN, the security answers and the phone numbers added by hand
 * (in the portal or by staff). The phone line recognises and checks the
 * caller by these, so when an administrator's e-mail change hands a free
 * account to another person's EMD record, they belong to the previous
 * holder and are wiped (AccountProvisioner::applyAdminEmailChange()). The
 * numbers that come from EMD are left to the sync.
 */
class ClientHolderData
{
    public function __construct(private readonly PinService $pins) {}

    /**
     * What wipe() would delete now, for the confirmation that names it:
     * whether a PIN (or its lockout state) is on file, the number of
     * security answers, and the numbers added by hand.
     *
     * @return array{pin: bool, answers: int, phones: list<string>}
     */
    public function held(Client $client): array
    {
        return [
            'pin' => $this->hasPinState($client),
            'answers' => ClientAnswer::query()->where('client_id', $client->getKey())->count(),
            'phones' => $this->handAddedPhones($client)->orderBy('number_e164')->pluck('number_e164')->all(),
        ];
    }

    /**
     * Deletes the PIN with its lockout state, the security answers and the
     * numbers added by hand, and returns how much went (the rows removed
     * earlier are not counted). The PIN goes first, so that removing the
     * last number does not clear it a second time under another reason.
     * The answers and numbers are deleted for good, the earlier removed
     * ones too: they are the previous holder's, and a removed row would
     * otherwise come back with that person's data when the new holder
     * answers the same question or adds the same number.
     *
     * @return array{pin: bool, answers: int, phones: int}
     */
    public function wipe(Client $client, string $reason): array
    {
        $pin = $this->hasPinState($client);

        if ($pin) {
            $this->pins->clearPin($client, $reason);
        }

        $answers = ClientAnswer::withTrashed()->where('client_id', $client->getKey())->get();
        $phones = $this->handAddedPhones($client)->withTrashed()->get();
        $wiped = [
            'pin' => $pin,
            'answers' => $answers->reject(fn (ClientAnswer $answer): bool => $answer->trashed())->count(),
            'phones' => $phones->reject(fn (ClientPhoneNumber $phone): bool => $phone->trashed())->count(),
        ];

        $answers->each(fn (ClientAnswer $answer) => $answer->forceDelete());
        $phones->each(fn (ClientPhoneNumber $phone) => $phone->forceDelete());

        // A directory number left on its own becomes the primary one.
        $remaining = $client->phoneNumbers()->orderBy('created_at')->get();
        if ($remaining->isNotEmpty() && ! $remaining->contains('is_primary', true)) {
            $remaining->first()->makePrimary();
        }

        return $wiped;
    }

    private function hasPinState(Client $client): bool
    {
        return $client->hasPin()
            || (int) $client->pin_failed_attempts > 0
            || $client->pin_locked_until !== null
            || (int) $client->pin_lockout_count > 0;
    }

    /**
     * @return HasMany<ClientPhoneNumber, Client>
     */
    private function handAddedPhones(Client $client): HasMany
    {
        return $client->phoneNumbers()->where('source', '!=', PhoneNumberSource::Sync->value);
    }
}
