<?php

use App\Auth\Mfa\StaffMfa;
use App\Auth\Role;
use App\Enums\MfaMethod;
use App\Enums\OutboundMessageStatus;
use App\Enums\PhoneNumberSource;
use App\Jobs\SendOutboundMessage;
use App\Mail\MagicLinkMail;
use App\Messaging\Channel;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * Anonymous login requests spread over many addresses and networks must
 * neither flood the mailbox and the SMS budget nor hold the time-critical
 * sign-in codes back behind them (security review G52a).
 */

beforeEach(function (): void {
    // The client site is same-origin, so Sanctum treats its requests as stateful.
    $this->withHeader('Referer', config('app.url'));
    $this->withoutMiddleware(ThrottleRequests::class);
    Mail::fake();
    $this->sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $this->sms);
    config()->set('hdid.messages.priority_queue', 'priority');
});

function floodClient(string $email, ?string $phone = null): Client
{
    $client = Client::factory()->synced()->create(['email' => $email]);

    if ($phone !== null) {
        $client->phoneNumbers()->create(['number_e164' => $phone, 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);
    }

    return $client;
}

function floodStaffWithEmailFactor(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    app(Settings::class)->set(SettingKey::UserLoginMfaEnabled, true);
    app(Settings::class)->set(SettingKey::UserLoginMfaMethods, ['app', 'sms', 'email']);
    $agent = User::factory()->synced()->withRole(Role::Agent)->create(['email' => 'agent@corp.hu']);
    $agent->forceFill(['mfa_email_enabled_at' => now()])->save();
    test()->actingAs($agent);

    return $agent;
}

/** Runs the delivery job of one message in this process, the way a worker would. */
function deliverNow(OutboundMessage $message): OutboundMessage
{
    app(Dispatcher::class)->dispatchNow(new SendOutboundMessage($message->id));

    return $message->refresh();
}

/** The queue the delivery job of the message was pushed on (Queue::fake). */
function pushedQueueOf(OutboundMessage $message): ?string
{
    return Queue::pushed(SendOutboundMessage::class, fn (SendOutboundMessage $job): bool => $job->messageId === $message->id)->last()?->queue;
}

/**
 * Evaluates config/hdid.php with HDID_PRIORITY_QUEUE as given (null: not
 * set), as a fresh boot would; the local value is restored afterwards.
 */
function priorityQueueConfigWith(?string $value): mixed
{
    $name = 'HDID_PRIORITY_QUEUE';
    $saved = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];

    $apply = function (?string $value) use ($name): void {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);

        if ($value !== null) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }
    };

    try {
        $apply($value);

        return (fn (): array => require config_path('hdid.php'))()['messages']['priority_queue'];
    } finally {
        $apply(null);
        [$envValue, $serverValue, $processValue] = $saved;

        if ($envValue !== null) {
            $_ENV[$name] = $envValue;
        }

        if ($serverValue !== null) {
            $_SERVER[$name] = $serverValue;
        }

        if ($processValue !== false) {
            putenv($name.'='.$processValue);
        }
    }
}

