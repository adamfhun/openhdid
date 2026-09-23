<?php

use App\Enums\IdChannel;
use App\Enums\OneTimeCodePurpose;
use App\Enums\PhoneNumberSource;
use App\Identification\MobileOtpService;
use App\Identification\QuestionCatalog;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\OneTimeCode;
use App\Models\Question;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->client = Client::factory()->synced()->create();
    Sanctum::actingAs($this->client, guard: 'client');
    $this->questions = Question::factory()->count(6)->create();
});

it('lists active questions with answered flags and eligibility', function (): void {
    $this->putJson('/api/v1/client/answers/'.$this->questions[0]->id, ['answer' => 'Rex'])->assertOk();

    $response = $this->getJson('/api/v1/client/questions')->assertOk();

    expect($response->json('data'))->toHaveCount(6)
        ->and(collect($response->json('data'))->firstWhere('id', $this->questions[0]->id)['answered'])->toBeTrue()
        ->and($response->json('meta'))->toBe(['answered' => 1, 'required' => 5, 'eligible' => false]);

    foreach ($this->questions->slice(1, 4) as $q) {
        $this->putJson('/api/v1/client/answers/'.$q->id, ['answer' => 'x']);
    }
    expect($this->getJson('/api/v1/client/questions')->json('meta.eligible'))->toBeTrue()
        ->and($this->getJson('/api/v1/client/me')->json('data.identification.eligible'))->toBeTrue();
});

it('never returns answers and refuses questions outside the active pool', function (): void {
    $this->putJson('/api/v1/client/answers/'.$this->questions[0]->id, ['answer' => 'Rex']);
    $this->getJson('/api/v1/client/questions')->assertDontSee('Rex');

    $retired = Question::factory()->inactive()->create();
    $this->putJson('/api/v1/client/answers/'.$retired->id, ['answer' => 'x'])->assertNotFound();

    $this->deleteJson('/api/v1/client/answers/'.$this->questions[0]->id)->assertOk();
    expect($this->client->answers()->count())->toBe(0);
});

it('flags answers that need an update after a meaning change and can make a number primary', function (): void {
    $this->putJson('/api/v1/client/answers/'.$this->questions[0]->id, ['answer' => 'Rex']);
    app(QuestionCatalog::class)->publishVersion($this->questions[0], 'Changed?', null, keepsAnswers: false);

    $q = collect($this->getJson('/api/v1/client/questions')->json('data'))->firstWhere('id', $this->questions[0]->id);
    expect($q['answered'])->toBeFalse()->and($q['needs_update'])->toBeTrue();

    $a = $this->client->phoneNumbers()->create(['number_e164' => '+36301111111', 'source' => PhoneNumberSource::Sync, 'is_primary' => true]);
    $b = $this->client->phoneNumbers()->create(['number_e164' => '+36302222222', 'source' => PhoneNumberSource::ClientSelf]);
    $this->postJson('/api/v1/client/phone-numbers/'.$b->id.'/primary')->assertOk();
    expect($a->fresh()->is_primary)->toBeFalse()->and($b->fresh()->is_primary)->toBeTrue()
        ->and($this->client->phoneNumbers()->where('is_primary', true)->count())->toBe(1);
});

it('sets, validates and clears the pin', function (): void {
    app(Settings::class)->set(SettingKey::PinClientChangesEnabled, true);

    $this->putJson('/api/v1/client/pin', ['pin' => '12', 'pin_confirmation' => '12'])->assertUnprocessable();
    $this->putJson('/api/v1/client/pin', ['pin' => '123456', 'pin_confirmation' => '999999'])->assertUnprocessable();
    $this->putJson('/api/v1/client/pin', ['pin' => '123456', 'pin_confirmation' => '123456'])->assertOk();

    expect($this->client->fresh()->hasPin())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'client.pin_set')->exists())->toBeTrue();

    $this->deleteJson('/api/v1/client/pin')->assertOk();
    expect($this->client->fresh()->hasPin())->toBeFalse();
});

it('adds own phone numbers and only removes own ones', function (): void {
    $synced = $this->client->phoneNumbers()->create(['number_e164' => '+36301111111', 'source' => PhoneNumberSource::Sync]);

    $this->postJson('/api/v1/client/phone-numbers', ['number' => 'nope'])->assertUnprocessable();
    $response = $this->postJson('/api/v1/client/phone-numbers', ['number' => '06 20 222 2222', 'label' => 'work'])->assertOk();
    $own = collect($response->json('data.phone_numbers'))->firstWhere('number', '+36202222222');
    expect($own['source'])->toBe('self');

    $this->deleteJson('/api/v1/client/phone-numbers/'.$synced->id)->assertForbidden();
    $this->deleteJson('/api/v1/client/phone-numbers/'.$own['id'])->assertOk();

    $other = Client::factory()->create();
    $foreign = $other->phoneNumbers()->create(['number_e164' => '+36303333333', 'source' => PhoneNumberSource::ClientSelf]);
    $this->deleteJson('/api/v1/client/phone-numbers/'.$foreign->id)->assertNotFound();
});

