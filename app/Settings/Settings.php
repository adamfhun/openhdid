<?php

namespace App\Settings;

use App\Audit\Auditor;
use App\Auth\Permission;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\System\Installation;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Single access point for runtime settings. The whole table is cached as one
 * map; any write invalidates it and is written to the audit log. The
 * in-process copy lives for one request or one queued job only (see
 * forgetLocal()), so long-running workers pick up panel changes.
 *
 * The map is cached under a key that carries a version, and every write
 * replaces the version. A request that read the table just before a write
 * committed may still store what it read, but under the old key, which
 * nobody reads any more; with a fixed key it would have put the old values
 * back in front of everyone until the next write.
 *
 * Both keys carry the installation id as well: two installations sharing
 * one Valkey/Redis under the same key prefix (a test and a production
 * installation with the default APP_NAME) would otherwise serve each other
 * their settings, the second factor and the sign-in switches included.
 */
class Settings
{
    private const CACHE_KEY = 'hdid.settings';

    /** How long a cached map or version lives at most (seconds); a lost version only means one fresh read. */
    private const CACHE_TTL = 86400;

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function __construct(
        private readonly Cache $cache,
        private readonly Auditor $auditor,
    ) {}

    public function get(SettingKey $key): mixed
    {
        $stored = $this->all();

        if (array_key_exists($key->value, $stored)) {
            return $key->type()->cast($stored[$key->value]);
        }

        return $key->default();
    }

    public function bool(SettingKey $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(SettingKey $key): int
    {
        return (int) $this->get($key);
    }

    public function string(SettingKey $key): ?string
    {
        $value = $this->get($key);

        return $value === null ? null : (string) $value;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function array(SettingKey $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? $value : [];
    }

    public function set(SettingKey $key, mixed $value): void
    {
        $this->setMany([$key->value => $value]);
    }

    private function write(SettingKey $key, mixed $value): void
    {
        if ($key->requiresSyncManage() && auth()->check()) {
            abort_unless(auth()->user()->can(Permission::SyncManage->value), 403);
        }

        $previous = $this->get($key);
        $value = $key->type()->cast($value);
        if (in_array($key, [SettingKey::SyncUserDomains, SettingKey::SyncClientDomains], true)) {
            $value = SettingRules::normalizeDomains((array) $value);
        }

        if ($previous === $value) {
            return;
        }

        Setting::query()->updateOrCreate(['key' => $key->value], ['value' => $value]);

        if (DB::transactionLevel() > 0) {
            $this->forgetLocal();
            DB::afterCommit(fn () => $this->forget());
        } else {
            $this->forget();
        }

        $this->auditor->record('setting.changed', context: [
            'key' => $key->value,
            'from' => $previous,
            'to' => $value,
        ]);

        $this->forgetRememberTokensWhenSwitchedOff($key, $value);
    }

    /**
     * Switching remember-me off makes every remember cookie of that account
     * type worthless at once: the stored tokens go, so no path that resolves
     * a guard (before or outside the session checks) can sign in with one.
     */
    private function forgetRememberTokensWhenSwitchedOff(SettingKey $key, mixed $value): void
    {
        $model = match ($key) {
            SettingKey::ClientLoginRememberEnabled => Client::class,
            SettingKey::UserLoginRememberEnabled => User::class,
            default => null,
        };

        if ($model !== null && $value === false) {
            $model::query()->whereNotNull('remember_token')->update(['remember_token' => null]);
        }
    }

    /**
     * @param  array<string, mixed>  $values  keyed by SettingKey value
     */
    public function setMany(array $values): void
    {
        $domainKeys = [SettingKey::SyncUserDomains->value, SettingKey::SyncClientDomains->value, SettingKey::SyncUniqueDomains->value];
        if (array_intersect(array_keys($values), $domainKeys) !== []) {
            $errors = SettingRules::domainErrors(array_replace($this->all(), $values));
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        try {
            DB::transaction(function () use ($values): void {
                foreach ($values as $key => $value) {
                    $this->write(SettingKey::from($key), $value);
                }
            });
        } finally {
            $this->forget();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $read = fn (): array => Setting::query()->pluck('value', 'key')->all();

        // Inside a transaction the rows are not committed yet: they must not
        // seed the shared cache, and they must not be memoised either, or a
        // rollback would leave this instance holding values that never landed.
        if (DB::transactionLevel() > 0) {
            return $this->loaded = $read();
        }

        // The key is fixed before the table is read: a write committing after
        // this point moves the version on, so what is read here lands under a
        // key that is no longer used.
        return $this->loaded = $this->cache->remember($this->cacheKey(), self::CACHE_TTL, $read);
    }

    public function forget(): void
    {
        $this->loaded = null;
        $versionKey = $this->versionKey();
        $old = $this->cache->get($versionKey);
        $this->cache->put($versionKey, Str::random(20), self::CACHE_TTL);

        if (is_string($old)) {
            $this->cache->forget($this->mapKey($old));
        }
    }

    private function cacheKey(): string
    {
        $versionKey = $this->versionKey();
        $version = $this->cache->get($versionKey);

        if (! is_string($version)) {
            // add() does not overwrite a version a concurrent write has just set.
            $this->cache->add($versionKey, Str::random(20), self::CACHE_TTL);
            $version = (string) $this->cache->get($versionKey);
        }

        return $this->mapKey($version);
    }

    /**
     * The key of the current version of this installation's settings.
     */
    private function versionKey(): string
    {
        return self::CACHE_KEY.'.'.Installation::id().'.version';
    }

    /**
     * The key of this installation's settings map in one version.
     */
    private function mapKey(string $version): string
    {
        return self::CACHE_KEY.'.'.Installation::id().'.'.$version;
    }

    /**
     * Drop only the in-process copy; the shared cache stays. Called before
     * every queued job so a worker never serves settings from a past request.
     */
    public function forgetLocal(): void
    {
        $this->loaded = null;
    }
}
