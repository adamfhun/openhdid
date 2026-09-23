<?php

namespace App\Settings;

use App\Audit\Auditor;
use App\Auth\Role;
use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single access point for runtime settings. The whole table is cached as one
 * map; any write invalidates it and is written to the audit log. The
 * in-process copy lives for one request or one queued job only (see
 * forgetLocal()), so long-running workers pick up panel changes.
 */
class Settings
{
    private const CACHE_KEY = 'hdid.settings';

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
        if ($key === SettingKey::SyncExportPayload && auth()->check()) {
            abort_unless(auth()->user()->hasRole(Role::SuperAdmin->value), 403);
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

        return $this->loaded = $this->cache->rememberForever(self::CACHE_KEY, $read);
    }

    public function forget(): void
    {
        $this->loaded = null;
        $this->cache->forget(self::CACHE_KEY);
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
