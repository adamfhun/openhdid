<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\PhoneNumberSource;
use App\Models\Concerns\HasUuidKey;
use Database\Factories\ClientPhoneNumberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['client_id', 'number_e164', 'label', 'source', 'is_primary', 'verified_at'])]
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
    }

    public function makePrimary(): void
    {
        $this->forceFill(['is_primary' => true])->save();
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
