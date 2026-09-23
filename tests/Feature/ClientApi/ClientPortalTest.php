<?php

use App\Mail\MagicLinkMail;
use App\Models\Client;
use App\Models\NewsPost;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('keeps the client signed in after a magic link without any referer header', function (): void {
    Mail::fake();
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    $url = null;
    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'c@x.hu']);
    Mail::assertSent(MagicLinkMail::class, function ($mail) use (&$url) {
        $url = $mail->url;

        return true;
    });

    $this->get($url)->assertRedirect('/');

    // A plain same-origin request: no Referer, no Origin, only the session cookie.
    $this->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('data.id', $client->id);
    $this->postJson('/api/v1/client/logout')->assertOk();
    $this->getJson('/api/v1/client/me')->assertUnauthorized();
});

it('lets a mobile app use the same endpoints with a bearer token and no csrf', function (): void {
    $client = Client::factory()->synced()->create();
    $token = $client->createToken('phone')->plainTextToken;

    $this->flushSession();
    $this->withToken($token)->postJson('/api/v1/client/mobile-code')->assertOk();
});

it('serves published news to clients without the author', function (): void {
    $author = User::factory()->create(['name' => 'Secret Admin']);
    NewsPost::factory()->create(['title' => 'Old', 'body' => '**bold** text', 'author_user_id' => $author->id, 'published_at' => now()->subDays(2)]);
    NewsPost::factory()->create(['title' => 'New', 'author_user_id' => $author->id, 'published_at' => now()->subHour()]);
    NewsPost::factory()->draft()->create(['title' => 'Draft']);
    NewsPost::factory()->create(['title' => 'Scheduled', 'published_at' => now()->addDay()]);

    $this->getJson('/api/v1/client/news')->assertUnauthorized();

    Sanctum::actingAs(Client::factory()->synced()->create(), guard: 'client');
    $response = $this->getJson('/api/v1/client/news')->assertOk();

    expect($response->json('data.*.title'))->toBe(['New', 'Old'])
        ->and($response->json('data.1.body_html'))->toContain('<strong>bold</strong>')
        ->and($response->getContent())->not->toContain('Secret Admin')
        ->and($response->json('meta.total'))->toBe(2);
});

it('exposes the palette and portal texts through the branding endpoint', function (): void {
    app(Settings::class)->set(SettingKey::PortalPrivacyUrl, 'https://example.test/privacy');

    $this->getJson('/api/v1/branding')->assertOk()
        ->assertJsonPath('branding.palette.primary', '#36495D')
        ->assertJsonPath('branding.portal.privacy_url', 'https://example.test/privacy');
});

it('links the published source only when the deployment names it', function (): void {
    config()->set('hdid.source_url', null);
    $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('branding.portal.source_url', null);

    config()->set('hdid.source_url', 'https://github.com/adamfhun/openhdid/tree/v1.0.0');
    $this->getJson('/api/v1/branding')->assertOk()
        ->assertJsonPath('branding.portal.source_url', 'https://github.com/adamfhun/openhdid/tree/v1.0.0');
});

it('falls back to the shipped icons until a favicon is uploaded', function (): void {
    $this->get('/login')->assertOk()
        ->assertSee('href="'.asset('favicon.svg').'" type="image/svg+xml"', false)
        ->assertSee(asset('favicon.ico'), false)
        ->assertSee(asset('apple-touch-icon.png'), false);
    $this->getJson('/api/v1/branding')->assertJsonPath('branding.favicon_url', asset('favicon.svg'));

    app(Settings::class)->set(SettingKey::BrandingFavicon, 'branding/icon.png');

    $uploaded = Storage::disk('public')->url('branding/icon.png');
    foreach (['/login', '/admin/login'] as $page) {
        $this->get($page)->assertOk()
            ->assertSee('<link rel="icon" href="'.$uploaded.'">', false)
            // iOS would fetch the shipped /apple-touch-icon.png without its own link.
            ->assertSee('<link rel="apple-touch-icon" href="'.$uploaded.'">', false)
            ->assertDontSee('favicon.svg', false)
            ->assertDontSee('apple-touch-icon.png', false);
    }
    $this->getJson('/api/v1/branding')->assertJsonPath('branding.favicon_url', $uploaded);
});

it('gives the staff panel the same shipped icons as the portal', function (): void {
    $this->get('/admin/login')->assertOk()
        ->assertSee('href="'.asset('favicon.svg').'" type="image/svg+xml"', false)
        ->assertSee(asset('favicon.ico'), false)
        ->assertSee(asset('apple-touch-icon.png'), false);
});

it('sends an expired or tampered magic link to the branded login page', function (): void {
    $this->get('/auth/client/magic/'.str_repeat('a', 64).'?signature=bogus&expires=1')
        ->assertRedirect('/login?error=invalid_credentials');
});

it('offers only configured and enabled SSO providers on the client portal', function (): void {
    app(Settings::class)->set(SettingKey::ClientSsoAdfsEnabled, true);
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, true);
    config()->set('hdid.oidc.client.adfs', ['issuer' => 'https://idp.test/adfs', 'client_id' => 'client-app']);
    config()->set('hdid.oidc.client.entra', ['tenant' => '', 'client_id' => 'client-app']);

    $this->getJson('/api/v1/branding')->assertOk()
        ->assertJsonPath('login_methods.adfs', true)->assertJsonPath('login_methods.entra', false);

    app(Settings::class)->set(SettingKey::ClientSsoAdfsEnabled, false);
    $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('login_methods.adfs', false);
});
