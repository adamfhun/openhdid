<?php

namespace App\System;

use App\Auth\Oidc\OidcProvider;
use App\Enums\ApiKeyScope;
use App\Enums\OutboundMessageStatus;
use App\Enums\PrincipalType;
use App\Enums\SyncRunStatus;
use App\Jobs\SendOutboundMessage;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\OutboundMessage;
use App\Models\SyncRun;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\HuDate;
use App\Sync\ApiTokens;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cheap, side-effect-free checks of every worker and interface the system
 * depends on. Nothing here sends a message or touches external data.
 */
class HealthChecks
{
    public const SCHEDULER_HEARTBEAT_KEY = 'hdid.scheduler.heartbeat';

    private const HEARTBEAT_MAX_AGE_MINUTES = 3;

    private const SYNC_QUEUE_MAX_WAIT_MINUTES = 5;

    public function __construct(
        private readonly Cache $cache,
        private readonly Http $http,
        private readonly Settings $settings,
        private readonly Retention $retention,
    ) {}

    /**
     * @return list<Check>
     */
    public function all(): array
    {
        $checks = [
            'database' => [__('Database'), $this->database(...)],
            'cache' => [__('Cache'), $this->cacheStore(...)],
            'queue' => [__('Queue'), $this->queue(...)],
            'scheduler' => [__('Scheduler'), $this->scheduler(...)],
            'messages' => [__('Outbound messages'), $this->outboundMessages(...)],
            'mail' => [__('Mail'), $this->mail(...)],
            'sms' => [__('SMS'), $this->sms(...)],
            'sync' => [__('EMD sync'), $this->syncApi(...)],
            'sync_auth' => [__('EMD API authentication'), $this->syncAuthentication(...)],
            'api_keys' => [__('API keys'), $this->apiKeys(...)],
            'package_overrides' => [__('Package overrides'), $this->packageOverrides(...)],
            'oidc' => [__('Single sign-on'), $this->oidc(...)],
            'storage' => [__('Storage'), $this->storage(...)],
            'retention' => [__('Data retention'), $this->retention(...)],
        ];

        // One broken dependency (say, the database) must not turn the whole
        // report into an exception: it shows up as a failed check instead.
        return array_values(array_map(function (array $check, string $key): Check {
            [$label, $run] = $check;

            try {
                return $run()->withHint(static::hint($key));
            } catch (Throwable $e) {
                return Check::fail($key, $label, $e->getMessage())->withHint(static::hint($key));
            }
        }, $checks, array_keys($checks)));
    }

