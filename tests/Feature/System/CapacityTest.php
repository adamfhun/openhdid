<?php

use App\Auth\Role;
use App\Http\Middleware\TouchLastSeen;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\System\CheckStatus;
use App\System\HealthChecks;
use App\System\RuntimeLimits;
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

/**
 * The PHP-FPM pool and container limits as the panel's request would see them.
 *
 * @param  array<string, mixed>|null  $status
 */
function fakeRuntimeLimits(?array $status, ?int $children, ?int $perRequest, ?int $container): void
{
    app()->instance(RuntimeLimits::class, new class($status, $children, $perRequest, $container) extends RuntimeLimits
    {
        /**
         * @param  array<string, mixed>|null  $status
         */
        public function __construct(private ?array $status, private ?int $children, private ?int $perRequest, private ?int $container) {}

        public function inFpm(): bool
        {
            return true;
        }

        public function fpmStatus(): ?array
        {
            return $this->status;
        }

        public function fpmMaxChildren(): ?int
        {
            return $this->children;
        }

        public function requestMemoryLimit(): ?int
        {
            return $this->perRequest;
        }

        public function containerMemoryLimit(): ?int
        {
            return $this->container;
        }

        public function opcacheBytes(): int
        {
            return 208 * 1_048_576;
        }
    });
}

it('shows the PHP-FPM pool on the panel and warns while requests wait for a free process', function (): void {
    config()->set('hdid.profile', null);
    fakeRuntimeLimits(['active-processes' => 3, 'total-processes' => 12, 'listen-queue' => 0, 'max-children-reached' => 2], 12, 128 * 1_048_576, 2688 * 1_048_576);

    $check = app(HealthChecks::class)->capacity();
    expect($check->status)->toBe(CheckStatus::Ok)
        ->and($check->detail)->toContain('PHP-FPM 3 of 12 processes busy, 0 waiting, all busy 2 time(s) since start');

    fakeRuntimeLimits(['active-processes' => 12, 'total-processes' => 12, 'listen-queue' => 9, 'max-children-reached' => 40], 12, 128 * 1_048_576, 2688 * 1_048_576);
    $check = app(HealthChecks::class)->capacity();
    expect($check->status)->toBe(CheckStatus::Warn)
        ->and($check->detail)->toContain('9 waiting', 'waiting for a free PHP-FPM process');
});

it('warns when the PHP-FPM processes may take more memory than the web container has', function (): void {
    config()->set('hdid.profile', null);
    // 16 processes × (256 MB + overhead) + opcache is far above a 1.5 GB limit.
    fakeRuntimeLimits(null, 16, 256 * 1_048_576, 1536 * 1_048_576);

    $check = app(HealthChecks::class)->capacity();

    expect($check->status)->toBe(CheckStatus::Warn)
        ->and($check->detail)->toContain('16 processes with memory_limit 256,0 MB', '1,5 GB', 'OPENHDID_FPM_MAX_CHILDREN', 'OPENHDID_WEB_MEM_LIMIT');

    // The shipped 5k sizing: 12 × 128 MB within 2688 MB.
    fakeRuntimeLimits(null, 12, 128 * 1_048_576, 2688 * 1_048_576);
    expect(app(HealthChecks::class)->capacity()->status)->toBe(CheckStatus::Ok);
});

it('says nothing about the pool where no PHP-FPM runs the check', function (): void {
    config()->set('hdid.profile', null);

    expect(app(RuntimeLimits::class)->inFpm())->toBeFalse()
        ->and(app(RuntimeLimits::class)->fpmStatus())->toBeNull()
        ->and(app(HealthChecks::class)->capacity()->detail)->not->toContain('PHP-FPM')
        ->and(RuntimeLimits::bytes('128M'))->toBe(134_217_728)
        ->and(RuntimeLimits::bytes('1G'))->toBe(1_073_741_824)
        ->and(RuntimeLimits::bytes('-1'))->toBe(-1);
});

it('warns when max_connections is below what one web server opens with the workers', function (): void {
    fakeRuntimeLimits(null, 12, 128 * 1_048_576, null);
    $problems = fn (int $max): array => (fn (array $connections): array => $this->runtimeLimits($connections))
        ->call(app(HealthChecks::class), ['used' => 5, 'max' => $max])['problems'];

    expect($problems(15))->toHaveCount(1)
        ->and($problems(15)[0])->toContain('max_connections is 15', '20 connections', 'OPENHDID_DB_MAX_CONNECTIONS')
        ->and($problems(40))->toBe([]);
});
