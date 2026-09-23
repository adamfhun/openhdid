<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginRejectedException;
use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MagicLinkController extends Controller
{
    public function __invoke(Request $request, string $token, ClientPasswordlessLogin $passwordless): RedirectResponse
    {
        try {
            $passwordless->consumeMagicLink($token);
        } catch (LoginRejectedException $e) {
            return redirect('/login?error='.$e->reason->value);
        }

        $request->session()->regenerate();

        return redirect('/');
    }
}
