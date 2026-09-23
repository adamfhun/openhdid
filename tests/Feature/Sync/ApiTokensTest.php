<?php

use App\Sync\ApiTokens;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->freezeTime();
    config()->set([
        'hdid.sync.driver' => 'spreadsheet',
        'hdid.sync.token_keep_alive' => true,
        'hdid.sync.login_url' => 'https://directory.test/login',
        'hdid.sync.refresh_url' => 'https://directory.test/refresh',
        'hdid.sync.username' => 'directory-user',
        'hdid.sync.password' => 'directory-secret',
    ]);
    Http::preventStrayRequests();
});

it('logs in once and rotates both tokens using the latest refresh token', function (bool $keepAlive): void {
    config()->set('hdid.sync.token_keep_alive', $keepAlive);
    Cache::setDefaultDriver('database');
    Http::fake([
        'https://directory.test/login' => Http::response(['access_token' => 'access-one', 'refresh_token' => 'refresh-one', 'roles' => ['ignored-role']]),
        'https://directory.test/refresh' => Http::sequence()
            ->push(['access_token' => 'access-two', 'refresh_token' => 'refresh-two'])
            ->push(['access_token' => 'access-three', 'refresh_token' => 'refresh-three']),
    ]);
    $tokens = app(ApiTokens::class);

    expect($tokens->accessToken())->toBe('access-one');
    expect($tokens->accessToken())->toBe('access-one');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://directory.test/login'
        && $request->method() === 'POST'
        && $request->data() === ['Password' => 'directory-secret', 'Username' => 'directory-user']);

    $this->travel(15)->minutes();
    expect($tokens->accessToken())->toBe('access-two');
    $tokens->refresh();
    expect($tokens->accessToken())->toBe('access-three');
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://directory.test/refresh'
        && $request->data() === ['refreshToken' => 'refresh-two']);

    $persisted = DB::table('cache')->get()->toJson();
    expect($persisted)->not->toContain('access-three', 'refresh-three', 'directory-secret', 'ignored-role');
    expect($tokens->status()['error'])->toBeNull();
})->with([true, false]);

it('attempts login exactly once when the refresh token is rejected', function (int $status): void {
    Http::fake([
        'https://directory.test/login' => Http::sequence()
            ->push(['access_token' => 'old-access', 'refresh_token' => 'old-refresh'])
            ->push(['access_token' => 'new-access', 'refresh_token' => 'new-refresh']),
        'https://directory.test/refresh' => Http::response(['error' => 'expired'], $status),
    ]);
    $tokens = app(ApiTokens::class);
    $tokens->accessToken();
    $tokens->refresh();

    expect($tokens->accessToken())->toBe('new-access');
    Http::assertSentCount(3);
})->with([400, 401, 403]);

it('stops after the single replacement login fails and does not retain response secrets', function (): void {
    Http::fake([
        'https://directory.test/login' => Http::sequence()
            ->push(['access_token' => 'old', 'refresh_token' => 'old-refresh'])
            ->push(['echoed_secret' => 'do-not-record'], 401),
        'https://directory.test/refresh' => Http::response([], 401),
    ]);
    $tokens = app(ApiTokens::class);
    $tokens->accessToken();

    expect(fn () => $tokens->refresh())->toThrow(RuntimeException::class);
    expect($tokens->status()['error'])->not->toContain('do-not-record');
    Http::assertSentCount(3);
});

it('logs in again once whatever makes the refresh fail', function (string $failure): void {
    Http::fake([
        'https://directory.test/login' => Http::sequence()
            ->push(['access_token' => 'old', 'refresh_token' => 'old-refresh'])
            ->push(['access_token' => 'fresh', 'refresh_token' => 'fresh-refresh']),
        'https://directory.test/refresh' => match ($failure) {
            'connection' => Http::failedConnection(),
            'malformed body' => Http::response(['access_token' => 'half']),
            default => Http::response(['secret' => 'not-for-logs'], (int) $failure),
        },
    ]);
    $tokens = app(ApiTokens::class);
    $tokens->accessToken();

    $tokens->refresh();

    expect($tokens->accessToken())->toBe('fresh')
        ->and($tokens->status()['error'])->toBeNull()
        ->and(app(HealthChecks::class)->syncAuthentication()->status)->toBe(CheckStatus::Ok);
    expect(Http::recorded(fn (Request $request) => $request->url() === 'https://directory.test/login'))->toHaveCount(2);
})->with(['connection', '500', '503', '422', 'malformed body']);

