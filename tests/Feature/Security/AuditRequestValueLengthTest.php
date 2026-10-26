<?php

use App\Audit\Auditor;
use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Enums\PrincipalType;
use App\Filament\Pages\Auth\Login;
use App\Models\AuditLog;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Livewire\Livewire;

/**
 * The length of the longest text anywhere in an audit entry's context.
 *
 * @param  array<array-key, mixed>  $context
 */
function longestAuditText(array $context): int
{
    $longest = 0;
    array_walk_recursive($context, function (mixed $value) use (&$longest): void {
        if (is_string($value)) {
            $longest = max($longest, mb_strlen($value));
        }
    });

    return $longest;
}

/**
 * What an anonymous caller types into a login form ends up in the audit log
 * of the refusal. Without a bound a megabyte "address" per request (five a
 * minute per address) would fill the database, the archive and the backups.
 */
it('clips what an anonymous login request sent before it reaches the audit log', function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    $huge = str_repeat('a', 200_000).'@x.hu';

    $this->postJson('/api/v1/client/auth/magic-link', ['email' => $huge]);
    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => $huge, 'code' => '123456']);

    expect(fn () => app(ClientPasswordlessLogin::class)->verifyOtp($huge, '123456'))->toThrow(LoginRejectedException::class)
        ->and(fn () => app(AccountLogin::class)->resolveByEmail(PrincipalType::Client, $huge, 'magic_link'))->toThrow(LoginRejectedException::class);
    app(AccountLogin::class)->recordRejection(PrincipalType::Client, 'otp_sms', LoginRejection::InvalidCredentials, ['email' => $huge, 'detail' => ['nested' => $huge]]);

    // The per-address request limit audits the address it throttles, too.
    foreach (range(1, ClientPasswordlessLogin::REQUESTS_PER_EMAIL + 1) as $ignored) {
        app(ClientPasswordlessLogin::class)->requestMagicLink($huge);
    }

    $entries = AuditLog::query()->where('event', 'like', 'login.%')->get();
    expect($entries->where('event', 'login.rejected')->count())->toBeGreaterThanOrEqual(3)
        ->and($entries->where('event', 'login.request_throttled')->count())->toBeGreaterThanOrEqual(1)
        ->and($entries->map(fn (AuditLog $entry): int => longestAuditText($entry->context ?? []))->max())->toBeLessThanOrEqual(Auditor::REQUEST_VALUE_MAX_LENGTH)
        ->and(AuditLog::query()->where('context', 'like', '%'.str_repeat('a', Auditor::REQUEST_VALUE_MAX_LENGTH + 1).'%')->exists())->toBeFalse('no entry holds the full text');
});

it('records at most an address worth of the e-mail field when the password login is switched off', function (): void {
    app(Settings::class)->set(SettingKey::UserLoginPasswordEnabled, false);

    Livewire::test(Login::class)
        ->set('data.email', str_repeat('A', 50_000).'@staff.hu')
        ->set('data.password', 'whatever-1')
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $entry = AuditLog::query()->where('event', 'login.rejected')->sole();
    expect($entry->context['reason'])->toBe(LoginRejection::MethodDisabled->value)
        ->and(mb_strlen($entry->context['email']))->toBe(254)
        ->and($entry->context['email'])->toBe(str_repeat('a', 254));
});

it('keeps long values of entries that do not record anonymous input', function (): void {
    $long = str_repeat('x', 5_000);

    app(Auditor::class)->record('setting.changed', null, ['key' => 'test.long_value', 'new' => $long]);

    expect(AuditLog::query()->where('event', 'setting.changed')->where('context->key', 'test.long_value')->sole()->context['new'])->toBe($long);
});
