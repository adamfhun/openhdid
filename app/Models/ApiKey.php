<?php

namespace App\Models;

use App\Audit\Auditable;
use App\Audit\Auditor;
use App\Enums\ApiKeyScope;
use App\Models\Concerns\HasUuidKey;
use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Long-lived machine-to-machine credential (IVR / call center, mobile app
 * backend). The plain key is shown once at creation and stored hashed.
 */
#[Fillable(['name', 'scope', 'key_hash', 'key_prefix', 'created_by_user_id'])]
#[Hidden(['key_hash', 'hmac_secret'])]
class ApiKey extends Model
{
    use Auditable;

    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    use HasUuidKey;

    /** @var list<string> */
    protected array $auditExclude = ['key_hash', 'hmac_secret', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'scope' => ApiKeyScope::class,
            'hmac_secret' => 'encrypted',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Create a key and return the plain secret alongside the record.
     *
     * @return array{key: ApiKey, plain: string}
     */
    public static function generate(string $name, ApiKeyScope $scope, ?User $by = null): array
    {
        $plain = 'hdid_'.Str::random(40);

        $key = static::query()->create([
            'name' => $name,
            'scope' => $scope,
            'key_hash' => static::hashOf($plain),
            'key_prefix' => substr($plain, 0, 12),
            'created_by_user_id' => $by?->id,
        ]);

        return ['key' => $key, 'plain' => $plain];
    }

    public static function hashOf(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public static function findActive(string $plain, ApiKeyScope $scope): ?self
    {
        if ($plain === '') {
            return null;
        }

        return static::query()->active()->where('scope', $scope)->where('key_hash', static::hashOf($plain))->first();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function requiresSignature(): bool
    {
        return $this->hmac_secret !== null;
    }

    /**
     * Issue a fresh signing secret; every request must then be signed with it.
     * Returned once, stored encrypted.
     */
    public function rotateSigningSecret(): string
    {
        $secret = Str::random(48);
        $this->forceFill(['hmac_secret' => $secret])->save();
        $this->audit('signing_secret_rotated');

        return $secret;
    }

    public function removeSigningSecret(): void
    {
        $this->forceFill(['hmac_secret' => null])->save();
        $this->audit('signing_secret_removed');
    }

    /**
     * Stamp the last use and leave an audit trace, at most once a minute per
     * key: enough to answer "which partner called what, when" without an
     * audit row per IVR request.
     */
    public function touchLastUsed(?string $routeName = null): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(now()->subMinute())) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
            app(Auditor::class)->record('api_key.used', $this, ['scope' => $this->scope->value, 'route' => $routeName ?? request()->route()?->getName()]);
        }
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @param  Builder<ApiKey>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
