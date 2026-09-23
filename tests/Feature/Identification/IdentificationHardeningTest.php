<?php

use App\Auth\Passwordless\OneTimeCodes;
use App\Auth\Role;
use App\Enums\IdChannel;
use App\Enums\IdSessionStatus;
use App\Enums\OneTimeCodePurpose;
use App\Filament\Admin\Pages\ManageSettings;
use App\Filament\Admin\Pages\SearchClients;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Identification\ClientAnswers;
use App\Identification\IdentificationException;
use App\Identification\MobileOtpService;
use App\Identification\PinService;
use App\Identification\QaSessionEngine;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientAnswer;
use App\Models\OneTimeCode;
use App\Models\Question;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

it('never issues a dictated code shorter than eight digits', function (): void {
    app(Settings::class)->set(SettingKey::MobileOtpLength, 4);

    expect(app(MobileOtpService::class)->length())->toBe(8)
        ->and(strlen(app(MobileOtpService::class)->issue(Client::factory()->create())['code']))->toBe(8);
});

it('caps failed agent verifications per hour, without counting successes', function (): void {
    app(Settings::class)->set(SettingKey::AgentAttemptsPerHour, 2);
    app(Settings::class)->set(SettingKey::PinAgentVerificationEnabled, true);
    $agent = User::factory()->create();
    RateLimiter::clear('agent-verifications:'.$agent->id);
    $client = Client::factory()->withPin('123456')->create();
    $otp = app(MobileOtpService::class);
    $pins = app(PinService::class);

    expect($pins->verify($client, '123456', IdChannel::Manual, $agent)->status)->toBe(IdSessionStatus::Passed);
    expect($otp->verifyCode('11111111', IdChannel::Manual, $agent))->toBeNull();
    expect($pins->verify($client, '000000', IdChannel::Manual, $agent)->status)->toBe(IdSessionStatus::Failed);

    expect(fn () => $otp->verifyCode('11111111', IdChannel::Manual, $agent))->toThrow(IdentificationException::class);
    expect(fn () => $pins->verify($client, '123456', IdChannel::Manual, $agent))->toThrow(IdentificationException::class);

    // The IVR is not an agent: no cap there.
    expect($otp->verifyCode('11111111', IdChannel::Ivr))->toBeNull();

    $this->travel(61)->minutes();
    expect($pins->verify($client, '123456', IdChannel::Manual, $agent)->status)->toBe(IdSessionStatus::Passed);
});

it('audits every wrong pin and doubles the lockout each time', function (): void {
    app(Settings::class)->set(SettingKey::PinMaxFailedAttempts, 2);
    app(Settings::class)->set(SettingKey::PinLockoutMinutes, 10);
    $client = Client::factory()->withPin('123456')->create();
    $pins = app(PinService::class);

    $pins->verify($client, '000000', IdChannel::Ivr);
    $pins->verify($client->fresh(), '000000', IdChannel::Ivr);
    expect($client->fresh()->pin_lockout_count)->toBe(1)
        ->and($client->fresh()->pin_locked_until->diffInMinutes(now(), true))->toEqualWithDelta(10, 1)
        ->and(AuditLog::query()->where('event', 'client.pin_wrong')->count())->toBe(2)
        ->and(AuditLog::query()->where('event', 'client.pin_wrong')->first()->context)->toMatchArray(['channel' => 'ivr', 'attempt' => 1]);

    $this->travel(11)->minutes();
    $pins->verify($client->fresh(), '000000', IdChannel::Ivr);
    $pins->verify($client->fresh(), '000000', IdChannel::Ivr);
    expect($client->fresh()->pin_lockout_count)->toBe(2)
        ->and($client->fresh()->pin_locked_until->diffInMinutes(now(), true))->toEqualWithDelta(20, 1)
        ->and($pins->lockoutMinutes(12))->toBe(1440);

    $this->travel(21)->minutes();
    expect($pins->verify($client->fresh(), '123456', IdChannel::Ivr)->status)->toBe(IdSessionStatus::Passed)
        ->and($client->fresh()->pin_lockout_count)->toBe(0);
});

it('counts overlapping wrong pins from stale instances without losing an attempt', function (): void {
    app(Settings::class)->set(SettingKey::PinMaxFailedAttempts, 5);
    $client = Client::factory()->withPin('123456')->create();
    $pins = app(PinService::class);

    // Two requests loaded the client before either wrote: each instance still
    // believes no attempt has failed yet.
    $first = Client::query()->findOrFail($client->id);
    $second = Client::query()->findOrFail($client->id);

    expect($pins->verify($first, '000000', IdChannel::Ivr)->outcome_reason)->toBe('wrong_pin')
        ->and($pins->verify($second, '000000', IdChannel::Ivr)->outcome_reason)->toBe('wrong_pin')
        ->and($client->fresh()->pin_failed_attempts)->toBe(2)
        ->and(AuditLog::query()->where('event', 'client.pin_wrong')->pluck('context')->map(fn (array $c) => $c['attempt'])->all())->toBe([1, 2]);
});

