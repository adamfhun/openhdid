<?php

use App\Branding\Branding;
use App\Http\Controllers\Api\V1\Client\AnswersController;
use App\Http\Controllers\Api\V1\Client\MobileCodeController;
use App\Http\Controllers\Api\V1\Client\NewsController;
use App\Http\Controllers\Api\V1\Client\PasswordlessController;
use App\Http\Controllers\Api\V1\Client\PhoneNumbersController;
use App\Http\Controllers\Api\V1\Client\PinController;
use App\Http\Controllers\Api\V1\Client\SessionController;
use App\Http\Controllers\Auth\MagicLinkController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\TierSwitchController;
use Illuminate\Support\Facades\Route;

Route::get('locale/{locale}', LocaleController::class)->name('locale.switch');
Route::post('tier/{tier}', TierSwitchController::class)->middleware('auth:web')->name('tier.switch');
Route::redirect('/helpdesk', '/admin');

Route::prefix('auth')->middleware('throttle:30,1,sso')->group(function (): void {
    Route::get('{principal}/{provider}/redirect', [SsoController::class, 'redirect'])->name('sso.redirect');
    Route::get('{principal}/{provider}/callback', [SsoController::class, 'callback'])->name('sso.callback');
    Route::get('client/magic/{token}', MagicLinkController::class)->middleware('signed')->name('client.magic-link');
});

/*
 * Client-site API. It lives on the session (web) stack so that the same-origin
 * SPA and the magic link share one cookie session; mobile apps use a Sanctum
 * token on the very same routes.
 */
Route::prefix('api/v1/client')->name('api.v1.client.')->group(function (): void {
    Route::middleware('throttle:5,1,client-auth')->group(function (): void {
        Route::post('auth/magic-link', [PasswordlessController::class, 'requestMagicLink'])->name('auth.magic-link');
        Route::post('auth/otp/request', [PasswordlessController::class, 'requestOtp'])->name('auth.otp.request');
        Route::post('auth/otp/verify', [PasswordlessController::class, 'verifyOtp'])->name('auth.otp.verify');
    });

    Route::middleware(['auth:client,sanctum', 'principal:client', 'entitled'])->group(function (): void {
        Route::get('me', [SessionController::class, 'me'])->name('me');
        Route::post('logout', [SessionController::class, 'logout'])->name('logout');
        Route::post('logout-all', [SessionController::class, 'logoutAll'])->name('logout-all');

        Route::get('questions', [AnswersController::class, 'index'])->name('questions');
        Route::put('answers/{question}', [AnswersController::class, 'store'])->name('answers.store');
        Route::delete('answers/{question}', [AnswersController::class, 'destroy'])->name('answers.destroy');

        // The uniqueness check answers "taken" or "free" for any candidate,
        // so this endpoint needs a bucket of its own.
        Route::put('pin', [PinController::class, 'store'])->middleware('throttle:10,1,pin-change')->name('pin.store');
        Route::delete('pin', [PinController::class, 'destroy'])->middleware('throttle:10,1,pin-change')->name('pin.destroy');

        Route::post('phone-numbers', [PhoneNumbersController::class, 'store'])->middleware('throttle:10,1,phone-add')->name('phone-numbers.store');
        Route::delete('phone-numbers/{phoneNumber}', [PhoneNumbersController::class, 'destroy'])->name('phone-numbers.destroy');
        Route::post('phone-numbers/{phoneNumber}/primary', [PhoneNumbersController::class, 'makePrimary'])->name('phone-numbers.primary');
        Route::post('phone-numbers/{phoneNumber}/verify/request', [PhoneNumbersController::class, 'requestVerification'])->middleware('throttle:3,10,phone-verify-request')->name('phone-numbers.verify.request');
        Route::post('phone-numbers/{phoneNumber}/verify', [PhoneNumbersController::class, 'verify'])->middleware('throttle:10,10,phone-verify')->name('phone-numbers.verify');

        Route::get('mobile-code', [MobileCodeController::class, 'show'])->name('mobile-code.show');
        Route::post('mobile-code', [MobileCodeController::class, 'store'])->middleware('throttle:6,1,mobile-code')->name('mobile-code');
        Route::get('news', [NewsController::class, 'index'])->name('news');
    });
});

Route::get('/{any?}', fn (Branding $branding) => view('client', ['branding' => $branding]))
    ->where('any', '^(?!admin|helpdesk|api|auth|livewire|docs|locale|up|vendor|storage).*$')
    ->name('client.spa');
