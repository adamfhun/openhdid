<?php

use App\Console\Commands\FlushOutboundMessagesCommand;
use App\Enums\OutboundMessageStatus;
use App\Jobs\SendOutboundMessage;
use App\Mail\MagicLinkMail;
use App\Mail\TemplatedMail;
use App\Messaging\Channel;
use App\Messaging\MessageKey;
use App\Messaging\MessageNotRetryableException;
use App\Messaging\Messenger;
use App\Models\Client;
use App\Models\OutboundMessage;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\LogSmsSender;
use App\Sms\OzekiSmsSender;
use App\Sms\SmsException;
use App\Sms\SmsSender;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Mail::fake();
    $this->sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $this->sms);
});

it('stores an sms, sends it through the queue and fills the support placeholders by tier', function (): void {
    app(Settings::class)->set(SettingKey::SupportPremiumPhone, '+36 1 999');
    $client = Client::factory()->premium()->create(['name' => 'Anna']);

    $message = app(Messenger::class)->sendTemplate(MessageKey::LoginOtpSms, $client, ['code' => '555555', 'minutes' => '5'], '+36301234567', 'hu');

    expect($message->fresh()->status)->toBe(OutboundMessageStatus::Sent)
        ->and($message->fresh()->attempts)->toBe(1)
        ->and($message->fresh()->sent_at)->not->toBeNull()
        ->and($this->sms->lastTo('+36301234567'))->toContain('555555')
        ->and(app(Messenger::class)->commonData($client)['support_phone'])->toBe('+36 1 999');
});

it('sends the magic link as the MagicLinkMail rendered from the template', function (): void {
    $client = Client::factory()->create();

    app(Messenger::class)->sendTemplate(MessageKey::MagicLink, $client, ['link' => 'https://x/l', 'minutes' => '60', 'days' => '1'], $client->email, 'en', ['url' => 'https://x/l', 'ttl_minutes' => 60]);

    Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail) => $mail->url === 'https://x/l' && $mail->client->is($client) && str_contains((string) $mail->htmlBody, 'https://x/l') && $mail->hasTo($client->email));
    expect(OutboundMessage::query()->first()->status)->toBe(OutboundMessageStatus::Sent);
});

it('records a failure, retries and gives up after the maximum attempts', function (): void {
    app()->instance(SmsSender::class, new class implements SmsSender
    {
        public function send(string $to, string $message): void
        {
            throw new SmsException('gateway down');
        }
    });
    $client = Client::factory()->create();

    $message = app(Messenger::class)->sendTemplate(MessageKey::PinSms, $client, ['pin' => '1'], '+36301234567');
    expect($message->fresh()->status)->toBe(OutboundMessageStatus::Queued)
        ->and($message->fresh()->attempts)->toBe(1)
        ->and($message->fresh()->error)->toContain('gateway down');

    SendOutboundMessage::dispatchSync($message->id);
    SendOutboundMessage::dispatchSync($message->id);
    expect($message->fresh()->status)->toBe(OutboundMessageStatus::Failed)->and($message->fresh()->attempts)->toBe(3);

    SendOutboundMessage::dispatchSync($message->id);
    expect($message->fresh()->attempts)->toBe(3, 'a failed message is not touched again')
        ->and($message->fresh()->redacted_at)->not->toBeNull()
        ->and($message->fresh()->body)->toBe('');

    app()->instance(SmsSender::class, $this->sms);
    expect(fn () => app(Messenger::class)->retry($message->fresh()))->toThrow(MessageNotRetryableException::class)
        ->and($message->fresh()->status)->toBe(OutboundMessageStatus::Failed)
        ->and($this->sms->sent)->toBe([], 'no SMS may leave with the wiped body');
});

