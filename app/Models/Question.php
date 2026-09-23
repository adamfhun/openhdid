<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Models\Concerns\HasUuidKey;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A question is a stable identity; its wording lives in numbered versions.
 * Publishing a new version either keeps existing answers valid (typo fix)
 * or invalidates them (meaning changed), decided per version.
 */
#[Fillable(['is_active', 'position', 'current_version_id'])]
class Question extends Model
{
    use Auditable;

    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    use HasUuidKey;
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer'];
    }

    /** @return BelongsTo<QuestionVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(QuestionVersion::class, 'current_version_id');
    }

    /** @return HasMany<QuestionVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(QuestionVersion::class)->orderByDesc('version');
    }

    /** @return HasMany<ClientAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(ClientAnswer::class);
    }

    public function getTextAttribute(): ?string
    {
        return $this->currentVersion?->text;
    }

    public function getHintAttribute(): ?string
    {
        return $this->currentVersion?->hint;
    }

    /**
     * @param  Builder<Question>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->whereNotNull('current_version_id');
    }
}
