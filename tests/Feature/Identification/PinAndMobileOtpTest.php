<?php

use App\Auth\Passwordless\OneTimeCodes;
use App\Auth\Permission;
use App\Auth\Role;
use App\Enums\IdChannel;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Enums\OneTimeCodePurpose;
use App\Filament\Admin\Pages\Identify;
use App\Identification\DictationCode;
use App\Identification\IdentificationException;
use App\Identification\MobileOtpService;
use App\Identification\PinService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

it('sets a pin of the configured length only', function (): void {
    $client = Client::factory()->create();
    $pins = app(PinService::class);

    expect(fn () => $pins->setPin($client, '12'))->toThrow(ValidationException::class);
    $pins->setPin($client, '123456');
    expect($client->fresh()->hasPin())->toBeTrue();
});

it('enforces new pin limits in the service while existing pins still verify', function (): void {
    $client = Client::factory()->withPin('123456')->create();
    app(Settings::class)->setMany([
        SettingKey::PinMinLength->value => 9,
        SettingKey::PinMaxLength->value => 11,
    ]);
    $pins = app(PinService::class);

    expect($pins->verify($client, '123456', IdChannel::Ivr)->status)->toBe(IdSessionStatus::Passed);
    expect(fn () => $pins->setPin($client, '12345678'))->toThrow(ValidationException::class);
    expect(fn () => $pins->setPin($client, '123456789012'))->toThrow(ValidationException::class);
    $pins->setPin($client, '01234567890');
    expect($pins->verify($client, '01234567890', IdChannel::Ivr)->status)->toBe(IdSessionStatus::Passed);
});

it('verifies a pin from the ivr and locks after repeated failures', function (): void {
    app(Settings::class)->set(SettingKey::PinMaxFailedAttempts, 2);
    $client = Client::factory()->withPin('123456')->create();
    $pins = app(PinService::class);

    expect($pins->verify($client, '000000', IdChannel::Ivr)->status)->toBe(IdSessionStatus::Failed);
    $locked = $pins->verify($client, '000000', IdChannel::Ivr);
    expect($locked->outcome_reason)->toBe('locked')->and($pins->isLocked($client->fresh()))->toBeTrue();

    expect($pins->verify($client->fresh(), '123456', IdChannel::Ivr)->status)->toBe(IdSessionStatus::Failed, 'locked even with the right pin');

    $this->travel(31)->minutes();
    $ok = $pins->verify($client->fresh(), '123456', IdChannel::Ivr);
    expect($ok->status)->toBe(IdSessionStatus::Passed)->and($ok->channel)->toBe(IdChannel::Ivr);
});

it('lets agents verify a pin only when enabled', function (): void {
    $client = Client::factory()->withPin('123456')->create();
    $agent = User::factory()->create();
    $pins = app(PinService::class);

    expect(fn () => $pins->verify($client, '123456', IdChannel::Manual, $agent))->toThrow(IdentificationException::class);

    app(Settings::class)->set(SettingKey::PinAgentVerificationEnabled, true);
    $session = $pins->verify($client, '123456', IdChannel::Manual, $agent);
    expect($session->status)->toBe(IdSessionStatus::Passed)->and($session->agent_user_id)->toBe($agent->id);
});

it('issues a dictation code that identifies the client on its own, once', function (): void {
    $client = Client::factory()->create();
    $otp = app(MobileOtpService::class);

    ['code' => $code, 'formatted' => $formatted] = $otp->issue($client);
    expect($code)->toMatch('/^\d(?:[1-9]\d)*[1-9]$/')->and(strlen($code))->toBe(8)
        ->and($formatted)->toBe(implode(' ', str_split($code, 2)))
        ->and($otp->current($client)['code'])->toBe($code);

    expect($otp->verifyCode('00000000', IdChannel::Ivr))->toBeNull();

    $session = $otp->verifyCode($formatted, IdChannel::Ivr);
    expect($session->status)->toBe(IdSessionStatus::Passed)->and($session->client_id)->toBe($client->id)
        ->and($otp->verifyCode($code, IdChannel::Ivr))->toBeNull('single use')
        ->and($otp->current($client))->toBeNull();
});

it('replaces the live code and never uses zero as the second digit of a pair', function (): void {
    $client = Client::factory()->create();
    $otp = app(MobileOtpService::class);

    $first = $otp->issue($client)['code'];
    $second = $otp->issue($client)['code'];

    expect($otp->verifyCode($first, IdChannel::Ivr))->toBeNull()
        ->and($otp->verifyCode($second, IdChannel::Ivr))->not->toBeNull();

    foreach (range(1, 50) as $i) {
        $code = DictationCode::generate(8);
        expect($code[1])->not->toBe('0')->and($code[3])->not->toBe('0')->and($code[5])->not->toBe('0')->and($code[7])->not->toBe('0');
    }
});

it('rejects a duplicate pin atomically and keeps the previous pin and live codes', function (): void {
    $owner = Client::factory()->withPin('123456')->create();
    $client = Client::factory()->withPin('654321')->create();
    $pins = app(PinService::class);
    $code = app(MobileOtpService::class)->issue($client)['code'];

    expect(fn () => $pins->setPin($client, '123456'))->toThrow(ValidationException::class);

    expect($pins->verify($client, '654321', IdChannel::Ivr)->status)->toBe(IdSessionStatus::Passed)
        ->and($owner->fresh()->pin_lookup)->not->toBe($client->fresh()->pin_lookup)
        ->and(app(MobileOtpService::class)->current($client)['code'])->toBe($code);
    $this->assertDatabaseMissing('audit_log', ['event' => 'client.pin_set', 'subject_id' => $client->id]);
});

