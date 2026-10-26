<?php

use App\Auth\Passwordless\OneTimeCodes;
use App\Http\Controllers\Api\V1\Client\PhoneNumbersController;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Question;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use App\Support\EmailAddressRule;
use App\Support\PhoneNumberRule;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;

/**
 * Every text field (input or textarea, not a checkbox or button) of the
 * client portal's Vue files, as written in the template.
 *
 * @return list<array{view: string, tag: string}>
 */
function portalLimitFields(): array
{
    $fields = [];

    foreach (File::allFiles(resource_path('js/client')) as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }

        // A tag ends at the first ">" outside a quoted attribute value, so an
        // arrow function in an event handler does not cut it short.
        preg_match_all('/<(?:input|textarea)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s', $file->getContents(), $matches);

        foreach ($matches[0] as $tag) {
            if (preg_match('/\stype="(checkbox|radio|hidden|submit|button|reset|file|range|color|image)"/', $tag) === 1) {
                continue;
            }

            $fields[] = ['view' => $file->getRelativePathname(), 'tag' => $tag];
        }
    }

    return $fields;
}

/**
 * The fixed maxlength of the portal field whose id starts with $id in the view.
 */
function portalLimitFieldMaxLength(string $view, string $id): ?int
{
    $field = collect(portalLimitFields())
        ->where('view', $view)
        ->first(fn (array $field): bool => preg_match('/\s:?id="`?'.preg_quote($id, '/').'/', $field['tag']) === 1);

    expect($field)->not->toBeNull("{$view} has a field {$id}");

    return preg_match('/\smaxlength="(\d+)"/', $field['tag'], $match) === 1 ? (int) $match[1] : null;
}

/**
 * A syntactically valid address of the given total length with a local part
 * of $local characters. Domain labels stay at 62 characters or less: the
 * bundled RFC parser (egulias/email-validator 4.0.4) counts the dot in front
 * of a label and refuses a 63-character one that is not the first, and this
 * address should only ever be refused for its total length.
 */
function portalLimitAddress(int $total, int $local = 64): string
{
    // Room for the labels before ".hu", each counted with the dot after it.
    $remaining = $total - $local - 1 - strlen('hu');
    $labels = [];
    $letter = 'a';

    while ($remaining > 0) {
        $length = min(62, $remaining - 1);
        if ($remaining - $length - 1 === 1) {
            $length--;
        }
        $labels[] = str_repeat($letter++, $length);
        $remaining -= $length + 1;
    }

    return str_repeat('l', $local).'@'.implode('.', [...$labels, 'hu']);
}

beforeEach(function (): void {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->withHeader('Referer', config('app.url'));
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    app()->instance(SmsSender::class, new FakeSmsSender);
});

it('gives every text field of the client portal an upper length bound', function (): void {
    $fields = portalLimitFields();
    $unbounded = collect($fields)
        ->reject(fn (array $field): bool => preg_match('/\s:?maxlength="[^"]+"/', $field['tag']) === 1)
        ->map(fn (array $field): string => $field['view'].': '.$field['tag'])
        ->values()
        ->all();

    expect(count($fields))->toBeGreaterThanOrEqual(10)
        ->and($unbounded)->toBe([]);
});

it('bounds each portal field exactly as the server does', function (string $view, string $id, int $limit): void {
    expect(portalLimitFieldMaxLength($view, $id))->toBe($limit);
})->with([
    'magic link e-mail' => ['views/Login.vue', 'email"', EmailAddressRule::MAX_LENGTH],
    'sms code e-mail' => ['views/Login.vue', 'email2', EmailAddressRule::MAX_LENGTH],
    'new phone number, as typed' => ['views/Phones.vue', 'number', PhoneNumberRule::RAW_MAX_LENGTH],
    'phone label' => ['views/Phones.vue', 'label', 50],
    'phone verification code' => ['views/Phones.vue', 'verify-', PhoneNumbersController::VERIFY_CODE_LENGTH],
    'answer' => ['views/Questions.vue', 'next-answer', 200],
    'replacement answer' => ['views/Questions.vue', 'replace-', 200],
]);

it('reads from the login link only a token of the issued length', function (): void {
    $page = File::get(resource_path('js/client/views/MagicLink.vue'));

    expect($page)->toContain('token=([A-Za-z0-9]{'.OneTimeCodes::TOKEN_LENGTH.'})(?![A-Za-z0-9])');
});

it('builds test addresses of the intended shape', function (): void {
    $address = portalLimitAddress(254);
    [$local, $domain] = explode('@', $address);

    expect(strlen($address))->toBe(254)
        ->and(strlen($local))->toBe(64)
        ->and(collect(explode('.', $domain))->max(fn (string $label): int => strlen($label)))->toBeLessThanOrEqual(62)
        ->and(strlen(portalLimitAddress(255)))->toBe(255)
        ->and(strlen(portalLimitAddress(100, local: 65)))->toBe(100);
});

it('takes a sign-in address as long as the portal field allows', function (string $endpoint): void {
    $response = $this->postJson($endpoint, ['email' => portalLimitAddress(EmailAddressRule::MAX_LENGTH), 'code' => '123456']);

    expect($response->status())->not->toBe(500);
    $response->assertJsonMissingValidationErrors('email');
})->with(['/api/v1/client/auth/magic-link', '/api/v1/client/auth/otp/request', '/api/v1/client/auth/otp/verify']);

