<?php

use App\Auth\Role;
use App\Enums\PhoneNumberSource;
use App\Filament\Admin\Pages\Identify;
use App\Filament\Admin\Pages\SearchClients;
use App\Filament\Admin\Pages\VerifyCode;
use App\Filament\Admin\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Filament\Admin\Resources\Clients\Pages\CreateClient;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\PhoneNumbersRelationManager;
use App\Filament\Admin\Resources\IdSessions\Pages\ManageIdSessions;
use App\Filament\Admin\Resources\MessageTemplates\Pages\EditMessageTemplate;
use App\Filament\Admin\Resources\NewsPosts\Pages\ManageNewsPosts;
use App\Filament\Admin\Resources\SyncRuns\Pages\ManageSyncRuns;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Concerns\LimitsSearchInput;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\MfaChallenge;
use App\Filament\Pages\Auth\MfaSetup;
use App\Identification\PinService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\IdSession;
use App\Models\MessageTemplate;
use App\Models\NewsPost;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\EmailAddressRule;
use App\Support\InputLimits;
use App\Support\PhoneNumberRule;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Concerns\InteractsWithTable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/**
 * Every bound on what a panel user types: the field shows it (maxLength,
 * maxlength), the server enforces the same number, and an oversized value
 * gets a translated validation message instead of a database error.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
});

/**
 * An address of exactly $total characters (252 to 311) with a full
 * 64-character local part; Laravel's plain "email" rule takes it at any of
 * these lengths.
 */
function panelLimitsAddress(int $total): string
{
    return str_repeat('l', 64).'@'.str_repeat('a', 60).'.'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.'.str_repeat('d', $total - 251).'.hu';
}

/**
 * Every text field built in the panel code, with its fluent chain: the
 * chain has to carry an upper bound (or make the field numeric).
 *
 * @return list<array{0: string, 1: string}>
 */
function panelTextFieldChains(): array
{
    $found = [];

    foreach (File::allFiles(app_path('Filament')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $tokens = token_get_all($file->getContents());
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], ['TextInput', 'Textarea', 'RichEditor', 'MarkdownEditor'], true)) {
                continue;
            }

            $next = array_values(array_filter(array_slice($tokens, $i + 1, 4), fn ($t): bool => ! (is_array($t) && $t[0] === T_WHITESPACE)));
            if (! is_array($next[0] ?? null) || $next[0][0] !== T_DOUBLE_COLON || ! is_array($next[1] ?? null) || $next[1][1] !== 'make') {
                continue;
            }

            $depth = 0;
            $chain = '';
            for ($j = $i; $j < $count; $j++) {
                $part = $tokens[$j];
                $text = is_array($part) ? $part[1] : $part;

                if (in_array($text, ['(', '['], true) || $text === '{' || (is_array($part) && in_array($part[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($depth === 0 && in_array($text, [',', ';'], true)) {
                    break;
                }

                $chain .= $text;
            }

            $found[] = [str_replace(base_path().'/', '', $file->getPathname()).':'.$token[2], $chain];
        }
    }

    return $found;
}

/**
 * The opening tags of the text inputs in the panel's own views.
 *
 * @return list<array{0: string, 1: string}>
 */
function panelViewTextInputs(): array
{
    $found = [];

    foreach (File::allFiles(resource_path('views/filament')) as $file) {
        $contents = $file->getContents();
        preg_match_all('/<(x-filament::input|input|textarea)(?=[\s\/>])/', $contents, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$match, $offset]) {
            // The tag ends at the first ">" outside a quoted attribute value.
            $quote = null;
            $end = $offset;
            for ($k = $offset; $k < strlen($contents); $k++) {
                $char = $contents[$k];
                if ($quote !== null) {
                    $quote = $char === $quote ? null : $quote;
                } elseif ($char === '"') {
                    $quote = $char;
                } elseif ($char === '>') {
                    $end = $k;
                    break;
                }
            }

            $tag = substr($contents, $offset, $end - $offset + 1);
            preg_match('/\stype="([a-z]+)"/', $tag, $type);

            if (in_array($type[1] ?? 'text', ['hidden', 'checkbox', 'radio', 'submit', 'button', 'file', 'number', 'date', 'datetime-local', 'color'], true)) {
                continue;
            }

            $found[] = [str_replace(base_path().'/', '', $file->getPathname()).':'.(substr_count(substr($contents, 0, $offset), "\n") + 1), $tag];
        }
    }

    return $found;
}

/**
 * The panel's Livewire components with a table, by class name.
 *
 * @return list<class-string>
 */
