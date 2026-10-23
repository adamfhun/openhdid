<?php

use App\Auth\Role;
use App\Enums\OutboundMessageStatus;
use App\Enums\SmsDeliveryStatus;
use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ManageOutboundMessages;
use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Messaging\SmsDeliveryChecks;
use App\Models\AuditLog;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Sms\FakeSmsSender;
use App\Sms\OzekiSmsSender;
use App\Sms\SmsDeliveryReport;
use App\Sms\SmsException;
use App\Sms\SmsSender;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $this->sms);
});

function sendTestSms(string $to = '+36301234567'): OutboundMessage
{
    return app(Messenger::class)->sendRaw(Channel::Sms, $to, null, 'Hello')->fresh();
}

it('stores the gateway id of a sent SMS and schedules the first delivery query', function (): void {
    $sms = sendTestSms();
    $mail = app(Messenger::class)->sendRaw(Channel::Email, 'a@x.hu', 'Subject', 'Body')->fresh();

    expect($sms->status)->toBe(OutboundMessageStatus::Sent)
        ->and($sms->gateway_message_id)->toBe('fake-1')
        ->and($sms->delivery_status)->toBe(SmsDeliveryStatus::Pending)
        ->and($sms->delivery_checks)->toBe(0)
        ->and($sms->next_delivery_check_at->timestamp)->toBe($sms->sent_at->addSeconds(30)->timestamp)
        ->and($mail->delivery_status)->toBeNull()
        ->and($mail->gateway_message_id)->toBeNull()
        ->and(SmsDeliveryChecks::offsets())->toBe([30, 120, 300, 1800, 7200]);
});

it('follows the configured offsets and settles the message when the gateway reports it delivered', function (): void {
    config()->set('hdid.sms.delivery_checks', '10,20,30');
    $sms = sendTestSms();
    $this->sms->reports['fake-1'] = new SmsDeliveryReport(SmsDeliveryStatus::Pending, 'sent');

    expect(app(SmsDeliveryChecks::class)->run())->toMatchArray(['checked' => 0]);

    $this->travel(11)->seconds();
    expect(app(SmsDeliveryChecks::class)->run())->toMatchArray(['checked' => 1, 'delivered' => 0]);
    $sms->refresh();
    expect($sms->delivery_checks)->toBe(1)
        ->and($sms->delivery_status)->toBe(SmsDeliveryStatus::Pending)
        ->and($sms->delivery_status_raw)->toBe('sent')
        ->and($sms->next_delivery_check_at->timestamp)->toBe($sms->sent_at->addSeconds(20)->timestamp)
        ->and($this->sms->queried)->toBe(['fake-1']);

    $this->sms->reports['fake-1'] = new SmsDeliveryReport(SmsDeliveryStatus::Delivered, 'deliveredtohandset');
    $this->travel(10)->seconds();
    expect(app(SmsDeliveryChecks::class)->run())->toMatchArray(['checked' => 1, 'delivered' => 1]);
    $sms->refresh();
    expect($sms->status)->toBe(OutboundMessageStatus::Delivered)
        ->and($sms->delivery_status)->toBe(SmsDeliveryStatus::Delivered)
        ->and($sms->delivery_status_raw)->toBe('deliveredtohandset')
        ->and($sms->delivery_checks)->toBe(2)
        ->and($sms->next_delivery_check_at)->toBeNull()
        ->and($sms->delivery_updated_at)->not->toBeNull();
    expect(AuditLog::query()->where('event', 'message.delivered')->sole()->context)->toMatchArray(['message_id' => $sms->id, 'delivery_status' => 'deliveredtohandset', 'checks' => 2]);

    // Settled: not queried again.
    $this->travel(60)->seconds();
    expect(app(SmsDeliveryChecks::class)->run())->toMatchArray(['checked' => 0]);
});

