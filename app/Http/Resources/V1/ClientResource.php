<?php

namespace App\Http\Resources\V1;

use App\Clients\ClientPhones;
use App\Clients\ClientTiers;
use App\Identification\ClientAnswers;
use App\Identification\PinService;
use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\SmsDestinations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tiers = app(ClientTiers::class);
        $tier = $tiers->tierFor($this->resource);
        $settings = app(Settings::class);
        // Whether a number is shared with another client is told only when the operator decided to say so.
        $shared = $settings->bool(SettingKey::PortalSharedNumberNotice) && $this->relationLoaded('phoneNumbers') ? app(ClientPhones::class)->sharedNumbersOf($this->resource) : [];
        $destinations = app(SmsDestinations::class);
        $smsTarget = $this->relationLoaded('phoneNumbers') ? $destinations->targetFor($this->resource) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'tier' => $tier?->value,
            'support' => $tier === null ? null : $tiers->supportFor($tier),
            'has_pin' => $this->hasPin(),
            'pin_set_at' => $this->pin_set_at,
            'phone_numbers' => $this->whenLoaded('phoneNumbers', fn () => $this->phoneNumbers->map(fn ($p) => [
                'id' => $p->id,
                'number' => $p->number_e164,
                'label' => $p->label,
                'source' => $p->source->value,
                'is_primary' => $p->is_primary,
                'verified' => $p->verified_at !== null,
                'verified_via' => $p->verified_via?->value,
                'shared' => in_array($p->number_e164, $shared, true),
                // A landline (or a number of a country no longer accepted): no SMS confirmation, no SMS sign-in code.
                'sms_capable' => $destinations->allows($p->number_e164),
                // Where the SMS sign-in code and the PIN by SMS go: the primary number, or the first one that may receive SMS.
                'sms_target' => $p->number_e164 === $smsTarget,
            ])->values()),
            // The countries whose numbers the client may add, named in the client's language.
            'phone_countries' => SmsDestinations::regionNames($destinations->allowedRegions()),
            'phone_verification' => $settings->bool(SettingKey::PortalPhoneVerificationEnabled),
            'phone_verification_on_call' => $settings->bool(SettingKey::VerifyPhoneOnIdentifiedCall),
            'max_phone_numbers' => $settings->int(SettingKey::PortalMaxPhoneNumbersPerClient),
            'last_login_at' => $this->last_login_at,
            'identification' => [
                'answered' => app(ClientAnswers::class)->usableCount($this->resource),
                'required' => app(ClientAnswers::class)->requiredCount(),
                'eligible' => app(ClientAnswers::class)->isEligible($this->resource),
                'pin_changes_enabled' => app(PinService::class)->clientChangesEnabled(),
                'pin_requires_phone' => ! app(PinService::class)->canSetPin($this->resource),
                'pin_min_length' => app(PinService::class)->minLength(),
                'pin_max_length' => app(PinService::class)->maxLength(),
            ],
        ];
    }
}
