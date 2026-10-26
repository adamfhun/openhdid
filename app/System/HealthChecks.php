<?php

namespace App\System;

use App\Audit\AuditArchive;
use App\Auth\AccountLogin;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Oidc\EntraTenantOverview;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\SsoSubjectBinding;
use App\Auth\Passwordless\LoginMessageCaps;
use App\Clients\ClientPhones;
use App\Clients\ClientTiers;
use App\Enums\ApiKeyScope;
use App\Enums\MfaMethod;
use App\Enums\OutboundMessageStatus;
use App\Enums\PrincipalType;
use App\Enums\SyncRunStatus;
use App\Mail\Transport\EwsTransport;
use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Messaging\SmsDeliveryChecks;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\OutboundMessage;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\HuDate;
use App\Support\OutboundProxy;
use App\Support\RateLimitExemptions;
use App\Sync\ApiTokens;
use App\Sync\SyncExternalRecords;
use Filament\Facades\Filament;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Carbon;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
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

    /** Minutes the oldest queued message may wait before the messages tile turns yellow. */
    private const MESSAGE_MAX_WAIT_MINUTES = 5;

    /** The same for a short-lived sign-in code (Messenger::isPriorityMessage), which lives minutes only. */
    private const SIGN_IN_MESSAGE_MAX_WAIT_MINUTES = 2;

    /** Share of maxmemory above which the Valkey tile turns yellow. */
    private const VALKEY_MEMORY_WARN_RATIO = 0.8;

    /** Minutes a rise of the session store's eviction counter keeps its tile yellow. */
    public const SESSION_EVICTION_WINDOW_MINUTES = 60;

    private const SESSION_EVICTIONS_KEY = 'hdid.health.session_evictions';

    /** Database connections besides the web server's: two workers, the EMD worker, the scheduler with two background tasks, a migration, one spare. */
    private const DB_CONNECTIONS_BESIDES_WEB = 8;

    public const SSO_MISMATCH_WINDOW_DAYS = 7;

    public function __construct(
        private readonly Cache $cache,
        private readonly Http $http,
        private readonly Settings $settings,
        private readonly Retention $retention,
        private readonly AuditArchive $archive,
    ) {}

    public function premiumOnly(): Check
    {
        $label = __('Premium clients only');
        $tiers = app(ClientTiers::class);

        if (! $tiers->premiumOnly()) {
            return Check::ok('premium_only', $label, __('Off: premium and standard clients are served.'));
        }

        return Check::info('premium_only', $label, __('On: only premium clients are served; :n open standard client(s) held back.', ['n' => $tiers->heldBackCount()]));
    }

    public function loginLinkPreview(): Check
    {
        $label = __('Login link preview');

        if (! app(Settings::class)->bool(SettingKey::ClientLoginMagicLinkPreviewInPanel)) {
            return Check::ok('login_link_preview', $label, __('Off: sent login links are not shown in the panel.'));
        }

        return Check::warn('login_link_preview', $label, __('On: the panel shows every client login link it sends, so staff could sign in as the client. Switch it off before go-live.'));
    }

    public function staffMfa(): Check
    {
        $label = __('Two-factor sign-in');
        $mfa = app(StaffMfa::class);

        if (! $mfa->isEnabled()) {
            return Check::ok('mfa', $label, __('Off: staff sign in without a second factor.'));
        }

        $methods = $mfa->allowedMethods();

        if ($methods === []) {
            return Check::fail('mfa', $label, __('On, but no second factor can be used: staff cannot finish signing in.'));
        }

        $staff = User::query()->open()->with('externalRecord')->get()->filter(fn (User $user): bool => $user->canAccessPanel(Filament::getPanel('admin')));
        $without = $staff->filter(fn (User $user): bool => $mfa->enrolledMethods($user) === [])->count();
        $locked = $staff->filter(fn (User $user): bool => $mfa->isLocked($user))->count();
        $smsMissing = in_array(MfaMethod::Sms->value, array_map('strval', app(Settings::class)->array(SettingKey::UserLoginMfaMethods)), true) && ! StaffMfa::smsAvailable();

        $message = __('On: :methods. Staff without a second factor yet: :without; locked: :locked.', [
            'methods' => implode(', ', array_map(fn (MfaMethod $method): string => $method->label(), $methods)),
            'without' => $without,
            'locked' => $locked,
        ]).($smsMissing ? ' '.__('SMS is selected but no SMS gateway is connected, so it is not offered.') : '');

        // With the provider's MFA accepted, company sign-on users may never need a factor of their own.
        $trustsProvider = app(Settings::class)->bool(SettingKey::UserLoginMfaTrustProvider);
        if ($trustsProvider && $without > 0) {
            $message .= ' '.__('Staff who sign in through ADFS or Entra with the provider\'s MFA need no factor of their own.');
        }

        return ($without > 0 && ! $trustsProvider) || $locked > 0 || $smsMissing ? Check::warn('mfa', $label, $message) : Check::ok('mfa', $label, $message);
    }

    /**
     * Accounts refused in the last week because their company sign-in came
     * from another directory identity than the bound one: each of them
     * cannot sign in until an administrator checks the account.
     */
    public function ssoBindings(): Check
    {
        $label = __('Sign-in bindings');
        $binding = app(SsoSubjectBinding::class);
        $bound = $binding->boundCount();
        $refused = $binding->mismatchesSince(now()->subDays(self::SSO_MISMATCH_WINDOW_DAYS));

        if ($refused > 0) {
            return Check::warn('sso_bindings', $label, __(':bound account(s) bound to a sign-in identity; :refused refused in the last :days days because the sign-in came from another identity.', ['bound' => $bound, 'refused' => $refused, 'days' => self::SSO_MISMATCH_WINDOW_DAYS]));
        }

        return Check::ok('sso_bindings', $label, __(':bound account(s) bound to a sign-in identity; no refusal from another identity in the last :days days.', ['bound' => $bound, 'days' => self::SSO_MISMATCH_WINDOW_DAYS]));
    }

    public function clientLoginEmd(): Check
    {
        $label = __('Client login and EMD');

        if (AccountLogin::clientsNeedEmd()) {
            return Check::ok('client_login_emd', $label, __('Clients sign in only with a present EMD record carrying their e-mail.'));
        }

        return Check::warn('client_login_emd', $label, __('CLIENT_LOGIN_REQUIRE_EMD is off: clients sign in without an EMD record, so a client missing from EMD or with a changed e-mail can still sign in until the account is closed.'));
    }

    /**
     * Demo data in the database (DemoData): red in production, where its
     * published passwords, PIN and answers must not go live; information
     * elsewhere, where it is expected.
     */
    public function demoData(): Check
    {
        $label = __('Demo data');
        $found = app(DemoData::class)->found();

        if (array_sum($found) === 0) {
            return Check::ok('demo_data', $label, __('No demo data in the database.'));
        }

        $detail = __('The database holds demo data: :users staff account(s), :clients client account(s) and :records EMD record(s) under the reserved .test domain, :calls demo call(s).', $found)
            .' '.__('Found first: :list.', ['list' => implode(', ', app(DemoData::class)->samples())]);

        if (DemoData::refusedHere()) {
            return Check::fail('demo_data', $label, $detail.' '.__('Their passwords, PIN and answers are published: with APP_ENV=production the demo accounts cannot sign in and the EMD sync does not hand their records over, but this database must not go live.'));
        }

        return Check::info('demo_data', $label, $detail.' '.__('Expected outside production; with APP_ENV=production the system refuses them.'));
    }

    public function outboundProxy(): Check
    {
        $label = __('Outbound proxy');

        if (($problem = OutboundProxy::problem()) !== null) {
            return Check::fail('proxy', $label, $problem);
        }

        if (($proxy = OutboundProxy::display()) === null) {
            return Check::ok('proxy', $label, __('None: outbound requests go direct.'));
        }

        return Check::ok('proxy', $label, __('Outbound requests go through :proxy; direct: :hosts.', [
            'proxy' => $proxy,
            'hosts' => implode(', ', [...OutboundProxy::ALWAYS_DIRECT, ...OutboundProxy::exceptions()]),
        ]));
    }

    /**
     * The networks free of the limits keyed on the client address
     * (HDID_RATE_LIMIT_EXEMPT_NETWORKS): information while some are listed,
     * red while an entry is refused, since then none is exempt.
     */
    public function rateLimitExemptions(): Check
    {
        $label = __('Rate limit exemptions');
        $exemptions = app(RateLimitExemptions::class);

        if (($invalid = $exemptions->invalid()) !== []) {
            return Check::fail('rate_limit_exemptions', $label, __('HDID_RATE_LIMIT_EXEMPT_NETWORKS holds an invalid or too wide entry (:list), so no network is exempt: every address is limited.', ['list' => implode(', ', $invalid)]));
        }

        if (($networks = $exemptions->networks()) === []) {
            return Check::ok('rate_limit_exemptions', $label, __('None: every client address is limited.'));
        }

        return Check::info('rate_limit_exemptions', $label, __('Not limited by client address: :list. The limits per e-mail address, phone number and system still apply.', ['list' => implode(', ', $networks)]));
    }

    /**
     * @return list<Check>
     */
    public function all(): array
    {
        // Operation first, then the modes block (see CheckGroup); the status
        // page shows them as two blocks in this order.
        $checks = [
            'database' => [__('Database'), $this->database(...)],
            'cache' => [__('Cache'), $this->cacheStore(...)],
            'valkey' => [__('Valkey / Redis'), $this->valkey(...)],
            'session_store' => [__('Session store'), $this->sessionStore(...)],
            'queue' => [__('Queue'), $this->queue(...)],
            'scheduler' => [__('Scheduler'), $this->scheduler(...)],
            'php' => [__('PHP time limit'), $this->phpTimeLimit(...)],
            'messages' => [__('Outbound messages'), $this->outboundMessages(...)],
            'mail' => [__('Mail'), $this->mail(...)],
            'sms' => [__('SMS'), $this->sms(...)],
            'sync' => [__('EMD sync'), $this->syncApi(...)],
            'sync_auth' => [__('EMD API authentication'), $this->syncAuthentication(...)],
            'api_keys' => [__('API keys'), $this->apiKeys(...)],
            'oidc' => [__('Single sign-on'), $this->oidc(...)],
            'proxy' => [__('Outbound proxy'), $this->outboundProxy(...)],
            'mfa' => [__('Two-factor sign-in'), $this->staffMfa(...)],
            'sso_bindings' => [__('Sign-in bindings'), $this->ssoBindings(...)],
            'storage' => [__('Storage'), $this->storage(...)],
            'file_permissions' => [__('File permissions'), $this->filePermissions(...)],
            'retention' => [__('Data retention'), $this->retention(...)],
            'audit' => [__('Audit log'), $this->auditLog(...)],
            'capacity' => [__('Capacity'), $this->capacity(...)],
            'demo_data' => [__('Demo data'), $this->demoData(...)],
            'premium_only' => [__('Premium clients only'), $this->premiumOnly(...)],
            'login_link_preview' => [__('Login link preview'), $this->loginLinkPreview(...)],
            'client_login_emd' => [__('Client login and EMD'), $this->clientLoginEmd(...)],
            'rate_limit_exemptions' => [__('Rate limit exemptions'), $this->rateLimitExemptions(...)],
            'adfs_debug' => [__('ADFS debugging'), $this->adfsDebug(...)],
            'package_overrides' => [__('Package overrides'), $this->packageOverrides(...)],
            'shared_numbers' => [__('Shared phone numbers'), $this->sharedPhoneNumbers(...)],
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
     * The checks in their two blocks, operation first.
     *
     * @return array<string, list<Check>> keyed by CheckGroup value
     */
    public function grouped(): array
    {
        $groups = array_fill_keys(array_map(fn (CheckGroup $group): string => $group->value, CheckGroup::cases()), []);

        foreach ($this->all() as $check) {
            $groups[$check->group->value][] = $check;
        }

        return $groups;
    }

    /**
     * What an operator can do when the check turns yellow or red. Kept next
     * to the checks so the advice stays in step with what is measured.
     */
    public static function hint(string $key): ?string
    {
        return match ($key) {
            'premium_only' => __('Information: Settings › Clients and levels › "Premium only" is on, so only premium clients are served; standard clients cannot sign in, the phone menu does not recognise them and no staff view shows them. Their data is kept and the sync keeps importing them. Switch it off to serve them again; the number shown is how many open standard clients are held back.'),
            'login_link_preview' => __('Yellow: Settings › Client portal › "Show the link in the panel after sending" is on, so every sent client login link is shown to the staff member who sends it. Meant for test environments only; switch it off for production.'),
            'mfa' => __('Yellow: some staff have no second factor yet (they set one up at their next sign-in, an app with an e-mail code too) or are locked after wrong codes (Users list, "Unlock login"). Red: two-factor sign-in is on but none of the chosen methods can be used, so nobody can finish signing in; choose another method under Settings › Staff sign-in, or switch it off with "php artisan hdid:mfa-reset --off" (in the container: docker compose exec web php artisan hdid:mfa-reset --off). A staff member who lost the factor: "Reset second factor" on the Users list, or hdid:mfa-reset <e-mail>.'),
            'rate_limit_exemptions' => __('Information: the networks of HDID_RATE_LIMIT_EXEMPT_NETWORKS (in the .env file or the environment of the web container) are not limited by client address: neither the per-address limits of the sign-in, company sign-in and other routes nor the panel\'s login limit, in the container not the nginx request and connection limits either. Meant for a company whose users all come from one address (NAT). The limits per e-mail address, phone number and system (login links, SMS codes) and the API keys\' limits still apply. Red: an entry is not an address or network, or is wider than /8 (IPv4) or /32 (IPv6); then no network is exempt (the container does not start). Behind a load balancer the application must see the real client address: list the balancer in TRUSTED_PROXIES, and in the container use OPENHDID_TLS=off, otherwise every client looks like the balancer. After a change reload the configuration (php artisan config:cache, or restart the containers).'),
            'client_login_emd' => __('Yellow: CLIENT_LOGIN_REQUIRE_EMD=false in the .env file (or the environment of the web container), so client logins do not ask for an EMD record. Closed accounts and clients without an entitling package are still refused. To require the record again, remove the line or set it to true, then reload the configuration (php artisan config:cache, or restart the containers).'),
            'sync_auth' => config('hdid.sync.token_keep_alive')
                ? __('EMD API tokens are refreshed every 15 minutes when EMD_SYNC_TOKEN_KEEP_ALIVE is enabled. Check the login/refresh URLs, credentials, scheduler and hdid:emd-sync-token. The EMD sync run checks the export separately.').' '.self::staleLockHint()
                : __('EMD_SYNC_TOKEN_KEEP_ALIVE is disabled. EMD sync reuses tokens refreshed less than 15 minutes ago, otherwise refreshes them on demand and logs in again when the refresh fails. Check the login/refresh URLs and credentials, or run hdid:emd-sync-token manually.').' '.self::staleLockHint(),
            'database' => __('Check that the database server is running and that the DB_* values in .env are correct. Nothing works without it; restart the DB service or fix the credentials, then reload this page.'),
            'cache' => __('The cache store (CACHE_STORE in .env) cannot be written or read. With Redis check that the service runs and REDIS_* is right; with the file or database store check disk space and permissions on storage/. Settings, sessions and login throttling depend on it.'),
            'queue' => __('E-mails, SMS and EMD sync run in the queue. Red: no worker heartbeat on the default queue, start or restart the queue worker (the "php artisan queue:work" service or the worker container). Yellow: the EMD sync queue (EMD_SYNC_QUEUE) has no worker of its own, or there are failed or piling jobs. Open the Queues page: it lists every queue with its worker, lets you run failed jobs again or delete them, and clears a queue, once the cause (mail, SMS, database) is fixed. The console equivalents are "php artisan queue:failed" and "queue:retry all".').' '.__('Yellow on the priority queue: the sign-in codes and links (HDID_PRIORITY_QUEUE) wait for a worker that listens on it before the default queue. On a traditional install run queue:work --queue=priority,default (deploy/hdid-queue.service), then php artisan queue:restart; the worker container listens on it by itself.').' '.self::sharedStoreHint(),
            'scheduler' => __('The scheduler does not run: the cron entry "* * * * * php artisan schedule:run" or the scheduler container is missing or stopped. It drives the EMD sync, the stale-call cleanup, the override expiry and the data retention. Add or repair it on the app server.').' '.self::sharedStoreHint().' '.self::staleLockHint(),
            'valkey' => __('Red: maxmemory-policy is not noeviction, so when memory runs out Valkey silently drops keys: sessions (everyone signed out), rate limits and lockouts, the replay guard of signed partner requests and one-time tokens, locks and queued jobs. Set maxmemory-policy noeviction: the bundled Valkey of compose.yaml has it; on an external or managed Valkey/Redis set it in valkey.conf (redis.conf) or in the provider\'s console (the managed default often evicts, e.g. volatile-lru). Red without memory figures: the server cannot be reached, check REDIS_HOST, REDIS_PORT and REDIS_PASSWORD. Yellow: memory use is above 80 % of maxmemory, there is no maxmemory (the store grows until the host runs out of memory), keys have been evicted since the server started, or REDIS_PREFIX is the default derived from APP_NAME. Raise the limit of the bundled Valkey with OPENHDID_VALKEY_MAXMEMORY and OPENHDID_VALKEY_MEM_LIMIT in .env, then run docker compose up -d; a managed one by its size; on a classic installation set maxmemory (for example 256mb) and maxmemory-policy noeviction in /etc/valkey/valkey.conf, then systemctl restart valkey. A store that fills up fast usually means a flood of anonymous sign-in pages, each opening a session: give the sessions a Valkey of their own (REDIS_SESSION_HOST, see the Session store tile). One Valkey belongs to one installation (or at least its own database, ACL user and key prefix): set REDIS_PREFIX and CACHE_PREFIX in .env to values of this installation, e.g. openhdid-prod- and openhdid-prod-cache-, only while no job waits: stop the web service and the scheduler first, let the workers finish the queue (php artisan queue:monitor redis:priority,redis:default,redis:sync shows the sizes, or the Queues page), then change the prefix and start everything again (container: docker compose up -d; classic installation: php artisan config:cache, then PHP-FPM and php artisan queue:restart); jobs left under the old prefix stay behind, and every session ends. Information: the provider allows neither INFO nor CONFIG GET; check the policy in its console.'),
            'php' => __('EMD sync and large file imports need a PHP max_execution_time of at least 120 seconds (300 recommended) in both PHP-FPM (the panel, this page) and the CLI (the worker, hdid:emd-sync); 0 means unlimited and is fine. Set it in php.ini or the FPM pool (php_admin_value[max_execution_time]) with request_terminate_timeout above it, then restart PHP-FPM. Below 120 s a sync refuses to start. This tile shows the limit of the process that ran the check: the panel shows PHP-FPM, "php artisan hdid:health" shows the CLI.'),
            'messages' => __('Failed messages of the last 24 hours. Open Outbound messages or the Queues page, look at the error of a failed row and fix the transport (E-mail or SMS tile), then resend from the list (one, the selected ones or every failed one). Queued ones that never leave point to the queue worker; "Send queued now" sends them from the browser without a worker. An SMS the network reported undeliverable (wrong or switched-off number) is not resent on its own: check the number on the client record and resend by hand if it was right. Overdue delivery queries mean the scheduler has not run the minutely SMS delivery checks.').' '.__('Yellow also when a queued message has waited more than 5 minutes, or a sign-in code more than 2: the worker is down, busy with a pile of messages, or does not listen on the priority queue. Sign-in codes (the client\'s SMS login code, the staff sign-in code) go on the queue named by HDID_PRIORITY_QUEUE (empty: default); client login links, which live for hours and can be asked for without signing in, wait on the default queue. The worker has to listen on the priority queue before the default one: on a classic installation queue:work --queue=priority,default (deploy/hdid-queue.service), then php artisan queue:restart; the worker container listens on it by itself. One that expired or was replaced by a newer one while it waited is not sent: the list shows it as cancelled with the reason.').' '.__('Yellow, too, while a system-wide limit of the anonymous login messages (Settings › Client portal › Sign-in methods: login links per hour, SMS codes per hour and per day) turns requests away, naming the channel; it lasts until that window is over, and meanwhile the portal\'s login page tells every visitor that links or SMS codes cannot be requested now. Usually a flood of login requests (login.request_capped in the audit log, with the channel); raise the limit only if real logins reach it. The networks of HDID_RATE_LIMIT_EXEMPT_NETWORKS are not exempt from these limits.'),
            'mail' => __('Red: the EWS URL or credentials are missing in the environment (MAIL_MAILER, EWS_*), or EWS_AUTH=ntlm is set where the libcurl has no NTLM (the OpenHDID image has it from 1.8.0; elsewhere use basic, oauth or SMTP). Yellow: a log/array mailer is set, so no e-mail leaves the server, fine for testing, not for production; or SMTP runs with MAIL_REQUIRE_TLS=false, so the password and the e-mails go out unencrypted when the server offers no STARTTLS: set MAIL_REQUIRE_TLS=true (the server has to offer STARTTLS) or MAIL_SCHEME=smtps with port 465, then restart the services (docker compose up -d, or on a classic installation php artisan config:cache, then PHP-FPM and php artisan queue:restart). Red also when e-mails of the last 24 hours failed because the SMTP server offers no STARTTLS (the error of the message says so): turn STARTTLS on at the server, or use MAIL_SCHEME=smtps with port 465 (MAIL_REQUIRE_TLS=false only for a local test server), restart the services the same way, then resend the failed messages from Outbound messages. Test with a magic link to your own client account.'),
            'sms' => __('Red with Ozeki: the gateway is unreachable or the credentials are wrong (OZEKI_*), check the Ozeki service and the network path. Red/yellow with the log driver: SMS are only written to the log, set SMS_DRIVER=ozeki for production. Delivery reports are asked for with the gateway\'s message id at the offsets in SMS_DELIVERY_CHECKS (OZEKI_STATUS_ACTION names the query action, OZEKI_DELIVERED_STATUSES and OZEKI_UNDELIVERED_STATUSES the status words); if every SMS stays "report pending" and ends "no report", the query action or the status words do not match your gateway: look at the raw gateway status on a message\'s details.'),
            'sync' => __('Red: the last run failed or no successful run happened in twice the expected interval. Open EMD sync runs for the error; check the API URL/key or upload the file manually. A refused run usually means the export was much smaller than before (row-ratio guard). Yellow with a waiting run: the sync started from the panel was not picked up, start a worker for the EMD_SYNC_QUEUE queue. With {{ ids }} in the export payload, a failed ID list query (EMD_SYNC_ID_LIST_URL, ID list settings) or no selected ID on the list also fails the run; the run details show the status codes. Yellow with accounts closing at the next sync: their records were absent from the last run(s); open EMD records, tab "Closes at the next sync", and check the export (or the ID list selection) before the next run closes them. Yellow with e-mail conflicts: either the directory renamed an account e-mail to an address another account holds (the account kept its old address and cannot sign in until the duplicate is resolved in EMD), or a new record arrived with an address already on an account, possibly written differently (accents, full-width characters, look-alike domain): that existing account is unchanged and keeps working, the record stays without an account. Check in EMD which record is the real one and correct the address there; do not delete the existing account, the look-alike record could then take its place at the next run. Of two new records with look-alike addresses in one run, the one that came first got the account. A changed classification (staff/client) closes the old account only after the same number of runs as a missing record; the run details list the pending ones.').' '.self::staleLockHint(),
            'api_keys' => __('Red: a mobile app backend key has no signing secret, so every request with it is refused (signing is mandatory for this scope). Issue one under API keys › Signing secret and hand it to the operator of the mobile backend, or revoke the key if it is not used. Yellow: a scope (call center or mobile backend) has no active key, so that partner cannot call in; create one under API keys and hand it over, or ignore this while the integration is not live yet.'),
            'package_overrides' => __('Informational: these clients carry a temporary manual package. Nothing to fix, but review the list now and then and end an override EMD has caught up with.'),
            'shared_numbers' => __('A number on file for more than one client recognises nobody in the phone menu, so those callers are not asked for their PIN. Open Shared phone numbers, decide whose number it is and remove it from the others; a number EMD lists for several people has to be corrected in the directory.'),
            'proxy' => __('Red: HDID_HTTP_PROXY is not a usable address; write it as http://host:port, with a password as http://user:password@host:port (special characters percent-encoded, @ as %40). Proxies with Windows (NTLM) sign-in are not supported. Internal servers reached without the proxy (master data, ADFS, Exchange, SMS gateway) go into HDID_NO_PROXY, comma-separated. After a change restart the services: docker compose up -d, or on a classic installation php artisan config:cache, then PHP-FPM and php artisan queue:restart.'),
            'sso_bindings' => __('Yellow: a company sign-in arrived under an account\'s e-mail address (or employee id) from a directory identity other than the one the account is bound to, or from an identity already bound to another account; that person cannot sign in until you act. Check the audit log (login.rejected with sso_subject_mismatch or sso_subject_taken) and the account; if the identity change is legitimate (re-created directory account, replaced registration), "Clear sign-in binding" on the account, or php artisan hdid:sso-unbind <e-mail> (in the container: docker compose exec web php artisan hdid:sso-unbind <e-mail>). The binding itself is set by the first company sign-in of each account.'),
            'oidc' => __('Red: a provider is switched on in Settings but its issuer or tenant, client id or secret is missing in the environment (USER_/CLIENT_ADFS_*, USER_/CLIENT_ENTRA_*), the Entra tenant list has a problem (the tile names it: a shared endpoint such as common or organizations, an id that is not a GUID, a malformed domain), or USER_/CLIENT_SSO_MATCH holds a value other than email or employee_id. Yellow: CLIENT_ENTRA_TENANT and CLIENT_ENTRA_TENANTS are both set (keep the list only), the same domain is listed under several tenants (check which tenant may sign in those accounts), or a domain of a CLIENT_ENTRA_TENANTS tenant is a staff domain or on no EMD domain list: the sync files staff-domain addresses as staff and skips unlisted ones, so it brings no client of that domain; put the domain on "Client e-mail domains" (and off "Staff e-mail domains") under Settings › Enterprise Master Data (EMD) › Classification, or remove it from the tenant. Settings › Client portal › Sign-in methods lists every tenant with the state of its domains under the Microsoft Entra ID switch. Either fill in the configuration or switch the provider off, otherwise the login button leads to an error. The ADFS claims compared with the account e-mail address come from USER_/CLIENT_ADFS_EMAIL_CLAIM; why a sign-in was refused is in the audit log (login.rejected). After a change restart the services: docker compose up -d, or on a classic installation php artisan config:cache, then PHP-FPM.'),
            'adfs_debug' => __('Yellow: HDID_ADFS_DEBUG is on, so every ADFS sign-in writes its decoded tokens, personal data included, to the application log. Meant for setting up the ADFS claims only; set it to false afterwards and restart the services (docker compose up -d, or php artisan config:cache and PHP-FPM on a classic installation).'),
            'session_store' => __('Yellow with "share the Valkey": the sessions live on the main Valkey (REDIS_SESSION_HOST is empty), so a flood of sign-in pages that fills it also stops the queue, the locks and the rate limits. Give them a Valkey of their own with maxmemory-policy allkeys-lru, which pushes out the sessions not used for the longest time (the flood\'s own first). The compose.yaml of OpenHDID brings one, the valkey-sessions service of the redis profile (REDIS_SESSION_HOST=valkey-sessions, its size from OPENHDID_VALKEY_SESSIONS_MAXMEMORY and OPENHDID_VALKEY_SESSIONS_MEM_LIMIT): docker compose up -d. With an external or managed Valkey (10k and 20k profiles) set up a second instance with allkeys-lru and put its address into REDIS_SESSION_HOST, REDIS_SESSION_PORT, REDIS_SESSION_PASSWORD and REDIS_SESSION_DB (an empty key takes its REDIS_* value), then restart the services. Classic installation: a second valkey-server, for example on port 6380, with the maxmemory of the sizing profile (5k: 384mb, 10k: 576mb, 20k: 1gb), maxmemory-policy allkeys-lru and save 300 1, then REDIS_SESSION_HOST=127.0.0.1 and REDIS_SESSION_PORT=6380 in .env, sudo -u www-data sh -c \'umask 027 && php artisan config:cache\', PHP-FPM restart and php artisan queue:restart. Moving the sessions signs everybody out once. Yellow on the session store itself: maxmemory-policy is noeviction (new sign-ins fail when it is full) or a volatile or random policy: set allkeys-lru; no maxmemory; or sessions were evicted in the last hour, usually a flood of sign-in pages, otherwise a store too small. Red: the session store cannot be reached, so nobody can sign in: check REDIS_SESSION_* and that the server runs.'),
            'file_permissions' => __('Red: a file that holds secrets (.env, the cached configuration in bootstrap/cache with APP_KEY and every password, the logs in storage/logs, the session files in storage/framework/sessions) can be read by any local account of the server, through folders that let that account pass. Classic installation, in the application folder (/var/www/hdid): sudo chown -R <installer>:www-data . && sudo chmod 750 . ; sudo chown -R www-data:www-data storage bootstrap/cache && sudo chmod -R u+rwX,g+rwX,o-rwx storage bootstrap/cache ; sudo chmod 640 .env ; then rebuild the caches as the runtime account: sudo -u www-data sh -c \'umask 027 && php artisan optimize\'. Give PHP-FPM UMask=0027 with the drop-in deploy/php-fpm-umask.conf (systemctl daemon-reload, then restart PHP-FPM); the queue workers and the cron samples already run with it. Daily logs written before keep their mode: sudo chmod o-rwx storage/logs/*. The OpenHDID container is not affected: it runs as one account with umask 027, and its image files are read-only.'),
            'storage' => __('Red: the public disk is not writable, fix the permissions of storage/app/public. Yellow: run "php artisan storage:link" on the server, otherwise uploaded logos and images do not show.'),
            'retention' => __('The daily "hdid:prune" task has not run for days. It runs through the scheduler, so check that tile first; run "php artisan hdid:prune" by hand to catch up.').' '.self::staleLockHint(),
            'audit' => __('The database keeps the audit entries of the last N days (Settings › Operations and retention › Audit log in the database); the nightly "hdid:prune" task moves older days into compressed daily files under storage/app/private/audit-archive with a SHA-256 sidecar, and deletes them from the table. Yellow: entries older than the window are still in the database, so the task has not run (see the Scheduler and Data retention tiles) or could not write the storage disk; run "php artisan hdid:prune" by hand, it archives at most 120 days per run. The files are listed and downloadable on the Audit archive page and go into the backups with the storage volume.'),
            'demo_data' => __('Red: the database holds the demo data (loaded with OPENHDID_DEMO_DATA=true or the demo seeder) and the system runs with APP_ENV=production. The passwords, PIN and answers of the demo accounts are published, so the demo accounts cannot sign in, the EMD sync does not hand a demo record to the real person who has its id (it reports an e-mail conflict instead), and the container\'s migrate service stops. Deleting the demo accounts one by one is not enough: their records, calls and audit entries stay. Start production with an empty database. Container: stop the installation and remove the demo\'s volumes (docker compose down -v removes every volume of the project, the uploaded files too), then docker compose up -d and create the first administrator again (hdid:make-admin). Classic installation: create a new, empty database, put it into DB_DATABASE in .env, run php artisan config:cache, php artisan migrate --force and php artisan db:seed --class=RolesAndPermissionsSeeder --force, then php artisan hdid:make-admin. To keep trying the demo instead, set APP_ENV=demo again. The detail names the addresses found first: if they are not the documented demo accounts but a few real rows that got a .test address (an administrator created with such an address, for example), the database is real, do not delete it: set APP_ENV=staging for a moment (container: docker compose up -d; classic installation: php artisan config:cache), correct those addresses on the panel (Users, Clients) or in EMD, then set APP_ENV=production again. In production a new .test address is refused (on the panel and by hdid:make-admin). Information: demo data outside production (APP_ENV=demo or local) is expected; it is refused only in production.'),
            'capacity' => __('Accounts active in the last 15 minutes (any request they made), today\'s peak and, on MariaDB, the connections in use against max_connections. With HDID_SIZING_PROFILE set (5k, 10k-single, 10k, 20k) the tile compares the peak with the profile\'s design point and turns yellow at 80 %: time to step up to the next profile (more PHP-FPM processes and workers, Valkey, a second application server or Kubernetes), see the operations manual. Without a profile the numbers are shown for information only.').' '.__('On the panel (PHP-FPM) the tile also shows the pool: busy processes, waiting requests and how often every process was busy. Yellow when requests wait for a free process, when the PHP-FPM processes × memory_limit and the opcache exceed the memory limit of the web container (OPENHDID_FPM_MAX_CHILDREN, OPENHDID_FPM_MEMORY_LIMIT, OPENHDID_WEB_MEM_LIMIT; Kubernetes: the web pod\'s limit), or when max_connections is below the connections this web server may open with the workers (OPENHDID_DB_MAX_CONNECTIONS, or the managed database). php artisan hdid:health runs outside PHP-FPM and does not show these.'),
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

        // One line per queue: the default one carries the messages, the EMD
        // sync queue (when separate) needs a worker of its own.
        $states = app(QueueMaintenance::class)->overview();
        $parts = [$connection];
        $pending = 0;
        $failed = 0;
        $defaultDown = false;
        $otherDown = false;

        foreach ($states as $state) {
            $line = $state->name.': '.($state->heartbeat === null ? __('no worker seen yet') : __('worker seen :when', ['when' => $state->heartbeat->diffForHumans()]));

            if ($state->waiting !== null) {
                $line .= ' · '.__(':n pending', ['n' => $state->pending()]).($state->oldestWaitingAt ? ' ('.__('oldest :when', ['when' => $state->oldestWaitingAt->diffForHumans()]).')' : '');
            }

            if ($state->failed > 0) {
                $line .= ' · '.__(':n failed', ['n' => $state->failed]);
            }

            if (! $state->configured) {
                $line .= ' · '.__('no worker configured for this queue');
            }

            $parts[] = $line;
            $pending += $state->pending();
            $failed += $state->failed;

            if ($state->configured && ! $state->isAlive()) {
                if ($state->name === 'default') {
                    $defaultDown = true;
                } else {
                    $otherDown = true;
                }
            }
        }

        $detail = implode(' · ', $parts);

        // A worker of another installation takes jobs from this queue: the
        // heartbeats above may be its own, so they prove nothing.
        if (($shared = $this->sharedStoreProblem()) !== null) {
            return Check::fail('queue', $label, $detail.' · '.$shared);
        }

        if ($defaultDown) {
            return Check::fail('queue', $label, $detail);
        }

        return $otherDown || $failed > 0 || $pending > 50 ? Check::warn('queue', $label, $detail) : Check::ok('queue', $label, $detail);
    }

    public function scheduler(): Check
    {
        $label = __('Scheduler');
        $heartbeat = Installation::readBeat($this->cache->get(self::SCHEDULER_HEARTBEAT_KEY));

        if ($heartbeat === null) {
            return Check::fail('scheduler', $label, __('No run recorded yet; is "schedule:run" in cron?'));
        }

        $detail = __('last run :when', ['when' => $heartbeat['at']->diffForHumans()]);

        if (($shared = $this->sharedStoreProblem()) !== null) {
            return Check::fail('scheduler', $label, $detail.' · '.$shared);
        }

        // The heartbeat names the installation whose scheduler wrote it; a bare
        // timestamp (older releases) is taken as this installation's.
        if ($heartbeat['installation'] !== null && $heartbeat['installation'] !== Installation::id()) {
            return Check::fail('scheduler', $label, __('The last scheduler heartbeat (:when) came from another installation (:other), not from this one (:own): another installation uses the same Valkey/Redis and key prefix, and the scheduler of this installation may not run at all.', [
                'when' => $heartbeat['at']->diffForHumans(),
                'other' => $heartbeat['installation'],
                'own' => Installation::id(),
            ]));
        }

        return $heartbeat['at']->lt(now()->subMinutes(self::HEARTBEAT_MAX_AGE_MINUTES)) ? Check::fail('scheduler', $label, $detail) : Check::ok('scheduler', $label, $detail);
    }

    /**
     * Another installation seen in the shared store (its heartbeat in the
     * scheduler key, or a worker of it running a heartbeat job of this one),
     * in words, or null.
     */
    public function sharedStoreProblem(): ?string
    {
        $conflict = Installation::conflict();

        if ($conflict === null) {
            return null;
        }

        $where = str_starts_with($conflict['source'], 'queue:')
            ? __('a heartbeat job of the ":queue" queue', ['queue' => substr($conflict['source'], strlen('queue:'))])
            : __('the scheduler heartbeat');

        return __('Another installation (:other) uses the same Valkey/Redis and key prefix as this one (:own), last seen :when in :where. Their queued jobs, heartbeats and rate limits mix (the locks and the scheduler\'s marks carry the installation id, so each runs its own scheduled tasks), so this tile cannot tell whether the workers and the scheduler of this installation run.', [
            'other' => $conflict['installation'],
            'own' => Installation::id(),
            'where' => $where,
            'when' => $conflict['at']->diffForHumans(),
        ]);
    }

    /**
     * What to do when the queue or scheduler tile names another installation.
     */
    /**
     * A scheduled task late behind an overlap lock that outlived a scheduler
     * restart: the command that releases it (install.sh check finds it too).
     */
    private static function staleLockHint(): string
    {
        return __('If a task is late while the scheduler runs (the EMD token refresh, the EMD sync or hdid:prune, for example), its lock may have outlived a restart of the scheduler: docker compose exec scheduler php artisan schedule:clear-cache (on a classic installation: sudo -u www-data sh -c \'umask 027 && php artisan schedule:clear-cache\'). From 1.15.0 a lock lives at most as long as its task may run.');
    }

    private static function sharedStoreHint(): string
    {
        return __('Red with "another installation": a second installation (a test or staging one) uses the same Valkey/Redis under the same key prefix, so the two take each other\'s jobs and write each other\'s heartbeats. Give each installation a Valkey of its own, or at least its own database, ACL user and key prefix (REDIS_PREFIX and CACHE_PREFIX in .env, changed only while the queue is empty), then restart the services (docker compose up -d, or on a classic installation php artisan config:cache, then PHP-FPM and php artisan queue:restart). The tile turns green again :minutes minutes after the last sighting.', ['minutes' => Installation::CONFLICT_MINUTES]);
    }

    /**
     * The Valkey/Redis the cache, the sessions or the queue run on. It has to
     * refuse writes when full rather than drop keys (sessions, rate limits,
     * replay guards, locks and queued jobs live there), have a memory limit
     * with room left, and keep the keys of this installation under a prefix
     * of its own. Managed services that allow neither INFO nor CONFIG GET
     * leave the limit and the policy unknown: information, not an error.
     */
    public function valkey(): Check
    {
        $label = __('Valkey / Redis');
        $uses = $this->redisUses();

        if ($uses === []) {
            return Check::ok('valkey', $label, SessionStore::onRedis()
                ? __('Not used by the cache and the queue; the sessions have a Valkey of their own (Session store tile).')
                : __('Not used: the cache, the sessions and the queue do not run on Valkey or Redis.'));
        }

        $parts = [__('used by: :parts', ['parts' => implode(', ', array_keys($uses))])];

        try {
            $connection = Redis::connection(reset($uses));
        } catch (Throwable $e) {
            return Check::fail('valkey', $label, implode(' · ', [...$parts, $e->getMessage()]));
        }

        $fails = [];
        $warns = [];
        $notes = [];

        try {
            $answer = $connection->command('info');
            $info = is_array($answer) ? self::flatInfo($answer) : null;
        } catch (Throwable) {
            $info = null;
        }

        $policy = isset($info['maxmemory_policy']) ? (string) $info['maxmemory_policy'] : null;
        $max = isset($info['maxmemory']) ? (int) $info['maxmemory'] : null;

        if ($policy === null || $max === null) {
            try {
                $config = $connection->command('config', ['GET', 'maxmemory*']);
                $config = is_array($config) ? $config : [];
                $policy ??= isset($config['maxmemory-policy']) ? (string) $config['maxmemory-policy'] : null;
                $max ??= isset($config['maxmemory']) ? (int) $config['maxmemory'] : null;
            } catch (Throwable) {
                // Many managed services refuse CONFIG; INFO is all there is.
            }
        }

        if ($info === null && $policy === null) {
            try {
                $connection->command('ping');
            } catch (Throwable $e) {
                return Check::fail('valkey', $label, implode(' · ', [...$parts, $e->getMessage()]));
            }

            $notes[] = __('The server allows neither INFO nor CONFIG GET, so its memory limit and eviction policy cannot be checked here; make sure in the provider\'s console that maxmemory-policy is noeviction.');
        } elseif ($policy === null) {
            $notes[] = __('The eviction policy cannot be read (neither INFO nor CONFIG GET names it); make sure that maxmemory-policy is noeviction.');
        } elseif ($policy !== 'noeviction') {
            $fails[] = __('maxmemory-policy is :policy: when memory runs out the server silently drops keys (sessions, rate limits, replay guards, locks, queued jobs) instead of refusing writes.', ['policy' => $policy]);
        } else {
            $parts[] = __('eviction policy :policy', ['policy' => $policy]);
        }

        $used = isset($info['used_memory']) ? (int) $info['used_memory'] : null;

        if ($max === 0) {
            $warns[] = __('No memory limit (maxmemory 0): the store grows until the host runs out of memory.');
        }

        if ($used !== null) {
            $parts[] = $max !== null && $max > 0
                ? __('memory :used / :max (:percent %)', ['used' => $this->humanBytes($used), 'max' => $this->humanBytes($max), 'percent' => (int) round(100 * $used / $max)])
                : __('memory :used', ['used' => $this->humanBytes($used)]);

            if ($max !== null && $max > 0 && $used >= self::VALKEY_MEMORY_WARN_RATIO * $max) {
                $warns[] = __('Memory use is above :percent % of maxmemory: at the limit every write fails.', ['percent' => (int) (100 * self::VALKEY_MEMORY_WARN_RATIO)]);
            }
        }

        $evicted = isset($info['evicted_keys']) ? (int) $info['evicted_keys'] : 0;
        if ($evicted > 0) {
            $warns[] = __(':n key(s) evicted since the server started.', ['n' => $evicted]);
        }

        // The framework's default prefix comes from APP_NAME alone, so every
        // installation with the same APP_NAME writes the same keys. The Valkey
        // the compose project brings along (host "valkey") belongs to this
        // installation only, so there the default is information, not a warning.
        $prefix = (string) config('database.redis.options.prefix', '');
        $bundled = strtolower((string) config('database.redis.'.reset($uses).'.host')) === 'valkey';
        if ($prefix === Str::slug((string) config('app.name')).'-database-') {
            $text = __('REDIS_PREFIX is not set: the keys carry ":prefix", which every installation named ":name" (APP_NAME) gets; on a Valkey another installation also uses, the two mix their sessions, queues, locks and heartbeats.', ['prefix' => $prefix, 'name' => (string) config('app.name')]);
            if ($bundled) {
                $notes[] = $text.' '.__('This one is the Valkey of the installation\'s own compose project, which nothing else reaches.');
            } else {
                $warns[] = $text;
            }
        } else {
            $parts[] = __('key prefix :prefix', ['prefix' => $prefix === '' ? '–' : $prefix]);
        }

        $detail = implode(' · ', [...$fails, ...$warns, ...$notes, ...$parts]);

        return match (true) {
            $fails !== [] => Check::fail('valkey', $label, $detail),
            $warns !== [] => Check::warn('valkey', $label, $detail),
            $notes !== [] => Check::info('valkey', $label, $detail),
            default => Check::ok('valkey', $label, $detail),
        };
    }

    /**
     * The Valkey/Redis of the sessions (SESSION_DRIVER=redis). On a server of
     * its own it should evict the least recently used keys (allkeys-lru): a
     * flood of sign-in pages then pushes out its own sessions first, real
     * users touch theirs on every request. Sessions on the main server share
     * its noeviction memory with the queue, the locks and the rate limits, so
     * a flood that fills it stops those writes too. Evictions are expected
     * under such a flood; a recent rise is still worth a look.
     */
    public function sessionStore(): Check
    {
        $label = __('Session store');

        if (! SessionStore::onRedis()) {
            return Check::ok('session_store', $label, __('Sessions use the :driver driver; a Valkey of their own applies with SESSION_DRIVER=redis only.', ['driver' => (string) config('session.driver')]));
        }

        if (SessionStore::sharesMainStore()) {
            return Check::warn('session_store', $label, __('The sessions share the Valkey of the queue, the locks and the rate limits (REDIS_SESSION_HOST is empty or names the same server): a flood of sign-in pages fills it with sessions, and once it is full the queue, the locks and the rate limits cannot be written either.'));
        }

        $connectionName = SessionStore::connection();
        $parts = [__('server :host', ['host' => (string) config("database.redis.{$connectionName}.host").':'.(string) config("database.redis.{$connectionName}.port")])];

        try {
            $connection = Redis::connection($connectionName);
            $answer = $connection->command('info');
            $info = is_array($answer) ? self::flatInfo($answer) : null;
        } catch (Throwable $e) {
            return Check::fail('session_store', $label, implode(' · ', [__('The session store cannot be reached, so nobody can sign in.'), ...$parts, $e->getMessage()]));
        }

        $policy = isset($info['maxmemory_policy']) ? (string) $info['maxmemory_policy'] : null;
        $max = isset($info['maxmemory']) ? (int) $info['maxmemory'] : null;

        if ($policy === null || $max === null) {
            try {
                $config = $connection->command('config', ['GET', 'maxmemory*']);
                $config = is_array($config) ? $config : [];
                $policy ??= isset($config['maxmemory-policy']) ? (string) $config['maxmemory-policy'] : null;
                $max ??= isset($config['maxmemory']) ? (int) $config['maxmemory'] : null;
            } catch (Throwable) {
                // Many managed services refuse CONFIG; INFO is all there is.
            }
        }

        $warns = [];
        $notes = [];

        if ($policy === null) {
            $notes[] = __('The eviction policy cannot be read (neither INFO nor CONFIG GET names it); make sure in the provider\'s console that maxmemory-policy is allkeys-lru.');
        } elseif ($policy === 'noeviction') {
            $warns[] = __('maxmemory-policy is noeviction: once the store is full, new sign-ins fail instead of pushing out the least recently used sessions. Set allkeys-lru on this server.');
        } elseif (! in_array($policy, ['allkeys-lru', 'allkeys-lfu'], true)) {
            $warns[] = __('maxmemory-policy is :policy: allkeys-lru is expected here, which pushes out the sessions not used for the longest time; :policy may drop the sessions of active users or refuse new ones.', ['policy' => $policy]);
        } else {
            $parts[] = __('eviction policy :policy', ['policy' => $policy]);
        }

        if ($max === 0) {
            $warns[] = __('No memory limit (maxmemory 0): the store grows until the host runs out of memory.');
        }

        $used = isset($info['used_memory']) ? (int) $info['used_memory'] : null;
        if ($used !== null) {
            $parts[] = $max !== null && $max > 0
                ? __('memory :used / :max (:percent %)', ['used' => $this->humanBytes($used), 'max' => $this->humanBytes($max), 'percent' => (int) round(100 * $used / $max)])
                : __('memory :used', ['used' => $this->humanBytes($used)]);
        }

        if (isset($info['evicted_keys'])) {
            $evicted = (int) $info['evicted_keys'];
            $parts[] = __(':n session(s) evicted since the server started', ['n' => $evicted]);

            if ($this->evictionsRoseRecently($evicted)) {
                $warns[] = __('Sessions were evicted in the last :minutes minutes: signed-in users not active for a while had to sign in again. Usually a flood of sign-in pages (see the rate-limited requests in the web log); if it happens without one, the store is too small for the sessions (OPENHDID_VALKEY_SESSIONS_MAXMEMORY, or the size of the managed instance).', ['minutes' => self::SESSION_EVICTION_WINDOW_MINUTES]);
            }
        }

        $detail = implode(' · ', [...$warns, ...$notes, ...$parts]);

        return match (true) {
            $warns !== [] => Check::warn('session_store', $label, $detail),
            $notes !== [] => Check::info('session_store', $label, $detail),
            default => Check::ok('session_store', $label, $detail),
        };
    }

    /**
     * Whether the eviction counter of the session store went up within the
     * window. The last count seen is kept in the cache: a higher one marks
     * the time, a lower one (the server restarted) starts over.
     */
    private function evictionsRoseRecently(int $evicted): bool
    {
        $seen = $this->cache->get(self::SESSION_EVICTIONS_KEY);
        $roseAt = is_array($seen) && isset($seen['rose_at']) ? (int) $seen['rose_at'] : null;

        if (is_array($seen) && isset($seen['count']) && $evicted > (int) $seen['count']) {
            $roseAt = now()->getTimestamp();
        }

        $this->cache->put(self::SESSION_EVICTIONS_KEY, ['count' => $evicted, 'rose_at' => $roseAt], now()->addDay());

        return $roseAt !== null && $roseAt >= now()->subMinutes(self::SESSION_EVICTION_WINDOW_MINUTES)->getTimestamp();
    }

    /**
     * The parts of the system on the main Valkey/Redis, each with the Redis
     * connection it uses. Sessions count here only while they share that
     * server (REDIS_SESSION_HOST empty); on a Valkey of their own the session
     * store tile looks at them.
     *
     * @return array<string, string> label => connection name
     */
    private function redisUses(): array
    {
        $uses = [];
        $store = (string) config('cache.default');

        if (config("cache.stores.{$store}.driver") === 'redis') {
            $uses[__('cache')] = (string) (config("cache.stores.{$store}.connection") ?: 'default');
        }

        // Sessions on a Valkey of their own are the session store tile's (allkeys-lru there).
        if (SessionStore::onRedis() && SessionStore::sharesMainStore()) {
            $uses[__('sessions')] = SessionStore::connection();
        }

        $queue = (string) config('queue.default');

        if (config("queue.connections.{$queue}.driver") === 'redis') {
            $uses[__('queue')] = (string) (config("queue.connections.{$queue}.connection") ?: 'default');
        }

        return $uses;
    }

    /**
     * INFO as one flat map: phpredis answers flat, predis by section.
     *
     * @param  array<int|string, mixed>  $info
     * @return array<string, mixed>
     */
    private static function flatInfo(array $info): array
    {
        $flat = [];

        foreach ($info as $key => $value) {
            if (is_array($value)) {
                $flat += $value;
            } else {
                $flat[(string) $key] = $value;
            }
        }

        return $flat;
    }

    /**
     * The time limit of the PHP process running this check (FPM on the
     * panel, the CLI in hdid:health): a sync refuses to start below the
     * minimum, so the operator learns about a short limit before an import.
     */
    public function phpTimeLimit(): Check
    {
        $label = __('PHP time limit');
        $limit = (int) ini_get('max_execution_time');
        $sapi = match (PHP_SAPI) {
            'cli' => 'CLI',
            'fpm-fcgi' => 'PHP-FPM',
            default => PHP_SAPI,
        };

        if ($limit <= 0) {
            return Check::ok('php', $label, __(':sapi · unlimited', ['sapi' => $sapi]));
        }

        $detail = __(':sapi · :seconds s (minimum :min, recommended :recommended)', ['sapi' => $sapi, 'seconds' => $limit, 'min' => SyncExternalRecords::MIN_TIME_LIMIT_SECONDS, 'recommended' => SyncExternalRecords::RECOMMENDED_TIME_LIMIT_SECONDS]);

        if ($limit < SyncExternalRecords::MIN_TIME_LIMIT_SECONDS) {
            return Check::fail('php', $label, $detail);
        }

        return $limit < SyncExternalRecords::RECOMMENDED_TIME_LIMIT_SECONDS ? Check::warn('php', $label, $detail) : Check::ok('php', $label, $detail);
    }

    public function outboundMessages(): Check
    {
        $label = __('Outbound messages');
        $since = now()->subDay();
        $queued = OutboundMessage::query()->where('status', OutboundMessageStatus::Queued)->count();
        $failed = OutboundMessage::query()->where('status', OutboundMessageStatus::Failed)->where('created_at', '>=', $since)->count();
        $sent = OutboundMessage::query()->whereIn('status', [OutboundMessageStatus::Sent, OutboundMessageStatus::Delivered, OutboundMessageStatus::Undeliverable])->where('created_at', '>=', $since)->count();
        $undeliverable = OutboundMessage::query()->where('status', OutboundMessageStatus::Undeliverable)->where('created_at', '>=', $since)->count();
        $awaiting = SmsDeliveryChecks::due()->count();
        $detail = __(':s sent, :f failed, :u SMS undeliverable in 24 h · :q queued', ['s' => $sent, 'f' => $failed, 'u' => $undeliverable, 'q' => $queued]);

        if ($awaiting > 0) {
            $detail .= ' · '.__(':n SMS delivery queries overdue (is the scheduler running?)', ['n' => $awaiting]);
        }

        if (($problem = SmsDeliveryChecks::configProblem()) !== null) {
            $detail .= ' · '.$problem;
        }

        // A message nobody sends for minutes: the worker is down, busy with a pile, or not
        // listening on its queue. A sign-in code only lives minutes, so it warns sooner. The wait
        // counts from the last time the row was queued (updated_at), so a failed message resent
        // by hand does not show days of waiting.
        $late = false;
        $oldest = OutboundMessage::query()->queued()->oldest('updated_at')->value('updated_at');

        if ($oldest !== null && $oldest->lt(now()->subMinutes(self::MESSAGE_MAX_WAIT_MINUTES))) {
            $detail .= ' · '.__('the oldest queued message has waited :minutes minutes', ['minutes' => (int) $oldest->diffInMinutes(now())]);
            $late = true;
        }

        $oldestSignIn = OutboundMessage::query()->queued()->whereNotNull('meta->valid_until')->oldest('updated_at')->limit(50)->get()
            ->first(fn (OutboundMessage $message): bool => Messenger::isPriorityMessage($message))?->updated_at;

        if ($oldestSignIn !== null && $oldestSignIn->lt(now()->subMinutes(self::SIGN_IN_MESSAGE_MAX_WAIT_MINUTES))) {
            $detail .= ' · '.__('sign-in codes have waited :minutes minutes on the ":queue" queue', ['minutes' => (int) $oldestSignIn->diffInMinutes(now()), 'queue' => Messenger::priorityQueue()]);
            $late = true;
        }

        // A system-wide limit of the anonymously requested login links or SMS codes turned
        // requests away; the tile stays yellow until that window is over.
        $capped = false;

        foreach ([LoginMessageCaps::CHANNEL_EMAIL, LoginMessageCaps::CHANNEL_SMS] as $channel) {
            if (($reached = LoginMessageCaps::reached($channel)) === null) {
                continue;
            }

            $text = match ([$channel, $reached['window']]) {
                [LoginMessageCaps::CHANNEL_EMAIL, 'hour'] => __('login links by e-mail: hourly limit (:limit) reached', ['limit' => $reached['limit']]),
                [LoginMessageCaps::CHANNEL_SMS, 'hour'] => __('SMS login codes: hourly limit (:limit) reached', ['limit' => $reached['limit']]),
                default => __('SMS login codes: daily limit (:limit) reached', ['limit' => $reached['limit']]),
            };
            $detail .= ' · '.$text.' '.$reached['at']->diffForHumans()
                .($reached['refused'] > 0 ? ', '.__(':n not sent', ['n' => $reached['refused']]) : '');
            $capped = true;
        }

        return $failed > 0 || $undeliverable > 0 || $problem !== null || $late || $capped
            ? Check::warn('messages', $label, $detail)
            : Check::ok('messages', $label, $detail);
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

            if (($cfg['auth'] ?? 'basic') === 'ntlm' && ! EwsTransport::ntlmSupported()) {
                return Check::fail('mail', $label, EwsTransport::ntlmUnavailableMessage());
            }

            return $configured
                ? Check::ok('mail', $label, __('EWS (:auth) · from :from', ['auth' => $cfg['auth'] ?? 'basic', 'from' => $from]))
                : Check::fail('mail', $label, __('EWS is selected but URL or credentials are missing'));
        }

        if (in_array($mailer, ['log', 'array'], true)) {
            return Check::warn('mail', $label, __('Mailer ":m": nothing leaves the server', ['m' => $mailer]));
        }

        // SMTP alone or as a leg of a failover/round-robin chain.
        $usesSmtp = $mailer === 'smtp' || in_array('smtp', (array) config("mail.mailers.{$mailer}.mailers", []), true);

        if ($usesSmtp && ! self::smtpRefusesPlainText()) {
            return Check::warn('mail', $label, __('SMTP may send unencrypted: MAIL_REQUIRE_TLS=false, so the SMTP password and every e-mail (login links, sign-in codes) go out in plain text when the server offers no STARTTLS · from :from', ['from' => $from]));
        }

        // Every e-mail stops while the server offers no STARTTLS and MAIL_REQUIRE_TLS is on
        // (SendOutboundMessage::errorText names the key in both languages).
        $noTls = $usesSmtp ? OutboundMessage::query()
            ->where('channel', Channel::Email)
            ->whereIn('status', [OutboundMessageStatus::Failed, OutboundMessageStatus::Queued])
            ->where('updated_at', '>=', now()->subDay())
            ->where('error', 'like', '%MAIL_REQUIRE_TLS%')
            ->count() : 0;

        if ($noTls > 0) {
            return Check::fail('mail', $label, __(':n e-mail(s) of the last 24 hours not sent: the SMTP server offers no STARTTLS, and MAIL_REQUIRE_TLS keeps the password and the messages from going out unencrypted · from :from', ['n' => $noTls, 'from' => $from]));
        }

        return Check::ok('mail', $label, __(':m · from :from', ['m' => $mailer, 'from' => $from]));
    }

    /**
     * Whether the smtp mailer only talks encrypted: direct TLS (MAIL_SCHEME=smtps,
     * or port 465) or required STARTTLS (MAIL_REQUIRE_TLS, on by default). The
     * configuration is read the way the MailManager reads it, MAIL_URL included.
     */
    private static function smtpRefusesPlainText(): bool
    {
        $config = (array) config('mail.mailers.smtp', []);

        if (isset($config['url'])) {
            $config = array_merge($config, (new ConfigurationUrlParser)->parseConfiguration($config));
        }

        return ($config['scheme'] ?? null) === 'smtps'
            || (int) ($config['port'] ?? 0) === 465
            || filter_var($config['require_tls'] ?? false, FILTER_VALIDATE_BOOL);
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

        // Closures the next run brings if the records stay absent: not an
        // error, but the operator should see them coming.
        $closing = ExternalRecord::query()->fromDirectory()->closingAtNextRun(max(1, $this->settings->int(SettingKey::SyncMissedRunsBeforeClose)))->count();
        if ($closing > 0) {
            return Check::warn('sync', $label, $detail.' · '.__(':n account(s) close at the next sync if their EMD record stays absent', ['n' => $closing]));
        }

        // An e-mail the directory renamed to an address another account holds
        // is kept unchanged; the owner cannot sign in until someone sorts it out.
        // A record whose address only the database collation equates with an
        // account's (accents, full-width characters, look-alike domain) gets no account; the holder keeps its own.
        $conflicts = (int) ($last->stats['email_conflicts'] ?? 0);
        if ($last->status === SyncRunStatus::Completed && $conflicts > 0) {
            return Check::warn('sync', $label, $detail.' · '.__(':n e-mail conflict(s) in the last run: an EMD record carries an address another account already holds, possibly written differently (accents, full-width characters, look-alike domain); see the run details', ['n' => $conflicts]));
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
        $active = Client::query()->whereNotNull('package_override')->where('package_override_until', '>=', now())
            ->tap(fn ($query) => app(ClientTiers::class)->scopeServed($query))
            ->with('packageOverrideBy')->orderBy('package_override_until')->get();

        if ($active->isEmpty()) {
            return Check::ok('package_overrides', $label, __('No manual override; every package comes from EMD.'));
        }

        $list = $active->take(5)->map(fn (Client $c) => $c->name.' → '.$c->package_override.' ('.$c->package_override_until?->format(HuDate::DATE).')')->implode(' · ');

        return Check::info('package_overrides', $label, __(':n client(s) with a temporary package override: :list', ['n' => $active->count(), 'list' => $list]));
    }

    public function sharedPhoneNumbers(): Check
    {
        $label = __('Shared phone numbers');
        $count = app(ClientPhones::class)->sharedNumberCount();

        return $count === 0
            ? Check::ok('shared_numbers', $label, __('Every registered number belongs to one client.'))
            : Check::info('shared_numbers', $label, __(':n phone number(s) on file for more than one client; the phone menu recognises nobody from them.', ['n' => $count]));
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

        // A key of a scope that always signs, made before signing became
        // mandatory: every request with it is refused until it gets a secret.
        $unsigned = ApiKey::query()->active()->whereNull('hmac_secret')
            ->whereIn('scope', array_filter(ApiKeyScope::cases(), fn (ApiKeyScope $scope): bool => $scope->signatureRequired()))
            ->pluck('name');

        if ($unsigned->isNotEmpty()) {
            return Check::fail('api_keys', $label, __('Without a signing secret, so every request with them is refused: :names', ['names' => $unsigned->implode(', ')]).' · '.$detail);
        }

        return $missing === [] ? Check::ok('api_keys', $label, $detail) : Check::warn('api_keys', $label, __('No active key for: :list', ['list' => implode(', ', $missing)]).' · '.$detail);
    }

    public function oidc(): Check
    {
        $label = __('Login providers');
        $parts = [];
        $broken = false;
        $noted = false;

        foreach (PrincipalType::cases() as $principal) {
            foreach (OidcProvider::cases() as $provider) {
                $config = $provider->configFor($principal);
                $enabled = $this->settings->bool($provider->enabledSetting($principal));
                $configured = $config->isConfigured();
                $broken = $broken || ($enabled && ! $configured);
                $state = $enabled ? ($configured ? __('on') : __('on, NOT configured')) : __('off');

                // Which claim binds an ADFS sign-in is deployment configuration: show it where it acts.
                if ($provider === OidcProvider::Adfs && $enabled && $configured) {
                    $state .= ' ('.__('e-mail claim: :claims', ['claims' => implode(', ', $config->emailClaims)]).')';
                }

                // Which tenants may sign in, and what is wrong with the list, where it acts.
                if ($provider === OidcProvider::Entra && $enabled) {
                    if ($config->tenantProblem !== null) {
                        $state .= ' ('.__('tenant list: :problem', ['problem' => $config->tenantProblem]).')';
                    } elseif ($configured) {
                        $state .= ' ('.__(':n tenant(s): :list', ['n' => count($config->tenants), 'list' => implode(', ', array_map(fn ($tenant): string => $tenant->describe(), $config->tenants))]).')';
                    }

                    if ($config->tenantNote !== null) {
                        $state .= ' · '.$config->tenantNote;
                        $noted = true;
                    }

                    // A tenant domain the EMD sync files as staff or skips brings no client.
                    if ($principal === PrincipalType::Client && ($domainNote = app(EntraTenantOverview::class)->healthNote()) !== null) {
                        $state .= ' · '.$domainNote;
                        $noted = true;
                    }
                }

                $parts[] = __($principal->value).'/'.$provider->value.': '.$state;
            }
        }

        $detail = implode(' · ', $parts);

        return match (true) {
            $broken => Check::fail('oidc', $label, $detail),
            $noted => Check::warn('oidc', $label, $detail),
            default => Check::ok('oidc', $label, $detail),
        };
    }

    /**
     * The ADFS token log is a debugging aid that writes personal data: a
     * mode of its own, so the provider tile only reflects configuration.
     */
    public function adfsDebug(): Check
    {
        $label = __('ADFS debugging');
        $principals = [];

        foreach (PrincipalType::cases() as $principal) {
            if (OidcProvider::Adfs->configFor($principal)->logsTokens()) {
                $principals[] = __($principal->value);
            }
        }

        if ($principals === []) {
            return Check::ok('adfs_debug', $label, __('Off: ADFS tokens are not written to the log.'));
        }

        return Check::warn('adfs_debug', $label, __('HDID_ADFS_DEBUG is on (:principals): the decoded content of every ADFS token, personal data included, goes to the application log.', ['principals' => implode(', ', $principals)]));
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

    /**
     * Files with secrets that any other local account of the server can
     * read (FilePermissions): on a classic installation a config cache built
     * as the installer, or with umask 022, hands out APP_KEY and every
     * password. The container (one account, read-only image) is not exposed.
     */
    public function filePermissions(): Check
    {
        $label = __('File permissions');

        // A development checkout (demo.sh) is the developer's own: its files are 0644 by design.
        if (app()->environment('local')) {
            return Check::info('file_permissions', $label, __('Not checked in the local development environment (APP_ENV=local).'));
        }

        $exposed = app(FilePermissions::class)->exposed();

        if ($exposed === []) {
            return Check::ok('file_permissions', $label, __('No file with secrets (.env, cached configuration, logs, session files) can be read by other local accounts.'));
        }

        $shown = array_map(fn (string $path): string => str_starts_with($path, base_path().'/') ? substr($path, strlen(base_path()) + 1) : $path, array_slice($exposed, 0, 5));

        return Check::fail('file_permissions', $label, __(':n file(s) with secrets can be read by any local account of the server: :files', [
            'n' => count($exposed),
            'files' => implode(', ', $shown).(count($exposed) > count($shown) ? ' …' : ''),
        ]));
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

    private function syncQueueName(): string
    {
        return (string) config('hdid.sync.queue', 'default');
    }

    /**
     * Hot and cold audit log: rows in the database against the hot window,
     * and the archive files written so far.
     */
    public function auditLog(): Check
    {
        $label = __('Audit log');
        $hotDays = $this->archive->hotDays();
        $cutoff = $this->archive->cutoff();
        $rows = AuditLog::query()->count();
        $oldestRaw = AuditLog::query()->min('created_at');
        $oldest = $oldestRaw ? Carbon::parse($oldestRaw) : null;
        $summary = $this->archive->summary();
        $last = $this->archive->lastRunAt();

        $parts = [
            __(':n entries in the database, :days days kept', ['n' => number_format($rows, 0, ',', ' '), 'days' => $hotDays]),
            __(':files archive files, :size', ['files' => $summary['files'], 'size' => $this->humanBytes($summary['bytes'])]),
            $last === null ? __('archiving has not run yet') : __('archived :when', ['when' => $last->diffForHumans()]),
        ];
        $detail = implode(' · ', $parts);

        // Three days of grace: the nightly task may have been skipped once.
        if ($oldest !== null && $oldest->lt($cutoff->subDays(3))) {
            return Check::warn('audit', $label, $detail.' · '.__('entries from :date are still waiting to be archived', ['date' => $oldest->format(HuDate::DATE)]));
        }

        return Check::ok('audit', $label, $detail);
    }

    /**
     * Activity against the sizing profile: accounts seen in the last quarter
     * of an hour, today's peak of that number, database connections.
     */
    public function capacity(): Check
    {
        $label = __('Capacity');
        $since = now()->subMinutes(15);
        $active = Client::query()->where('last_seen_at', '>=', $since)->count()
            + User::query()->where('last_seen_at', '>=', $since)->count();

        $peakKey = 'hdid.capacity.peak.'.today()->toDateString();
        $peak = max((int) $this->cache->get($peakKey, 0), $active);
        $this->cache->put($peakKey, $peak, now()->addDays(2));

        $parts = [
            __(':n accounts active in the last 15 minutes', ['n' => $active]),
            __('peak today :n', ['n' => $peak]),
        ];
        $warn = false;

        $connections = $this->databaseConnections();
        if ($connections !== null) {
            $parts[] = __('database connections :used / :max', ['used' => $connections['used'], 'max' => $connections['max']]);
            $warn = $warn || ($connections['max'] > 0 && $connections['used'] / $connections['max'] >= 0.8);
        }

        ['figures' => $fpmFigures, 'problems' => $limitProblems] = $this->runtimeLimits($connections);
        if ($fpmFigures !== null) {
            $parts[] = $fpmFigures;
        }
        foreach ($limitProblems as $problem) {
            $parts[] = $problem;
            $warn = true;
        }

        $profile = SizingProfile::current();
        if ($profile === null) {
            $parts[] = __('no sizing profile set (HDID_SIZING_PROFILE)');
        } else {
            $designed = $profile->designedConcurrency();
            $parts[] = __(':profile · designed for :n simultaneous accounts', ['profile' => $profile->label(), 'n' => $designed]);
            $warn = $warn || $peak >= 0.8 * $designed;
        }

        $detail = implode(' · ', $parts);

        return $warn ? Check::warn('capacity', $label, $detail) : Check::ok('capacity', $label, $detail);
    }

    /**
     * The PHP-FPM pool as seen from the request running this check, and the
     * limits that contradict each other: FPM processes × memory_limit above
     * the container's memory limit, or fewer database connections allowed
     * than this web server alone may open. Each problem is one sentence.
     *
     * @param  array{used: int, max: int}|null  $connections
     * @return array{figures: ?string, problems: list<string>}
     */
    private function runtimeLimits(?array $connections): array
    {
        $limits = app(RuntimeLimits::class);
        $figures = null;
        $problems = [];

        $status = $limits->fpmStatus();
        if ($status !== null) {
            $figures = __('PHP-FPM :active of :total processes busy, :queue waiting, all busy :reached time(s) since start', [
                'active' => (int) ($status['active-processes'] ?? 0),
                'total' => (int) ($status['total-processes'] ?? 0),
                'queue' => (int) ($status['listen-queue'] ?? 0),
                'reached' => (int) ($status['max-children-reached'] ?? 0),
            ]);

            if ((int) ($status['listen-queue'] ?? 0) > 0) {
                $problems[] = __('Requests are waiting for a free PHP-FPM process: the web server is at its limit (OPENHDID_FPM_MAX_CHILDREN, or the load is higher than the profile).');
            }
        }

        $children = $limits->fpmMaxChildren();
        $perRequest = $limits->requestMemoryLimit();
        $container = $limits->containerMemoryLimit();

        if ($children !== null && $perRequest !== null && $container !== null) {
            $need = $children * ($perRequest + RuntimeLimits::FPM_PROCESS_OVERHEAD) + $limits->opcacheBytes();

            if ($need > $container) {
                $problems[] = __('PHP-FPM may take :need (:children processes with memory_limit :limit each, and the opcache), more than the memory limit of the web container, :max: under load the kernel stops the whole web server. Lower OPENHDID_FPM_MAX_CHILDREN or OPENHDID_FPM_MEMORY_LIMIT, or raise OPENHDID_WEB_MEM_LIMIT (Kubernetes: the memory limit of the web pod).', [
                    'need' => $this->humanBytes($need),
                    'children' => $children,
                    'limit' => $this->humanBytes($perRequest),
                    'max' => $this->humanBytes($container),
                ]);
            }
        }

        if ($children !== null && $connections !== null && $connections['max'] > 0 && $connections['max'] < $children + self::DB_CONNECTIONS_BESIDES_WEB) {
            $problems[] = __('max_connections is :max, fewer than the :n connections this web server alone may open (:children PHP-FPM processes) with the workers and the scheduler: under load requests fail with "Too many connections". Raise max_connections (OPENHDID_DB_MAX_CONNECTIONS, or the managed database) to at least the PHP-FPM processes of every web server, plus :rest.', [
                'max' => $connections['max'],
                'n' => $children + self::DB_CONNECTIONS_BESIDES_WEB,
                'children' => $children,
                'rest' => self::DB_CONNECTIONS_BESIDES_WEB,
            ]);
        }

        return ['figures' => $figures, 'problems' => $problems];
    }

    /**
     * @return array{used: int, max: int}|null null when the driver has no such counters (SQLite)
     */
    private function databaseConnections(): ?array
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        try {
            $used = DB::selectOne("SHOW STATUS LIKE 'Threads_connected'");
            $max = DB::selectOne("SHOW VARIABLES LIKE 'max_connections'");

            return $used === null || $max === null ? null : ['used' => (int) $used->Value, 'max' => (int) $max->Value];
        } catch (Throwable) {
            return null;
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 1, ',', ' ').' GB';
        }
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 1, ',', ' ').' MB';
        }

        return number_format($bytes / 1024, 0, ',', ' ').' kB';
    }
}
