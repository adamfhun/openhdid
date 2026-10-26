<?php

use App\Audit\Auditor;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two installations on one Valkey with the same key prefix (the test cache
 * store is the shared one), each with a database of its own. The databases
 * are fresh SQLite connections outside the test transaction, because the
 * settings only use the shared cache outside a transaction.
 *
 * @param  array<string, bool>  $mfaByInstallation
 */
function twoInstallationsOnOneCache(array $mfaByInstallation): void
{
    foreach ($mfaByInstallation as $name => $mfa) {
        config()->set("database.connections.shared_{$name}", ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        Schema::connection("shared_{$name}")->create('settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
        DB::connection("shared_{$name}")->table('settings')->insert(['key' => SettingKey::UserLoginMfaEnabled->value, 'value' => json_encode($mfa)]);
    }
}

function switchToInstallation(string $name): void
{
    config()->set([
        'database.default' => "shared_{$name}",
        'app.key' => 'base64:'.base64_encode(str_pad($name, 32, '-')),
    ]);
}

it('serves every installation its own settings from a cache store they share', function (): void {
    $default = config('database.default');
    $appKey = config('app.key');
    twoInstallationsOnOneCache(['production' => true, 'staging' => false]);
    $production = new Settings(Cache::store(), app(Auditor::class));
    $staging = new Settings(Cache::store(), app(Auditor::class));

    try {
        // Staging switched the second factor off and is the first to read: it fills the shared cache.
        switchToInstallation('staging');
        expect(DB::transactionLevel())->toBe(0)
            ->and($staging->bool(SettingKey::UserLoginMfaEnabled))->toBeFalse();

        // The next production request must get its own table, not the staging map.
        switchToInstallation('production');
        $production->forgetLocal();
        expect($production->bool(SettingKey::UserLoginMfaEnabled))->toBeTrue('production keeps its second factor');

        // A staging save renews only the staging version; production keeps reading its own values.
        switchToInstallation('staging');
        $staging->forget();
        expect($staging->bool(SettingKey::UserLoginMfaEnabled))->toBeFalse();

        switchToInstallation('production');
        $production->forgetLocal();
        expect($production->bool(SettingKey::UserLoginMfaEnabled))->toBeTrue();
    } finally {
        config()->set(['database.default' => $default, 'app.key' => $appKey]);
    }
});
