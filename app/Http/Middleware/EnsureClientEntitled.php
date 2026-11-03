<?php

namespace App\Http\Middleware;

use App\Audit\Auditor;
use App\Auth\AccountLogin;
use App\Auth\LoginRejection;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A client keeps portal access only while the login rule would still let it
 * in: open, not a demo account in production, with a matching EMD record
 * (unless CLIENT_LOGIN_REQUIRE_EMD is off), and with a package listed in the
 * settings. The sync or the switch may change any of these at any time, so
 * this is checked on every request; a client that lost access is signed out
 * on the spot.
 */
class EnsureClientEntitled
{
    /** The reasons that come from the EMD part of the rule. */
    private const DIRECTORY_REASONS = [LoginRejection::NoExternalRecord, LoginRejection::ExternalRecordMissing, LoginRejection::ExternalRecordMismatch];

    public function __construct(
        private readonly AccountLogin $login,
        private readonly Auditor $auditor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user();

        if ($client instanceof Client && ($rejection = $this->login->ongoingRejectionFor($client->loadMissing('externalRecord'))) !== null) {
            $client->currentAccessToken()?->delete();
            $revoked = $client->revokeAccess();

            // Closure, a lost package and the demo rule are audited where they
            // happen; an EMD record that stopped matching is noticed here.
            if (in_array($rejection, self::DIRECTORY_REASONS, true)) {
                $this->auditor->record('account.access_revoked', $client, ['reason' => $rejection->value, 'emd_required' => true] + $revoked);
            }

            if ($request->hasSession()) {
                Auth::guard('client')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json(['message' => __($rejection->message()), 'reason' => $rejection->value], 403);
        }

        return $next($request);
    }
}