it('issues a dictation code and shows it again while it lives', function (): void {
    $this->getJson('/api/v1/client/mobile-code')->assertOk()->assertJsonPath('code', null)->assertJsonPath('length', 8);

    $response = $this->postJson('/api/v1/client/mobile-code')->assertOk();
    $code = $response->json('code');

    expect($code)->toMatch('/^\d{8}$/')
        ->and($response->json('formatted'))->toBe(implode(' ', str_split($code, 2)))
        ->and(OneTimeCode::query()->where('purpose', OneTimeCodePurpose::MobileOtp)->count())->toBe(1);

    $this->getJson('/api/v1/client/mobile-code')->assertOk()->assertJsonPath('code', $code);

    $this->travel(6)->minutes();
    $this->getJson('/api/v1/client/mobile-code')->assertOk()->assertJsonPath('code', null)->assertJsonPath('identified', null);
});

it('tells the client when the helpdesk accepted their code', function (): void {
    $code = $this->postJson('/api/v1/client/mobile-code')->json('code');
    app(MobileOtpService::class)->verifyCode($code, IdChannel::Manual, User::factory()->create());

    $this->getJson('/api/v1/client/mobile-code')->assertOk()
        ->assertJsonPath('code', null)
        ->assertJsonPath('identified.channel', 'manual');

    $this->travel(20)->minutes();
    $this->getJson('/api/v1/client/mobile-code')->assertOk()->assertJsonPath('identified', null);
});

it('signs out a client whose package lost portal access', function (): void {
    $this->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('data.tier', 'premium')
        ->assertJsonMissingPath('data.implicit_package')
        ->assertJsonMissingPath('data.explicit_package');

    $this->client->update(['explicit_package' => 'Nothing']);

    $this->getJson('/api/v1/client/me')->assertForbidden()->assertJsonPath('reason', 'not_entitled');
});

it('lets the client choose any pin length between six and ten digits', function (): void {
    app(Settings::class)->set(SettingKey::PinClientChangesEnabled, true);

    $this->putJson('/api/v1/client/pin', ['pin' => '12345', 'pin_confirmation' => '12345'])->assertUnprocessable();
    $this->putJson('/api/v1/client/pin', ['pin' => '12345678901', 'pin_confirmation' => '12345678901'])->assertUnprocessable();
    $this->putJson('/api/v1/client/pin', ['pin' => '12345678', 'pin_confirmation' => '12345678'])->assertOk()
        ->assertJsonPath('min_length', 6)->assertJsonPath('max_length', 10);
    $this->putJson('/api/v1/client/pin', ['pin' => '1234567890', 'pin_confirmation' => '1234567890'])->assertOk();

    expect(Hash::check('1234567890', $this->client->fresh()->pin_hash))->toBeTrue();
});

it('exposes and enforces configured pin limits on the client api', function (): void {
    app(Settings::class)->set(SettingKey::PinClientChangesEnabled, true);

    app(Settings::class)->setMany([
        SettingKey::PinMinLength->value => 9,
        SettingKey::PinMaxLength->value => 12,
    ]);

    $this->getJson('/api/v1/client/me')->assertOk()
        ->assertJsonPath('data.identification.pin_min_length', 9)
        ->assertJsonPath('data.identification.pin_max_length', 12);
    $this->putJson('/api/v1/client/pin', ['pin' => '12345678', 'pin_confirmation' => '12345678'])
        ->assertUnprocessable()->assertJsonValidationErrors('pin');
    expect($this->client->fresh()->hasPin())->toBeFalse();

    $this->putJson('/api/v1/client/pin', ['pin' => '012345678901', 'pin_confirmation' => '012345678901'])
        ->assertOk()->assertJsonPath('min_length', 9)->assertJsonPath('max_length', 12);
    expect(Hash::check('012345678901', $this->client->fresh()->pin_hash))->toBeTrue();
    $this->assertDatabaseHas('audit_log', ['event' => 'client.pin_set', 'subject_id' => $this->client->id]);
});

it('blocks client pin creation by default and reports that it is managed by staff', function (): void {
    $this->getJson('/api/v1/client/me')->assertOk()->assertJsonPath('data.identification.pin_changes_enabled', false);
    $this->putJson('/api/v1/client/pin', ['pin' => '123456', 'pin_confirmation' => '123456'])
        ->assertForbidden();

    expect($this->client->fresh()->hasPin())->toBeFalse();
    $this->assertDatabaseMissing('audit_log', ['event' => 'client.pin_set']);
});

it('applies the client pin switch again on replacement and removal', function (): void {
    app(Settings::class)->set(SettingKey::PinClientChangesEnabled, true);
    $this->putJson('/api/v1/client/pin', ['pin' => '123456', 'pin_confirmation' => '123456'])->assertOk();
    app(Settings::class)->set(SettingKey::PinClientChangesEnabled, false);

    $this->putJson('/api/v1/client/pin', ['pin' => '654321', 'pin_confirmation' => '654321'])->assertForbidden();
    $this->deleteJson('/api/v1/client/pin')->assertForbidden();

    expect(Hash::check('123456', $this->client->fresh()->pin_hash))->toBeTrue();
    $this->assertDatabaseMissing('audit_log', ['event' => 'client.pin_cleared']);
});

it('rejects another clients pin through the client api when changes are enabled', function (): void {
    app(Settings::class)->set(SettingKey::PinClientChangesEnabled, true);
    Client::factory()->withPin('123456')->create();

    $this->putJson('/api/v1/client/pin', ['pin' => '123456', 'pin_confirmation' => '123456'])
        ->assertUnprocessable()->assertJsonValidationErrors(['pin' => __('This PIN is already in use. Choose another PIN.')]);

    expect($this->client->fresh()->hasPin())->toBeFalse();
});
