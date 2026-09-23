<?php

namespace App\Auth\Oidc;

/**
 * The identity provider could not be reached or answered with a server
 * error: a temporary outage, not a problem with the user's credentials.
 */
class OidcUnavailableException extends OidcException {}
