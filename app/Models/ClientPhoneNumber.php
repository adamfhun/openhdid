<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Clients\ClientPhones;
use App\Enums\PhoneNumberSource;
use App\Enums\PhoneVerificationSource;
use App\Models\Concerns\HasUuidKey;
use Database\Factories\ClientPhoneNumberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['client_id', 'number_e164', 'label', 'source', 'is_primary', 'verified_at', 'verified_via', 'verified_by_user_id'])]
class ClientPhoneNumber extends Model
{
    use Auditable;

    /** @use HasFactory<ClientPhoneNumberFactory> */
    use HasFactory;

    use HasUuidKey;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'source' => PhoneNumberSource::class,
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'verified_via' => PhoneVerificationSource::class,
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (ClientPhoneNumber $phone): void {
            if ($phone->is_primary && $phone->wasChanged('is_primary') || ($phone->is_primary && $phone->wasRecentlyCreated)) {
                static::query()
                    ->where('client_id', $phone->client_id)
                    ->whereKeyNot($phone->getKey())
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
        });

        // Whoever removes the last number (client, operator, directory sync,
        // the shared-numbers page): the PIN cannot be used any more and goes too.
        static::deleted(fn (ClientPhoneNumber $phone) => app(ClientPhones::class)->afterRemoval($phone));
    }

    public function makePrimary(): void
    {
        $this->forceFill(['is_primary' => true])->save();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * "SMS code", "identified call", … or "not verified".
     */
    public function verificationLabel(): string
    {
        return $this->isVerified() ? ($this->verified_via?->label() ?? __('verified')) : __('not verified');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
