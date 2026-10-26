<?php

use App\Support\DomainNameRule;
use App\Support\EmailAddressRule;
use App\Support\InputLimits;
use App\Support\PhoneNumberRule;
use Illuminate\Support\Facades\Validator;

/**
 * An address of exactly the given length: a local part of $local octets
 * and a domain of labels filling the rest. The labels stay at 62 octets:
 * the RFC parser the framework uses counts the dot before a label into it.
 */
function addressOfLength(int $total, int $local = 64): string
{
    $length = $total - $local - 1;
    $labels = [];

    while ($length > 62) {
        $take = min(61, $length - 2);
        $labels[] = str_repeat('d', $take);
        $length -= $take + 1;
    }

    $labels[] = str_repeat('c', $length);

    return str_repeat('l', $local).'@'.implode('.', $labels);
}

function passesRule(mixed $value, object $rule): bool
{
    return Validator::make(['value' => $value], ['value' => [$rule]])->passes();
}

it('accepts an e-mail address of 254 characters and refuses one of 255', function (): void {
    $ok = addressOfLength(254);
    $long = addressOfLength(255);

    expect(strlen($ok))->toBe(254)->and(strlen($long))->toBe(255)
        ->and(passesRule($ok, new EmailAddressRule))->toBeTrue()
        ->and(EmailAddressRule::isValid($ok))->toBeTrue()
        ->and(passesRule($long, new EmailAddressRule))->toBeFalse()
        ->and(EmailAddressRule::problem($long))->toBe('The e-mail address may be at most 254 characters long.');
});

it('limits the part of an e-mail address before the @ to 64 characters', function (): void {
    expect(EmailAddressRule::isValid(str_repeat('a', 64).'@example.com'))->toBeTrue()
        ->and(EmailAddressRule::problem(str_repeat('a', 65).'@example.com'))->toBe('The part of the e-mail address before the @ may be at most 64 characters long.')
        ->and(passesRule(str_repeat('a', 65).'@example.com', new EmailAddressRule))->toBeFalse();
});

it('limits each label of the e-mail domain to 63 characters', function (): void {
    expect(EmailAddressRule::isValid('a@'.str_repeat('b', 63).'.com'))->toBeTrue()
        ->and(EmailAddressRule::isValid('a@'.str_repeat('b', 64).'.com'))->toBeFalse();
});

it('still accepts the addresses the plain email rule accepted and refuses the malformed ones', function (string $address, bool $valid): void {
    expect(EmailAddressRule::isValid($address))->toBe($valid)
        ->and(passesRule($address, new EmailAddressRule))->toBe($valid);
})->with([
    'plain' => ['anna.kovacs@example.com', true],
    'plus tag' => ['anna+portal@example.co.uk', true],
    'internationalised domain' => ['anna@bücher.example', true],
    'address literal' => ['anna@[192.0.2.1]', true],
    'no at sign' => ['anna.example.com', false],
    'empty domain' => ['anna@', false],
    'line break' => ["anna@example.com\nBcc: x@y.hu", false],
    'domain label with an underscore' => ['anna@exa_mple.com', false],
]);

it('gives the framework message for a malformed address and a translated one for a long one', function (): void {
    app()->setLocale('hu');

    $malformed = Validator::make(['email' => 'nope'], ['email' => [new EmailAddressRule]]);
    $long = Validator::make(['email' => str_repeat('a', 65).'@example.com'], ['email' => [new EmailAddressRule]]);

    expect($malformed->errors()->first('email'))->toBe(__('validation.email', ['attribute' => __('validation.attributes.email')]))
        ->and($long->errors()->first('email'))->toBe('Az e-mail-cím @ előtti része legfeljebb 64 karakter lehet.');
});

it('refuses an e-mail value that is not a string', function (): void {
    expect(passesRule(['a@b.hu'], new EmailAddressRule))->toBeFalse();
});

it('limits a domain name to 253 characters and a label to 63', function (): void {
    $label = str_repeat('a', 63);
    $domain253 = implode('.', [$label, $label, $label, str_repeat('b', 61)]);

    expect(strlen($domain253))->toBe(253)
        ->and(DomainNameRule::isValid($domain253))->toBeTrue()
        ->and(DomainNameRule::problem($domain253.'c'))->toBe('The domain may be at most 253 characters long.')
        ->and(DomainNameRule::isValid($label.'.hu'))->toBeTrue()
        ->and(DomainNameRule::problem($label.'a.hu'))->toBe('A part of the domain between two dots may be at most 63 characters long.')
        ->and(DomainNameRule::isValid('bücher.example'))->toBeTrue()
        ->and(DomainNameRule::isValid('-bad.example'))->toBeFalse()
        ->and(DomainNameRule::isValid('bad..example'))->toBeFalse()
        ->and(DomainNameRule::isValid(''))->toBeFalse()
        ->and(passesRule($label.'a.hu', new DomainNameRule))->toBeFalse();
});

it('accepts a typed phone number of 32 characters and refuses one of 33', function (): void {
    $typed = '+36 30 123 4567';
    $padded32 = str_pad($typed, 32, ' ');

    expect(mb_strlen($padded32))->toBe(32)
        ->and(PhoneNumberRule::isValid($padded32))->toBeTrue()
        ->and(passesRule($padded32, new PhoneNumberRule))->toBeTrue()
        ->and(PhoneNumberRule::problem($padded32.' '))->toBe('The phone number may be at most 32 characters long.')
        ->and(passesRule($padded32.' ', new PhoneNumberRule))->toBeFalse()
        ->and(PhoneNumberRule::problem('12'))->toBe('This is not a valid phone number.')
        ->and(passesRule(['+36301234567'], new PhoneNumberRule))->toBeFalse();
});

it('cuts a search term to the search limit', function (): void {
    expect(InputLimits::search('  '.str_repeat('é', 300).'  '))->toBe(str_repeat('é', InputLimits::SEARCH))
        ->and(InputLimits::search(null))->toBe('')
        ->and(InputLimits::search(' anna '))->toBe('anna');
});

it('counts every character of an e-mail address as one, accented ones too (decision of 2026-10-26)', function (): void {
    // 64 accented characters before the @ (128 bytes in UTF-8), the whole address 254 characters.
    $local = str_repeat('é', 64);
    $ok = $local.'@'.substr(addressOfLength(254), 65);
    $long = $local.'@'.substr(addressOfLength(255), 65);

    expect(mb_strlen($ok))->toBe(254)
        ->and(strlen($ok))->toBeGreaterThan(254)
        ->and(EmailAddressRule::isValid($ok))->toBeTrue()
        ->and(passesRule($ok, new EmailAddressRule))->toBeTrue()
        // The form fields' max (Filament maxLength, the portal's maxlength) count characters alike.
        ->and(Validator::make(['email' => $ok], ['email' => ['string', 'max:'.EmailAddressRule::MAX_LENGTH, new EmailAddressRule]])->passes())->toBeTrue()
        ->and(EmailAddressRule::problem($long))->toBe('The e-mail address may be at most 254 characters long.')
        ->and(EmailAddressRule::isValid('árvíztűrő@example.com'))->toBeTrue()
        ->and(EmailAddressRule::isValid($local.'@example.com'))->toBeTrue()
        ->and(EmailAddressRule::problem('é'.$local.'@example.com'))->toBe('The part of the e-mail address before the @ may be at most 64 characters long.');
});