function panelTableComponents(): array
{
    $classes = [];

    foreach (File::allFiles(app_path('Filament')) as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file->getPathname(), app_path().'/'));

        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        if (in_array(InteractsWithTable::class, class_uses_recursive($class), true)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

it('gives every text field of the panel forms an upper length bound', function (): void {
    // The settings page bounds its text fields through limitLength(), which wraps the field.
    $wrapped = function (array $field): bool {
        [$path, $line] = explode(':', $field[0]);

        return str_contains((string) (file(base_path($path))[(int) $line - 1] ?? ''), '$this->limitLength(');
    };
    $unbounded = collect(panelTextFieldChains())
        ->reject(fn (array $field): bool => preg_match('/->(maxLength|length|numeric|integer)\(/', $field[1]) === 1 || $wrapped($field))
        ->map(fn (array $field): string => $field[0])
        ->values()
        ->all();

    expect(panelTextFieldChains())->not->toBeEmpty()
        ->and($unbounded)->toBe([]);
});

it('gives every text input of the panel views a maxlength attribute', function (): void {
    $unbounded = collect(panelViewTextInputs())
        ->reject(fn (array $input): bool => preg_match('/\s:?maxlength=/', $input[1]) === 1)
        ->map(fn (array $input): string => $input[0])
        ->values()
        ->all();

    expect(panelViewTextInputs())->not->toBeEmpty()
        ->and($unbounded)->toBe([]);
});

it('cuts the table search of every panel table before it is remembered', function (): void {
    $without = array_values(array_filter(panelTableComponents(), fn (string $class): bool => ! in_array(LimitsSearchInput::class, class_uses_recursive($class), true)));

    expect(panelTableComponents())->not->toBeEmpty()
        ->and($without)->toBe([]);

    $page = Livewire::test(ListClients::class)
        ->set('tableSearch', str_repeat('k', 5_000))
        ->set('tableColumnSearches.name', str_repeat('n', 5_000));

    // What the tables remember, flattened: the search and each column search.
    $remembered = fn (): array => collect(Arr::dot(session()->all()))->filter(fn ($value, string $key): bool => str_contains($key, 'search') && is_string($value) && $value !== '')->all();

    expect(mb_strlen($page->get('tableSearch')))->toBe(InputLimits::SEARCH)
        ->and(mb_strlen($page->get('tableColumnSearches')['name']))->toBe(InputLimits::SEARCH)
        ->and($remembered())->not->toBeEmpty()
        ->and(collect($remembered())->max(fn (mixed $value): int => mb_strlen((string) $value)))->toBe(InputLimits::SEARCH);

    // A search that comes with the address is cut once the table is set up.
    session()->flush();
    $linked = Livewire::withQueryParams(['search' => str_repeat('u', 5_000)])->test(ListClients::class);

    expect(mb_strlen($linked->get('tableSearch')))->toBe(InputLimits::SEARCH)
        ->and(collect($remembered())->max(fn (mixed $value): int => mb_strlen((string) $value)))->toBe(InputLimits::SEARCH);
});

it('bounds the staff login fields like the account form', function (): void {
    auth()->logout();

    Livewire::test(Login::class)
        ->assertFormFieldExists('email', fn (TextInput $field): bool => $field->getMaxLength() === EmailAddressRule::MAX_LENGTH)
        ->assertFormFieldExists('password', fn (TextInput $field): bool => $field->getMaxLength() === 255);
});

it('refuses a sign-in address longer than the standard as a validation error, before any account lookup', function (): void {
    auth()->logout();

    Livewire::test(Login::class)
        ->fillForm(['email' => panelLimitsAddress(255), 'password' => 'whatever-1234'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(AuditLog::query()->where('event', 'login.rejected')->count())->toBe(0);
});

it('refuses an overlong second-factor code without counting it as a wrong code', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::UserLoginMfaEnabled, true);
    $settings->set(SettingKey::UserLoginMfaMethods, ['app', 'email']);
    $this->admin->forceFill(['mfa_app_secret' => app(Google2FA::class)->generateSecretKey(16)])->save();

    Livewire::test(MfaChallenge::class)
        ->assertSeeHtml('maxlength="'.MfaChallenge::CODE_INPUT_MAX_LENGTH.'"')
        ->set('code', str_repeat('1', MfaChallenge::CODE_INPUT_MAX_LENGTH + 1))
        ->call('verify')
        ->assertHasErrors(['code']);

    expect($this->admin->fresh()->failed_mfa_attempts)->toBe(0);

    // A first factor being set up: the same bound on the confirmation code.
    $newcomer = User::factory()->withRole(Role::Agent)->create();
    $this->actingAs($newcomer);

    Livewire::test(MfaSetup::class)
        ->call('start', 'email')
        ->set('code', str_repeat('2', MfaChallenge::CODE_INPUT_MAX_LENGTH + 1))
        ->call('confirm')
        ->assertHasErrors(['code']);

    expect($newcomer->fresh()->failed_mfa_attempts)->toBe(0);
});

it('takes a PIN of digits only, at most as long as a PIN can be, before any session or attempt', function (string $pin): void {
    app(Settings::class)->set(SettingKey::PinAgentVerificationEnabled, true);
    $client = Client::factory()->synced()->create();
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Sync, 'verified_at' => now()]);
    app(PinService::class)->setPin($client, '123456');

    Livewire::test(Identify::class, ['client' => $client->id])
        ->call('chooseMethod', 'pin')
        ->assertSeeHtml('maxlength="'.PinService::MAX_ALLOWED_LENGTH.'"')
        ->set('pin', $pin)
        ->call('verifyPin')
        ->assertNotified();

    expect(IdSession::query()->count())->toBe(0)
        ->and($client->fresh()->pin_failed_attempts)->toBe(0);
})->with([
    'too long' => [str_repeat('1', PinService::MAX_ALLOWED_LENGTH + 1)],
    'not digits' => ['12a456'],
    'shorter than any PIN can be' => ['123'],
    'huge' => [str_repeat('9', 100_000)],
]);

