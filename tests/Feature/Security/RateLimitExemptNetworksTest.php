<?php

use App\Auth\Role;
use App\Filament\Pages\Auth\Login;
use App\Mail\MagicLinkMail;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Support\RateLimitExemptions;
use App\System\CheckGroup;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
 * A company may send every user from one address (NAT): the networks of
 * HDID_RATE_LIMIT_EXEMPT_NETWORKS are free of the limits keyed on the client
 * address, never of the limits per e-mail address, phone number or system.
 * Requests in tests come from 127.0.0.1.
 */

beforeEach(function (): void {
    // The client site is same-origin, so Sanctum treats its requests as stateful.
    $this->withHeader('Referer', config('app.url'));
    Mail::fake();
});

function exemptNetworks(string $value): RateLimitExemptions
{
    config()->set('hdid.rate_limit_exempt_networks', $value);

    return app(RateLimitExemptions::class);
}

it('reads IPv4 and IPv6 networks separated by commas or spaces, an empty value exempting nobody', function (): void {
    $exemptions = exemptNetworks(" 10.0.0.0/8, 192.0.2.7\n2001:db8::/32  ");

    expect($exemptions->networks())->toBe(['10.0.0.0/8', '192.0.2.7', '2001:db8::/32'])
        ->and($exemptions->invalid())->toBe([])
        ->and($exemptions->covers('10.20.30.40'))->toBeTrue()
        ->and($exemptions->covers('192.0.2.7'))->toBeTrue()
        ->and($exemptions->covers('192.0.2.8'))->toBeFalse()
        ->and($exemptions->covers('2001:db8:1::5'))->toBeTrue()
        ->and($exemptions->covers('2001:db9::5'))->toBeFalse()
        ->and($exemptions->covers(null))->toBeFalse();

    expect(exemptNetworks('')->networks())->toBe([])
        ->and(exemptNetworks('')->covers('127.0.0.1'))->toBeFalse();
});

it('refuses an invalid or too wide network and then exempts nobody at all', function (string $entry): void {
    $exemptions = exemptNetworks('10.0.0.0/8 '.$entry);

    expect($exemptions->invalid())->toBe([$entry])
        ->and($exemptions->networks())->toBe([])
        ->and($exemptions->covers('10.1.1.1'))->toBeFalse();
})->with(['*', '0.0.0.0/0', '::/0', '10.0.0.0/7', '1.0.0.0/1', '2001::/31', '2000::/3', '10.0.0.300/24', 'example.org', '10.0.0.0/33', '10.0.0.0/x', '2001:db8::/129']);

it('lets an exempt network past the address limit of the sign-in requests, but not past the limit per e-mail address', function (): void {
    exemptNetworks('127.0.0.0/8');
    Client::factory()->synced()->create(['email' => 'a@x.hu']);

    foreach (range(1, 8) as $attempt) {
        $this->postJson('/api/v1/client/auth/magic-link', ['email' => "nobody{$attempt}@x.hu"])->assertOk();
    }

    foreach (range(1, 6) as $attempt) {
        $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'a@x.hu'])->assertOk();
    }

    Mail::assertSent(MagicLinkMail::class, 5);
    expect(AuditLog::query()->where('event', 'login.request_throttled')->count())->toBe(1);
});

it('keeps the address limit for a network outside the list', function (): void {
    exemptNetworks('10.0.0.0/8');

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/client/auth/magic-link', ['email' => "nobody{$attempt}@x.hu"])->assertOk();
    }

    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'nobody6@x.hu'])->assertStatus(429);
});

it('keeps the address limit while the list holds an invalid entry', function (): void {
    exemptNetworks('127.0.0.0/8 0.0.0.0/0');

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/client/auth/magic-link', ['email' => "nobody{$attempt}@x.hu"])->assertOk();
    }

    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'nobody6@x.hu'])->assertStatus(429);
});

