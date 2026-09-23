<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies
|--------------------------------------------------------------------------
|
| Reverse proxies whose X-Forwarded-* headers are trusted, read by the
| framework's TrustProxies middleware at request time (config is loaded by
| then; bootstrap/app.php runs too early for env() or config()). No load
| balancer is assumed: the local web server proxy is enough by default.
| Comma-separated list, or '*' to trust every caller.
|
*/

return [
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1'),
];
