<?php

use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use App\Auth\Passwordless\OneTimeCodes;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PrincipalType;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\User;
use App\System\CheckStatus;
use App\System\HealthChecks;

function loginRejection(Client|User $account, PrincipalType $type): ?LoginRejection
{
    try {
        app(AccountLogin::class)->assertEligible($account, $type, 'magic_link');

        return null;
    } catch (LoginRejectedException $exception) {
        return $exception->reason;
    }
}

it('asks clients for a present EMD record with their e-mail by default', function (): void {
    expect(loginRejection(Client::factory()->create(), PrincipalType::Client))->toBe(LoginRejection::NoExternalRecord)
        ->and(loginRejection(Client::factory()->create(['external_record_id' => ExternalRecord::factory()->client()->missing()]), PrincipalType::Client))->toBe(LoginRejection::ExternalRecordMissing)
        ->and(app(HealthChecks::class)->clientLoginEmd()->status)->toBe(CheckStatus::Ok);
});

it('lets clients in without an EMD record when CLIENT_LOGIN_REQUIRE_EMD is off, keeping the other rules and staff untouched', function (): void {
    config()->set('hdid.client_login.require_emd', false);
    $unlinked = Client::factory()->create();
    $missing = Client::factory()->create(['external_record_id' => ExternalRecord::factory()->client()->missing()]);
    $mismatch = Client::factory()->synced()->create();
    $mismatch->externalRecord->update(['email' => 'other@client.hu']);

    expect(loginRejection($unlinked, PrincipalType::Client))->toBeNull()
        ->and(loginRejection($missing, PrincipalType::Client))->toBeNull()
        ->and(loginRejection($mismatch, PrincipalType::Client))->toBeNull()
        ->and(loginRejection(Client::factory()->closed()->create(), PrincipalType::Client))->toBe(LoginRejection::AccountClosed)
        ->and(loginRejection(Client::factory()->unentitled()->create(), PrincipalType::Client))->toBe(LoginRejection::NotEntitled)
        ->and(loginRejection(User::factory()->create(), PrincipalType::User))->toBe(LoginRejection::NoExternalRecord)
        ->and(app(HealthChecks::class)->clientLoginEmd()->status)->toBe(CheckStatus::Warn);

    app(AccountLogin::class)->recordSuccess($unlinked, 'magic_link');
    expect(AuditLog::query()->where('event', 'login.succeeded')->sole()->context)->toMatchArray(['method' => 'magic_link', 'emd_required' => false])
        ->and(AuditLog::query()->where('event', 'login.rejected')->where('subject_id', $unlinked->id)->exists())->toBeFalse();
});

it('signs a client without an EMD record in through the login link while the switch is off', function (): void {
    config()->set('hdid.client_login.require_emd', false);
    $client = Client::factory()->create(['email' => 'kezi@client.hu']);
    ['token' => $token] = app(OneTimeCodes::class)->issueToken($client, OneTimeCodePurpose::MagicLink, 60);

    signInWithLink(url('/login/link#token='.$token))->assertOk();

    expect(auth('client')->id())->toBe($client->id);
});

it('signs out the browser sessions and app tokens of clients without a matching EMD record once CLIENT_LOGIN_REQUIRE_EMD is switched back on', function (Closure $makeClient, string $reason): void {
    config()->set('hdid.client_login.require_emd', false);
    $client = $makeClient();
    $bearer = app(AccountLogin::class)->issueToken($client, 'test', 'phone');
    ['token' => $token] = app(OneTimeCodes::class)->issueToken($client, OneTimeCodePurpose::MagicLink, 60);
    signInWithLink(url('/login/link#token='.$token))->assertOk();
    $this->getJson('/api/v1/client/me')->assertOk();

    config()->set('hdid.client_login.require_emd', true);
    $epoch = $client->fresh()->session_epoch;

    $this->getJson('/api/v1/client/me')->assertForbidden()->assertJsonPath('reason', $reason);
    expect($client->fresh()->tokens()->count())->toBe(0)
        ->and($client->fresh()->session_epoch)->toBe($epoch + 1);

    $this->app['auth']->forgetGuards();
    $this->withToken($bearer)->getJson('/api/v1/client/me')->assertUnauthorized();
})->with([
    'no record' => [fn () => Client::factory()->create(), 'no_external_record'],
    'a missing record' => [fn () => Client::factory()->create(['external_record_id' => ExternalRecord::factory()->client()->missing()]), 'external_record_missing'],
    'a record with another e-mail' => [function () {
        $client = Client::factory()->synced()->create();
        $client->externalRecord->update(['email' => 'other@client.hu']);

        return $client;
    }, 'external_record_mismatch'],
]);

it('keeps synced clients with a matching record signed in when the switch goes back on', function (): void {
    config()->set('hdid.client_login.require_emd', false);
    $client = Client::factory()->synced()->create();
    $bearer = app(AccountLogin::class)->issueToken($client, 'test', 'phone');

    config()->set('hdid.client_login.require_emd', true);

    $this->withToken($bearer)->getJson('/api/v1/client/me')->assertOk();
});

it('records one audit entry when the portal check signs a client out', function (): void {
    config()->set('hdid.client_login.require_emd', false);
    $client = Client::factory()->create();
    $bearer = app(AccountLogin::class)->issueToken($client, 'test', 'phone');
    config()->set('hdid.client_login.require_emd', true);

    $this->withToken($bearer)->getJson('/api/v1/client/me')->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withToken($bearer)->getJson('/api/v1/client/me')->assertUnauthorized();

    expect(AuditLog::query()->where('event', 'account.access_revoked')->where('subject_id', $client->id)->sole()->context)
        ->toMatchArray(['reason' => 'no_external_record', 'emd_required' => true]);
});

it('signs the portal out for every reason the per-request check can answer', function (): void {
    $source = (string) file_get_contents(resource_path('js/client/loginErrors.js'));
    preg_match('/export const revokingReasons = \[([^\]]*)\]/', $source, $match);
    $listed = array_map(fn (string $reason): string => trim($reason, " '\n"), explode(',', $match[1] ?? ''));

    foreach ([LoginRejection::AccountClosed, LoginRejection::DemoAccount, LoginRejection::NoExternalRecord, LoginRejection::ExternalRecordMissing, LoginRejection::ExternalRecordMismatch, LoginRejection::NotEntitled] as $reason) {
        expect($listed)->toContain($reason->value)
            ->and($source)->toContain($reason->value.':');
    }

    foreach (['api.js', 'store.js', 'router.js'] as $file) {
        expect((string) file_get_contents(resource_path('js/client/'.$file)))->toContain('revokingReasons');
    }
});
