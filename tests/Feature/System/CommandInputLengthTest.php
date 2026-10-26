<?php

use App\Models\ApiKey;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Support\EmailAddressRule;
use App\Support\PhoneNumberRule;

/**
 * The commands take what the operator types as they are given: an address
 * or a name beyond its standard length is refused with the reason and a
 * non-zero exit code, before anything is written (on MariaDB in strict
 * mode a longer value failed with a database error).
 */
it('refuses an over-long address or name for the first administrator', function (array $arguments, string $reason): void {
    $this->artisan('hdid:make-admin', $arguments + ['--password' => 'a-long-password-1'])
        ->expectsOutputToContain($reason)
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
})->with([
    'address over 254 characters' => [['email' => str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 61).'.hu'], 'The e-mail address may be at most 254 characters long.'],
    'local part over 64 characters' => [['email' => str_repeat('a', 65).'@x.hu'], 'The part of the e-mail address before the @ may be at most 64 characters long.'],
    'malformed address' => [['email' => 'admin-at-x.hu'], 'This is not a valid e-mail address.'],
    'name over 255 characters' => [['email' => 'admin@x.hu', '--name' => str_repeat('N', 256)], 'The name may be at most 255 characters long.'],
]);

it('still creates the first administrator with a name of 255 characters', function (): void {
    $this->artisan('hdid:make-admin', ['email' => 'admin@x.hu', '--name' => str_repeat('N', 255), '--password' => 'a-long-password-1'])->assertSuccessful();

    expect(User::query()->sole()->name)->toBe(str_repeat('N', 255));
});

it('refuses a machine key name longer than the panel allows', function (): void {
    $this->artisan('hdid:api-key:create', ['name' => str_repeat('k', 101), 'scope' => 'callcenter'])
        ->expectsOutputToContain('The name may be at most 100 characters long.')
        ->assertFailed();
    $this->artisan('hdid:api-key:create', ['name' => str_repeat('k', 100), 'scope' => 'callcenter'])->assertSuccessful();

    expect(ApiKey::query()->pluck('name')->all())->toBe([str_repeat('k', 100)]);
});

it('refuses an over-long test address or number', function (): void {
    $this->artisan('hdid:test-mail', ['address' => str_repeat('a', 65).'@x.hu'])
        ->expectsOutputToContain('The part of the e-mail address before the @ may be at most '.EmailAddressRule::LOCAL_MAX_LENGTH.' characters long.')
        ->assertFailed();
    $this->artisan('hdid:test-sms', ['number' => '+36'.str_repeat(' ', PhoneNumberRule::RAW_MAX_LENGTH - 13).'30 123 4567'])
        ->expectsOutputToContain('The phone number may be at most 32 characters long.')
        ->assertFailed();

    expect(OutboundMessage::query()->count())->toBe(0);
});