it('refuses a longer sign-in address with a validation error and keeps it out of the audit log', function (string $endpoint, string $address): void {
    $this->postJson($endpoint, ['email' => $address, 'code' => '123456'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    expect(AuditLog::query()->where('context', 'like', '%'.substr($address, 0, 60).'%')->exists())->toBeFalse();
})->with(['/api/v1/client/auth/magic-link', '/api/v1/client/auth/otp/request', '/api/v1/client/auth/otp/verify'])
    ->with([
        'one character over the total' => fn (): string => portalLimitAddress(EmailAddressRule::MAX_LENGTH + 1),
        'local part over 64 characters' => fn (): string => portalLimitAddress(100, local: EmailAddressRule::LOCAL_MAX_LENGTH + 1),
        'a megabyte' => fn (): string => str_repeat('a', 1_000_000).'@example.hu',
    ]);

it('takes an sms code of exactly the set length and refuses anything else as invalid input', function (int $length, string $code, bool $valid): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsLength, $length);
    $response = $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'nobody@example.hu', 'code' => $code]);

    expect($response->status())->not->toBe(500);
    $valid ? $response->assertJsonMissingValidationErrors('code') : $response->assertUnprocessable()->assertJsonValidationErrors('code');
})->with([
    'the default six' => [6, '123456', true],
    'eight when eight are set' => [8, '12345678', true],
    'six when eight are set' => [8, '123456', false],
    'shortest setting' => [4, '1234', true],
    'longest setting' => [10, '1234567890', true],
    'too long' => [6, '1234567', false],
    'letters' => [6, 'abcdef', false],
    'spaces inside' => [6, '123 456', false],
]);

it('tells the portal how many digits the sms code has, and the field asks for exactly these', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsLength, 8);

    $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath('otp_sms.code_length', 8);

    $field = collect(portalLimitFields())->where('view', 'views/Login.vue')->first(fn (array $field): bool => str_contains($field['tag'], 'id="code"'));
    expect($field['tag'] ?? '')->toContain(':maxlength="otpPolicy.code_length"')
        ->and($field['tag'] ?? '')->toContain(':minlength="otpPolicy.code_length"')
        ->and((string) file_get_contents(resource_path('js/client/store.js')))->toContain('code_length: '.SettingKey::ClientLoginOtpSmsLength->default());
});

it('names the code field in the language of the portal', function (): void {
    app()->setLocale('hu');

    $this->postJson('/api/v1/client/auth/otp/verify', ['email' => 'nobody@example.hu', 'code' => '12'], ['Accept-Language' => 'hu'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.code.0', fn (string $message): bool => str_contains($message, 'SMS-ben kapott kód'));
});

it('takes only a login link token of the issued form', function (string $token, bool $valid): void {
    $response = $this->postJson('/api/v1/client/auth/magic-link/consume', ['token' => $token]);

    expect($response->status())->not->toBe(500);
    $valid ? $response->assertJsonMissingValidationErrors('token') : $response->assertUnprocessable()->assertJsonValidationErrors('token');
})->with([
    'issued form, unknown' => [str_repeat('A1b2', OneTimeCodes::TOKEN_LENGTH / 4), true],
    'one character longer' => [str_repeat('A1b2', OneTimeCodes::TOKEN_LENGTH / 4).'x', false],
    'one character shorter' => [substr(str_repeat('A1b2', OneTimeCodes::TOKEN_LENGTH / 4), 1), false],
    'not letters and digits' => [str_repeat('A1b-', OneTimeCodes::TOKEN_LENGTH / 4), false],
    'accented letters' => [str_repeat('Á1b2', OneTimeCodes::TOKEN_LENGTH / 4), false],
]);

it('takes a typed phone number as long as the portal field allows and refuses a longer one', function (): void {
    $client = Client::factory()->synced()->create();
    Sanctum::actingAs($client, guard: 'client');

    $atLimit = '+36'.str_repeat(' ', PhoneNumberRule::RAW_MAX_LENGTH - 14).'20 222 2222';
    expect(mb_strlen($atLimit))->toBe(PhoneNumberRule::RAW_MAX_LENGTH);

    $this->postJson('/api/v1/client/phone-numbers', ['number' => $atLimit])->assertOk();

    $this->postJson('/api/v1/client/phone-numbers', ['number' => '+36'.str_repeat(' ', PhoneNumberRule::RAW_MAX_LENGTH - 13).'20 333 3333'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('number');
    $this->postJson('/api/v1/client/phone-numbers', ['number' => str_repeat('1', 100_000)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('number');

    expect($client->phoneNumbers()->pluck('number_e164')->all())->toBe(['+36202222222']);
});

it('keeps the phone label and the answers to the portal field limits', function (): void {
    $client = Client::factory()->synced()->create();
    Sanctum::actingAs($client, guard: 'client');
    $question = Question::factory()->create();

    $this->postJson('/api/v1/client/phone-numbers', ['number' => '+36202222222', 'label' => str_repeat('é', 50)])->assertOk();
    $this->postJson('/api/v1/client/phone-numbers', ['number' => '+36203333333', 'label' => str_repeat('é', 51)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('label');

    $this->putJson('/api/v1/client/answers/'.$question->id, ['answer' => str_repeat('ő', 200)])->assertOk();
    $this->putJson('/api/v1/client/answers/'.$question->id, ['answer' => str_repeat('ő', 201)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('answer');
});