it('acknowledges an undeliverable SMS without resending it and lets an operator queue it again by hand', function (): void {
    config()->set('hdid.sms.delivery_checks', '10');
    $sms = sendTestSms();
    $this->sms->reports['fake-1'] = new SmsDeliveryReport(SmsDeliveryStatus::Undeliverable, 'undelivered');
    $this->travel(11)->seconds();

    expect(app(SmsDeliveryChecks::class)->run())->toMatchArray(['checked' => 1, 'undeliverable' => 1]);
    $sms->refresh();
    expect($sms->status)->toBe(OutboundMessageStatus::Undeliverable)
        ->and($sms->delivery_status)->toBe(SmsDeliveryStatus::Undeliverable)
        ->and($sms->error)->toContain('undelivered')
        ->and(count($this->sms->sent))->toBe(1)
        ->and(AuditLog::query()->where('event', 'message.undeliverable')->sole()->context)->toMatchArray(['message_id' => $sms->id, 'delivery_status' => 'undelivered']);

    // Nothing resends it on its own; a manual retry starts a fresh send and a fresh report.
    expect(app(Messenger::class)->retryableFailed()->count())->toBe(0);
    app(Messenger::class)->retry($sms);
    $sms->refresh();
    expect(count($this->sms->sent))->toBe(2)
        ->and($sms->status)->toBe(OutboundMessageStatus::Sent)
        ->and($sms->gateway_message_id)->toBe('fake-2')
        ->and($sms->delivery_status)->toBe(SmsDeliveryStatus::Pending)
        ->and($sms->delivery_checks)->toBe(0)
        ->and($sms->error)->toBeNull();
});

it('gives up after the last query, keeps the message sent with no report, and counts a failed query as a query', function (): void {
    config()->set('hdid.sms.delivery_checks', '10,20');
    $sms = sendTestSms();
    $this->sms->reports['fake-1'] = new SmsException('gateway down');
    $this->travel(11)->seconds();

    app(SmsDeliveryChecks::class)->run();
    $sms->refresh();
    expect($sms->delivery_checks)->toBe(1)
        ->and($sms->delivery_status)->toBe(SmsDeliveryStatus::Pending)
        ->and($sms->delivery_status_raw)->toBe('query failed');

    $this->sms->reports['fake-1'] = new SmsDeliveryReport(SmsDeliveryStatus::Pending, 'sent');
    $this->travel(10)->seconds();
    expect(app(SmsDeliveryChecks::class)->run())->toMatchArray(['checked' => 1, 'no_report' => 1]);
    $sms->refresh();
    expect($sms->status)->toBe(OutboundMessageStatus::Sent)
        ->and($sms->delivery_status)->toBe(SmsDeliveryStatus::Unknown)
        ->and($sms->delivery_checks)->toBe(2)
        ->and($sms->next_delivery_check_at)->toBeNull()
        ->and(AuditLog::query()->whereIn('event', ['message.delivered', 'message.undeliverable'])->exists())->toBeFalse();

    // A driver that cannot report at all ends the follow-up at once.
    $other = sendTestSms('+36301234568');
    $this->travel(11)->seconds();
    app(SmsDeliveryChecks::class)->run();
    expect($other->fresh()->delivery_status)->toBe(SmsDeliveryStatus::Unknown);
});

it('falls back to the default offsets and warns on the status page when the configured list is invalid', function (string $value, bool $valid): void {
    config()->set('hdid.sms.delivery_checks', $value);

    $problem = SmsDeliveryChecks::configProblem();
    $check = app(HealthChecks::class)->outboundMessages();

    if ($valid) {
        expect($problem)->toBeNull()->and($check->status)->toBe(CheckStatus::Ok);
    } else {
        expect($problem)->toContain('SMS_DELIVERY_CHECKS')
            ->and(SmsDeliveryChecks::offsets())->toBe(SmsDeliveryChecks::DEFAULT_CHECKS)
            ->and($check->status)->toBe(CheckStatus::Warn)
            ->and($check->detail)->toContain('SMS_DELIVERY_CHECKS');
    }
})->with([
    'default' => ['', true],
    'custom' => ['60, 600,3600', true],
    'a day' => ['86400', true],
    'six entries' => ['1,2,3,4,5,6', false],
    'not a number' => ['30,x', false],
    'too long' => ['90000', false],
    'zero' => ['0,30', false],
]);