it('exempts the company sign-in routes too', function (): void {
    $sso = fn () => $this->get('/auth/client/entra/redirect');

    exemptNetworks('127.0.0.1');
    foreach (range(1, 31) as $attempt) {
        expect($sso()->getStatusCode())->not->toBe(429);
    }

    exemptNetworks('');
    foreach (range(1, 30) as $attempt) {
        expect($sso()->getStatusCode())->not->toBe(429);
    }
    expect($sso()->getStatusCode())->toBe(429);
});

it('keeps the named limiters of the machine interfaces for an exempt network', function (): void {
    exemptNetworks('127.0.0.0/8');
    RateLimiter::for('exempt-test', fn (Request $request): Limit => Limit::perMinute(1)->by('exempt-test:'.$request->ip()));
    // Under /api: the client site's catch-all route answers every other path.
    Route::middleware('throttle:exempt-test')->get('/api/exempt-test', fn () => 'ok');

    $this->get('/api/exempt-test')->assertOk();
    $this->get('/api/exempt-test')->assertStatus(429);
});

it('lets staff of an exempt network past the panel\'s per-address login limit, the account lockout still counting', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $user = User::factory()->synced()->withRole(Role::Agent)->create(['password' => 'right-password-1']);
    $attempt = function (string $email, string $password) {
        Auth::guard('web')->logout();

        return Livewire::test(Login::class)->fillForm(['email' => $email, 'password' => $password])->call('authenticate');
    };

    exemptNetworks('127.0.0.0/8');
    foreach (range(1, 6) as $i) {
        $attempt("nobody{$i}@corp.hu", 'whatever');
    }
    expect(AuditLog::query()->where('event', 'login.rejected')->count())->toBe(6, 'every attempt reached the account check');

    foreach (range(1, 5) as $i) {
        $attempt($user->email, 'wrong-password');
    }
    expect($user->fresh()->isLoginLocked())->toBeTrue();

    exemptNetworks('10.0.0.0/8');
    foreach (range(1, 5) as $i) {
        $attempt("someone{$i}@corp.hu", 'whatever');
    }
    $before = AuditLog::query()->where('event', 'login.rejected')->count();
    $attempt('someone6@corp.hu', 'whatever');
    expect(AuditLog::query()->where('event', 'login.rejected')->count())->toBe($before, 'outside the list the per-address limit stops the attempt first');
});

it('lists the exempt networks on the status page as information, and an invalid list in red', function (): void {
    $checks = app(HealthChecks::class);

    exemptNetworks('');
    $none = $checks->rateLimitExemptions();
    expect($none->status)->toBe(CheckStatus::Ok)
        ->and($none->group)->toBe(CheckGroup::Modes);

    exemptNetworks('10.0.0.0/8, 2001:db8::/32');
    $listed = $checks->rateLimitExemptions();
    expect($listed->status)->toBe(CheckStatus::Info)
        ->and($listed->detail)->toContain('10.0.0.0/8', '2001:db8::/32');

    exemptNetworks('10.0.0.0/8 0.0.0.0/0');
    $refused = $checks->rateLimitExemptions();
    expect($refused->status)->toBe(CheckStatus::Fail)
        ->and($refused->detail)->toContain('0.0.0.0/0')
        ->and(HealthChecks::hint('rate_limit_exemptions'))->toContain('HDID_RATE_LIMIT_EXEMPT_NETWORKS', 'TRUSTED_PROXIES', 'OPENHDID_TLS=off');

    expect(collect($checks->all())->pluck('key'))->toContain('rate_limit_exemptions');
});

it('documents the exempt networks in both environment samples', function (): void {
    expect((string) file_get_contents(base_path('.env.example')))->toMatch('/^HDID_RATE_LIMIT_EXEMPT_NETWORKS=$/m');

    if (is_file(base_path('deploy/openhdid/repo/.env.example'))) {
        expect((string) file_get_contents(base_path('deploy/openhdid/repo/.env.example')))->toMatch('/^HDID_RATE_LIMIT_EXEMPT_NETWORKS=$/m');
    }
});
