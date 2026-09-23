<?php

namespace App\Http\Controllers;

use App\Localization\SetLocale;
use App\Support\SafeRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, SetLocale::SUPPORTED, true), 404);

        return redirect()->to(SafeRedirect::back('/'))->withCookie(cookie()->forever(SetLocale::COOKIE, $locale, httpOnly: false));
    }
}
