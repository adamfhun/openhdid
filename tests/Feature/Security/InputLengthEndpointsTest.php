<?php

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\EmailAddressRule;
use App\Support\PhoneNumberRule;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Every client and machine endpoint answers an input beyond the standard
 * length with a translated 422 (or a 404 for an over-long path), never a
 * 500, and the oversized value never reaches the audit log in full.
 */
beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
});

/** An address of 255 octets: one past the e-mail limit. */
function overlongAddress(): string
{
    return str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 61).'.hu';
}

function noAuditHolds(string $value): bool
{
    return ! AuditLog::query()->where('context', 'like', '%'.$value.'%')->exists();
}

it('refuses an over-long address on the login link and code requests before anything is recorded', function (string $uri): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $address = overlongAddress();
    expect(strlen($address))->toBe(EmailAddressRule::MAX_LENGTH + 1);

    $this->postJson($uri, ['email' => $address, 'code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => 'The e-mail address may be at most 254 characters long.']);

    expect(AuditLog::query()->where('event', 'like', 'login.%')->count())->toBe(0)
        ->and(noAuditHolds(str_repeat('a', 64).'@'))->toBeTrue();
})->with([
    'login link' => '/api/v1/client/auth/magic-link',
    'sms code' => '/api/v1/client/auth/otp/request',
    'sms code check' => '/api/v1/client/auth/otp/verify',
]);

it('still answers a well-formed unknown address of the full length neutrally', function (): void {
    $address = str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 60).'.hu';
    expect(strlen($address))->toBe(EmailAddressRule::MAX_LENGTH);

    $this->postJson('/api/v1/client/auth/magic-link', ['email' => $address])->assertOk();
});

it('accepts only a code of the set length on the sms code check', function (string $code): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);

    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'anna@example.com', 'code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors(['code']);

    expect(AuditLog::query()->where('event', 'login.rejected')->count())->toBe(0);
})->with(['letters' => '12ab56', 'too short' => '123', 'too long' => '12345678901']);

it('accepts only a token of the issued shape on the login link landing page', function (string $token): void {
    $this->postJson('/api/v1/client/auth/magic-link/consume', ['token' => $token])
        ->assertUnprocessable()->assertJsonValidationErrors(['token']);
})->with([
    'one character more' => str_repeat('a', 65),
    'one character less' => str_repeat('a', 63),
    'not alphanumeric' => str_repeat('a', 63).'-',
]);

it('refuses an over-long typed phone number on the portal with the reason', function (): void {
    $client = Client::factory()->synced()->create();

    $this->actingAs($client, 'client')
        ->postJson('/api/v1/client/phone-numbers', ['number' => '+36'.str_repeat(' ', PhoneNumberRule::RAW_MAX_LENGTH - 13).'30 123 4567'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['number' => 'The phone number may be at most 32 characters long.']);

    expect($client->phoneNumbers()->count())->toBe(0);
});

it('refuses an over-long address and an external id of more than seven digits on the ivr code request', function (): void {
    ['plain' => $mobile, 'secret' => $secret] = ApiKey::generate('app', ApiKeyScope::MobileBackend);

    signedMachineCall($mobile, $secret, 'POST', '/api/v1/mobile/ivr-code', ['email' => overlongAddress()])
        ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'The e-mail address may be at most 254 characters long.']);
    signedMachineCall($mobile, $secret, 'POST', '/api/v1/mobile/ivr-code', ['external_id' => 12345678])
        ->assertUnprocessable()->assertJsonValidationErrors(['external_id']);
    signedMachineCall($mobile, $secret, 'POST', '/api/v1/mobile/ivr-code', ['external_id' => str_repeat('9', 40)])
        ->assertUnprocessable()->assertJsonValidationErrors(['external_id']);
});

