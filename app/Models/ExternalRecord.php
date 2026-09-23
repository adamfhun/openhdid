<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Enums\PrincipalType;
use App\Models\Concerns\HasUuidKey;
use Database\Factories\ExternalRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A row from the external directory (API / CSV / XLSX). Both Users and
 * Clients come from the same source; `kind` is decided by the company filter.
 */
#[Fillable(['external_id', 'kind', 'company', 'title', 'department', 'login_name', 'room', 'employment_status', 'name', 'email', 'email_domain', 'implicit_package', 'explicit_package', 'phones', 'attributes', 'first_seen_run_id', 'last_seen_run_id', 'last_seen_at', 'missed_runs', 'missing_since'])]
class ExternalRecord extends Model
{
    use Auditable;

    /** @use HasFactory<ExternalRecordFactory> */
    use HasFactory;

    use HasUuidKey;
    use SoftDeletes;

    /**
     * Every run stamps the bookkeeping columns on every record; auditing
     * them would write one log row per record per run.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['first_seen_run_id', 'last_seen_run_id', 'last_seen_at', 'missed_runs'];

    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'kind' => PrincipalType::class,
            'last_seen_at' => 'datetime',
            'phones' => 'array',
            'attributes' => 'array',
            'missed_runs' => 'integer',
            'missing_since' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ExternalRecord $record): void {
            $record->email_domain = static::domainOf($record->email);
        });
    }

    /**
     * One of the unmapped source columns (e.g. title, department), matched
     * without regard to case, or null when the directory did not send it.
     */
    public function attribute(string $key): ?string
    {
        foreach ((array) ($this->getAttribute('attributes') ?? []) as $name => $value) {
            if (mb_strtolower(trim((string) $name)) === mb_strtolower($key) && is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * "Title · Department" for a caller card, or null when neither is known.
     */
    public function jobLabel(): ?string
    {
        $parts = array_filter([$this->jobTitle(), $this->departmentName()]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * The fixed column, or the same-named "other column" from before it existed.
     */
    public function jobTitle(): ?string
    {
        return $this->title ?: $this->attribute('title');
    }

    public function departmentName(): ?string
    {
        return $this->department ?: $this->attribute('department');
    }

    public static function domainOf(?string $email): ?string
    {
        $at = strrpos((string) $email, '@');

        return $at === false ? null : mb_strtolower(substr($email, $at + 1));
    }

    public function isMissing(): bool
    {
        return $this->missing_since !== null;
    }

    /** @return HasOne<User, $this> */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** @return HasOne<Client, $this> */
    public function client(): HasOne
    {
        return $this->hasOne(Client::class);
    }

    /** @return BelongsTo<SyncRun, $this> */
    public function lastSeenRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'last_seen_run_id');
    }

    /**
     * @param  Builder<ExternalRecord>  $query
     */
    public function scopePresent(Builder $query): void
    {
        $query->whereNull('missing_since');
    }
}
