<?php

namespace App\Http\Resources\V1;

use App\Clients\ClientTiers;
use App\Identification\ClientAnswers;
use App\Identification\PinService;
use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
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
            ])->values()),
            'phone_verification' => app(Settings::class)->bool(SettingKey::PortalPhoneVerificationEnabled),
            'max_phone_numbers' => app(Settings::class)->int(SettingKey::PortalMaxPhoneNumbersPerClient),
            'last_login_at' => $this->last_login_at,
            'identification' => [
                'answered' => app(ClientAnswers::class)->usableCount($this->resource),
                'required' => app(ClientAnswers::class)->requiredCount(),
                'eligible' => app(ClientAnswers::class)->isEligible($this->resource),
                'pin_changes_enabled' => app(PinService::class)->clientChangesEnabled(),
                'pin_min_length' => app(PinService::class)->minLength(),
                'pin_max_length' => app(PinService::class)->maxLength(),
            ],
        ];
    }
}