/** A file of the container release: under deploy/openhdid/repo here, at the root of the published tree. */
function priorityQueueReleaseFile(string $path): ?string
{
    foreach ([base_path('deploy/openhdid/repo/'.$path), base_path($path)] as $candidate) {
        if (is_file($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }

    return null;
}

it('queues the short-lived sign-in codes of clients and staff on the priority queue and every other message, the login link too, as before', function (): void {
    Queue::fake();
    $client = floodClient('a@x.hu', '+36301234567');
    $messenger = app(Messenger::class);

    $link = $messenger->sendTemplate(MessageKey::MagicLink, $client, ['link' => 'https://x/l', 'minutes' => '720', 'hours' => '12', 'days' => '1'], $client->email, meta: ['url' => 'https://x/l', 'ttl_minutes' => 720]);
    $code = $messenger->sendTemplate(MessageKey::LoginOtpSms, $client, ['code' => '123456', 'minutes' => '5'], '+36301234567');
    $pin = $messenger->sendTemplate(MessageKey::PinSms, $client, ['pin' => '123456'], '+36301234567');
    $test = $messenger->sendRaw(Channel::Email, 'ops@x.hu', 'Test', '<p>Test</p>');

    $agent = floodStaffWithEmailFactor();
    app(StaffMfa::class)->sendCode($agent, MfaMethod::Email, StaffMfa::PURPOSE_CHALLENGE);
    $staffCode = OutboundMessage::query()->where('recipient', 'agent@corp.hu')->sole();

    expect(pushedQueueOf($link))->toBeNull('the login link lives for hours and can be asked for anonymously: it waits on the default queue')
        ->and(pushedQueueOf($code))->toBe('priority')
        ->and(pushedQueueOf($staffCode))->toBe('priority')
        ->and(pushedQueueOf($pin))->toBeNull('the connection\'s default queue, as before')
        ->and(pushedQueueOf($test))->toBeNull();
});

it('keeps every message on the default queue while HDID_PRIORITY_QUEUE is not set, so a worker listening there only still sends them', function (): void {
    expect(priorityQueueConfigWith(null))->toBe('default')
        ->and(priorityQueueConfigWith(''))->toBe('default')
        ->and(priorityQueueConfigWith('priority'))->toBe('priority');

    Queue::fake();
    config()->set('hdid.messages.priority_queue', 'default');
    $client = floodClient('a@x.hu', '+36301234567');
    $code = app(Messenger::class)->sendTemplate(MessageKey::LoginOtpSms, $client, ['code' => '123456', 'minutes' => '5'], '+36301234567');

    expect(pushedQueueOf($code))->toBe('default');
});

it('puts a sign-in code no worker picked up back on the priority queue', function (): void {
    Queue::fake();
    $agent = floodStaffWithEmailFactor();
    app(StaffMfa::class)->sendCode($agent, MfaMethod::Email, StaffMfa::PURPOSE_CHALLENGE);
    $staffCode = OutboundMessage::query()->where('recipient', 'agent@corp.hu')->sole();
    Queue::fake(); // forget the first push

    $this->travel(6)->minutes(); // past the stale threshold, within the code's 10 minutes
    $this->artisan('schedule:test', ['--name' => 'hdid:requeue-messages'])->assertSuccessful();

    expect(pushedQueueOf($staffCode))->toBe('priority');
});

it('serves the staff sign-in code before a flood of anonymous login links', function (): void {
    config()->set('queue.default', 'database');
    $agent = floodStaffWithEmailFactor();
    foreach (range(1, 12) as $i) {
        app(Messenger::class)->sendTemplate(MessageKey::MagicLink, floodClient("c{$i}@x.hu"), ['link' => 'https://x/l', 'minutes' => '720', 'hours' => '12', 'days' => '1'], "c{$i}@x.hu", meta: ['url' => 'https://x/l', 'ttl_minutes' => 720]);
    }
    app(StaffMfa::class)->sendCode($agent, MfaMethod::Email, StaffMfa::PURPOSE_CHALLENGE);
    $staffCode = OutboundMessage::query()->where('recipient', 'agent@corp.hu')->sole();

    $first = Queue::connection('database')->pop('priority');
    expect($first)->not->toBeNull()
        ->and(unserialize(json_decode($first->getRawBody(), true)['data']['command'])->messageId)->toBe($staffCode->id)
        ->and(DB::table('jobs')->where('queue', 'default')->count())->toBe(12, 'the links wait on the default queue');
});

it('does not send a login code that expired while it waited in the queue, and says why', function (): void {
    Queue::fake();
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    floodClient('a@x.hu', '+36301234567');
    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'a@x.hu'])->assertOk();
    $message = OutboundMessage::query()->sole();

    $this->travel(6)->minutes(); // the code lives 5 minutes
    deliverNow($message);

    expect($message->status)->toBe(OutboundMessageStatus::Cancelled)
        ->and($message->error)->toBe(__('Not sent: the sign-in code or link had expired before it could go out.'))
        ->and($message->isRedacted())->toBeTrue()
        ->and($this->sms->sent)->toBeEmpty()
        ->and(AuditLog::query()->where('event', 'message.cancelled')->sole()->context)->toMatchArray(['message_id' => $message->id, 'reason' => 'secret_expired']);
});

it('sends only the newest of the login links and codes waiting for one recipient, cancelling the replaced ones', function (): void {
    Queue::fake();
    app(Settings::class)->set(SettingKey::ClientLoginOtpSmsEnabled, true);
    floodClient('a@x.hu', '+36301234567');

    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'a@x.hu'])->assertOk();
    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'a@x.hu'])->assertOk();
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/client/auth/magic-link', ['email' => 'a@x.hu'])->assertOk();
    $this->postJson('/api/v1/client/auth/otp/request', ['email' => 'a@x.hu'])->assertOk();
    [$oldLink, $oldCode, $newLink, $newCode] = OutboundMessage::query()->orderBy('id')->get()->all();

    foreach ([$oldLink, $oldCode] as $replaced) {
        expect(deliverNow($replaced)->status)->toBe(OutboundMessageStatus::Cancelled)
            ->and($replaced->error)->toBe(__('Not sent: a newer sign-in code or link replaced it before it could go out.'))
            ->and($replaced->isRedacted())->toBeTrue();
    }

    expect(deliverNow($newLink)->status)->toBe(OutboundMessageStatus::Sent)
        ->and(deliverNow($newCode)->status)->toBe(OutboundMessageStatus::Sent)
        ->and($this->sms->sent)->toHaveCount(1)
        ->and(AuditLog::query()->where('event', 'message.cancelled')->get()->pluck('context.reason')->all())->toBe(['secret_superseded', 'secret_superseded']);
    Mail::assertSent(MagicLinkMail::class, 1);
});

