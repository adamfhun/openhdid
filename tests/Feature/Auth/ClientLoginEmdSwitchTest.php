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
