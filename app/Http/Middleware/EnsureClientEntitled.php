<?php

namespace App\Http\Middleware;

use App\Auth\LoginRejection;
use App\Clients\ClientTiers;
use App\Models\Client;
use App\System\DemoData;
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

        if ($client instanceof Client && ($rejection = $this->rejectionFor($client)) !== null) {
            $client->currentAccessToken()?->delete();
            $client->revokeAccess();

            if ($request->hasSession()) {
                Auth::guard('client')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => __($rejection->message()), 'reason' => $rejection->value], 403);
        }

        return $next($request);
    }

    /**
     * Why the signed-in client may not go on, if at all. A demo account
     * (DemoData) is refused in production on every request, so a session
     * or token from before the switch to production stops working too.
     */
    private function rejectionFor(Client $client): ?LoginRejection
    {
        return match (true) {
            $client->isClosed() => LoginRejection::AccountClosed,
            DemoData::refusedHere() && DemoData::isDemoAccount($client) => LoginRejection::DemoAccount,
            ! $this->tiers->isEntitled($client) => LoginRejection::NotEntitled,
            default => null,
        };
    }
}