it('reserves pins for closed and soft deleted accounts', function (): void {
    $owner = Client::factory()->withPin('123456')->closed()->create();
    $client = Client::factory()->create();
    $pins = app(PinService::class);

    expect(fn () => $pins->setPin($client, '123456'))->toThrow(ValidationException::class);
    $owner->delete();
    expect(fn () => $pins->setPin($client, '123456'))->toThrow(ValidationException::class);
});

it('allows the same client to keep a pin and frees it after replacement or removal', function (): void {
    $owner = Client::factory()->withPin('123456')->create();
    $client = Client::factory()->create();
    $pins = app(PinService::class);

    $pins->setPin($owner, '123456');
    $pins->setPin($owner, '654321');
    $pins->setPin($client, '123456');
    $pins->clearPin($owner);
    $pins->setPin($client, '654321');

    expect($owner->fresh()->pin_lookup)->toBeNull()
        ->and($pins->verify($client, '654321', IdChannel::Ivr)->status)->toBe(IdSessionStatus::Passed);
});

it('keeps the pin lookup out of model serialization and audit records', function (): void {
    $client = Client::factory()->create();
    app(PinService::class)->setPin($client, '123456');

    expect($client->toArray())->not->toHaveKey('pin_lookup')->not->toHaveKey('pin_hash');
    expect(AuditLog::query()->where('subject_id', $client->id)->get()->toJson())
        ->not->toContain($client->pin_lookup, $client->pin_hash, '123456');
});

it('allows shared pins when uniqueness is disabled and preserves them when reenabled', function (): void {
    $owner = Client::factory()->withPin('123456')->create();
    $client = Client::factory()->create();
    $pins = app(PinService::class);
    app(Settings::class)->set(SettingKey::PinUniqueRequired, false);

    $pins->setPin($client, '123456');
    expect($client->pin_lookup)->toBe($owner->pin_lookup);

    app(Settings::class)->set(SettingKey::PinUniqueRequired, true);
    expect($pins->verify($owner, '123456', IdChannel::Ivr)->status)->toBe(IdSessionStatus::Passed)
        ->and($pins->verify($client, '123456', IdChannel::Ivr)->status)->toBe(IdSessionStatus::Passed);

    $third = Client::factory()->create();
    expect(fn () => $pins->setPin($third, '123456'))->toThrow(ValidationException::class);
});

it('needs the pin verification permission on the agent side, not just the setting', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    app(Settings::class)->set(SettingKey::PinAgentVerificationEnabled, true);

    $client = Client::factory()->synced()->withPin('123456')->create();
    $agent = User::factory()->withRole(Role::Agent)->create();
    $this->actingAs($agent);

    expect(Livewire::test(Identify::class, ['client' => $client->id])->instance()->agentPinEnabled())->toBeTrue();

    $agent->roles()->first()->revokePermissionTo(Permission::IdentificationPinVerify->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($agent->fresh());

    $page = Livewire::test(Identify::class, ['client' => $client->id]);
    expect($page->instance()->agentPinEnabled())->toBeFalse();

    // The Livewire action is callable directly: the server has to refuse it too.
    $page->set('pin', '123456')->call('verifyPin');
    expect(IdSession::query()->where('client_id', $client->id)->where('method', IdMethod::Pin)->exists())->toBeFalse();
});

it('drops the decryptable copy of a code once it is used or superseded', function (): void {
    $client = Client::factory()->synced()->create();
    $codes = app(OneTimeCodes::class);

    $first = $codes->issueUnique($client, OneTimeCodePurpose::MobileOtp, fn () => app(DictationCode::class)->generate(8), 10, 3);
    expect($first['record']->fresh()->secret_encrypted)->not->toBeNull();

    // A new code of the same purpose supersedes the previous one.
    $second = $codes->issueUnique($client, OneTimeCodePurpose::MobileOtp, fn () => app(DictationCode::class)->generate(8), 10, 3);
    expect($first['record']->fresh()->secret_encrypted)->toBeNull();

    expect($codes->consume(OneTimeCodePurpose::MobileOtp, $second['code']))->not->toBeNull()
        ->and($second['record']->fresh()->secret_encrypted)->toBeNull('a used code keeps no readable copy');
});

it('holds the global pin lock while it checks uniqueness and writes, so two clients cannot take the same pin', function (): void {
    app(Settings::class)->set(SettingKey::PinUniqueRequired, true);
    $client = Client::factory()->synced()->create();
    $held = [];

    Event::listen('eloquent.saving: '.Client::class, function (Client $saved) use (&$held): void {
        // A second lock instance can only take the key when nobody holds it.
        $probe = Cache::lock('hdid:pin-assignment', 1);
        $free = $probe->get();
        if ($free) {
            $probe->release();
        }
        $held[] = ! $free;
    });

    app(PinService::class)->setPin($client, '654321');

    expect($held)->not->toBeEmpty()
        ->and(array_unique($held))->toBe([true], 'the uniqueness check and the write share one critical section');

    // With the lock proven, a competing assignment can only arrive after the
    // first one finished, and is refused.
    $other = Client::factory()->synced()->create();
    expect(fn () => app(PinService::class)->setPin($other, '654321'))->toThrow(ValidationException::class);
    expect($other->fresh()->pin_hash)->toBeNull('nothing is written when the pin turns out to be taken');
});
