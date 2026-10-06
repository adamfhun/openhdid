<?php

namespace App\Http\Controllers\Auth;

use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Links e-mailed before the landing page existed: opening one no longer
 * signs in (a mail scanner would use it up), it leads to the landing page
 * of the client's own entry with the link still unused.
 */
class MagicLinkController extends Controller
{
    public function __invoke(string $token, ClientPasswordlessLogin $passwordless): RedirectResponse
    {
        $landing = $passwordless->landingUrlForToken($token);

        return redirect($landing ?? '/login?error=invalid_credentials');
    }
}