it('retries a failed message that still has its body, but only from the failed state', function (): void {
    app()->instance(SmsSender::class, new class implements SmsSender
    {
        public function send(string $to, string $message): void
        {
            throw new SmsException('gateway down');
        }
    });
    $message = app(Messenger::class)->sendRaw(Channel::Sms, '+36301234567', null, 'plain test text');
    SendOutboundMessage::dispatchSync($message->id);
    SendOutboundMessage::dispatchSync($message->id);
    expect($message->fresh()->status)->toBe(OutboundMessageStatus::Failed)->and($message->fresh()->body)->toBe('plain test text');

    app()->instance(SmsSender::class, $this->sms);
    app(Messenger::class)->retry($message->fresh());
    expect($message->fresh()->status)->toBe(OutboundMessageStatus::Sent)
        ->and($message->fresh()->attempts)->toBe(1)
        ->and($this->sms->lastTo('+36301234567'))->toBe('plain test text');

    expect(fn () => app(Messenger::class)->retry($message->fresh()))->toThrow(MessageNotRetryableException::class)
        ->and($message->fresh()->status)->toBe(OutboundMessageStatus::Sent)
        ->and($this->sms->sent)->toHaveCount(1);
});

it('cancels a queued message and wipes its secret, but leaves a message already being sent alone', function (): void {
    Queue::fake();
    $client = Client::factory()->create();
    $queued = app(Messenger::class)->sendTemplate(MessageKey::PinSms, $client, ['pin' => '123456'], '+36301234567');
    expect($queued->fresh()->body)->toContain('123456');

    expect(app(Messenger::class)->cancel($queued))->toBeTrue()
        ->and($queued->fresh()->status)->toBe(OutboundMessageStatus::Cancelled)
        ->and($queued->fresh()->body)->toBe('')
        ->and($queued->fresh()->meta)->toBeNull()
        ->and($queued->fresh()->redacted_at)->not->toBeNull();

    $sending = OutboundMessage::factory()->sms()->create(['template_key' => MessageKey::PinSms, 'body' => 'PIN 654321', 'status' => OutboundMessageStatus::Sending]);

    expect(app(Messenger::class)->cancel($sending))->toBeFalse()
        ->and($sending->fresh()->status)->toBe(OutboundMessageStatus::Sending)
        ->and($sending->fresh()->body)->toBe('PIN 654321')
        ->and($sending->fresh()->redacted_at)->toBeNull();
});

it('flushes queued messages synchronously and re-queues stale ones', function (): void {
    Queue::fake();
    $client = Client::factory()->create();
    $queued = app(Messenger::class)->sendTemplate(MessageKey::PinSms, $client, ['pin' => '2'], '+36301234567');
    Queue::assertPushed(SendOutboundMessage::class, fn (SendOutboundMessage $job) => $job->messageId === $queued->id);
    expect($queued->fresh()->status)->toBe(OutboundMessageStatus::Queued);

    $this->artisan(FlushOutboundMessagesCommand::class)->assertSuccessful();
    expect($queued->fresh()->status)->toBe(OutboundMessageStatus::Sent);

    OutboundMessage::factory()->sms()->create(['updated_at' => now()->subMinutes(OutboundMessage::STALE_QUEUED_MINUTES + 1)]);
    OutboundMessage::factory()->sms()->create(['status' => OutboundMessageStatus::Sending, 'updated_at' => now()->subMinutes(OutboundMessage::STALE_SENDING_MINUTES + 1)]);
    OutboundMessage::factory()->sms()->create(['status' => OutboundMessageStatus::Sending, 'updated_at' => now()->subMinutes(15)]);
    OutboundMessage::factory()->sms()->create(['updated_at' => now()->subMinutes(3)]);
    OutboundMessage::factory()->sms()->create();
    expect(OutboundMessage::query()->stale()->count())->toBe(2);
});

it('sends a raw e-mail with the generic mailable', function (): void {
    $message = app(Messenger::class)->sendRaw(Channel::Email, 'to@x.hu', 'Hi', '<p>body</p>');

    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('to@x.hu') && $mail->subjectLine === 'Hi');
    expect($message->fresh()->status)->toBe(OutboundMessageStatus::Sent);
});

