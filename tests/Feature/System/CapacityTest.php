<?php

use App\Auth\Role;
use App\Http\Middleware\TouchLastSeen;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\System\CheckStatus;
use App\System\HealthChecks;
use App\System\SizingProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;

it('stamps the last activity of a signed-in client once in five minutes', function (): void {
    $this->withHeader('Referer', config('app.url'));
    $client = Client::factory()->synced()->create();
    $this->actingAs($client, 'client');

    $this->freezeSecond();
    $this->getJson('/api/v1/client/me')->assertOk();
    $first = $client->fresh()->last_seen_at;
    expect($first)->not->toBeNull()
        ->and($first->getTimestamp())->toBe(now()->getTimestamp());

    $this->travel(2)->minutes();
    $this->getJson('/api/v1/client/me')->assertOk();
    expect($client->fresh()->last_seen_at->getTimestamp())->toBe($first->getTimestamp(), 'debounced');

    $this->travel(TouchLastSeen::DEBOUNCE_SECONDS)->seconds();
    $this->getJson('/api/v1/client/me')->assertOk();
    expect($client->fresh()->last_seen_at->getTimestamp())->toBeGreaterThan($first->getTimestamp());
});

it('stamps the last activity of a staff member on panel requests without an audit row', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($admin)->get('/admin')->assertOk();

    expect($admin->fresh()->last_seen_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event', 'user.updated')->exists())->toBeFalse();
});

it('counts the active accounts and compares the peak with the sizing profile', function (): void {
    Client::factory()->count(3)->create(['last_seen_at' => now()->subMinutes(2)]);
    Client::factory()->create(['last_seen_at' => now()->subMinutes(20)]);
    User::factory()->create(['last_seen_at' => now()->subMinute()]);
    $checks = app(HealthChecks::class);

    config()->set('hdid.profile', null);
    $check = $checks->capacity();
    expect($check->status)->toBe(CheckStatus::Ok)
        ->and($check->detail)->toContain('4 accounts active')
        ->and($check->detail)->toContain('peak today 4')
        ->and($check->detail)->toContain('no sizing profile');

    config()->set('hdid.profile', '5k');
    expect($checks->capacity()->status)->toBe(CheckStatus::Ok)
        ->and($checks->capacity()->detail)->toContain('designed for 400');

    // The peak of the day is remembered, and 80 % of the design point turns the tile yellow.
    Cache::put('hdid.capacity.peak.'.today()->toDateString(), 320, now()->addDay());
    $check = $checks->capacity();
    expect($check->status)->toBe(CheckStatus::Warn)
        ->and($check->detail)->toContain('peak today 320');
});

it('maps the sizing profiles to their design points', function (): void {
    expect(SizingProfile::Small->designedConcurrency())->toBe(400)
        ->and(SizingProfile::Medium->designedConcurrency())->toBe(800)
        ->and(SizingProfile::Large->designedConcurrency())->toBe(1600);

    config()->set('hdid.profile', ' 20K ');
    expect(SizingProfile::current())->toBe(SizingProfile::Large);
    config()->set('hdid.profile', 'huge');
    expect(SizingProfile::current())->toBeNull();
});

it('includes the audit and capacity tiles with hints in the health report', function (): void {
    $keys = collect(app(HealthChecks::class)->all())->keyBy('key');

    expect($keys->has('audit'))->toBeTrue()
        ->and($keys->has('capacity'))->toBeTrue()
        ->and($keys->get('audit')->hint)->toContain('hdid:prune')
        ->and($keys->get('capacity')->hint)->toContain('HDID_SIZING_PROFILE');
});