it('reads a call id from the address only when it is a UUID', function (): void {
    $client = Client::factory()->synced()->create();
    DB::enableQueryLog();

    Livewire::test(Identify::class, ['client' => $client->id, 'call' => str_repeat('x', 5_000)])
        ->assertSet('call', null);
    Livewire::withQueryParams(['call' => 'not-a-uuid'])->test(SearchClients::class)
        ->assertOk();
    Livewire::withQueryParams(['call' => 'not-a-uuid'])->test(VerifyCode::class)
        ->assertSet('call', null);

    $bindings = collect(DB::getQueryLog())->flatMap(fn (array $query): array => $query['bindings']);
    expect($bindings->contains(fn ($binding): bool => $binding === 'not-a-uuid' || $binding === str_repeat('x', 5_000)))->toBeFalse();
});

it('cuts the client search to the search limit before it reaches the query and the audit log', function (): void {
    Livewire::test(SearchClients::class)
        ->assertSeeHtml('maxlength="'.InputLimits::SEARCH.'"')
        ->set('q', str_repeat('Kovács ', 2_000));

    $entry = AuditLog::query()->where('event', 'client.searched')->sole();
    expect(mb_strlen($entry->context['term']))->toBeLessThanOrEqual(InputLimits::SEARCH);
});

it('cuts and records a client search opened from the address like a typed one', function (): void {
    $client = Client::factory()->synced()->create(['name' => 'Kovács Anna']);

    Livewire::withQueryParams(['q' => 'Kovács'])->test(SearchClients::class)
        ->assertSee('Kovács Anna');
    expect(AuditLog::query()->where('event', 'client.searched')->sole()->context['term'])->toBe('Kovács');

    $page = Livewire::withQueryParams(['q' => str_repeat('Kovács ', 1_000)])->test(SearchClients::class);
    expect(mb_strlen($page->get('q')))->toBe(InputLimits::SEARCH)
        ->and(AuditLog::query()->where('event', 'client.searched')->count())->toBe(2)
        ->and(mb_strlen(AuditLog::query()->where('event', 'client.searched')->latest('id')->first()->context['term']))->toBeLessThanOrEqual(InputLimits::SEARCH);
});

it('refuses e-mail addresses beyond the standard on the client and the staff form', function (): void {
    Livewire::test(CreateClient::class)
        ->assertFormFieldExists('email', fn (TextInput $field): bool => $field->getMaxLength() === EmailAddressRule::MAX_LENGTH)
        ->fillForm(['name' => 'Long Address', 'email' => panelLimitsAddress(255)])
        ->call('create')
        ->assertHasFormErrors(['email']);

    Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Long Local', 'email' => str_repeat('l', 65).'@corp.hu'])
        ->call('create')
        ->assertHasFormErrors(['email']);

    Livewire::test(CreateUser::class)
        ->assertFormFieldExists('email', fn (TextInput $field): bool => $field->getMaxLength() === EmailAddressRule::MAX_LENGTH)
        ->fillForm(['name' => 'Long Address', 'email' => panelLimitsAddress(255), 'password' => 'a-long-password-1', 'roles' => [Spatie\Permission\Models\Role::findByName('agent')->id]])
        ->call('create')
        ->assertHasFormErrors(['email']);

    Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Longest Address', 'email' => panelLimitsAddress(254)])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Client::query()->where('name', 'Longest Address')->exists())->toBeTrue();
});

