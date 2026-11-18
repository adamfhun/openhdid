<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Models\Concerns\HasUuidKey;
use Database\Factories\ClientAnswerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * The answer is stored encrypted (agents must read it), plus a hash of the
 * normalised form for automated comparison.
 */
#[Fillable(['client_id', 'question_id', 'question_version_id', 'answer', 'answer_hash'])]
#[Hidden(['answer', 'answer_hash'])]
class ClientAnswer extends Model
{
    use Auditable;

    /**
     * The revision is housekeeping, not a change worth an audit entry.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['revision'];

    /** @use HasFactory<ClientAnswerFactory> */
    use HasFactory;

    use HasUuidKey;
    use SoftDeletes;

    protected function casts(): array
    {
        return ['answer' => 'encrypted'];
    }

    /**
     * A new random revision whenever the answer's text changes, on whatever
     * path it is written: a blind check that matched shows the stored answer
     * only while it still is the revision that was compared.
     */
    protected static function booted(): void
    {
        static::saving(function (self $answer): void {
            if ($answer->revision === null || $answer->isDirty(['answer', 'answer_hash'])) {
                $answer->revision = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
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
}
