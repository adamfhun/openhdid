<?php

use App\Auth\LoginLockout;
use App\Auth\LoginRejectedException;
use App\Auth\Mfa\MfaException;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Enums\IdChannel;
use App\Enums\IdSessionStatus;
use App\Enums\MfaMethod;
use App\Enums\PhoneNumberSource;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Identification\ClientAnswers;
use App\Identification\PinService;
use App\Identification\QaSessionEngine;
use App\Models\Client;
use App\Models\OutboundMessage;
use App\Models\Question;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use App\Sync\Contracts\SourceReader;
use App\Sync\ExternalRecordDto;
use App\Sync\SyncExternalRecords;
use App\System\Installation;
use App\System\InstallationCommandMutex;
use App\System\InstallationEventMutex;
use App\System\InstallationSchedulingMutex;
use Filament\Facades\Filament;
use Illuminate\Cache\ArrayStore;
use Illuminate\Console\CommandMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PragmaRX\Google2FA\Google2FA;

/**
 * G31c: two installations on one Valkey/Redis with the same key prefix (the
 * test cache store plays the shared store, the APP_KEY tells them apart).
 * The scheduler's mutexes and the global locks carry the installation id,
 * so each installation runs its own work, while within one installation
 * they still keep a task to one run and one server.
 *
 * OPS-7: the per-account and per-number locks of the sign-in and
 * identification paths and the second-factor codes carry the id as well,
 * so a cloned database (the same account ids) or a second installation on
 * the same store never waits for, or reads, the other's.
 */
function locksInstallation(string $letter): void
{
    config()->set('app.key', 'base64:'.base64_encode(str_repeat($letter, 32)));
}

function locksPruneEvent(): Event
{
    return collect(app(Schedule::class)->events())->sole(fn (Event $event): bool => str_contains((string) $event->command, 'hdid:prune'));
}

function locksReader(): SourceReader
{
    return new class implements SourceReader
    {
        public function source(): SyncSource
        {
            return SyncSource::Csv;
        }

        public function label(): ?string
        {
            return 'test';
        }

        public function read(): iterable
        {
            yield new ExternalRecordDto(1, 'Name 1', 'a@x.hu', 'Acme', [], [], 'Basic', null);
        }

        public function details(): array
        {
            return [];
        }
    };
}

/**
 * Makes a store that remembers the name of every lock taken on it the
 * default cache store, and returns it.
 */
function locksRecordingStore(): ArrayStore
{
    $store = new class extends ArrayStore
    {
        /** @var list<string> */
        public array $lockNames = [];

        public function lock($name, $seconds = 0, $owner = null)
        {
            $this->lockNames[] = $name;

            return parent::lock($name, $seconds, $owner);
        }
    };

    Cache::extend('recording', fn () => Cache::repository($store));
    config()->set('cache.stores.recording', ['driver' => 'recording']);
    Cache::setDefaultDriver('recording');

    return $store;
}

function locksSmsClient(string $email, string $phone): Client
{
    $client = Client::factory()->synced()->create(['email' => $email]);
    $client->phoneNumbers()->create(['number_e164' => $phone, 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);

    return $client;
}

function locksQaClient(): Client
{
    $client = Client::factory()->synced()->create();

    foreach (Question::factory()->count(5)->create() as $i => $question) {
        app(ClientAnswers::class)->save($client, $question, 'answer '.$i);
    }

    return $client;
}

beforeEach(function (): void {
    locksInstallation('p');
});

it('runs a scheduled task on both installations that share the store, and still once on each', function (): void {
    $event = locksPruneEvent();
    $servers = app(SchedulingMutex::class);
    $minute = now();

    // The production installation runs the nightly prune: its overlap and one-server marks are taken.
    expect($servers)->toBeInstanceOf(InstallationSchedulingMutex::class)
        ->and($event->mutex)->toBeInstanceOf(InstallationEventMutex::class)
        ->and($event->shouldSkipDueToOverlapping())->toBeFalse()
        ->and($servers->create($event, $minute))->toBeTrue();

    locksInstallation('s');
    expect($event->shouldSkipDueToOverlapping())->toBeFalse('the staging installation runs its own prune')
        ->and($servers->create($event, $minute))->toBeTrue();

    locksInstallation('p');
    expect($event->shouldSkipDueToOverlapping())->toBeTrue('a second run of the same installation still waits')
        ->and($servers->create($event, $minute))->toBeFalse('a second server of the same installation still skips');
});

it('lets the EMD sync of each installation run while the other holds its lock', function (): void {
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['x.hu']);
    $production = Cache::lock(Installation::lockName('hdid:sync'), 3600);
    expect($production->get())->toBeTrue();

    expect(fn () => app(SyncExternalRecords::class)->run(locksReader()))->toThrow(RuntimeException::class);

    locksInstallation('s');
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['x.hu']);
    expect(app(SyncExternalRecords::class)->run(locksReader())->status)->toBe(SyncRunStatus::Completed);

    $production->release();
});

