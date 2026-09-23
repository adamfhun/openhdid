<?php

use App\CallCenter\CallCenterService;
use App\Enums\ApiKeyScope;
use App\Enums\CallStatus;
use App\Enums\IdChannel;
use App\Enums\IdMethod;
use App\Enums\PhoneNumberSource;
use App\Http\Middleware\AuthenticateApiKey;
use App\Identification\ClientSearch;
use App\Identification\MobileOtpService;
use App\Models\ApiKey;
use App\Models\Call;
use App\Models\Client;
use App\Models\IdSession;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\PhoneNormalizer;

beforeEach(function (): void {
    config()->set('hdid.callcenter.api_key', 'key-123');
    config()->set('hdid.callcenter.hmac_secret', null);
    $this->withHeader('X-Api-Key', 'key-123');
});

it('rejects a missing or wrong api key', function (): void {
    $this->withHeaders(['X-Api-Key' => 'nope'])->postJson('/api/v1/callcenter/calls', [])->assertUnauthorized();
    $this->withHeaders(['X-Api-Key' => ''])->postJson('/api/v1/callcenter/calls', [])->assertUnauthorized();
});

it('creates a call and matches the caller by any of the client phone numbers', function (): void {
    $client = Client::factory()->synced()->create();
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::ClientSelf]);

    $response = $this->postJson('/api/v1/callcenter/calls', [
        'call_id' => 'c-1', 'caller_number' => '06 30 123 4567', 'status' => 'ringing', 'queue' => 'support',
    ]);

    $response->assertCreated()->assertJsonPath('data.client.id', $client->id)->assertJsonPath('data.caller_number', '+36301234567');

    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-1', 'caller_number' => '06301234567', 'status' => 'active'])->assertOk();
    expect(Call::query()->count())->toBe(1)->and(Call::query()->first()->status)->toBe(CallStatus::Active);

    $this->postJson('/api/v1/callcenter/calls/c-1/end')->assertOk();
    expect(Call::query()->first()->status)->toBe(CallStatus::Missed, 'ended without an agent counts as missed');

    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-9', 'caller_number' => null, 'status' => 'ringing']);
    $this->postJson('/api/v1/callcenter/calls/c-9/end', ['missed' => true])->assertOk();
    expect(Call::query()->missed()->count())->toBe(2);
    $this->postJson('/api/v1/callcenter/calls/nope/end')->assertNotFound();
});

it('lets the ivr look up the caller and the state of a call', function (): void {
    $client = Client::factory()->synced()->withPin('654321')->create(['name' => 'Kovács Anna']);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin]);

    // Lookup by number, without a call
    $this->getJson('/api/v1/callcenter/lookup?caller_number=06301234567')->assertOk()
        ->assertJsonPath('caller_number', '+36301234567')
        ->assertJsonPath('client.name', 'Kovács Anna')
        ->assertJsonPath('client.has_pin', true)
        ->assertJsonPath('client.tier', 'premium');
    $this->getJson('/api/v1/callcenter/lookup?caller_number=06209999999')->assertOk()->assertJsonPath('client', null);

    // Call event over GET (legacy IVR) returns the matched client at once
    $this->getJson('/api/v1/callcenter/calls?call_id=c-7&caller_number=06301234567&status=ringing&queue=support')
        ->assertCreated()->assertJsonPath('data.client.id', $client->id)->assertJsonPath('data.client.has_pin', true);

    // State of the call any time later, with whether an identification passed
    $this->getJson('/api/v1/callcenter/calls/c-7')->assertOk()
        ->assertJsonPath('data.client.name', 'Kovács Anna')->assertJsonPath('data.identified', false);
    $this->postJson('/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'c-7', 'pin' => '654321'])
        ->assertOk()->assertJsonPath('identified', true)->assertJsonPath('client_name', 'Kovács Anna');
    $this->getJson('/api/v1/callcenter/calls/c-7')->assertOk()->assertJsonPath('data.identified', true);
    $this->getJson('/api/v1/callcenter/calls/nope')->assertNotFound();
});

it('takes the client the call center names, otherwise matches by number on every event', function (): void {
    $anna = Client::factory()->synced()->create();
    $anna->externalRecord->update(['external_id' => 5150]);
    $bela = Client::factory()->synced()->create();
    $bela->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin]);

    // named by external id: wins over the number
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'n-1', 'caller_number' => '+36301234567', 'status' => 'ringing', 'client_external_id' => 5150])
        ->assertCreated()->assertJsonPath('data.client.id', $anna->id);

    // unknown number and no client named: unmatched; the number is added later and matched then
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'n-2', 'caller_number' => null, 'status' => 'ringing'])
        ->assertCreated()->assertJsonPath('data.client', null);
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'n-2', 'caller_number' => '+36301234567', 'status' => 'active'])
        ->assertOk()->assertJsonPath('data.client.id', $bela->id);

    // a bogus client id is ignored, the number still matches
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'n-3', 'caller_number' => '+36301234567', 'status' => 'ringing', 'client_id' => '00000000-0000-0000-0000-000000000000'])
        ->assertCreated()->assertJsonPath('data.client.id', $bela->id);
});

