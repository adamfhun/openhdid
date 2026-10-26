<?php

use App\Enums\ApiKeyScope;
use App\Enums\OneTimeCodePurpose;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\OneTimeCode;
use Illuminate\Database\Eloquent\Model;

/**
 * The mobile backend names the client by the address it authenticated. The
 * MariaDB collation is accent-, case- and width-insensitive, so a look-alike
 * address ("víctim@…", an IDN domain) would find another client; the code
 * request has to follow the login's exact match. SQLite compares bytes, so
 * the loaded row is altered to stand for the look-alike match.
 */
it('issues no ivr code for an address that only the database collation equates with a client', function (): void {
    ['plain' => $mobile, 'secret' => $secret] = ApiKey::generate('app', ApiKeyScope::MobileBackend);
    $victim = Client::factory()->synced()->create(['email' => 'victim@x.hu']);

    $storedAs = fn (Model $found) => $found->setRawAttributes(['email' => 'víctim@x.hu'] + $found->getAttributes(), sync: true);
    Client::retrieved($storedAs);

    try {
        $lookAlike = signedMachineCall($mobile, $secret, 'POST', '/api/v1/mobile/ivr-code', ['email' => 'victim@x.hu']);
    } finally {
        Client::flushEventListeners();
    }

    $unknown = signedMachineCall($mobile, $secret, 'POST', '/api/v1/mobile/ivr-code', ['email' => 'nobody@x.hu']);

    $lookAlike->assertUnprocessable()->assertJsonMissingPath('code');
    expect($lookAlike->json())->toBe($unknown->assertUnprocessable()->json(), 'the same answer as for an unknown address')
        ->and(OneTimeCode::query()->where('client_id', $victim->id)->where('purpose', OneTimeCodePurpose::IvrCode)->count())->toBe(0);
});

it('still issues the ivr code when the address differs only in letter case or surrounding spaces', function (): void {
    ['plain' => $mobile, 'secret' => $secret] = ApiKey::generate('app', ApiKeyScope::MobileBackend);
    $client = Client::factory()->synced()->create(['email' => 'app@x.hu']);

    signedMachineCall($mobile, $secret, 'POST', '/api/v1/mobile/ivr-code', ['email' => ' App@X.hu '])
        ->assertOk()->assertJsonPath('client_id', $client->id);
});

it('issues no ivr code to a closed client found by its exact address, nor when the address and the external id name different clients', function (): void {
    ['plain' => $mobile, 'secret' => $secret] = ApiKey::generate('app', ApiKeyScope::MobileBackend);
    Client::factory()->synced()->closed()->create(['email' => 'closed@x.hu']);
    $other = Client::factory()->synced()->create(['email' => 'other@x.hu']);
    $other->externalRecord->update(['external_id' => 4242]);
    Client::factory()->synced()->create(['email' => 'mine@x.hu']);

    signedMachineCall($mobile, $secret, 'POST', '/api/v1/mobile/ivr-code', ['email' => 'closed@x.hu'])->assertUnprocessable();
    signedMachineCall($mobile, $secret, 'POST', '/api/v1/mobile/ivr-code', ['email' => 'mine@x.hu', 'external_id' => 4242])->assertUnprocessable();
    signedMachineCall($mobile, $secret, 'POST', '/api/v1/mobile/ivr-code', ['email' => 'other@x.hu', 'external_id' => 4242])
        ->assertOk()->assertJsonPath('client_id', $other->id);
});
