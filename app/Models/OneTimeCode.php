<?php

namespace App\Models;

use App\Enums\OneTimeCodePurpose;
use App\Models\Concerns\HasTimeOrderedUuidKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_id', 'purpose', 'code_hash', 'lookup', 'secret_encrypted', 'destination', 'expires_at', 'max_attempts'])]
#[Hidden(['code_hash', 'lookup', 'secret_encrypted'])]
class OneTimeCode extends Model
{
    use HasTimeOrderedUuidKey;

    protected function casts(): array
    {
        return [
            'purpose' => OneTimeCodePurpose::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < $this->max_attempts;
    }

    /**
     * @param  Builder<OneTimeCode>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->whereColumn('attempts', '<', 'max_attempts');
    }
}
