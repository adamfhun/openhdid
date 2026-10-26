<?php

use App\Enums\PhoneNumberSource;
use App\Models\Client;
use App\Models\IdSession;

beforeEach(function (): void {
    config()->set('hdid.callcenter.api_key', 'key-123');
    config()->set('hdid.callcenter.hmac_secret', null);

    $client = Client::factory()->synced()->withPin('654321')->create();
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin]);
    $this->withHeaders(['X-Api-Key' => 'key-123'])
        ->postJson('/api/v1/callcenter/calls', ['call_id' => '1729771234.17', 'caller_number' => '+36301234567', 'status' => 'ringing'])
        ->assertCreated();
    $this->client = $client;
});

it('does not let unauthenticated requests use up the per-call ivr budget of the real partner', function (): void {
    // A guessed call id (Asterisk UNIQUEID) hammered with made-up keys and without any key.
    foreach (range(1, 9) as $i) {
        $this->withHeaders(['X-Api-Key' => 'forged-'.$i])
            ->getJson('/api/v1/callcenter/ivr/verify-pin?call_id=1729771234.17&pin=0')
            ->assertUnauthorized();
    }
    $this->withHeaders(['X-Api-Key' => ''])
        ->getJson('/api/v1/callcenter/ivr/verify-pin?call_id=1729771234.17&pin=0')
        ->assertUnauthorized();

    $this->withHeaders(['X-Api-Key' => 'key-123'])
        ->postJson('/api/v1/callcenter/ivr/verify-pin', ['call_id' => '1729771234.17', 'pin' => '654321'])
        ->assertOk()->assertJsonPath('identified', true)->assertJsonPath('client_id', $this->client->id);
    $this->withHeaders(['X-Api-Key' => 'key-123'])
        ->postJson('/api/v1/callcenter/ivr/verify-code', ['call_id' => '1729771234.17', 'code' => '11111111'])
        ->assertOk();

    expect(IdSession::query()->where('channel', 'ivr')->count())->toBe(1);
});

it('keeps the per-call ivr budget of ten checks a minute for the authenticated partner', function (): void {
    foreach (range(1, 10) as $i) {
        $this->withHeaders(['X-Api-Key' => 'key-123'])
            ->postJson('/api/v1/callcenter/ivr/verify-code', ['call_id' => '1729771234.17', 'code' => '11111111'])
            ->assertOk();
    }

    $this->withHeaders(['X-Api-Key' => 'key-123'])
        ->postJson('/api/v1/callcenter/ivr/verify-pin', ['call_id' => '1729771234.17', 'pin' => '654321'])
        ->assertStatus(429);
    $this->withHeaders(['X-Api-Key' => 'key-123'])
        ->postJson('/api/v1/callcenter/ivr/verify-code', ['call_id' => 'another-call', 'code' => '11111111'])
        ->assertOk();
});

it('does not let unsigned requests with the real legacy key use up the per-call budget once signing is on', function (): void {
    config()->set('hdid.callcenter.hmac_secret', 'shh');
    // Whoever found the key (a web-server log, a query string) but not the signing secret.
    foreach (range(1, 12) as $i) {
        $this->withHeaders(['X-Api-Key' => 'key-123'])
            ->postJson('/api/v1/callcenter/ivr/verify-pin', ['call_id' => '1729771234.17', 'pin' => '000000'])
            ->assertUnauthorized();
    }

    $body = json_encode(['call_id' => '1729771234.17', 'pin' => '654321']);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp."\nPOST\n/api/v1/callcenter/ivr/verify-pin\n".$body, 'shh');

    $this->call('POST', '/api/v1/callcenter/ivr/verify-pin', [], [], [], [
        'HTTP_X_API_KEY' => 'key-123', 'HTTP_X_TIMESTAMP' => (string) $timestamp, 'HTTP_X_SIGNATURE' => $signature,
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    ], $body)->assertOk()->assertJsonPath('identified', true);
});

it('does not let forged lookups use up the real key\'s budget either', function (): void {
    foreach (range(1, 12) as $i) {
        $this->withHeaders(['X-Api-Key' => 'forged-'.$i])->getJson('/api/v1/callcenter/lookup?caller_number=%2B36301234567')->assertUnauthorized();
    }

    $this->withHeaders(['X-Api-Key' => 'key-123'])->getJson('/api/v1/callcenter/lookup?caller_number=%2B36301234567')->assertOk();
});
