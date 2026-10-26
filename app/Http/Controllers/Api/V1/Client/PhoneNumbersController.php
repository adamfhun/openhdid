<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Audit\Auditor;
use App\Auth\Passwordless\OneTimeCodes;
use App\Clients\ClientPhones;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PhoneNumberSource;
use App\Enums\PhoneVerificationSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ClientResource;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Models\Client;
use App\Models\ClientPhoneNumber;
use App\Models\OneTimeCode;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\SmsDestinations;
use App\Support\PhoneNormalizer;
use App\Support\PhoneNumberRule;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Phone numbers the client adds for themselves. Numbers that came from the
 * directory sync or an admin cannot be changed here. A self-added number is
 * unverified until the client confirms a code sent to it by SMS; unverified
 * numbers still help the caller lookup, but verified ones outrank them.
 * Only numbers of the accepted countries are taken (phone.allowed_regions):
 * any other is refused with a machine-readable reason, while the helpdesk
 * can still record it. A landline or other number of an accepted country
 * that no text message may go to is taken (it recognises the caller), and
 * the resource flags it (sms_capable), so it cannot be confirmed by SMS.
 */
class PhoneNumbersController extends Controller
{
    /** The machine-readable reason of the 422 for a number of a country not accepted. */
    public const REASON_REGION_NOT_ACCEPTED = 'phone_region_not_accepted';

    public const VERIFY_CODE_LENGTH = 6;

    private const VERIFY_TTL_MINUTES = 10;