it('does not match closed clients', function (): void {
    $client = Client::factory()->synced()->closed()->create();
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin]);

    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-2', 'caller_number' => '+36301234567', 'status' => 'ringing'])
        ->assertCreated()->assertJsonPath('data.client', null);
});

it('verifies an ivr pin against the matched caller and records the session on the call', function (): void {
    $client = Client::factory()->synced()->withPin('654321')->create();
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin]);
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-3', 'caller_number' => '+36301234567', 'status' => 'ringing']);

    $this->postJson('/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'c-3', 'pin' => '000000'])
        ->assertOk()->assertJsonPath('identified', false)->assertJsonPath('result', 'wrong_pin');
    $this->postJson('/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'c-3', 'pin' => '654321'])
        ->assertOk()->assertJsonPath('identified', true)->assertJsonPath('client_id', $client->id);

    $this->postJson('/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'unknown', 'pin' => '654321'])
        ->assertOk()->assertJsonPath('result', 'unknown_caller');

    $call = Call::query()->where('external_call_id', 'c-3')->first();
    expect($call->idSessions)->toHaveCount(2)->and($call->passedSession())->not->toBeNull()
        ->and(IdSession::query()->where('channel', 'ivr')->count())->toBe(2);
});

it('forgets the previous client\'s pass when the call is reattached to another client', function (): void {
    $anna = Client::factory()->synced()->withPin('654321')->create();
    $anna->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin]);
    $bela = Client::factory()->synced()->withPin('111111')->create();
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-8', 'caller_number' => '+36301234567', 'status' => 'ringing']);

    $this->postJson('/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'c-8', 'pin' => '654321'])->assertOk()->assertJsonPath('identified', true);
    $this->getJson('/api/v1/callcenter/calls/c-8')->assertOk()->assertJsonPath('data.client.id', $anna->id)->assertJsonPath('data.identified', true);

    $call = Call::query()->where('external_call_id', 'c-8')->firstOrFail();
    app(CallCenterService::class)->attachClient($call, $bela);

    $this->getJson('/api/v1/callcenter/calls/c-8')->assertOk()
        ->assertJsonPath('data.client.id', $bela->id)->assertJsonPath('data.identified', false);
    expect($call->fresh()->load('idSessions')->clientIdSessions())->toHaveCount(0);

    $this->postJson('/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'c-8', 'pin' => '111111'])->assertOk()->assertJsonPath('identified', true);
    $this->getJson('/api/v1/callcenter/calls/c-8')->assertOk()
        ->assertJsonPath('data.client.id', $bela->id)->assertJsonPath('data.identified', true);
});

it('identifies the caller by a dictated code alone and attaches the client to the call', function (): void {
    $client = Client::factory()->synced()->create();
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-5', 'caller_number' => null, 'status' => 'ringing']);

    $code = app(MobileOtpService::class)->issue($client)['code'];

    $this->postJson('/api/v1/callcenter/ivr/verify-code', ['call_id' => 'c-5', 'code' => '11111111'])
        ->assertOk()->assertJsonPath('identified', false)->assertJsonPath('result', 'unknown_code');

    // Legacy IVR: GET with the code in the query string
    $this->getJson('/api/v1/callcenter/ivr/verify-code?call_id=c-5&code='.$code)
        ->assertOk()->assertJsonPath('identified', true)->assertJsonPath('client_id', $client->id);

    expect(Call::query()->where('external_call_id', 'c-5')->value('client_id'))->toBe($client->id);

    // Without a call the code still identifies the client
    $again = app(MobileOtpService::class)->issue($client)['code'];
    $this->postJson('/api/v1/callcenter/ivr/verify-code', ['code' => $again])->assertOk()->assertJsonPath('client_id', $client->id);
});

it('accepts a managed api key from the header, bearer token or query string', function (): void {
    config()->set('hdid.callcenter.api_key', null);
    ['plain' => $plain] = ApiKey::generate('ivr', ApiKeyScope::CallCenter);
    ['plain' => $mobile] = ApiKey::generate('app', ApiKeyScope::MobileBackend);

    $this->withHeaders(['X-Api-Key' => $plain])->postJson('/api/v1/callcenter/calls', ['call_id' => 'k-1', 'caller_number' => null, 'status' => 'ringing'])->assertCreated();
    $this->withHeaders(['X-Api-Key' => ''])->withToken($plain)->postJson('/api/v1/callcenter/calls', ['call_id' => 'k-2', 'caller_number' => null, 'status' => 'ringing'])->assertCreated();
    $this->withHeaders(['X-Api-Key' => ''])->postJson('/api/v1/callcenter/calls?api_key='.$plain, ['call_id' => 'k-3', 'caller_number' => null, 'status' => 'ringing'])->assertCreated();

    $this->withHeaders(['X-Api-Key' => $mobile])->postJson('/api/v1/callcenter/calls', [])->assertUnauthorized('wrong scope');
    expect(ApiKey::query()->where('scope', ApiKeyScope::CallCenter)->first()->last_used_at)->not->toBeNull();

    ApiKey::query()->where('scope', ApiKeyScope::CallCenter)->first()->revoke();
    $this->withHeaders(['X-Api-Key' => $plain])->postJson('/api/v1/callcenter/calls', [])->assertUnauthorized();
});

it('lets the mobile backend request an ivr code for a client', function (): void {
    ['plain' => $mobile] = ApiKey::generate('app', ApiKeyScope::MobileBackend);
    $client = Client::factory()->synced()->create(['email' => 'app@x.hu']);
    $client->externalRecord->update(['external_id' => 777]);

    $this->withHeaders(['X-Api-Key' => $mobile])->postJson('/api/v1/mobile/ivr-code', ['email' => 'App@x.hu'])
        ->assertOk()->assertJsonPath('client_id', $client->id)->assertJsonStructure(['code', 'formatted', 'expires_at']);
    $code = $this->withHeaders(['X-Api-Key' => $mobile])->getJson('/api/v1/mobile/ivr-code?external_id=777')
        ->assertOk()->assertJsonPath('client_id', $client->id)->json('code');

    // The IVR code is accepted by the IVR only, never on the agent's page
    expect(app(MobileOtpService::class)->verifyCode($code, IdChannel::Manual))->toBeNull();
    $this->withHeaders(['X-Api-Key' => 'key-123'])->postJson('/api/v1/callcenter/ivr/verify-code', ['code' => $code])
        ->assertOk()->assertJsonPath('identified', true)->assertJsonPath('client_id', $client->id);
    expect(IdSession::query()->where('client_id', $client->id)->value('method'))->toBe(IdMethod::IvrCode);
    $this->withHeaders(['X-Api-Key' => $mobile])->postJson('/api/v1/mobile/ivr-code', ['email' => 'nobody@x.hu'])->assertUnprocessable();

    $this->withHeaders(['X-Api-Key' => 'key-123'])->postJson('/api/v1/mobile/ivr-code', ['email' => 'app@x.hu'])->assertUnauthorized('call-center key has no mobile scope');
});

it('issues independently sized ivr codes and accepts live codes after a length change', function (): void {
    ['plain' => $mobile] = ApiKey::generate('app', ApiKeyScope::MobileBackend);
    $client = Client::factory()->synced()->create();
    app(Settings::class)->setMany([
        SettingKey::IvrCodeLength->value => 12,
        SettingKey::MobileOtpLength->value => 10,
    ]);

    $code = $this->withHeaders(['X-Api-Key' => $mobile])
        ->postJson('/api/v1/mobile/ivr-code', ['email' => $client->email])
        ->assertOk()->json('code');
    expect($code)->toMatch('/^[0-9]{12}$/')
        ->and(strlen(app(MobileOtpService::class)->issue($client)['code']))->toBe(10);

    app(Settings::class)->set(SettingKey::IvrCodeLength, 8);
    $this->withHeaders(['X-Api-Key' => 'key-123'])
        ->postJson('/api/v1/callcenter/ivr/verify-code', ['code' => $code])
        ->assertOk()->assertJsonPath('identified', true)->assertJsonPath('client_id', $client->id);
    $this->assertDatabaseHas('id_sessions', ['client_id' => $client->id, 'method' => IdMethod::IvrCode->value]);
});

it('enforces the hmac signature when a secret is configured', function (): void {
    config()->set('hdid.callcenter.hmac_secret', 'shh');
    $body = json_encode(['call_id' => 'c-4', 'caller_number' => null, 'status' => 'ringing']);
    $ts = time();

    $this->call('POST', '/api/v1/callcenter/calls', [], [], [], [
        'HTTP_X_API_KEY' => 'key-123', 'HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_SIGNATURE' => 'bad', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $body)->assertUnauthorized();

    $this->call('POST', '/api/v1/callcenter/calls', [], [], [], [
        'HTTP_X_API_KEY' => 'key-123', 'HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', AuthenticateApiKey::signedPayload($ts, 'POST', '/api/v1/callcenter/calls', $body), 'shh'), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $body)->assertCreated();

    // The very same signed request is not accepted a second time.
    $this->call('POST', '/api/v1/callcenter/calls', [], [], [], [
        'HTTP_X_API_KEY' => 'key-123', 'HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', AuthenticateApiKey::signedPayload($ts, 'POST', '/api/v1/callcenter/calls', $body), 'shh'), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $body)->assertUnauthorized();

    $old = $ts - 1000;
    $this->call('POST', '/api/v1/callcenter/calls', [], [], [], [
        'HTTP_X_API_KEY' => 'key-123', 'HTTP_X_TIMESTAMP' => $old, 'HTTP_X_SIGNATURE' => hash_hmac('sha256', AuthenticateApiKey::signedPayload($old, 'POST', '/api/v1/callcenter/calls', $body), 'shh'), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $body)->assertUnauthorized();
});

it('searches clients by name, email, phone and external id', function (): void {
    $anna = Client::factory()->synced()->create(['name' => 'Kovács Anna', 'email' => 'anna@x.hu']);
    $anna->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin]);
    $anna->externalRecord->update(['external_id' => 4242]);
    Client::factory()->create(['name' => 'Nagy Béla', 'email' => 'bela@x.hu']);
    $search = app(ClientSearch::class);

    expect($search->search('kovács')->pluck('id')->all())->toBe([$anna->id])
        ->and($search->search('anna@x')->pluck('id')->all())->toBe([$anna->id])
        ->and($search->search('06 30 123 4567')->pluck('id')->all())->toBe([$anna->id])
        ->and($search->search('1234')->pluck('id')->all())->toBe([$anna->id])
        ->and($search->search('4242')->pluck('id')->all())->toBe([$anna->id])
        ->and($search->search('a'))->toHaveCount(0)
        ->and($search->search('x.hu'))->toHaveCount(2);
});

it('accepts the caller number the way the IVR sends it, with the country code but no plus sign', function (): void {
    $client = Client::factory()->synced()->create();
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::ClientSelf]);

    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'ivr-1', 'caller_number' => '36301234567', 'status' => 'ringing', 'queue' => '1413'])
        ->assertCreated()
        ->assertJsonPath('data.caller_number', '+36301234567')
        ->assertJsonPath('data.client.id', $client->id);

    $this->getJson('/api/v1/callcenter/calls/ivr-1')
        ->assertOk()
        ->assertJsonPath('data.caller_number', '+36301234567')
        ->assertJsonPath('data.client.id', $client->id);

    expect(app(PhoneNormalizer::class)->normalize('36 30 123 4567'))->toBe('+36301234567')
        ->and(app(PhoneNormalizer::class)->normalize('0036301234567'))->toBe('+36301234567')
        ->and(app(PhoneNormalizer::class)->normalize('06301234567'))->toBe('+36301234567')
        ->and(app(PhoneNormalizer::class)->normalize('+36301234567'))->toBe('+36301234567');
});

