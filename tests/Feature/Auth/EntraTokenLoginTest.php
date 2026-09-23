<?php

use App\Auth\Oidc\OidcProvider;
use App\Auth\Role;
use App\Enums\PrincipalType;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeOidc;

const ENTRA_DISCOVERY = 'https://login.microsoftonline.com/tenant-1/v2.0/.well-known/openid-configuration';
const ENTRA_ISSUER = 'https://login.microsoftonline.com/tenant-1/v2.0';

beforeEach(function (): void {
    config()->set('hdid.oidc.client.entra', ['tenant' => 'tenant-1', 'client_id' => 'web-app', 'client_secret' => 's', 'mobile_client_id' => 'mobile-app']);
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, true);
});

it('exchanges a valid Entra id token for an api token', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'c@x.hu']);
    FakeOidc::fake([], 'https://login.microsoftonline.com/tenant-1/v2.0', ENTRA_DISCOVERY);
    $idToken = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => 'tenant-1', 'preferred_username' => 'C@x.hu'], ENTRA_ISSUER);

    $response = $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $idToken, 'device_name' => 'iPhone']);

    $response->assertOk()->assertJsonPath('email', 'c@x.hu');
    $token = $response->json('token');

    $this->flushSession();
    $this->withToken($token)->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('data.id', $client->id);
});

it('rejects a token for the wrong audience or an unknown account', function (): void {
    FakeOidc::fake([], 'https://login.microsoftonline.com/tenant-1/v2.0', ENTRA_DISCOVERY);

    $wrongAud = FakeOidc::idToken(['aud' => 'web-app', 'tid' => 'tenant-1', 'preferred_username' => 'c@x.hu'], ENTRA_ISSUER);
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $wrongAud, 'device_name' => 'x'])->assertUnprocessable();

    $unknown = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => 'tenant-1', 'preferred_username' => 'nobody@x.hu'], ENTRA_ISSUER);
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $unknown, 'device_name' => 'x'])
        ->assertForbidden()->assertJsonPath('reason', 'no_account');
});

it('rejects a token issued by another tenant even when signature, audience and e-mail match', function (): void {
    Client::factory()->synced()->create(['email' => 'c@x.hu']);
    FakeOidc::fake([], ENTRA_ISSUER, ENTRA_DISCOVERY);

    $foreign = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => 'attacker-tenant', 'preferred_username' => 'c@x.hu', 'email' => 'c@x.hu', 'xms_edov' => true], ENTRA_ISSUER);
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $foreign, 'device_name' => 'x'])
        ->assertUnprocessable()->assertJsonPath('errors.id_token.0', 'ID token tenant mismatch.');

    $noTenant = FakeOidc::idToken(['aud' => 'mobile-app', 'preferred_username' => 'c@x.hu'], ENTRA_ISSUER);
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $noTenant, 'device_name' => 'x'])->assertUnprocessable();
});

it('trusts the entra email claim only when the tenant marks it verified', function (): void {
    $client = Client::factory()->synced()->create(['email' => 'real@x.hu']);
    FakeOidc::fake([], ENTRA_ISSUER, ENTRA_DISCOVERY);

    // Unverified mail attribute pointing at someone else: the UPN decides, which is nobody.
    $spoofed = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => 'tenant-1', 'email' => 'real@x.hu', 'preferred_username' => 'me@other.hu'], ENTRA_ISSUER);
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $spoofed, 'device_name' => 'x'])
        ->assertForbidden()->assertJsonPath('reason', 'no_account');

    // Verified e-mail (xms_edov) is accepted even when the UPN differs.
    $verified = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => 'tenant-1', 'email' => 'real@x.hu', 'xms_edov' => true, 'preferred_username' => 'alias@x.hu'], ENTRA_ISSUER);
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $verified, 'device_name' => 'x'])
        ->assertOk()->assertJsonPath('email', 'real@x.hu');
    expect($client->fresh()->last_login_at)->not->toBeNull();
});

