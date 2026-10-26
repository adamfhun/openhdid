<?php

use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\ThrottleRequestsUnlessExempt;
use App\Models\User;
use App\Support\ClientAddress;
use App\Support\RateLimitExemptions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/*
 * One subscriber gets a whole IPv6 /64 and may use any address in it, so the
 * limits keyed on the client address count the /64 network, as the
 * container's nginx does: the application's route throttles, the address
 * ceiling of the machine interfaces and the panel's login limit. An IPv4
 * address counts alone, an IPv4-mapped IPv6 address as its IPv4 address.
 */

beforeEach(function (): void {
    // The client site is same-origin, so Sanctum treats its requests as stateful.
    $this->withHeader('Referer', config('app.url'));
    Mail::fake();
});

it('keys an IPv6 address by its /64 network and an IPv4 address by itself', function (?string $address, string $key): void {
    expect(ClientAddress::limitKey($address))->toBe($key);
})->with([
    'full IPv6' => ['2001:db8:1:2:3:4:5:6', '2001:db8:1:2::/64'],
    'compressed IPv6' => ['2001:db8:1:2::9', '2001:db8:1:2::/64'],
    'zero groups in the network' => ['2001:db8::1:2:3:4', '2001:db8::/64'],
    'upper case and leading zeros' => ['2001:0DB8:0001:0002::ABCD', '2001:db8:1:2::/64'],
    'IPv4' => ['192.0.2.7', '192.0.2.7'],
    'IPv4-mapped IPv6' => ['::ffff:192.0.2.7', '192.0.2.7'],
    'loopback IPv6' => ['::1', '::/64'],
    'not an address' => ['unknown', 'unknown'],
    'none' => [null, ''],
]);

it('counts the sign-in requests of one IPv6 /64 together, whichever address of it they come from', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => "2001:db8:1:2::{$attempt}"])
            ->postJson('/api/v1/client/auth/magic-link', ['email' => "nobody{$attempt}@x.hu"])->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:aaaa:bbbb:cccc:dddd'])
        ->postJson('/api/v1/client/auth/magic-link', ['email' => 'nobody6@x.hu'])->assertStatus(429);

    // Another /64 (another subscriber) and an IPv4 client keep their own budget.
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::1'])
        ->postJson('/api/v1/client/auth/magic-link', ['email' => 'nobody7@x.hu'])->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.7'])
        ->postJson('/api/v1/client/auth/magic-link', ['email' => 'nobody8@x.hu'])->assertOk();
});

it('keeps the per-user throttle signature of a signed-in user', function (): void {
    $middleware = new class(app(Illuminate\Cache\RateLimiter::class), app(RateLimitExemptions::class)) extends ThrottleRequestsUnlessExempt
    {
        public function signature(Request $request): string
        {
            return $this->resolveRequestSignature($request);
        }
    };
    $request = Request::create('/x', 'POST', server: ['REMOTE_ADDR' => '2001:db8:1:2::5']);
    $request->setRouteResolver(fn () => (new Route('POST', '/x', fn () => null))->bind($request));

    expect($middleware->signature($request))->toBe(sha1('|2001:db8:1:2::/64'));

    $user = User::factory()->make(['id' => 42]);
    $request->setUserResolver(fn () => $user);

    expect($middleware->signature($request))->toBe(sha1('42'));
});

it('counts the address ceiling of the machine interfaces per IPv6 /64', function (): void {
    $limits = RateLimiter::limiter('machine')(Request::create('/api/v1/callcenter/events', 'POST', server: ['REMOTE_ADDR' => '2001:db8:1:2::77']));

    expect($limits[0]->key)->toBe('machine-ip:2001:db8:1:2::/64');
});

it('counts the panel\'s login attempts per IPv6 /64', function (): void {
    $login = new class extends Login
    {
        public function key(): string
        {
            return $this->getRateLimitKey('authenticate', Login::class);
        }
    };

    request()->server->set('REMOTE_ADDR', '2001:db8:1:2::1');
    $first = $login->key();
    request()->server->set('REMOTE_ADDR', '2001:db8:1:2:ffff::9');
    $second = $login->key();
    request()->server->set('REMOTE_ADDR', '2001:db8:1:3::1');
    $other = $login->key();

    expect($first)->toBe($second)->and($other)->not->toBe($first);
});

it('looks an IPv4-mapped address up as IPv4 in the exempt networks, as nginx does', function (): void {
    config()->set('hdid.rate_limit_exempt_networks', '::/32 192.0.2.0/24');
    $exemptions = app(RateLimitExemptions::class);

    expect($exemptions->covers('::ffff:198.51.100.1'))->toBeFalse()
        ->and($exemptions->covers('::ffff:192.0.2.9'))->toBeTrue()
        ->and($exemptions->covers('::5'))->toBeTrue();
});