it('wipes the secret body once a message is sent or given up on, but keeps the subject', function (): void {
    $client = Client::factory()->create();

    $sent = app(Messenger::class)->sendTemplate(MessageKey::PinSms, $client, ['pin' => '123456'], '+36301234567');
    expect($this->sms->lastTo('+36301234567'))->toContain('123456')
        ->and($sent->fresh()->status)->toBe(OutboundMessageStatus::Sent)
        ->and($sent->fresh()->body)->toBe('')
        ->and($sent->fresh()->redacted_at)->not->toBeNull();

    $link = app(Messenger::class)->sendTemplate(MessageKey::MagicLink, $client, ['link' => 'https://x/secret', 'minutes' => '60', 'days' => '1'], $client->email, 'en', ['url' => 'https://x/secret', 'ttl_minutes' => 60]);
    expect($link->fresh()->body)->toBe('')->and($link->fresh()->meta)->toBeNull()->and($link->fresh()->subject)->not->toBeNull();

    app()->instance(SmsSender::class, new class implements SmsSender
    {
        public function send(string $to, string $message): void
        {
            throw new SmsException('down');
        }
    });
    $failed = app(Messenger::class)->sendTemplate(MessageKey::PinSms, $client, ['pin' => '999999'], '+36301234567');
    expect($failed->fresh()->body)->toContain('999999'); // a retryable message still needs its body
    SendOutboundMessage::dispatchSync($failed->id);
    SendOutboundMessage::dispatchSync($failed->id);
    expect($failed->fresh()->status)->toBe(OutboundMessageStatus::Failed)->and($failed->fresh()->body)->toBe('');
});

it('is unique per message and claims the row only once', function (): void {
    $job = new SendOutboundMessage('abc');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('abc');

    $client = Client::factory()->create();
    $message = app(Messenger::class)->sendTemplate(MessageKey::PinSms, $client, ['pin' => '1'], '+36301234567');
    SendOutboundMessage::dispatchSync($message->id);
    expect($message->fresh()->attempts)->toBe(1)->and($this->sms->sent)->toHaveCount(1);
});

it('sends e-mail in the client\'s own language but SMS always in Hungarian', function (): void {
    $client = Client::factory()->create(['locale' => 'en']);
    app()->setLocale('hu');

    app(Messenger::class)->sendTemplate(MessageKey::LoginOtpSms, $client, ['code' => '111111', 'minutes' => '5'], '+36301234567');
    expect($this->sms->lastTo('+36301234567'))->toContain('belépési kód');

    $mail = app(Messenger::class)->sendTemplate(MessageKey::MagicLink, $client, ['link' => 'https://x/l', 'minutes' => '60', 'days' => '1'], $client->email, null, ['url' => 'https://x/l', 'ttl_minutes' => 60]);
    expect($mail->subject)->toContain('login link');
});

it('masks codes in the log driver and posts to ozeki without retrying a timed-out send', function (): void {
    expect(LogSmsSender::mask('HDID: your PIN is 123456. Code 98 76-54-32-10 ok, call 12'))->toBe('HDID: your PIN is ****56. Code ********10 ok, call 12');

    Http::fake(['http://ozeki.test/api' => Http::response('<acceptreport>ok</acceptreport>')]);
    (new OzekiSmsSender(app(Factory::class), 'http://ozeki.test/api', 'u', 'p'))->send('+36301234567', 'hi');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! str_contains($r->url(), 'password') && $r['password'] === 'p' && $r['recipient'] === '+36301234567');

    Http::fake(['http://ozeki.test/reject' => Http::response('<error>no credit</error>', 200)]);
    expect(fn () => (new OzekiSmsSender(app(Factory::class), 'http://ozeki.test/reject', 'u', 'p'))->send('+36301234567', 'hi'))->toThrow(SmsException::class);
});