it('treats entra without a dedicated tenant as not configured', function (): void {
    foreach (['', 'common', 'organizations'] as $tenant) {
        config()->set('hdid.oidc.client.entra', ['tenant' => $tenant, 'client_id' => 'web-app', 'client_secret' => 's', 'mobile_client_id' => 'mobile-app']);
        expect(OidcProvider::Entra->configFor(PrincipalType::Client)->isConfigured())->toBeFalse();
    }

    Http::fake();
    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => 'x', 'device_name' => 'x'])
        ->assertForbidden()->assertJsonPath('reason', 'method_disabled');
    Http::assertNothingSent();
});

it('answers 503 with a reason when Entra cannot be reached', function (): void {
    Client::factory()->synced()->create(['email' => 'c@x.hu']);
    Http::fake([ENTRA_DISCOVERY => Http::failedConnection()]);

    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => 'x', 'device_name' => 'x'])
        ->assertServiceUnavailable()->assertJsonPath('reason', 'provider_unavailable');
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('refuses when the provider is disabled', function (): void {
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, false);
    Http::fake();

    $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => 'x', 'device_name' => 'x'])
        ->assertForbidden()->assertJsonPath('reason', 'method_disabled');
});

it('a user token cannot use client endpoints', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/client/me')->assertForbidden();
});

it('keeps a previously issued Entra API token after disabling the provider', function (): void {
    $client = Client::factory()->synced()->create();
    FakeOidc::fake([], ENTRA_ISSUER, ENTRA_DISCOVERY);
    $idToken = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => 'tenant-1', 'preferred_username' => $client->email], ENTRA_ISSUER);
    $token = $this->postJson('/api/v1/auth/client/entra/token', ['id_token' => $idToken, 'device_name' => 'phone'])
        ->assertOk()->json('token');
    app(Settings::class)->set(SettingKey::ClientSsoEntraEnabled, false);
    $this->flushSession();

    $this->withToken($token)->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('data.id', $client->id);

    expect($client->tokens()->count())->toBe(1);
});

it('rejects new tokens for a disabled provider without contacting Entra', function (PrincipalType $principal): void {
    app(Settings::class)->set(OidcProvider::Entra->enabledSetting($principal), false);
    Http::preventStrayRequests();

    $this->postJson("/api/v1/auth/{$principal->value}/entra/token", ['id_token' => 'unused', 'device_name' => 'phone'])
        ->assertForbidden()->assertJsonPath('reason', 'method_disabled');

    Http::assertNothingSent();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect(AuditLog::query()->where('event', 'login.rejected')->sole()->context['principal'])->toBe($principal->value);
})->with([PrincipalType::User, PrincipalType::Client]);

it('rechecks Entra after validating the remote token', function (PrincipalType $principal): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    config()->set("hdid.oidc.{$principal->value}.entra", config('hdid.oidc.client.entra'));
    app(Settings::class)->set(OidcProvider::Entra->enabledSetting($principal), true);
    $account = $principal->modelClass()::factory()->synced()->create();
    if ($account instanceof User) {
        $account->assignRole(Role::Agent->value);
    }
    FakeOidc::fake([], ENTRA_ISSUER, ENTRA_DISCOVERY);
    Http::preventStrayRequests();
    $idToken = FakeOidc::idToken(['aud' => 'mobile-app', 'tid' => 'tenant-1', 'preferred_username' => $account->email], ENTRA_ISSUER);
    Event::listen(RequestSending::class, function (RequestSending $event) use ($principal): void {
        if ($event->request->url() === 'https://idp.test/jwks') {
            (clone app(Settings::class))->set(OidcProvider::Entra->enabledSetting($principal), false);
        }
    });

    $this->postJson("/api/v1/auth/{$principal->value}/entra/token", ['id_token' => $idToken, 'device_name' => 'phone'])
        ->assertForbidden()->assertJsonPath('reason', 'method_disabled');

    Http::assertSentCount(2);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect($account->fresh()->last_login_at)->toBeNull();
    $rejected = AuditLog::query()->where('event', 'login.rejected')->sole();
    expect($rejected->context['reason'])->toBe('method_disabled')
        ->and($rejected->subject_id)->toBe($account->id);
})->with([PrincipalType::User, PrincipalType::Client]);
