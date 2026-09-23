<?php

namespace App\Http\Middleware;

use App\Auth\LoginRejection;
use App\Clients\ClientTiers;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A client keeps portal access only while one of its packages is listed in
 * settings. The sync may change packages at any time, so this is checked on
 * every request; a client that lost access, or whose account was closed in
 * the meantime, is signed out on the spot.
 */
class EnsureClientEntitled
{
    public function __construct(private readonly ClientTiers $tiers) {}

    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user();

        if ($client instanceof Client && ($client->isClosed() || ! $this->tiers->isEntitled($client))) {
            $client->currentAccessToken()?->delete();
            $client->revokeAccess();

            if ($request->hasSession()) {
                Auth::guard('client')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $rejection = $client->isClosed() ? LoginRejection::AccountClosed : LoginRejection::NotEntitled;

            return response()->json(['message' => __($rejection->message()), 'reason' => $rejection->value], 403);
        }

        return $next($request);
    }
}