    /**
     * What an operator can do when the check turns yellow or red. Kept next
     * to the checks so the advice stays in step with what is measured.
     */
    public static function hint(string $key): ?string
    {
        return match ($key) {
            'sync_auth' => config('hdid.sync.token_keep_alive')
                ? __('EMD API tokens are refreshed every 15 minutes when EMD_SYNC_TOKEN_KEEP_ALIVE is enabled. Check the login/refresh URLs, credentials, scheduler and hdid:emd-sync-token. The EMD sync run checks the export separately.')
                : __('EMD_SYNC_TOKEN_KEEP_ALIVE is disabled. EMD sync reuses tokens refreshed less than 15 minutes ago, otherwise refreshes them on demand and logs in again when the refresh fails. Check the login/refresh URLs and credentials, or run hdid:emd-sync-token manually.'),
            'database' => __('Check that the database server is running and that the DB_* values in .env are correct. Nothing works without it; restart the DB service or fix the credentials, then reload this page.'),
            'cache' => __('The cache store (CACHE_STORE in .env) cannot be written or read. With Redis check that the service runs and REDIS_* is right; with the file or database store check disk space and permissions on storage/. Settings, sessions and login throttling depend on it.'),
            'queue' => __('E-mails, SMS and EMD sync run in the queue. Red: no worker heartbeat, start or restart the queue worker (the "php artisan queue:work" service or the worker container). The EMD sync started from the panel runs on the EMD_SYNC_QUEUE queue, which needs a worker too. Yellow: failed or piling jobs, see "php artisan queue:failed" and retry with "queue:retry all" once the cause (mail, SMS, DB) is fixed.'),
            'scheduler' => __('The scheduler does not run: the cron entry "* * * * * php artisan schedule:run" or the scheduler container is missing or stopped. It drives the EMD sync, the stale-call cleanup, the override expiry and the data retention. Add or repair it on the app server.'),
            'messages' => __('Failed messages of the last 24 hours. Open Outbound messages, look at the error of a failed row and fix the transport (E-mail or SMS tile), then resend from the list. Queued ones that never leave point to the queue worker.'),
            'mail' => __('Red: the EWS URL or credentials are missing in the environment (MAIL_MAILER, EWS_*). Yellow: a log/array mailer is set, so no e-mail leaves the server, fine for testing, not for production. Test with a magic link to your own client account.'),
            'sms' => __('Red with Ozeki: the gateway is unreachable or the credentials are wrong (OZEKI_*), check the Ozeki service and the network path. Red/yellow with the log driver: SMS are only written to the log, set SMS_DRIVER=ozeki for production.'),
            'sync' => __('Red: the last run failed or no successful run happened in twice the expected interval. Open EMD sync runs for the error; check the API URL/key or upload the file manually. A refused run usually means the export was much smaller than before (row-ratio guard). Yellow with a waiting run: the sync started from the panel was not picked up, start a worker for the EMD_SYNC_QUEUE queue.'),
            'api_keys' => __('A scope (call center or mobile backend) has no active key, so that partner cannot call in. Create one under API keys and hand it over, or ignore this while the integration is not live yet.'),
            'package_overrides' => __('Informational: these clients carry a temporary manual package. Nothing to fix, but review the list now and then and end an override EMD has caught up with.'),
            'oidc' => __('A provider is switched on in Settings but its issuer or tenant, client id or secret is missing in the environment (USER_/CLIENT_ADFS_*, USER_/CLIENT_ENTRA_*). Either fill in the configuration or switch the provider off, otherwise the login button leads to an error.'),
            'storage' => __('Red: the public disk is not writable, fix the permissions of storage/app/public. Yellow: run "php artisan storage:link" on the server, otherwise uploaded logos and images do not show.'),
            'retention' => __('The daily "hdid:prune" task has not run for days. It runs through the scheduler, so check that tile first; run "php artisan hdid:prune" by hand to catch up.'),
            default => null,
        };
    }

    public function database(): Check
    {
        $label = __('Database');

        try {
            $started = hrtime(true);
            DB::select('select 1');
            $ms = (hrtime(true) - $started) / 1_000_000;

            return Check::ok('database', $label, __(':driver · ping :ms ms', ['driver' => DB::connection()->getDriverName(), 'ms' => number_format($ms, 1)]));
        } catch (Throwable $e) {
            return Check::fail('database', $label, $e->getMessage());
        }
    }

    public function cacheStore(): Check
    {
        $label = __('Cache');
        $store = (string) config('cache.default');

        try {
            $key = 'hdid.health.'.Str::random(8);
            $this->cache->put($key, 'x', 10);
            $ok = $this->cache->get($key) === 'x';
            $this->cache->forget($key);

            return $ok ? Check::ok('cache', $label, $store) : Check::fail('cache', $label, __(':store does not read back what it wrote', ['store' => $store]));
        } catch (Throwable $e) {
            return Check::fail('cache', $label, $store.': '.$e->getMessage());
        }
    }

    public function queue(): Check
    {
        $label = __('Queue worker');
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            return Check::warn('queue', $label, __('Connection "sync": jobs run inside the web request, no worker needed.'));
        }

        $pending = null;
        $oldest = null;
        $failed = null;

        try {
            if ($connection === 'database') {
                $pending = DB::table((string) config('queue.connections.database.table', 'jobs'))->count();
                $oldestTs = DB::table((string) config('queue.connections.database.table', 'jobs'))->min('created_at');
                $oldest = $oldestTs ? Carbon::createFromTimestamp((int) $oldestTs) : null;
            }
            $failed = DB::table((string) config('queue.failed.table', 'failed_jobs'))->count();
        } catch (Throwable) {
            // Tables may not exist yet; the heartbeat still tells the story.
        }