it('bounds the typed phone number of the phone numbers tab', function (): void {
    $client = Client::factory()->create();

    Livewire::test(PhoneNumbersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])
        ->callAction(TestAction::make('create')->table(), ['number_e164' => '+36 30 123 4567'.str_repeat(' ', PhoneNumberRule::RAW_MAX_LENGTH)])
        ->assertHasActionErrors(['number_e164']);

    expect($client->phoneNumbers()->count())->toBe(0);
});

it('bounds the news body and the message templates', function (): void {
    Livewire::test(ManageNewsPosts::class)
        ->callAction('create', ['title' => 'Long', 'body' => str_repeat('a', InputLimits::TEXT_CHARS + 1)])
        ->assertHasActionErrors(['body']);
    expect(NewsPost::query()->count())->toBe(0);

    // The list page creates the template rows.
    $this->get('/admin/content/message-templates')->assertOk();

    $sms = MessageTemplate::query()->where('key', 'pin_sms')->where('locale', 'hu')->firstOrFail();
    Livewire::test(EditMessageTemplate::class, ['record' => $sms->id])
        ->fillForm(['body' => '{{ pin }} '.str_repeat('a', InputLimits::SMS_BODY)])
        ->call('save')
        ->assertHasFormErrors(['body']);

    $mail = MessageTemplate::query()->where('key', 'magic_link')->where('locale', 'en')->firstOrFail();
    Livewire::test(EditMessageTemplate::class, ['record' => $mail->id])
        ->callAction('sendTest', ['email' => panelLimitsAddress(255)])
        ->assertHasActionErrors(['email']);
    Livewire::test(EditMessageTemplate::class, ['record' => $sms->id])
        ->callAction('sendTest', ['phone' => '+36 30 123 4567'.str_repeat('-', PhoneNumberRule::RAW_MAX_LENGTH)])
        ->assertHasActionErrors(['phone']);
});

it('refuses an EMD file above the upload limit with a validation message', function (): void {
    $file = UploadedFile::fake()->create('clients.csv', ManageSyncRuns::UPLOAD_MAX_KILOBYTES + 1, 'text/csv');

    Livewire::test(ManageSyncRuns::class)
        ->callAction('importFile', ['file' => $file])
        ->assertHasActionErrors(['file']);

    expect(SyncRun::query()->count())->toBe(0);
});

it('cuts the audit log and identification filters and reads ids only when they are UUIDs', function (): void {
    DB::enableQueryLog();

    Livewire::test(ManageAuditLogs::class)
        ->filterTable('subject_id', ['subject_id' => 'not-a-uuid'])
        ->filterTable('actor_id', ['actor_id' => 'not-a-uuid'])
        ->filterTable('event', ['event' => str_repeat('e', 5_000)])
        ->assertOk();
    Livewire::test(ManageIdSessions::class)
        ->filterTable('client', ['client' => str_repeat('c', 5_000)])
        ->assertOk();

    $bindings = collect(DB::getQueryLog())->flatMap(fn (array $query): array => $query['bindings'])->filter(fn ($binding): bool => is_string($binding));
    expect($bindings->contains('not-a-uuid'))->toBeFalse()
        ->and($bindings->max(fn (string $binding): int => mb_strlen($binding)))->toBeLessThanOrEqual(InputLimits::SEARCH + 2);
});

it('keeps the text of a table filter within the search limit in the page state and the session', function (): void {
    $page = Livewire::test(ManageAuditLogs::class)->filterTable('event', ['event' => str_repeat('e', 5_000)]);

    $longest = 0;
    array_walk_recursive($page->instance()->tableFilters, function (mixed $value) use (&$longest): void {
        $longest = is_string($value) ? max($longest, mb_strlen($value)) : $longest;
    });
    $stored = session($page->instance()->getTableFiltersSessionKey());
    array_walk_recursive($stored, function (mixed $value) use (&$longest): void {
        $longest = is_string($value) ? max($longest, mb_strlen($value)) : $longest;
    });

    expect($longest)->toBe(InputLimits::SEARCH);
});

it('leads an overlong leave address to the panel home page', function (): void {
    $home = Filament::getUrl();

    expect(EditUser::safeLeaveUrl('/admin/clients?'.str_repeat('a', InputLimits::URL)))->toBe($home)
        ->and(EditUser::safeLeaveUrl('/admin/clients?q=anna'))->toBe('/admin/clients?q=anna');
});

it('says once why an address is not valid on the client and the staff form', function (): void {
    $errors = Livewire::test(CreateClient::class)
        ->fillForm(['name' => 'Rossz Cím', 'email' => 'not an address'])
        ->call('create')
        ->assertHasFormErrors(['email'])
        ->errors()->get('data.email');

    expect($errors)->toHaveCount(1);
});