it('reads the accept report id from ozeki and asks for the status with the configured action and status words', function (): void {
    Http::fake([
        'http://ozeki.test/api' => function (Request $request) {
            return match ($request['action']) {
                'sendmessage' => Http::response('<?xml version="1.0"?><response><action>sendmessage</action><data><acceptreport><statuscode>0</statuscode><statusmessage>Message accepted for delivery</statusmessage><messageid>ABC-123</messageid><recipient>+36301234567</recipient></acceptreport></data></response>'),
                'querymessage' => Http::response(match ($request['messageid']) {
                    'ABC-123' => '<response><action>querymessage</action><data><message><messageid>ABC-123</messageid><messagestatus>DeliveredToHandset</messagestatus></message></data></response>',
                    'DEAD' => '<response><data><message><status>undelivered</status></message></data></response>',
                    'WAIT' => '<response><data><message><status>sent</status></message></data></response>',
                    'ERR' => '<response><error>no such message</error></response>',
                    default => '<response><data></data></response>',
                }),
                'customquery' => Http::response('<response><data><message><deliverystatus>ok</deliverystatus></message></data></response>'),
                default => Http::response('<error>bad action</error>', 400),
            };
        },
    ]);

    $ozeki = new OzekiSmsSender(app(Factory::class), 'http://ozeki.test/api', 'u', 'p');

    expect($ozeki->send('+36301234567', 'Hello'))->toBe('ABC-123')
        ->and($ozeki->deliveryStatus('ABC-123'))->toEqual(new SmsDeliveryReport(SmsDeliveryStatus::Delivered, 'DeliveredToHandset'))
        ->and($ozeki->deliveryStatus('DEAD')->status)->toBe(SmsDeliveryStatus::Undeliverable)
        ->and($ozeki->deliveryStatus('WAIT')->status)->toBe(SmsDeliveryStatus::Pending);
    expect(fn () => $ozeki->deliveryStatus('ERR'))->toThrow(SmsException::class);
    expect(fn () => $ozeki->deliveryStatus('NONE'))->toThrow(SmsException::class);

    Http::assertSent(fn (Request $request): bool => $request['action'] === 'querymessage' && $request['messageid'] === 'ABC-123' && $request['username'] === 'u' && $request->method() === 'POST');

    $custom = new OzekiSmsSender(app(Factory::class), 'http://ozeki.test/api', 'u', 'p', 'customquery', ['ok'], ['nope']);
    expect($custom->deliveryStatus('X')->status)->toBe(SmsDeliveryStatus::Delivered);
});

it('shows the delivery report on the message, counts undeliverable SMS on the status page, and is scheduled every minute', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');

    $undeliverable = OutboundMessage::factory()->sms()->sent()->create([
        'status' => OutboundMessageStatus::Undeliverable, 'gateway_message_id' => 'g-1', 'delivery_status' => SmsDeliveryStatus::Undeliverable,
        'delivery_status_raw' => 'undelivered', 'delivery_checks' => 2, 'delivery_updated_at' => now(), 'error' => 'The network reported the SMS undeliverable (undelivered).',
    ]);
    $pending = OutboundMessage::factory()->sms()->sent()->create(['gateway_message_id' => 'g-2', 'delivery_status' => SmsDeliveryStatus::Pending, 'next_delivery_check_at' => now()->addMinutes(2)]);

    Livewire::test(ManageOutboundMessages::class)
        ->assertCanSeeTableRecords([$undeliverable, $pending])
        ->assertSee('Undeliverable')
        ->assertTableActionVisible('retry', $undeliverable)
        ->assertTableActionHidden('retry', $pending)
        ->filterTable('status', OutboundMessageStatus::Undeliverable->value)
        ->assertCanSeeTableRecords([$undeliverable])
        ->assertCanNotSeeTableRecords([$pending]);

    expect(OutboundMessageResource::deliveryNote($undeliverable))->toContain('gateway status: undelivered')->toContain('2 of 5 queries')
        ->and(OutboundMessageResource::deliveryNote($pending))->toContain('Report pending')->toContain('next ');

    $check = app(HealthChecks::class)->outboundMessages();
    expect($check->status)->toBe(CheckStatus::Warn)->and($check->detail)->toContain('1 SMS undeliverable');

    $pending->forceFill(['next_delivery_check_at' => now()->subMinutes(10)])->save();
    expect(app(HealthChecks::class)->outboundMessages()->detail)->toContain('1 SMS delivery queries overdue');

    $event = collect(app(Schedule::class)->events())->sole(fn ($event): bool => $event->description === 'hdid:sms-delivery-checks');
    expect($event->expression)->toBe('* * * * *');
});