it('refuses pins and live codes of accounts that were closed or lost access in the meantime', function (): void {
    $client = Client::factory()->withPin('123456')->create();
    $code = app(MobileOtpService::class)->issue($client)['code'];

    $client->update(['explicit_package' => 'Nothing', 'implicit_package' => null]);
    expect(app(PinService::class)->verify($client->fresh(), '123456', IdChannel::Ivr)->outcome_reason)->toBe('account_closed')
        ->and(app(MobileOtpService::class)->verifyCode($code, IdChannel::Ivr))->toBeNull()
        ->and(AuditLog::query()->where('event', 'id_session.code_refused')->where('subject_id', $client->id)->exists())->toBeTrue();

    $client = Client::factory()->withPin('123456')->create();
    $code = app(MobileOtpService::class)->issue($client)['code'];
    $client->forceFill(['closed_at' => now()])->save();
    expect(app(MobileOtpService::class)->verifyCode($code, IdChannel::Ivr))->toBeNull()
        ->and(app(PinService::class)->verify($client->fresh(), '123456', IdChannel::Ivr)->status)->toBe(IdSessionStatus::Failed);
});

it('revokes every live secret when the pin changes and frees the lookup of used codes', function (): void {
    $client = Client::factory()->create();
    $otp = app(MobileOtpService::class);
    $code = $otp->issue($client)['code'];
    $otp->issue($client, purpose: OneTimeCodePurpose::IvrCode);

    expect(OneTimeCode::query()->where('client_id', $client->id)->whereNotNull('lookup')->count())->toBe(2);

    app(PinService::class)->setPin($client, '123456');
    expect(OneTimeCode::query()->where('client_id', $client->id)->usable()->count())->toBe(0)
        ->and(OneTimeCode::query()->where('client_id', $client->id)->whereNotNull('lookup')->count())->toBe(0)
        ->and($otp->verifyCode($code, IdChannel::Ivr))->toBeNull();

    $code = $otp->issue($client)['code'];
    $otp->verifyCode($code, IdChannel::Ivr);
    expect(OneTimeCode::query()->whereNotNull('lookup')->count())->toBe(0);
});

it('draws again when the generated code collides with a live one', function (): void {
    $first = Client::factory()->create();
    $second = Client::factory()->create();
    $otp = app(MobileOtpService::class);
    $code = $otp->issue($first)['code'];

    $draws = [$code, $code, '12345678'];
    $issued = app(OneTimeCodes::class)->issueUnique($second, OneTimeCodePurpose::MobileOtp, function () use (&$draws): string {
        return array_shift($draws);
    }, 5, 1);

    expect($issued['code'])->toBe('12345678')->and($draws)->toBe([]);
});

it('lets a client remove an answer and answer the same question again', function (): void {
    $client = Client::factory()->create();
    $question = Question::factory()->create();
    $answers = app(ClientAnswers::class);

    $answers->save($client, $question, 'first');
    $answers->remove($client, $question);
    expect(ClientAnswer::withTrashed()->count())->toBe(1)->and($answers->usableCount($client))->toBe(0);

    $answers->save($client, $question, 'second');
    expect(ClientAnswer::query()->count())->toBe(1)
        ->and($answers->usable($client)->first()->answer)->toBe('second');
});

it('cancels open sessions of a client and refuses a second agent on the same client', function (): void {
    $questions = Question::factory()->count(5)->create();
    $client = Client::factory()->create();
    foreach ($questions as $i => $question) {
        app(ClientAnswers::class)->save($client, $question, 'a'.$i);
    }
    [$first, $second] = User::factory()->count(2)->create();
    $engine = app(QaSessionEngine::class);

    $session = $engine->start($client, $first);
    expect($engine->start($client, $first)->id)->toBe($session->id);
    expect(fn () => $engine->start($client, $second))->toThrow(IdentificationException::class, $first->name);

    expect($engine->cancelOpenSessions($client, $second))->toBe(0)
        ->and($engine->cancelOpenSessions($client, $first, 'call_reassigned'))->toBe(1)
        ->and($session->fresh()->status)->toBe(IdSessionStatus::Cancelled)
        ->and($session->fresh()->outcome_reason)->toBe('call_reassigned');

    expect($engine->start($client, $second)->agent_user_id)->toBe($second->id);
});

describe('panel', function (): void {
    beforeEach(function (): void {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->withRole(Role::Admin)->create();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');
    });

    it('rejects contradictory q-a thresholds and too short codes on the settings page', function (): void {
        Livewire::test(ManageSettings::class)
            ->fillForm([
                ManageSettings::fieldName(SettingKey::QaMaxQuestionsPerSession) => 2,
                ManageSettings::fieldName(SettingKey::QaMinAcceptedToPass) => 3,
                ManageSettings::fieldName(SettingKey::QaMinAnsweredQuestionsRequired) => 1,
            ])
            ->call('save')
            ->assertHasFormErrors([ManageSettings::fieldName(SettingKey::QaMinAcceptedToPass), ManageSettings::fieldName(SettingKey::QaMinAnsweredQuestionsRequired)]);

        expect(app(Settings::class)->int(SettingKey::QaMinAcceptedToPass))->toBe(2);

        Livewire::test(ManageSettings::class)
            ->fillForm([ManageSettings::fieldName(SettingKey::MobileOtpLength) => 6])
            ->call('save')
            ->assertHasFormErrors([ManageSettings::fieldName(SettingKey::MobileOtpLength)]);
    });

    it('audits who looked at a client and what was searched', function (): void {
        $client = Client::factory()->synced()->create(['name' => 'Kovács Anna']);

        Livewire::test(ViewClient::class, ['record' => $client->id])->assertOk();
        Livewire::test(SearchClients::class)->set('q', 'Kov')->set('q', 'K');

        expect(AuditLog::query()->where('event', 'client.viewed')->where('subject_id', $client->id)->where('actor_id', $this->admin->id)->exists())->toBeTrue()
            ->and(AuditLog::query()->where('event', 'client.searched')->pluck('context')->map(fn (array $c) => $c['term'])->all())->toBe(['Kov']);
    });
});
