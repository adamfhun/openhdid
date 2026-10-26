<?php

use App\Mail\MagicLinkMail;
use App\Models\Client;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->withHeader('Referer', config('app.url'));
    Mail::fake();
});

/**
 * Run requests the way a deployed instance sees them: the framework leaves
 * the host check off in local development and in tests.
 */
function asDeployed(Closure $requests): void
{
    app()['env'] = 'production';
    // The debug page would print this file's source, and with it every expected text.
    config()->set('app.debug', false);

    try {
        $requests();
    } finally {
        app()['env'] = 'testing';
        // The trusted host list is static on the Symfony request; never let it leak into other tests.
        Request::setTrustedHosts([]);
    }
}

/**
 * The provider applies the configured root once, at boot; a test that
 * changes APP_URL or the environment applies it again.
 */
function applyConfiguredRootUrl(): void
{
    (fn () => $this->useConfiguredRootUrl())->call(app()->getProvider(AppServiceProvider::class));
}

it('builds the e-mailed login link from APP_URL whatever Host the request names', function (): void {
    Client::factory()->synced()->create(['email' => 'victim@x.hu']);
    $root = rtrim((string) config('app.url'), '/');

    $this->postJson('http://evil.example/api/v1/client/auth/magic-link', ['email' => 'victim@x.hu'])->assertOk();
    // A forwarding proxy on 127.0.0.1 (the trusted default) passes the client's X-Forwarded-Host on.
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeader('X-Forwarded-Host', 'evil2.example')
        ->postJson('/api/v1/client/auth/magic-link', ['email' => 'victim@x.hu'])->assertOk();

    $urls = [];
    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$urls): bool {
        $urls[] = $mail->url;

        return true;
    });

    expect($urls)->toHaveCount(2);
    foreach ($urls as $url) {
        expect($url)->toStartWith($root.'/premium/login/link#token=')->not->toContain('evil');
    }
});

it('starts every generated address with APP_URL, not with the Host of the request', function (): void {
    $root = rtrim((string) config('app.url'), '/');

    $this->get('http://evil.example/up')->assertOk();
    expect(url('/admin'))->toBe($root.'/admin')
        ->and(route('locale.switch', 'hu'))->toBe($root.'/locale/hu');

    $this->withHeader('X-Forwarded-Host', 'evil2.example')->get('/up')->assertOk();
    expect(url('/admin'))->toBe($root.'/admin');
});

it('keeps the https scheme of an https APP_URL behind a proxy that forwards plain http', function (): void {
    config()->set('app.url', 'https://hdid.example.test');
    applyConfiguredRootUrl();

    $this->get('http://hdid.example.test/up')->assertOk();

    expect(url('/premium/login/link'))->toBe('https://hdid.example.test/premium/login/link');
});

it('leaves the address of the request alone in local development', function (): void {
    app()['env'] = 'local';
    URL::forceRootUrl(null);
    applyConfiguredRootUrl();

    $this->get('http://192.168.1.20:8000/up')->assertOk();

    expect(url('/admin'))->toBe('http://192.168.1.20:8000/admin');
    app()['env'] = 'testing';
});

it('refuses a request naming an unknown host outside local development', function (): void {
    asDeployed(function (): void {
        $this->get('/up')->assertOk();
        // The refusal names its reason, so a wrong APP_URL is easy to spot.
        $this->get('http://evil.example/up')->assertStatus(400)
            ->assertSee('Unknown host name: this address is not set up for the application.');
        $this->postJson('http://evil.example/api/v1/client/auth/magic-link', ['email' => 'victim@x.hu'])
            ->assertStatus(400)->assertJsonPath('reason', 'unknown_host');
        $this->withHeader('X-Forwarded-Host', 'evil2.example')->get('/up')->assertStatus(400);
    });

    Mail::assertNothingSent();
});

it('accepts the extra host names and the internal liveness probe of the container', function (): void {
    config()->set('app.url', 'https://hdid.example.test');
    config()->set('hdid.extra_hosts', ['ivr.internal.example', '*.hdid.example.test', '10.0.0.5']);

    asDeployed(function (): void {
        $this->get('https://hdid.example.test/up')->assertOk();
        $this->get('http://ivr.internal.example/up')->assertOk();
        $this->get('http://portal.hdid.example.test/up')->assertOk();
        $this->get('http://10.0.0.5/up')->assertOk();
        // The image's HEALTHCHECK asks the internal port with "Host: localhost".
        $this->get('http://localhost/up')->assertOk();

        $this->get('http://hdid.example.test.evil.example/up')->assertStatus(400);
        $this->get('http://10.0.0.6/up')->assertStatus(400);
    });
});

it('builds the login link from APP_URL by itself, even where no root URL is forced (local)', function (): void {
    Client::factory()->synced()->create(['email' => 'victim@x.hu']);
    $root = rtrim((string) config('app.url'), '/');
    URL::forceRootUrl(null);

    try {
        $this->postJson('http://evil.example/api/v1/client/auth/magic-link', ['email' => 'victim@x.hu'])->assertOk();
    } finally {
        applyConfiguredRootUrl();
    }

    Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail): bool => str_starts_with($mail->url, $root.'/') && ! str_contains($mail->url, 'evil.example'));
});
