<?php

use App\Auth\Role;
use App\Enums\IdSessionStatus;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use App\Reporting\DashboardCounters;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('computes the dashboard counters once per poll interval for everyone with the same view', function (): void {
    $first = User::factory()->withRole(Role::Supervisor)->create(['handles_tiers' => ['premium']]);
    $second = User::factory()->withRole(Role::Supervisor)->create(['handles_tiers' => ['premium']]);
    Call::factory()->count(2)->create(['tier' => 'premium']);
    $client = Client::factory()->withPin()->create();
    IdSession::factory()->create(['client_id' => $client->id, 'status' => IdSessionStatus::Passed, 'started_at' => now()]);
    IdSession::factory()->create(['client_id' => $client->id, 'status' => IdSessionStatus::Failed, 'started_at' => now()->subDays(2)]);

    $counters = app(DashboardCounters::class);
    $one = $counters->forUser($first);
    expect($one['ongoing'])->toBe(2)
        ->and($one['identifications']['total'])->toBe(1)
        ->and($one['identifications']['passed'])->toBe(1)
        ->and($one['identifications']['trend'])->toHaveCount(7)
        ->and($one['clients'])->toMatchArray(['total' => 1, 'with_pin' => 1]);

    // Only the permission lookup of the second agent hits the database; the
    // counters themselves come from the shared copy.
    DB::enableQueryLog();
    expect($counters->forUser($second))->toBe($one);
    $counterQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => preg_match('/from "(calls|id_sessions|clients)"/', $sql) === 1);
    DB::disableQueryLog();
    expect($counterQueries->all())->toBe([], 'the second agent with the same view reads the shared copy');

    // A new call shows up once the interval has passed.
    Call::factory()->create(['tier' => 'premium']);
    expect($counters->forUser($first)['ongoing'])->toBe(2);
    Cache::flush();
    expect($counters->forUser($first)['ongoing'])->toBe(3);
});

it('follows the dashboard poll interval and hides what the user may not see', function (): void {
    app(Settings::class)->set(SettingKey::CallsDashboardPollSeconds, 9);
    $counters = app(DashboardCounters::class);
    expect($counters->ttlSeconds())->toBe(9);

    // A staff member without the identification and client permissions gets the call tiles only.
    $agent = User::factory()->create(['handles_tiers' => ['premium']]);
    $result = $counters->forUser($agent);
    expect($result['identifications'])->toBeNull()
        ->and($result['clients'])->toBeNull()
        ->and($result)->toHaveKeys(['ongoing', 'missed']);
});