    private const VERIFY_MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly PhoneNormalizer $phones,
        private readonly Settings $settings,
        private readonly OneTimeCodes $codes,
        private readonly Messenger $messenger,
        private readonly Auditor $auditor,
        private readonly SmsDestinations $destinations,
    ) {}

    public function store(Request $request): ClientResource
    {
        $data = $request->validate([
            'number' => ['required', 'string', new PhoneNumberRule],
            'label' => ['nullable', 'string', 'max:50'],
        ]);

        $e164 = $this->phones->normalize($data['number']);

        if ($e164 === null) {
            throw ValidationException::withMessages(['number' => __('This is not a valid phone number.')]);
        }

        /** @var Client $client */
        $client = $request->user();

        /** @var ClientPhoneNumber|null $existing */
        $existing = $client->phoneNumbers()->withTrashed()->where('number_e164', $e164)->first();

        if ($existing !== null && ! $existing->trashed()) {
            return new ClientResource($client->load('phoneNumbers'));
        }

        $this->refuseOutsideAcceptedCountries($e164);

        $max = $this->settings->int(SettingKey::PortalMaxPhoneNumbersPerClient);

        if ($max > 0 && $client->phoneNumbers()->count() >= $max) {
            throw ValidationException::withMessages(['number' => __('You can keep at most :n phone numbers. Remove one first.', ['n' => $max])]);
        }

        $isFirst = ! $client->phoneNumbers()->exists();

        if ($existing !== null) {
            // The same number was removed earlier: bring the row back instead
            // of colliding with the unique index. Ownership must be proven again.
            $existing->restore();
            $existing->forceFill([
                'label' => $data['label'] ?? null,
                'source' => PhoneNumberSource::ClientSelf,
                'is_primary' => $isFirst,
                'verified_at' => null,
            ])->save();
        } else {
            $client->phoneNumbers()->create([
                'number_e164' => $e164,
                'label' => $data['label'] ?? null,
                'source' => PhoneNumberSource::ClientSelf,
                'is_primary' => $isFirst,
            ]);
        }

        return new ClientResource($client->load('phoneNumbers'));
    }

    /**
     * Make one of the client's numbers the primary one.
     */
    public function makePrimary(Request $request, ClientPhoneNumber $phoneNumber): ClientResource
    {
        /** @var Client $client */
        $client = $request->user();

        abort_unless($phoneNumber->client_id === $client->id, 404);

        $phoneNumber->makePrimary();

        return new ClientResource($client->load('phoneNumbers'));
    }

    /**
     * Send a verification code by SMS to a number the client added.
     *
     * @response array{message: string, expires_in_minutes: int}
     */
    public function requestVerification(Request $request, ClientPhoneNumber $phoneNumber): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();

        abort_unless($phoneNumber->client_id === $client->id, 404);
        abort_unless($this->verificationEnabled(), 403, __('Phone number verification is not available.'));
        abort_if($phoneNumber->verified_at !== null, 409, __('This number is already verified.'));
        // A landline of an accepted country, or a number whose country is no longer accepted.
        $this->refuseWithoutSms($phoneNumber->number_e164);
        // A dead gateway would queue a message nobody receives while the page says "sent".
        abort_if(app(HealthChecks::class)->sms()->status === CheckStatus::Fail, 503, __('The verification SMS cannot be sent right now. Please try again later, or ask the helpdesk to confirm the number.'));

        ['code' => $code] = $this->codes->issueNumeric(
            $client,
            OneTimeCodePurpose::PhoneVerify,
            self::VERIFY_CODE_LENGTH,
            self::VERIFY_TTL_MINUTES,
            self::VERIFY_MAX_ATTEMPTS,
            $phoneNumber->number_e164,
        );

        $this->messenger->sendTemplate(MessageKey::PhoneVerifySms, $client, [
            'code' => $code,
            'minutes' => (string) self::VERIFY_TTL_MINUTES,
        ], $phoneNumber->number_e164);

        $this->auditor->record('client_phone.verification_sent', $phoneNumber, ['number' => $phoneNumber->number_e164]);

        return response()->json([
            'message' => __('We sent a code to :number.', ['number' => $phoneNumber->number_e164]),
            'expires_in_minutes' => self::VERIFY_TTL_MINUTES,
        ]);
    }

    /**
     * Confirm the code received on the number; marks it verified.
     */
    public function verify(Request $request, ClientPhoneNumber $phoneNumber): ClientResource
    {
        $data = $request->validate(['code' => ['required', 'digits:'.self::VERIFY_CODE_LENGTH]]);

        /** @var Client $client */
        $client = $request->user();

        abort_unless($phoneNumber->client_id === $client->id, 404);
        abort_unless($this->verificationEnabled(), 403, __('Phone number verification is not available.'));

        if ($phoneNumber->verified_at !== null) {
            return new ClientResource($client->load('phoneNumbers'));
        }

        // The pending code must have been sent to this very number.
        $pending = OneTimeCode::query()
            ->where('client_id', $client->id)
            ->where('purpose', OneTimeCodePurpose::PhoneVerify)
            ->usable()
            ->latest('id')
            ->value('destination');

        if ($pending !== $phoneNumber->number_e164 || ! $this->codes->verify($client, OneTimeCodePurpose::PhoneVerify, $data['code'])) {
            $this->auditor->record('client_phone.verification_failed', $phoneNumber, ['number' => $phoneNumber->number_e164]);

            throw ValidationException::withMessages(['code' => __('The code is not valid or has expired.')]);
        }

        app(ClientPhones::class)->verify($phoneNumber, PhoneVerificationSource::Sms);

        return new ClientResource($client->load('phoneNumbers'));
    }

    /**
     * @response array{message: string, pin_cleared: bool}
     */
    public function destroy(Request $request, ClientPhoneNumber $phoneNumber): JsonResponse
    {
        /** @var Client $client */
        $client = $request->user();

        abort_unless($phoneNumber->client_id === $client->id, 404);
        abort_unless($phoneNumber->source === PhoneNumberSource::ClientSelf, 403, __('Only numbers you added yourself can be removed.'));

        $hadPin = $client->hasPin();
        $phoneNumber->delete();
        $pinCleared = $hadPin && ! $client->fresh()->hasPin();

        return response()->json([
            'message' => $pinCleared ? __('Phone number removed. It was your last registered number, so your PIN was removed too: the phone menu could no longer use it.') : __('Phone number removed.'),
            'pin_cleared' => $pinCleared,
        ]);
    }

    /**
     * 422 with the reason phone_region_not_accepted when the number is not
     * of an accepted country; the portal shows it in its words.
     *
     * @throws HttpResponseException
     */
    private function refuseOutsideAcceptedCountries(string $e164): void
    {
        if (! $this->destinations->acceptsRegionOf($e164)) {
            $this->refuse(self::REASON_REGION_NOT_ACCEPTED, __('Phone numbers of this country cannot be added here. The helpdesk can record the number for you.'));
        }
    }

    /**
     * 422 with the reason (sms_not_mobile, sms_region_not_allowed) when no
     * text message may go to the number; the portal shows it in its words.
     *
     * @throws HttpResponseException
     */
    private function refuseWithoutSms(string $e164): void
    {
        $refusal = $this->messenger->smsRefusal($e164);

        if ($refusal !== null) {
            $this->refuse($refusal->portalReason(), $refusal->portalMessage());
        }
    }

    /**
     * @throws HttpResponseException
     */
    private function refuse(string $reason, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'reason' => $reason,
            'errors' => ['number' => [$message]],
        ], 422));
    }

    private function verificationEnabled(): bool
    {
        return $this->settings->bool(SettingKey::PortalPhoneVerificationEnabled);
    }
}
