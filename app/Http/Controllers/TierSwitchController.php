<?php

namespace App\Http\Controllers;

use App\Enums\ClientTier;
use App\Models\User;
use App\Support\SafeRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lets a staff member choose which service level they are working on right
 * now; "all" clears the choice.
 */
class TierSwitchController extends Controller
{
    public function __invoke(Request $request, string $tier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->switchTier(ClientTier::tryFrom($tier));

        return redirect()->to(SafeRedirect::back('/admin'));
    }
}