it('keeps the isolated migration of one installation from stopping another one\'s', function (): void {
    expect(app(CommandMutex::class))->toBeInstanceOf(InstallationCommandMutex::class);
    $migrate = app(Kernel::class)->all()['migrate'];
    $mutex = app(CommandMutex::class);

    expect($mutex->create($migrate))->toBeTrue();

    locksInstallation('s');
    expect($mutex->create($migrate))->toBeTrue();

    locksInstallation('p');
    expect($mutex->create($migrate))->toBeFalse('the same installation still runs one migration at a time');
});

it('changes only the lock names, not the cached values the installation reads', function (): void {
    Cache::put('hdid.example', 'kept');

    expect(Installation::lockName('hdid:sync'))->toBe('hdid-'.Installation::id().':hdid:sync')
        ->and(Cache::get('hdid.example'))->toBe('kept');
});

it('takes the per-account lock of every sign-in and identification check under the name of this installation', function (Closure $run): void {
    locksInstallation('s');
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    app()->instance(SmsSender::class, new FakeSmsSender);
    $store = locksRecordingStore();

    $expected = $run();

    expect($store->lockNames)->toContain(Installation::lockName($expected))
        ->and(array_filter($store->lockNames, fn (string $name): bool => ! str_starts_with($name, Installation::lockName(''))))->toBe([]);
})->with([
    'the sign-in lockout count' => [function (): string {
        $client = Client::factory()->synced()->create();
        app(LoginLockout::class)->recordFailure($client, ClientPasswordlessLogin::METHOD_OTP_SMS);

        return 'login-lockout:'.$client->getMorphClass().':'.$client->getKey();
    }],
    'the SMS budget of a number' => [function (): string {
        locksSmsClient('c@x.hu', '+36301234567');
        app(ClientPasswordlessLogin::class)->requestOtp('c@x.hu');

        return 'sms-budget:+36301234567';
    }],
    'the check of an SMS login code' => [function (): string {
        $client = locksSmsClient('c@x.hu', '+36301234567');
        app(ClientPasswordlessLogin::class)->requestOtp('c@x.hu');

        try {
            app(ClientPasswordlessLogin::class)->verifyOtp('c@x.hu', '000000');
        } catch (LoginRejectedException) {
        }

        return 'hdid:login-otp:'.$client->id;
    }],
    'the staff second factor' => [function (): string {
        $user = User::factory()->create();

        try {
            app(StaffMfa::class)->verify($user, MfaMethod::Email, '123456');
        } catch (MfaException) {
        }

        return 'hdid:mfa:'.$user->getKey();
    }],
    'the start of a question-and-answer session' => [function (): string {
        $client = locksQaClient();
        app(QaSessionEngine::class)->start($client, User::factory()->create());

        return 'hdid:qa:'.$client->id;
    }],
    'the PIN check' => [function (): string {
        $client = Client::factory()->withPin('123456')->create();
        app(PinService::class)->verify($client, '000000', IdChannel::Ivr);

        return 'hdid:pin:'.$client->id;
    }],
]);