it('keeps an unparseable caller number as sent, so the dashboard can still show it', function (): void {
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'ivr-2', 'caller_number' => '3630123456789', 'status' => 'ringing'])
        ->assertCreated()
        ->assertJsonPath('data.caller_number', null)
        ->assertJsonPath('data.caller_number_raw', '3630123456789');

    expect(Call::query()->where('external_call_id', 'ivr-2')->value('caller_number_raw'))->toBe('3630123456789');
});

it('answers an unknown call id with a plain 404 message', function (): void {
    $this->getJson('/api/v1/callcenter/calls/no-such-call')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Unknown call.']);
});

it('verifies a ten-digit pin in the phone menu', function (): void {
    $client = Client::factory()->synced()->withPin('1234567890')->create();
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::ClientSelf]);
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'c-10', 'caller_number' => '+36301234567', 'status' => 'ringing']);

    $this->postJson('/api/v1/callcenter/ivr/verify-pin', ['call_id' => 'c-10', 'pin' => '1234567890'])
        ->assertOk()->assertJsonPath('identified', true)->assertJsonPath('client_id', $client->id);
});

it('preserves the known caller number when a status update omits it', function (): void {
    config()->set('hdid.callcenter.api_key', 'review-test-key');
    config()->set('hdid.callcenter.hmac_secret', null);
    $this->withHeaders(['X-Api-Key' => 'review-test-key']);
    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'review-call', 'caller_number' => '36301234567', 'status' => 'ringing'])
        ->assertCreated()
        ->assertJsonPath('data.caller_number', '+36301234567');

    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'review-call', 'status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.caller_number', '+36301234567')
        ->assertJsonPath('data.caller_number_raw', '36301234567');

    $this->getJson('/api/v1/callcenter/calls/review-call')->assertOk()
        ->assertJsonPath('data.caller_number', '+36301234567')
        ->assertJsonPath('data.caller_number_raw', '36301234567');

    $this->postJson('/api/v1/callcenter/calls', ['call_id' => 'review-call', 'caller_number' => null, 'status' => 'ended'])
        ->assertOk()->assertJsonPath('data.caller_number', null)->assertJsonPath('data.caller_number_raw', null);
});
