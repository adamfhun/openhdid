<?php

use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Portal access is package-gated; factories give clients the "Premium" package.
        app(Settings::class)->set(SettingKey::PackagesPremium, ['Premium']);
        app(Settings::class)->set(SettingKey::PackagesStandard, ['Basic']);
    })
    ->in('Feature');

/**
 * Press the button on the login link's landing page: the token sits in the
 * link's fragment and is posted, as the portal does.
 */
function signInWithLink(string $url): TestResponse
{
    preg_match('/#token=([A-Za-z0-9]+)$/', $url, $match);

    return test()->postJson('/api/v1/client/auth/magic-link/consume', ['token' => $match[1] ?? '']);
}
