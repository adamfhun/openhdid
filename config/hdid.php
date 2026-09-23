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
    'csv_delimiter' => $emd('CSV_DELIMITER', ','),
    // Queue of the panel-started EMD sync job. A dedicated queue with its own worker keeps a
    // long sync from delaying e-mails and SMS; "default" keeps single-worker installs running.
    'queue' => env('EMD_SYNC_QUEUE') ?: 'default',
];

return [
    // Release version of the running build, set by the container image; shown on the status page only.
    'version' => env('HDID_VERSION') ?: null,

    // Public source repository of the running build. When set, the portal and panel footers link to
    // it, which is how a network service offers its source under the AGPL-3.0 (section 13).
    'source_url' => env('HDID_SOURCE_URL') ?: null,

    'phone' => [
        'default_region' => env('HDID_PHONE_REGION', 'HU'),
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
    ],

    'ozeki' => [
        'url' => env('OZEKI_URL', 'http://127.0.0.1:9501/api'),
        'username' => env('OZEKI_USERNAME'),
        'password' => env('OZEKI_PASSWORD'),
    ],

    'oidc' => [
        'user' => [
            'adfs' => [
                'issuer' => env('USER_ADFS_ISSUER'),
                'client_id' => env('USER_ADFS_CLIENT_ID'),
                'client_secret' => env('USER_ADFS_CLIENT_SECRET'),
            ],
            'entra' => [
                'tenant' => env('USER_ENTRA_TENANT'),
                'client_id' => env('USER_ENTRA_CLIENT_ID'),
                'client_secret' => env('USER_ENTRA_CLIENT_SECRET'),
                'mobile_client_id' => env('USER_ENTRA_MOBILE_CLIENT_ID'),
            ],
        ],
        'client' => [
            'adfs' => [
                'issuer' => env('CLIENT_ADFS_ISSUER'),
                'client_id' => env('CLIENT_ADFS_CLIENT_ID'),
                'client_secret' => env('CLIENT_ADFS_CLIENT_SECRET'),
            ],
            'entra' => [
                'tenant' => env('CLIENT_ENTRA_TENANT'),
                'client_id' => env('CLIENT_ENTRA_CLIENT_ID'),
                'client_secret' => env('CLIENT_ENTRA_CLIENT_SECRET'),
                'mobile_client_id' => env('CLIENT_ENTRA_MOBILE_CLIENT_ID'),
            ],
        ],
    ],
];
