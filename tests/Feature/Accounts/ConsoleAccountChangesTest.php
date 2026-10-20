<?php

use App\Auth\Role;
use App\Models\AuditLog;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
    $this->watcher = User::factory()->synced()->withRole(Role::SuperAdmin)->create(['email' => 'watcher@corp.hu']);
});

it('changes an existing account from the command line only with --force while two-factor sign-in is on', function (): void {
    app(Settings::class)->set(SettingKey::UserLoginMfaEnabled, true);
    $existing = User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'target@corp.hu']);
    $before = $existing->password;

    $this->artisan('hdid:make-admin', ['email' => 'target@corp.hu', '--password' => 'a-long-password-1'])->assertFailed();
    expect($existing->fresh()->password)->toBe($before)
        ->and($existing->fresh()->hasRole(Role::SuperAdmin->value))->toBeFalse()
        ->and(AuditLog::query()->where('event', 'account.admin_made_by_command')->exists())->toBeFalse();

    $this->artisan('hdid:make-admin', ['email' => 'target@corp.hu', '--password' => 'a-long-password-1', '--force' => true])->assertSuccessful();
    expect(Hash::check('a-long-password-1', $existing->fresh()->password))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'account.admin_made_by_command')->sole()->context)
        ->toMatchArray(['via' => 'console', 'created' => false, 'promoted' => true, 'forced' => true, 'mfa_enabled' => true])
        ->toHaveKeys(['os_user', 'host']);
});

it('creates a new super-admin from the command line with two-factor sign-in on, and tells the other super-admins', function (): void {
    app(Settings::class)->set(SettingKey::UserLoginMfaEnabled, true);

    $this->artisan('hdid:make-admin', ['email' => 'new@corp.hu', '--password' => 'a-long-password-1'])->assertSuccessful();

    $mail = OutboundMessage::query()->where('recipient', 'watcher@corp.hu')->sole();
    expect($mail->body)->toContain('new@corp.hu')->toContain('hdid:make-admin')
        ->and(OutboundMessage::query()->where('recipient', 'new@corp.hu')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'account.admin_made_by_command')->sole()->context)->toMatchArray(['created' => true]);
});

it('audits and reports the second-factor reset and the switch-off from the command line', function (): void {
    app(Settings::class)->set(SettingKey::UserLoginMfaEnabled, true);
    User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'lost@corp.hu', 'mfa_email_enabled_at' => now()]);

    $this->artisan('hdid:mfa-reset', ['email' => 'lost@corp.hu', '--off' => true])->assertSuccessful();

    expect(AuditLog::query()->where('event', 'account.mfa_reset_by_command')->sole()->context)->toMatchArray(['via' => 'console'])
        ->and(AuditLog::query()->where('event', 'mfa.switched_off_by_command')->count())->toBe(1)
        ->and(OutboundMessage::query()->where('recipient', 'watcher@corp.hu')->count())->toBe(2);
});