it('does not send a staff sign-in code that expired or that a newer code replaced while it waited', function (): void {
    Queue::fake();
    $agent = floodStaffWithEmailFactor();
    $mfa = app(StaffMfa::class);

    $mfa->sendCode($agent, MfaMethod::Email, StaffMfa::PURPOSE_CHALLENGE);
    $this->travel(61)->seconds();
    $mfa->sendCode($agent, MfaMethod::Email, StaffMfa::PURPOSE_CHALLENGE);
    $this->travel(61)->seconds();
    $mfa->sendCode($agent, MfaMethod::Email, StaffMfa::PURPOSE_CHALLENGE);
    [$replaced, $expired, $live] = OutboundMessage::query()->orderBy('id')->get()->all();

    expect(deliverNow($replaced)->status)->toBe(OutboundMessageStatus::Cancelled)
        ->and($replaced->error)->toBe(__('Not sent: a newer sign-in code or link replaced it before it could go out.'))
        ->and(deliverNow($live)->status)->toBe(OutboundMessageStatus::Sent);

    $this->travel(StaffMfa::CODE_TTL_MINUTES + 1)->minutes();
    expect(deliverNow($expired)->status)->toBe(OutboundMessageStatus::Cancelled);
});

it('still sends messages without a short-lived secret however long they waited', function (): void {
    Queue::fake();
    $client = floodClient('a@x.hu', '+36301234567');
    $pin = app(Messenger::class)->sendTemplate(MessageKey::PinSms, $client, ['pin' => '123456'], '+36301234567');
    app(Messenger::class)->sendTemplate(MessageKey::PinSms, $client, ['pin' => '654321'], '+36301234567');

    $this->travel(3)->hours();

    expect(deliverNow($pin)->status)->toBe(OutboundMessageStatus::Sent)
        ->and($this->sms->lastTo('+36301234567'))->toContain('123456');
});

it('turns the outbound messages tile yellow once the oldest queued message waited more than five minutes', function (): void {
    $checks = app(HealthChecks::class);
    OutboundMessage::factory()->create(['created_at' => now()->subMinutes(4), 'updated_at' => now()->subMinutes(4)]);
    expect($checks->outboundMessages()->status)->toBe(CheckStatus::Ok);

    OutboundMessage::factory()->create(['created_at' => now()->subMinutes(7), 'updated_at' => now()->subMinutes(7)]);
    $tile = $checks->outboundMessages();

    expect($tile->status)->toBe(CheckStatus::Warn)
        ->and($tile->detail)->toContain(__('the oldest queued message has waited :minutes minutes', ['minutes' => 7]));
});

it('counts the wait of a failed message resent by hand from the resend, not from when it was first written', function (): void {
    Queue::fake();
    $checks = app(HealthChecks::class);
    $failed = OutboundMessage::factory()->create([
        'status' => OutboundMessageStatus::Failed,
        'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
    ]);

    app(Messenger::class)->retry($failed);
    expect($checks->outboundMessages()->status)->toBe(CheckStatus::Ok);

    $this->travel(6)->minutes();
    expect($checks->outboundMessages()->status)->toBe(CheckStatus::Warn)
        ->and($checks->outboundMessages()->detail)->toContain(__('the oldest queued message has waited :minutes minutes', ['minutes' => 6]));
});