it('lets the SMS login code of one installation through while another installation holds the lock of the same number', function (): void {
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    app()->instance(SmsSender::class, $sms = new FakeSmsSender);
    $phone = '+36301234567';

    // The production installation is sending a code to the number: its lock, under the name of
    // the release before and of this one, is held for the whole test.
    foreach (['sms-budget:'.$phone, Installation::lockName('sms-budget:'.$phone)] as $name) {
        expect(Cache::lock($name, 30)->get())->toBeTrue();
    }

    locksInstallation('s');
    locksSmsClient('c@x.hu', $phone);
    app(ClientPasswordlessLogin::class)->requestOtp('c@x.hu');

    expect($sms->sent)->toHaveCount(1)
        ->and($sms->sent[0]['to'])->toBe($phone);
});

it('keeps the per-account PIN lock of two installations apart, also on a cloned database', function (): void {
    locksInstallation('s');
    $client = Client::factory()->withPin('123456')->create();

    // The production installation (a database cloned from it holds the same client id) checks a PIN of the client.
    locksInstallation('p');
    foreach (['hdid:pin:'.$client->id, Installation::lockName('hdid:pin:'.$client->id)] as $name) {
        expect(Cache::lock($name, 30)->get())->toBeTrue();
    }

    locksInstallation('s');
    expect(app(PinService::class)->verify($client, '123456', IdChannel::Ivr)->status)->toBe(IdSessionStatus::Passed);
});

it('keeps the staff second-factor code of a cloned account apart between two installations', function (): void {
    Queue::fake();
    app(Settings::class)->set(SettingKey::UserLoginMfaEnabled, true);
    app(Settings::class)->set(SettingKey::UserLoginMfaMethods, ['email']);
    $user = User::factory()->create(['mfa_email_enabled_at' => now()]);
    $mfa = app(StaffMfa::class);

    // The production installation sends a code to the account; the cloned staging one has the same user id.
    $mfa->sendCode($user, MfaMethod::Email, StaffMfa::PURPOSE_CHALLENGE);
    preg_match('/\d{6}/', (string) OutboundMessage::query()->latest('id')->firstOrFail()->body, $match);

    locksInstallation('s');
    expect(fn () => $mfa->verify($user->fresh(), MfaMethod::Email, $match[0]))->toThrow(MfaException::class);

    locksInstallation('p');
    $mfa->verify($user->fresh(), MfaMethod::Email, $match[0]);
    expect($mfa->passedInSession($user->fresh()))->toBeTrue();
});

it('keeps the pending authenticator app setup of a cloned account to the installation that began it', function (): void {
    Queue::fake();
    app(Settings::class)->set(SettingKey::UserLoginMfaEnabled, true);
    app(Settings::class)->set(SettingKey::UserLoginMfaMethods, ['app', 'email']);
    Filament::setCurrentPanel('admin');
    $user = User::factory()->create();
    $this->actingAs($user);
    $mfa = app(StaffMfa::class);

    // The production installation begins the setup of a first authenticator app; the cloned staging one has the same user id.
    ['secret' => $secret] = $mfa->beginAppSetup($user);
    $mfa->sendCode($user, MfaMethod::Email, StaffMfa::PURPOSE_BOOTSTRAP);
    preg_match('/\d{6}/', (string) OutboundMessage::query()->latest('id')->firstOrFail()->body, $match);

    locksInstallation('s');
    expect(fn () => $mfa->confirmAppSetup($user->fresh(), app(Google2FA::class)->getCurrentOtp($secret), $match[0]))->toThrow(MfaException::class, 'The setup has expired. Start it again.')
        ->and($user->fresh()->mfa_app_secret)->toBeNull();

    locksInstallation('p');
    $mfa->confirmAppSetup($user->fresh(), app(Google2FA::class)->getCurrentOtp($secret), $match[0]);
    expect($user->fresh()->mfa_app_secret)->toBe($secret);
});