        $heartbeat = $this->heartbeat(SendOutboundMessage::HEARTBEAT_KEY);
        $parts = [$connection];
        $parts[] = $heartbeat === null ? __('no worker seen yet') : __('worker seen :when', ['when' => $heartbeat->diffForHumans()]);
        if ($pending !== null) {
            $parts[] = __(':n pending', ['n' => $pending]).($oldest ? ' ('.__('oldest :when', ['when' => $oldest->diffForHumans()]).')' : '');
        }
        if ($failed !== null) {
            $parts[] = __(':n failed', ['n' => $failed]);
        }
        $detail = implode(' · ', $parts);

        if ($heartbeat === null || $heartbeat->lt(now()->subMinutes(self::HEARTBEAT_MAX_AGE_MINUTES))) {
            return Check::fail('queue', $label, $detail);
        }

        return ($failed ?? 0) > 0 || ($pending ?? 0) > 50 ? Check::warn('queue', $label, $detail) : Check::ok('queue', $label, $detail);
    }

    public function scheduler(): Check
    {
        $label = __('Scheduler');
        $heartbeat = $this->heartbeat(self::SCHEDULER_HEARTBEAT_KEY);

        if ($heartbeat === null) {
            return Check::fail('scheduler', $label, __('No run recorded yet; is "schedule:run" in cron?'));
        }

        $detail = __('last run :when', ['when' => $heartbeat->diffForHumans()]);

        return $heartbeat->lt(now()->subMinutes(self::HEARTBEAT_MAX_AGE_MINUTES)) ? Check::fail('scheduler', $label, $detail) : Check::ok('scheduler', $label, $detail);
    }

    public function outboundMessages(): Check
    {
        $label = __('Outbound messages');
        $since = now()->subDay();
        $queued = OutboundMessage::query()->where('status', OutboundMessageStatus::Queued)->count();
        $failed = OutboundMessage::query()->where('status', OutboundMessageStatus::Failed)->where('created_at', '>=', $since)->count();
        $sent = OutboundMessage::query()->where('status', OutboundMessageStatus::Sent)->where('created_at', '>=', $since)->count();
        $detail = __(':s sent, :f failed in 24 h · :q queued', ['s' => $sent, 'f' => $failed, 'q' => $queued]);

        return $failed > 0 ? Check::warn('messages', $label, $detail) : Check::ok('messages', $label, $detail);
    }

    public function mail(): Check
    {
        $label = __('E-mail transport');
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');

        if ($mailer === 'ews') {
            $cfg = (array) config('hdid.ews');
            $configured = filled($cfg['url'] ?? null) && (($cfg['auth'] ?? 'basic') === 'oauth'
                ? filled($cfg['oauth']['client_id'] ?? null) && filled($cfg['oauth']['client_secret'] ?? null)
                : filled($cfg['username'] ?? null) && filled($cfg['password'] ?? null));

            return $configured
                ? Check::ok('mail', $label, __('EWS (:auth) · from :from', ['auth' => $cfg['auth'] ?? 'basic', 'from' => $from]))
                : Check::fail('mail', $label, __('EWS is selected but URL or credentials are missing'));
        }

        if (in_array($mailer, ['log', 'array'], true)) {
            return Check::warn('mail', $label, __('Mailer ":m": nothing leaves the server', ['m' => $mailer]));
        }

        return Check::ok('mail', $label, __(':m · from :from', ['m' => $mailer, 'from' => $from]));
    }

    public function sms(): Check
    {
        $label = __('SMS gateway');
        $driver = (string) config('hdid.sms.driver', 'log');

        if ($driver !== 'ozeki') {
            $detail = __('Driver ":d": messages are only logged', ['d' => $driver]);

            // In production a logged PIN is a PIN nobody received.
            return app()->environment('production') ? Check::fail('sms', $label, $detail) : Check::warn('sms', $label, $detail);
        }

        $url = (string) config('hdid.ozeki.url');

        if ($url === '' || blank(config('hdid.ozeki.username'))) {
            return Check::fail('sms', $label, __('Ozeki is selected but URL or credentials are missing'));
        }

        $reachable = $this->cache->remember('hdid.health.ozeki', 60, function () use ($url): bool {
            try {
                return $this->http->timeout(3)->get($url)->status() < 500;
            } catch (Throwable) {
                return false;
            }
        });

        return $reachable ? Check::ok('sms', $label, __('Ozeki reachable at :url', ['url' => $url])) : Check::fail('sms', $label, __('Ozeki not reachable at :url', ['url' => $url]));
    }

    public function syncApi(): Check
    {
        $label = __('EMD sync');
        $apiConfigured = filled(config('hdid.sync.api_url'));
        $last = SyncRun::query()->where('dry_run', false)->latest('id')->first();

        $source = $apiConfigured ? __('API configured') : __('API not configured (file import only)');

        // The former SYNC_* names keep working; the note only reminds, it never changes the status.
        $legacy = (array) config('hdid.sync.legacy_env', []);
        if ($legacy !== []) {
            $source .= ' · '.__('Former variable names in use: :names. They still work; rename them to EMD_SYNC_* at the next change.', ['names' => implode(', ', $legacy)]);
        }

        if ($last === null) {
            return Check::warn('sync', $label, $source.' · '.__('no run yet'));
        }

        $detail = $source.' · '.__('last run :status :when', ['status' => __($last->status->value), 'when' => ($last->finished_at ?? $last->started_at)?->diffForHumans()]);

        if ($last->status === SyncRunStatus::Failed) {
            return Check::fail('sync', $label, $detail);
        }

        // A sync that silently stopped running is as bad as one that fails.
        $lastSuccess = SyncRun::query()->where('status', SyncRunStatus::Completed)->where('dry_run', false)->latest('started_at')->value('finished_at');
        $maxAgeHours = 2 * max(1, $this->settings->int(SettingKey::SyncExpectedIntervalHours));
        $lastSuccessAt = $lastSuccess ? Carbon::parse($lastSuccess) : null;

        if ($lastSuccessAt === null || $lastSuccessAt->lt(now()->subHours($maxAgeHours))) {
            return Check::fail('sync', $label, $detail.' · '.__('no successful run in the last :h hours', ['h' => $maxAgeHours]));
        }

        // A panel-started run nobody picks up means no worker listens on the sync queue.
        $waiting = SyncRun::query()->where('status', SyncRunStatus::Queued)
            ->where('started_at', '<', now()->subMinutes(self::SYNC_QUEUE_MAX_WAIT_MINUTES))->oldest('started_at')->first();
        if ($waiting !== null) {
            return Check::warn('sync', $label, $detail.' · '.__('An EMD sync has been waiting on the ":queue" queue for :minutes minutes; no worker seems to listen on it.', [
                'queue' => config('hdid.sync.queue'),
                'minutes' => (int) $waiting->started_at->diffInMinutes(now()),
            ]));
        }

        return Check::ok('sync', $label, $detail);
    }

    public function syncAuthentication(): Check
    {
        $label = __('EMD API authentication');
        if (config('hdid.sync.driver') !== 'spreadsheet') {
            return Check::ok('sync_auth', $label, __('File export integration is disabled.'));
        }
        $tokens = app(ApiTokens::class);
        if (! $tokens->isConfigured()) {
            return Check::fail('sync_auth', $label, __('EMD API login is not configured.'));
        }
        $status = $tokens->status();
        if (! empty($status['error'])) {
            return Check::fail('sync_auth', $label, $status['error']);
        }
        if (empty($status['last_success'])) {
            // Until one login succeeded the credentials are unproven, with or without the keep-alive.
            return Check::warn('sync_auth', $label, config('hdid.sync.token_keep_alive')
                ? __('No successful token refresh yet.')
                : __('Token keep-alive is disabled and no EMD login has succeeded yet; run hdid:emd-sync-token once to check the credentials.'));
        }
        $lastSuccess = Carbon::parse($status['last_success']);
        $detail = __('Last successful token refresh: :time', ['time' => $lastSuccess->format(HuDate::DATETIME)]);
        if (! config('hdid.sync.token_keep_alive')) {
            return Check::ok('sync_auth', $label, __('Token keep-alive is disabled; authentication runs on demand.').' · '.$detail);
        }
        if ($lastSuccess->lt(now()->subMinutes(ApiTokens::REFRESH_MINUTES + 5))) {
            return Check::fail('sync_auth', $label, $detail);
        }

        return Check::ok('sync_auth', $label, $detail);
    }

    public function packageOverrides(): Check
    {
        $label = __('Package overrides');
        $active = Client::query()->whereNotNull('package_override')->where('package_override_until', '>=', now())->with('packageOverrideBy')->orderBy('package_override_until')->get();

        if ($active->isEmpty()) {
            return Check::ok('package_overrides', $label, __('No manual override; every package comes from EMD.'));
        }

        $list = $active->take(5)->map(fn (Client $c) => $c->name.' → '.$c->package_override.' ('.$c->package_override_until?->format(HuDate::DATE).')')->implode(' · ');

        return Check::warn('package_overrides', $label, __(':n client(s) with a temporary package override: :list', ['n' => $active->count(), 'list' => $list]));
    }

    public function apiKeys(): Check
    {
        $label = __('API keys');
        $parts = [];
        $missing = [];

        foreach (ApiKeyScope::cases() as $scope) {
            $keys = ApiKey::query()->active()->where('scope', $scope)->get();
            $lastUsed = $keys->max('last_used_at');
            $legacy = $scope === ApiKeyScope::CallCenter && filled(config('hdid.callcenter.api_key'));

            if ($keys->isEmpty() && ! $legacy) {
                $missing[] = $scope->label();
            }

            $parts[] = $scope->label().': '.($keys->count() + ($legacy ? 1 : 0)).($lastUsed ? ' ('.__('used :when', ['when' => $lastUsed->diffForHumans()]).')' : '');
        }

        $detail = implode(' · ', $parts);

        return $missing === [] ? Check::ok('api_keys', $label, $detail) : Check::warn('api_keys', $label, __('No active key for: :list', ['list' => implode(', ', $missing)]).' · '.$detail);
    }

    public function oidc(): Check
    {
        $label = __('Login providers');
        $parts = [];
        $broken = false;

        foreach (PrincipalType::cases() as $principal) {
            foreach (OidcProvider::cases() as $provider) {
                $enabled = $this->settings->bool($provider->enabledSetting($principal));
                $configured = $provider->configFor($principal)->isConfigured();
                $broken = $broken || ($enabled && ! $configured);
                $parts[] = __($principal->value).'/'.$provider->value.': '.($enabled ? ($configured ? __('on') : __('on, NOT configured')) : __('off'));
            }
        }

        $detail = implode(' · ', $parts);

        return $broken ? Check::fail('oidc', $label, $detail) : Check::ok('oidc', $label, $detail);
    }

    public function storage(): Check
    {
        $label = __('Storage');

        try {
            $disk = Storage::disk('public');
            $probe = 'health/'.Str::random(8).'.txt';
            $disk->put($probe, 'ok');
            $writable = $disk->get($probe) === 'ok';
            $disk->delete($probe);
        } catch (Throwable $e) {
            return Check::fail('storage', $label, $e->getMessage());
        }

        $linked = is_link(public_path('storage')) || is_dir(public_path('storage'));

        if (! $writable) {
            return Check::fail('storage', $label, __('Public disk is not writable'));
        }

        return $linked ? Check::ok('storage', $label, __('Public disk writable, storage link present')) : Check::warn('storage', $label, __('Public disk writable, but public/storage is missing (run storage:link)'));
    }

    public function retention(): Check
    {
        $label = __('Data retention');
        $last = $this->retention->lastRunAt();

        if ($last === null) {
            return Check::warn('retention', $label, __('The prune task has not run yet (daily "hdid:prune" via the scheduler).'));
        }

        $detail = __('last run :when · keeps :g years, short-lived :s years', ['when' => $last->diffForHumans(), 'g' => $this->settings->int(SettingKey::RetentionGeneralYears), 's' => $this->settings->int(SettingKey::RetentionShortLivedYears)]);

        return $last->lt(now()->subDays(3)) ? Check::warn('retention', $label, $detail) : Check::ok('retention', $label, $detail);
    }

    private function heartbeat(string $key): ?Carbon
    {
        $value = $this->cache->get($key);

        return is_string($value) ? Carbon::parse($value) : null;
    }
}