it('warns sooner when a sign-in code waits on the priority queue, naming the queue', function (): void {
    $checks = app(HealthChecks::class);
    $code = fn (int $minutes) => OutboundMessage::factory()->create([
        'channel' => 'sms', 'recipient' => '+36301234567',
        'template_key' => MessageKey::LoginOtpSms,
        'meta' => ['valid_until' => now()->subMinutes($minutes)->addMinutes(5)->toIso8601String()],
        'created_at' => now()->subMinutes($minutes), 'updated_at' => now()->subMinutes($minutes),
    ]);

    // A login link waiting 3 minutes is no sign-in code: it lives for hours.
    OutboundMessage::factory()->create([
        'template_key' => MessageKey::MagicLink,
        'meta' => ['url' => 'https://x/l', 'valid_until' => now()->addHours(12)->toIso8601String()],
        'created_at' => now()->subMinutes(3), 'updated_at' => now()->subMinutes(3),
    ]);
    $code(1);
    expect($checks->outboundMessages()->status)->toBe(CheckStatus::Ok);

    $code(3);
    $tile = $checks->outboundMessages();
    expect($tile->status)->toBe(CheckStatus::Warn)
        ->and($tile->detail)->toContain(__('sign-in codes have waited :minutes minutes on the ":queue" queue', ['minutes' => 3, 'queue' => 'priority']))
        ->and(HealthChecks::hint('messages'))->toContain('HDID_PRIORITY_QUEUE');
});

describe('worker samples', function (): void {
    it('lets the traditional single worker listen on the priority queue before the default one', function (): void {
        $unit = base_path('deploy/hdid-queue.service');

        if (! is_file($unit)) {
            $this->markTestSkipped('The traditional deploy samples are not part of this tree.');
        }

        expect((string) file_get_contents($unit))->toMatch('/^ExecStart=.*\squeue:work\s.*--queue=priority,default(\s|$)/m');
    });

    it('lets the container worker listen on the priority queue before the default one', function (): void {
        $role = priorityQueueReleaseFile('docker/openhdid');
        $compose = priorityQueueReleaseFile('compose.yaml');
        $kubernetes = priorityQueueReleaseFile('kubernetes/base/worker.yaml');

        if ($role === null || $compose === null) {
            $this->markTestSkipped('The container release files are not part of this tree.');
        }

        preg_match('/^    worker\)\n(.*?)^        ;;/ms', $role, $branch);
        expect($branch[1] ?? '')->toContain('${HDID_PRIORITY_QUEUE:-priority}')->toContain(',default');

        // The worker service names no queue list of its own, so the role's default applies.
        preg_match('/^  worker:\n((?:    .*\n)+)/m', $compose, $service);
        expect($service[1] ?? '')->toMatch('/^    command: \[worker\]$/m');

        if ($kubernetes !== null) {
            expect($kubernetes)->toMatch('/^\s+args: \[worker\]$/m');
        }
    });

    it('documents the priority queue in both environment samples', function (): void {
        expect((string) file_get_contents(base_path('.env.example')))->toMatch('/^HDID_PRIORITY_QUEUE=/m');

        $container = priorityQueueReleaseFile('.env.example');

        if (is_file(base_path('deploy/openhdid/repo/.env.example'))) {
            expect($container)->toMatch('/^HDID_PRIORITY_QUEUE=priority$/m');
        }
    });
});

it('lets a newer login code replace only the same client\'s older one on a shared number', function (): void {
    Queue::fake();
    $anna = floodClient('anna@x.hu', '+36301234567');
    $bela = floodClient('bela@x.hu', '+36301234567');
    $messenger = app(Messenger::class);
    $send = fn (Client $client, string $code) => $messenger->sendTemplate(MessageKey::LoginOtpSms, $client, ['code' => $code, 'minutes' => '5'], '+36301234567');

    $annaCode = $send($anna, '111111');
    $this->travel(61)->seconds();
    $belaCode = $send($bela, '222222');

    // Béla's code on the shared number leaves Anna's, still valid, alone.
    expect(deliverNow($annaCode)->status)->toBe(OutboundMessageStatus::Sent)
        ->and(deliverNow($belaCode)->status)->toBe(OutboundMessageStatus::Sent);

    $older = $send($anna, '333333');
    $this->travel(61)->seconds();
    $send($anna, '444444');
    expect(deliverNow($older)->status)->toBe(OutboundMessageStatus::Cancelled, 'her own newer code still replaces her older one');
});

it('lets a newer staff sign-in code replace only the same user\'s older one on a shared SMS number', function (): void {
    Queue::fake();
    $messenger = app(Messenger::class);
    $send = fn (string $user, string $code) => $messenger->sendRaw(Channel::Sms, '+36301234567', null, "Your sign-in code: {$code}.", carriesSecret: true, validUntil: now()->addMinutes(10), forUserId: $user);

    $first = $send('user-a', '111111');
    $this->travel(61)->seconds();
    $send('user-b', '222222');
    expect(deliverNow($first)->status)->toBe(OutboundMessageStatus::Sent);

    $older = $send('user-a', '333333');
    $this->travel(61)->seconds();
    $send('user-a', '444444');
    expect(deliverNow($older)->status)->toBe(OutboundMessageStatus::Cancelled);
});
