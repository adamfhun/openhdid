<?php

/**
 * Cross-origin resource sharing: off on every path. No browser client calls the
 * application from another origin (the portal and the panel are same-origin, the
 * telephony system, the IVR and the mobile back end call it server to server), so
 * no response carries an Access-Control-Allow-Origin header. The framework default
 * allowed any origin on api/*, the cookie-authenticated client API included.
 */
return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
