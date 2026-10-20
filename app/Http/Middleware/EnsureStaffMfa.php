<?php

namespace App\Http\Middleware;

use App\Auth\Mfa\StaffMfa;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * While two-factor sign-in is on, a staff session that has not passed the
 * second factor reaches nothing but the factor pages and the logout. It
 * runs on every panel request, Livewire updates included (persistent panel
 * middleware), and on the staff routes outside the panel, so no login path
 * (password, ADFS, Entra, remember-me cookie) can step past it.
 */
class EnsureStaffMfa
{
    /** @var list<string> */
    public const OPEN_ROUTES = ['filament.admin.mfa.challenge', 'filament.admin.mfa.setup', 'filament.admin.auth.logout'];

    public function __construct(private readonly StaffMfa $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user instanceof User || ! $request->hasSession() || $request->routeIs(...self::OPEN_ROUTES) || $this->mfa->isSatisfied($user)) {
            return $next($request);
        }

        if (! $request->isMethod('GET') || $request->hasHeader('X-Livewire') || $request->expectsJson()) {
            throw new AccessDeniedHttpException(__('Verify your sign-in with a second factor first.'));
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route($this->mfa->needsSetup($user) ? 'filament.admin.mfa.setup' : 'filament.admin.mfa.challenge');
    }
}
