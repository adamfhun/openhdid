<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Settings\SettingKey;
use App\Settings\Settings;

it('returns the default when nothing is stored', function (): void {
    $settings = app(Settings::class);

    expect($settings->int(SettingKey::QaMaxQuestionsPerSession))->toBe(4)
        ->and($settings->bool(SettingKey::PinAgentVerificationEnabled))->toBeFalse();
});

it('stores, casts and audits a changed value', function (): void {
    $settings = app(Settings::class);

    $settings->set(SettingKey::QaMaxQuestionsPerSession, '6');
    $settings->set(SettingKey::PinAgentVerificationEnabled, '1');

    expect($settings->int(SettingKey::QaMaxQuestionsPerSession))->toBe(6)
        ->and($settings->bool(SettingKey::PinAgentVerificationEnabled))->toBeTrue()
        ->and(Setting::query()->whereIn('key', [SettingKey::QaMaxQuestionsPerSession->value, SettingKey::PinAgentVerificationEnabled->value])->count())->toBe(2)
        ->and(AuditLog::query()->where('event', 'setting.changed')->where('context->key', SettingKey::QaMaxQuestionsPerSession->value)->count())->toBe(1);

    $log = AuditLog::query()->where('event', 'setting.changed')->where('context->key', SettingKey::QaMaxQuestionsPerSession->value)->first();
    expect($log->context)->toMatchArray(['key' => SettingKey::QaMaxQuestionsPerSession->value, 'from' => 4, 'to' => 6]);
});

it('does not audit an unchanged value', function (): void {
    app(Settings::class)->set(SettingKey::QaMaxQuestionsPerSession, 4);

    expect(AuditLog::query()->where('context->key', SettingKey::QaMaxQuestionsPerSession->value)->count())->toBe(0);
});

it('invalidates its cache on write', function (): void {
    $settings = app(Settings::class);
    $settings->all();

    Setting::query()->create(['key' => SettingKey::PinMaxFailedAttempts->value, 'value' => 7]);
    expect($settings->int(SettingKey::PinMaxFailedAttempts))->toBe(5);

    $settings->set(SettingKey::PinMaxFailedAttempts, 8);
    expect(app(Settings::class)->int(SettingKey::PinMaxFailedAttempts))->toBe(8);
});
