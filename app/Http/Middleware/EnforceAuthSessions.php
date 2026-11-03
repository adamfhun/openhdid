<?php

namespace App\Http\Middleware;

use App\Auth\AuthSessions;
use App\Enums\PrincipalType;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Absolute session lifetime and "sign out everywhere", before authentication.
 */
class EnforceAuthSessions
{
    public function __construct(private readonly AuthSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $endedByClosure = $this->sessions->enforce($request);
        $response = $next($request);

        // A closed staff member lands on the panel's login page, whose
        // password form answers neutrally: the notice there says why the
        // session ended. Sent after the request ran, so in its language.
        if (in_array(PrincipalType::User, $endedByClosure, true)) {
            Notification::make()->danger()->persistent()
                ->title(__('Signed out.'))
                ->body(__('This account has been closed.'))
                ->send();
        }

        return $response;
    }
}
