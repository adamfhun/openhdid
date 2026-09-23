<?php

namespace App\Models;

use App\Enums\StepVerdict;
use App\Models\Concerns\HasTimeOrderedUuidKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id_session_id', 'question_id', 'question_version_id', 'verdict', 'shown_at', 'decided_at'])]
class IdSessionStep extends Model
{
    use HasTimeOrderedUuidKey;

    protected function casts(): array
    {
        return [
            'verdict' => StepVerdict::class,
            'shown_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IdSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(IdSession::class, 'id_session_id');
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** @return BelongsTo<QuestionVersion, $this> */
    public function questionVersion(): BelongsTo
    {
        return $this->belongsTo(QuestionVersion::class);
    }

    public function isPending(): bool
    {
        return $this->verdict === StepVerdict::Pending;
    }
}
