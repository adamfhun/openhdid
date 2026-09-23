<?php

use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Portal access is package-gated; factories give clients the "Premium" package.
        app(Settings::class)->set(SettingKey::PackagesPremium, ['Premium']);
        app(Settings::class)->set(SettingKey::PackagesStandard, ['Basic']);
    })
    ->in('Feature');
