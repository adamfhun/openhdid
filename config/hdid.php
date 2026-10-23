<?php

/*
|--------------------------------------------------------------------------
| HDID application configuration
|--------------------------------------------------------------------------
|
| Static, deployment-level configuration. Runtime business decisions live in
| the `settings` table and are edited from the admin panel (see
| App\Settings\SettingKey for keys and defaults).
|
*/

/*
| Enterprise Master Data (EMD) variables are EMD_SYNC_*; the former SYNC_*
| names stay accepted as aliases. A filled new name wins, an empty one falls
| back, so a blank line copied from .env.example cannot hide a live value.
*/
$emdLegacy = [];
$emd = function (string $name, mixed $default = null) use (&$emdLegacy): mixed {
    $value = env('EMD_SYNC_'.$name);
    if ($value !== null && $value !== '') {
        return $value;
    }

    $legacy = env('SYNC_'.$name);
    if ($legacy !== null && $legacy !== '') {
        $emdLegacy[] = 'SYNC_'.$name;

        return $legacy;
    }

    return $value ?? $default;
};

// Writes every ADFS token's decoded content to the application log (both account types).
$adfsDebug = filter_var(env('HDID_ADFS_DEBUG', false), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;

$sync = [
    // Cron expression for the scheduled EMD sync; empty = never.
    'cron' => $emd('CRON') ?: null,
    'token_keep_alive' => filter_var($emd('TOKEN_KEEP_ALIVE', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
    'api_url' => $emd('API_URL'),
    'api_token' => $emd('API_TOKEN'),
    'api_timeout' => (int) $emd('API_TIMEOUT', 60),
    'driver' => $emd('DRIVER', 'spreadsheet'),
    'login_url' => $emd('LOGIN_URL'),
    'refresh_url' => $emd('REFRESH_URL'),
    'username' => $emd('USERNAME'),
    'password' => $emd('PASSWORD'),
    'auth_timeout' => (int) $emd('AUTH_TIMEOUT', 20),
    'export_format' => $emd('EXPORT_FORMAT', 'xlsx'),
    'export_timeout' => (int) $emd('EXPORT_TIMEOUT', 600),
    // ID list query (e.g. organizations) whose selected IDs fill {{ ids }} in the export payload.
    'id_list_url' => $emd('ID_LIST_URL'),
    'csv_delimiter' => $emd('CSV_DELIMITER', ','),
    // Queue of the panel-started EMD sync job. A dedicated queue with its own worker keeps a
    // long sync from delaying e-mails and SMS; "default" keeps single-worker installs running.
    'queue' => env('EMD_SYNC_QUEUE') ?: 'default',
];

return [
    // Release version of the running build, set by the container image; shown on the status page only.
    'version' => env('HDID_VERSION') ?: null,

    // The usage profile the deployment was sized for (5k, 10k, 20k; see the operations
    // manual): the capacity tile of the system status compares the measured activity with it.
    'profile' => env('HDID_SIZING_PROFILE') ?: null,

    // Public source repository of the running build. When set, the portal and panel footers link to
    // it, which is how a network service offers its source under the AGPL-3.0 (section 13).
    'source_url' => env('HDID_SOURCE_URL') ?: null,

    // Whether the footers show the "Source code" link at all. Only an explicit off value hides it,
    // so an empty SHOW_SC line copied from .env.example keeps the link.
    'show_source_url' => ! in_array(env('SHOW_SC', true), [false, 0, '0', 'false', 'off', 'no'], true),

    // Client logins need a present EMD record carrying the account's e-mail. False lets clients
    // sign in without one (closure, packages and the per-request checks stay); staff are not affected.
    'client_login' => [
        'require_emd' => filter_var(env('CLIENT_LOGIN_REQUIRE_EMD', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
    ],

    // Staff MFA: "amr" values of a verified SSO ID token that prove the provider already asked for a
    // second factor (Entra "mfa", ADFS "multipleauthn"); HDID_MFA_PROVIDER_AMR adds more, comma-separated.
    'mfa' => [
        'provider_amr' => array_values(array_unique(array_filter([
            'mfa',
            'http://schemas.microsoft.com/claims/multipleauthn',
            ...array_map('trim', explode(',', (string) env('HDID_MFA_PROVIDER_AMR', ''))),
        ]))),
    ],

    'phone' => [
        'default_region' => env('HDID_PHONE_REGION', 'HU'),
    ],

    // Proxy of the application's outbound HTTP(S) requests (master data, Exchange, SMS gateway, ADFS,
    // Entra), e.g. http://proxy.example.org:3128, with a password http://user:password@proxy.example.org:3128.
    // Empty: every request goes direct, whatever HTTP_PROXY or HTTPS_PROXY the process inherited.
    'http' => [
        'proxy' => env('HDID_HTTP_PROXY') ?: null,
        // Hosts reached without the proxy, comma-separated: a name covers its subdomains too
        // (example.org, .example.org), an IP address or a range (10.0.0.0/8); * means all.
        'no_proxy' => env('HDID_NO_PROXY') ?: null,
    ],

    'sync' => $sync + [
        // Former SYNC_* names in use, shown as a note on the status page.
        'legacy_env' => $emdLegacy,
    ],

    'callcenter' => [
        'api_key' => env('CALLCENTER_API_KEY'),
        'hmac_secret' => env('CALLCENTER_HMAC_SECRET'),
        'signature_ttl' => (int) env('CALLCENTER_SIGNATURE_TTL', 300),
    ],

    'ews' => [
        'url' => env('EWS_URL'),
        'auth' => env('EWS_AUTH', 'basic'), // basic | ntlm | oauth
        'username' => env('EWS_USERNAME'),
        'password' => env('EWS_PASSWORD'),
        'oauth' => [
            'tenant' => env('EWS_OAUTH_TENANT'),
            'client_id' => env('EWS_OAUTH_CLIENT_ID'),
            'client_secret' => env('EWS_OAUTH_CLIENT_SECRET'),
            'scope' => env('EWS_OAUTH_SCOPE', 'https://outlook.office365.com/.default'),
        ],
        'verify_tls' => (bool) env('EWS_VERIFY_TLS', true),
    ],

    'security' => [
        // Content-Security-Policy header; keep it on in production, add the
        // Vite dev server or other hosts to the extra sources when needed.
        'csp_enabled' => (bool) env('HDID_CSP_ENABLED', env('APP_ENV') !== 'local'),
        'csp_extra_sources' => env('HDID_CSP_EXTRA_SOURCES', ''),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'), // ozeki | log
        // Seconds after sending at which the gateway is asked for the delivery report, comma-separated, at most five.
        'delivery_checks' => env('SMS_DELIVERY_CHECKS'),
    ],

    'ozeki' => [
        'url' => env('OZEKI_URL', 'http://127.0.0.1:9501/api'),
        'username' => env('OZEKI_USERNAME'),
        'password' => env('OZEKI_PASSWORD'),
        // The HTTP API action that returns a message's status by its id, and the status words meaning delivered / undeliverable.
        'status_action' => env('OZEKI_STATUS_ACTION'),
        'delivered_statuses' => env('OZEKI_DELIVERED_STATUSES'),
        'undelivered_statuses' => env('OZEKI_UNDELIVERED_STATUSES'),
    ],

    'oidc' => [
        // A company sign-in without a stable subject (Entra oid, ADFS sub) is refused; false signs it in unbound.
        'require_subject' => filter_var(env('HDID_SSO_REQUIRE_SUBJECT', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
        'user' => [
            // Which claim finds the account: "email" (default) or "employee_id" (compared with the EMD external id).
            'match' => env('USER_SSO_MATCH'),
            // The claim carrying the employee id in employee_id mode; empty: employeeId.
            'employee_id_claim' => env('USER_SSO_EMPLOYEE_ID_CLAIM'),
            'adfs' => [
                'issuer' => env('USER_ADFS_ISSUER'),
                'client_id' => env('USER_ADFS_CLIENT_ID'),
                'client_secret' => env('USER_ADFS_CLIENT_SECRET'),
                // Claims compared with the account's e-mail address, in order (comma-separated); empty: the default list.
                'email_claims' => env('USER_ADFS_EMAIL_CLAIM'),
                // Scopes requested on top of "openid profile email", space- or comma-separated.
                'scopes' => env('USER_ADFS_SCOPES'),
                // Web API identifier sent as the "resource" parameter; empty: none sent.
                'resource' => env('USER_ADFS_RESOURCE'),
                'debug' => $adfsDebug,
            ],
            'entra' => [
                'tenant' => env('USER_ENTRA_TENANT'),
                'client_id' => env('USER_ENTRA_CLIENT_ID'),
                'client_secret' => env('USER_ENTRA_CLIENT_SECRET'),
            ],
        ],
        'client' => [
            'match' => env('CLIENT_SSO_MATCH'),
            'employee_id_claim' => env('CLIENT_SSO_EMPLOYEE_ID_CLAIM'),
            'adfs' => [
                'issuer' => env('CLIENT_ADFS_ISSUER'),
                'client_id' => env('CLIENT_ADFS_CLIENT_ID'),
                'client_secret' => env('CLIENT_ADFS_CLIENT_SECRET'),
                // Claims compared with the account's e-mail address, in order (comma-separated); empty: the default list.
                'email_claims' => env('CLIENT_ADFS_EMAIL_CLAIM'),
                // Scopes requested on top of "openid profile email", space- or comma-separated.
                'scopes' => env('CLIENT_ADFS_SCOPES'),
                // Web API identifier sent as the "resource" parameter; empty: none sent.
                'resource' => env('CLIENT_ADFS_RESOURCE'),
                'debug' => $adfsDebug,
            ],
            'entra' => [
                'tenant' => env('CLIENT_ENTRA_TENANT'),
                // Several tenants, comma-separated; a tenant followed by ":domain|domain" may only present those addresses.
                'tenants' => env('CLIENT_ENTRA_TENANTS'),
                'client_id' => env('CLIENT_ENTRA_CLIENT_ID'),
                'client_secret' => env('CLIENT_ENTRA_CLIENT_SECRET'),
                'mobile_client_id' => env('CLIENT_ENTRA_MOBILE_CLIENT_ID'),
            ],
        ],
    ],
];
