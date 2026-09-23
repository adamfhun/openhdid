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

/**
 * The answer is stored encrypted (agents must read it), plus a hash of the
 * normalised form for automated comparison.
 */
#[Fillable(['client_id', 'question_id', 'question_version_id', 'answer', 'answer_hash'])]
#[Hidden(['answer', 'answer_hash'])]
class ClientAnswer extends Model
{
    use Auditable;

    /** @use HasFactory<ClientAnswerFactory> */
    use HasFactory;

    use HasUuidKey;
    use SoftDeletes;

    protected function casts(): array
    {
        return ['answer' => 'encrypted'];
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
