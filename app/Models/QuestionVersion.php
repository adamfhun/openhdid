<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Models\Concerns\HasTimeOrderedUuidKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['question_id', 'version', 'text', 'hint', 'keeps_answers', 'published_by_user_id'])]
class QuestionVersion extends Model
{
    use Auditable;
    use HasTimeOrderedUuidKey;

    protected function casts(): array
    {
        return ['version' => 'integer', 'keeps_answers' => 'boolean'];
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** @return HasMany<ClientAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(ClientAnswer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