describe('call center', function (): void {
    beforeEach(function (): void {
        config()->set('hdid.callcenter.api_key', 'key-123');
        config()->set('hdid.callcenter.hmac_secret', null);
        $this->withHeader('X-Api-Key', 'key-123');
    });

    it('refuses a client external id of more than seven digits on a call event', function (): void {
        $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-1', 'status' => 'ringing', 'client_external_id' => str_repeat('9', 30)])
            ->assertUnprocessable()->assertJsonValidationErrors(['client_external_id']);

        expect(Call::query()->count())->toBe(0);
    });

    it('bounds the metadata of a call event to 50 keys of short scalar values', function (array $metadata, string $error): void {
        $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-1', 'status' => 'ringing', 'metadata' => $metadata])
            ->assertUnprocessable()->assertJsonValidationErrors([$error]);

        expect(Call::query()->count())->toBe(0);
    })->with([
        '51 keys' => [array_fill_keys(array_map(fn (int $i): string => 'k'.$i, range(1, 51)), 'v'), 'metadata'],
        'a value of 256 characters' => [['note' => str_repeat('x', 256)], 'metadata.note'],
        'a nested value' => [['deep' => ['deeper' => 'x']], 'metadata.deep'],
        'a key of 101 characters' => [[str_repeat('k', 101) => 'v'], 'metadata'],
    ]);

    it('keeps 50 metadata keys and refuses an event that would grow the merged metadata beyond them', function (): void {
        $first = array_fill_keys(array_map(fn (int $i): string => 'a'.$i, range(1, 50)), str_repeat('v', 255));

        $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-1', 'status' => 'ringing', 'metadata' => $first])->assertCreated();
        $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-1', 'status' => 'active', 'metadata' => ['a1' => 'changed']])->assertOk();
        $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-1', 'status' => 'active', 'metadata' => ['b1' => 'one more']])
            ->assertUnprocessable()->assertJsonValidationErrors(['metadata' => 'The call would carry more than 50 metadata keys; send fewer keys or reuse the existing ones.']);

        $call = Call::query()->sole();
        expect($call->metadata)->toHaveCount(50)->and($call->metadata['a1'])->toBe('changed');
    });

    it('still takes the status events of a call that gathered more keys before the bound existed', function (): void {
        $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-1', 'status' => 'ringing'])->assertCreated();
        $call = Call::query()->sole();
        $call->forceFill(['metadata' => array_fill_keys(array_map(fn (int $i): string => 'old'.$i, range(1, 60)), 'v')])->save();

        $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-1', 'status' => 'ended'])->assertOk();
        $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-1', 'status' => 'ended', 'metadata' => ['old1' => 'changed']])->assertOk();
        expect($call->fresh()->status->value)->toBe('ended');

        $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-1', 'status' => 'ended', 'metadata' => ['new' => 'x']])
            ->assertUnprocessable()->assertJsonValidationErrors(['metadata']);
    });

    it('takes the legacy key sent as a JSON number', function (): void {
        config()->set('hdid.callcenter.api_key', '123456');

        $this->withHeader('X-Api-Key', '')->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-9', 'status' => 'ringing', 'api_key' => 123456])->assertCreated();
    });

    it('answers an over-long call id in the path with 404', function (string $method, string $suffix): void {
        $this->json($method, '/api/v1/callcenter/calls/'.str_repeat('x', 101).$suffix)->assertNotFound();
        $this->json($method, '/api/v1/callcenter/calls/'.str_repeat('x', 100).$suffix)->assertNotFound()->assertJsonPath('message', 'Unknown call.');
    })->with([
        'state' => ['GET', ''],
        'end' => ['POST', '/end'],
    ]);

    it('refuses an over-long or malformed api key before it is hashed and records only the reason', function (): void {
        $this->withHeader('X-Api-Key', str_repeat('k', 256))->getJson('/api/v1/callcenter/lookup?caller_number=1')->assertUnauthorized();
        $this->withHeader('X-Api-Key', '')->getJson('/api/v1/callcenter/lookup?caller_number=1&api_key[]=x')->assertUnauthorized();

        $entries = AuditLog::query()->where('event', 'api_key.rejected')->get();
        expect($entries->pluck('context.reason')->all())->toBe(['malformed'])
            ->and(noAuditHolds(str_repeat('k', 50)))->toBeTrue();
    });

    it('still accepts a managed key of the issued format', function (): void {
        ['plain' => $plain] = ApiKey::generate('ivr', ApiKeyScope::CallCenter);

        $this->withHeader('X-Api-Key', $plain)->getJson('/api/v1/callcenter/lookup?caller_number=1')->assertOk();
    });
});
