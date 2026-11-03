<?php

namespace App\Clients;

use App\Audit\Auditor;
use App\Enums\IdSessionStatus;
use App\Enums\PhoneNumberSource;
use App\Enums\PhoneVerificationSource;
use App\Identification\PinService;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Models\Client;
use App\Models\ClientPhoneNumber;
use App\Models\IdSession;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\SmsDestinations;
use App\System\Installation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules around a client's phone numbers: the phone menu recognises a
 * caller by number, so the PIN is worthless without one, a number held by
 * several open clients recognises nobody, and a number counts as verified
 * only when something proved it (the directory, the operator who added it,
 * the SMS code, a staff confirmation, or an identified call from it).
 */
class ClientPhones
{
    public const PIN_CLEARED_REASON = 'last_phone_removed';

    /** Lock around one client's portal number additions. */
    private const PHONES_LOCK_PREFIX = 'hdid:phones:';

    public function __construct(
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly ClientTiers $tiers,
    ) {}

    /**
     * Open clients other than the given one that carry this number. The
     * holder checks count every open client, served or not: a number shared
     * with a client hidden by premium-only mode is still shared, so the phone
     * menu cannot tell the callers apart. Names are shown via holderNames().
     *
     * @return Collection<int, Client>
     */
    public function otherHolders(string $e164, ?Client $except = null): Collection
    {
        return Client::query()->open()
            ->whereHas('phoneNumbers', fn (Builder $query) => $query->where('number_e164', $e164))
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except->getKey()))
            ->orderBy('name')
            ->get();
    }

    /**
     * The holders as a staff view may name them: the served ones by name, the
     * ones hidden by premium-only mode only counted.
     *
     * @param  Collection<int, Client>  $holders
     */
    public function holderNames(Collection $holders): string
    {
        $named = $holders->filter(fn (Client $client): bool => $this->tiers->isServed($client))->pluck('name');
        $hidden = $holders->count() - $named->count();

        return $named->when($hidden > 0, fn ($names) => $names->push(__(':n client(s) not served at present', ['n' => $hidden])))->implode(', ');
    }

    public function isShared(ClientPhoneNumber $phone): bool
    {
        return in_array($phone->number_e164, $this->sharedNumbersOf($phone->client), true);
    }

    /**
     * The client's numbers that another open client also carries: the phone
     * menu cannot recognise the caller from any of them.
     *
     * @return list<string>
     */
    public function sharedNumbersOf(Client $client): array
    {
        $numbers = $client->relationLoaded('phoneNumbers') ? $client->phoneNumbers : $client->phoneNumbers()->get();

        if ($numbers->isEmpty()) {
            return [];
        }

        return ClientPhoneNumber::query()
            ->whereIn('number_e164', $numbers->pluck('number_e164')->all())
            ->whereKeyNot($numbers->pluck('id')->all())
            ->where('client_id', '!=', $client->getKey())
            ->whereHas('client', fn (Builder $query) => $query->open())
            ->distinct()
            ->pluck('number_e164')
            ->all();
    }

    /**
     * Every number row whose number is on file for more than one open client,
     * listing only the rows of clients the system serves (in premium-only mode
     * a number shared with a hidden client shows with its served holder only).
     *
     * @return Builder<ClientPhoneNumber>
     */
    public function sharedNumbersQuery(): Builder
    {
        return ClientPhoneNumber::query()
            ->whereIn('number_e164', fn (QueryBuilder $sub) => $sub
                ->select('shared.number_e164')
                ->from('client_phone_numbers as shared')
                ->join('clients as holders', 'holders.id', '=', 'shared.client_id')
                ->whereNull('shared.deleted_at')
                ->whereNull('holders.deleted_at')
                ->whereNull('holders.closed_at')
                ->groupBy('shared.number_e164')
                ->havingRaw('COUNT(DISTINCT shared.client_id) > 1'))
            ->whereHas('client', fn (Builder $query) => $query->open()->tap(fn (Builder $served) => $this->tiers->scopeServed($served)));
    }

    public function sharedNumberCount(): int
    {
        return $this->sharedNumbersQuery()->distinct()->count('number_e164');
    }

    /**
     * Mark a number as verified and record what proved it.
     *
     * @param  array<string, mixed>  $context
     */
    public function verify(ClientPhoneNumber $phone, PhoneVerificationSource $via, ?User $by = null, array $context = []): void
    {
        $wasVouched = $phone->vouchedForSignIn();

        $phone->forceFill([
            'verified_at' => $phone->verified_at ?? now(),
            'verified_via' => $via,
            'verified_by_user_id' => $by?->getKey(),
        ])->save();

        $this->auditor->record('client_phone.verified', $phone, $context + ['number' => $phone->number_e164, 'via' => $via->value], $by);

        if (! $wasVouched) {
            $this->tellNewSignInNumber($phone);
        }
    }

    /**
     * A number the client added became usable for the SMS sign-in: the
     * client hears of it by e-mail, in their own language, so a number
     * slipped in by somebody else does not go unnoticed.
     */
    private function tellNewSignInNumber(ClientPhoneNumber $phone): void
    {
        $client = Client::query()->find($phone->client_id);

        if ($client === null || blank($client->email)
            || ! $this->settings->bool(SettingKey::ClientLoginOtpSmsEnabled)
            || ! app(SmsDestinations::class)->allows($phone->number_e164)) {
            return;
        }

        app(Messenger::class)->sendTemplate(MessageKey::SignInNumberAdded, $client, ['number' => $phone->number_e164], $client->email);
    }

    public function verifiesOnIdentifiedCall(): bool
    {
        return $this->settings->bool(SettingKey::VerifyPhoneOnIdentifiedCall);
    }

    /**
     * A client identified on a call proves the number the call came from;
     * when the operator switched this on, the client's still unverified copy
     * of that number becomes verified.
     */
    public function verifyFromIdentifiedCall(IdSession $session): void
    {
        if (! $this->verifiesOnIdentifiedCall() || $session->call_id === null || $session->status !== IdSessionStatus::Passed) {
            return;
        }

        $number = $session->call()->first()?->caller_number_e164;

        if ($number === null) {
            return;
        }

        $phone = ClientPhoneNumber::query()
            ->where('client_id', $session->client_id)
            ->where('number_e164', $number)
            ->whereNull('verified_at')
            ->first();

        if ($phone !== null) {
            $this->verify($phone, PhoneVerificationSource::IdentifiedCall, null, ['id_session_id' => $session->id, 'call_id' => $session->call_id]);
        }
    }

    /**
     * Without a registered number the phone menu never asks for the PIN, so
     * when the last number goes, the PIN goes with it.
     */
    public function afterRemoval(ClientPhoneNumber $phone): void
    {
        // On the client row's lock, as setting a PIN re-checks the numbers
        // on it: a PIN set while the last number goes is either refused or
        // cleared here, never left without a number.
        DB::transaction(function () use ($phone): void {
            $client = Client::query()->whereKey($phone->client_id)->lockForUpdate()->first();

            if ($client === null || ! $client->hasPin() || $client->phoneNumbers()->exists()) {
                return;
            }

            app(PinService::class)->clearPin($client, self::PIN_CLEARED_REASON);
        });
    }

    /**
     * A number the client adds on the portal: unconfirmed until the client
     * confirms a code sent to it. A number removed earlier comes back (the
     * unique index keeps one row per number), its ownership to be proven
     * again. The limit check and the write run under the client's lock, so
     * simultaneous additions cannot pass the limit or collide; adding a
     * number the client already has changes nothing.
     *
     * @throws ValidationException
     */
    public function addOwnNumber(Client $client, string $e164, ?string $label): void
    {
        Cache::lock(Installation::lockName(self::PHONES_LOCK_PREFIX.$client->id), 10)->block(5, function () use ($client, $e164, $label): void {
            /** @var ClientPhoneNumber|null $existing */
            $existing = $client->phoneNumbers()->withTrashed()->where('number_e164', $e164)->first();

            if ($existing !== null && ! $existing->trashed()) {
                return;
            }

            $max = $this->settings->int(SettingKey::PortalMaxPhoneNumbersPerClient);

            if ($max > 0 && $client->phoneNumbers()->count() >= $max) {
                throw ValidationException::withMessages(['number' => __('You can keep at most :n phone numbers. Remove one first.', ['n' => $max])]);
            }

            $isFirst = ! $client->phoneNumbers()->exists();

            if ($existing !== null) {
                $existing->restore();
                $existing->forceFill([
                    'label' => $label,
                    'source' => PhoneNumberSource::ClientSelf,
                    'is_primary' => $isFirst,
                    'verified_at' => null,
                    'verified_via' => null,
                    'verified_by_user_id' => null,
                ])->save();

                return;
            }

            $client->phoneNumbers()->create([
                'number_e164' => $e164,
                'label' => $label,
                'source' => PhoneNumberSource::ClientSelf,
                'is_primary' => $isFirst,
            ]);
        });
    }

    /**
     * The operator added or changed a number that other clients carry and
     * confirmed that they know what it means for the phone menu.
     *
     * @param  Collection<int, Client>  $holders
     */
    public function recordSharedAcknowledgement(ClientPhoneNumber $phone, Collection $holders, ?User $by = null): void
    {
        $this->auditor->record('client_phone.shared_acknowledged', $phone, [
            'number' => $phone->number_e164,
            'shared_with' => $holders->pluck('id')->all(),
        ], $by);
    }
}
