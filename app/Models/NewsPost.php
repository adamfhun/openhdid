<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Models\Concerns\HasTimeOrderedUuidKey;
use Database\Factories\NewsPostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A news item shown to clients after sign-in. Written in Markdown; the
 * author is recorded for the audit but never exposed to clients.
 */
#[Fillable(['title', 'body', 'published_at', 'author_user_id'])]
class NewsPost extends Model
{
    use Auditable;

    /** @use HasFactory<NewsPostFactory> */
    use HasFactory;

    use HasTimeOrderedUuidKey;
    use SoftDeletes;

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    public function bodyHtml(): string
    {
        return (string) Str::markdown($this->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /**
     * @param  Builder<NewsPost>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}