it('reports a failed replacement login without retaining response secrets', function (): void {
    Http::fake([
        'https://directory.test/login' => Http::sequence()
            ->push(['access_token' => 'old', 'refresh_token' => 'old-refresh'])
            ->push(['secret' => 'not-for-logs'], 503),
        'https://directory.test/refresh' => Http::response(['secret' => 'not-for-logs'], 503),
    ]);
    $tokens = app(ApiTokens::class);
    $tokens->accessToken();

    expect(fn () => $tokens->refresh())->toThrow(RuntimeException::class);
    expect(app(HealthChecks::class)->syncAuthentication()->status)->toBe(CheckStatus::Fail);
    expect($tokens->status()['error'])->not->toContain('not-for-logs', 'old-refresh');
    expect(Http::recorded(fn (Request $request) => $request->url() === 'https://directory.test/login'))->toHaveCount(2);
});

it('rejects malformed token responses', function (array $response): void {
    Http::fake(['https://directory.test/login' => Http::response($response)]);
    expect(fn () => app(ApiTokens::class)->accessToken())->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
})->with([
    [['access_token' => 'a']],
    [['access_token' => ' ', 'refresh_token' => 'r']],
    [['access_token' => ['unexpected'], 'refresh_token' => 'r']],
]);

it('reports refresh availability and staleness without making a health-check request', function (): void {
    Http::fake(['https://directory.test/login' => Http::response(['access_token' => 'a', 'refresh_token' => 'r'])]);
    $checks = app(HealthChecks::class);
    expect($checks->syncAuthentication()->status)->toBe(CheckStatus::Warn);

    $this->artisan('hdid:emd-sync-token')->assertSuccessful();
    expect($checks->syncAuthentication()->status)->toBe(CheckStatus::Ok);
    $this->travel(21)->minutes();
    expect($checks->syncAuthentication()->status)->toBe(CheckStatus::Fail);
    Http::assertSentCount(1);

    config()->set('hdid.sync.username', null);
    expect($checks->syncAuthentication()->status)->toBe(CheckStatus::Fail);
    config()->set('hdid.sync.driver', 'json');
    expect($checks->syncAuthentication()->status)->toBe(CheckStatus::Ok);
});

it('runs scheduled token maintenance only for the enabled spreadsheet integration', function (string $driver, bool $keepAlive, bool $runs): void {
    config()->set(['hdid.sync.driver' => $driver, 'hdid.sync.token_keep_alive' => $keepAlive]);
    $event = collect(app(Schedule::class)->events())->sole(fn ($event): bool => $event->description === 'hdid:emd-sync-token');
    $this->travelTo(now()->startOfHour());

    expect($event->filtersPass(app()))->toBe($runs)
        ->and($event->isDue(app()))->toBeTrue();
    $this->travel(7)->minutes();
    expect($event->isDue(app()))->toBeFalse();
    $this->travel(8)->minutes();
    expect($event->isDue(app()))->toBeTrue();
})->with([
    'enabled spreadsheet' => ['spreadsheet', true, true],
    'disabled spreadsheet' => ['spreadsheet', false, false],
    'legacy JSON with keep-alive' => ['json', true, false],
    'legacy JSON without keep-alive' => ['json', false, false],
]);

it('allows manual refresh with keep-alive off and ignores age without hiding authentication failures', function (): void {
    config()->set('hdid.sync.token_keep_alive', false);
    Http::fake([
        'https://directory.test/login' => Http::sequence()
            ->push(['access_token' => 'a', 'refresh_token' => 'r'])
            ->pushStatus(503),
        'https://directory.test/refresh' => Http::sequence()
            ->push(['access_token' => 'b', 'refresh_token' => 's'])
            ->pushStatus(503),
    ]);
    $checks = app(HealthChecks::class);
    // Unproven credentials warn even without the keep-alive.
    expect($checks->syncAuthentication()->status)->toBe(CheckStatus::Warn);
    $this->artisan('hdid:emd-sync-token')->assertSuccessful();
    $this->artisan('hdid:emd-sync-token')->assertSuccessful();
    expect(app(ApiTokens::class)->accessToken())->toBe('b');
    $this->travel(3)->hours();
    expect($checks->syncAuthentication()->status)->toBe(CheckStatus::Ok);
    Http::assertSentCount(2);

    // The refresh fails, the one replacement login fails too.
    $this->artisan('hdid:emd-sync-token')->assertFailed();
    expect($checks->syncAuthentication()->status)->toBe(CheckStatus::Fail);
    Http::assertSentCount(4);
    config()->set('hdid.sync.username', null);
    expect($checks->syncAuthentication()->status)->toBe(CheckStatus::Fail);
});

it('keeps the former command names as aliases', function (): void {
    Http::fake(['https://directory.test/login' => Http::response(['access_token' => 'a', 'refresh_token' => 'r'])]);

    expect(Artisan::all())->toHaveKeys(['hdid:sync', 'hdid:sync-token', 'hdid:emd-sync', 'hdid:emd-sync-token']);
    $this->artisan('hdid:sync-token')->assertSuccessful();
    expect(app(ApiTokens::class)->status()['error'])->toBeNull();
});
