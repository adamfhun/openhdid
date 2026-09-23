<?php

use App\Http\Controllers\Api\V1\Auth\EntraTokenController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\CallCenter\CallController;
use App\Http\Controllers\Api\V1\CallCenter\IvrController;
use App\Http\Controllers\Api\V1\Mobile\IvrCodeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('branding', BrandingController::class)->name('branding');

    Route::post('auth/{principal}/entra/token', EntraTokenController::class)
        ->middleware('throttle:10,1,entra-token')
        ->name('auth.entra.token');

    Route::prefix('callcenter')->name('callcenter.')->middleware(['throttle:machine', 'api-key:callcenter'])->group(function (): void {
        Route::match(['get', 'post'], 'calls', [CallController::class, 'upsert'])->name('calls.upsert');
        Route::get('calls/{callId}', [CallController::class, 'show'])->name('calls.show');
        Route::match(['get', 'post'], 'calls/{callId}/end', [CallController::class, 'end'])->name('calls.end');
        Route::get('lookup', [CallController::class, 'lookup'])->withoutMiddleware('throttle:machine')->middleware('throttle:ivr')->name('lookup');
        Route::match(['get', 'post'], 'ivr/verify-pin', [IvrController::class, 'verifyPin'])->withoutMiddleware('throttle:machine')->middleware('throttle:ivr')->name('ivr.verify-pin');
        Route::match(['get', 'post'], 'ivr/verify-code', [IvrController::class, 'verifyCode'])->withoutMiddleware('throttle:machine')->middleware('throttle:ivr')->name('ivr.verify-code');
    });

    Route::prefix('mobile')->name('mobile.')->middleware(['throttle:api-key', 'api-key:mobile_backend'])->group(function (): void {
        Route::match(['get', 'post'], 'ivr-code', IvrCodeController::class)->name('ivr-code');
    });

});
