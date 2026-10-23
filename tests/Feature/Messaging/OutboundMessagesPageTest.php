<?php

use App\Audit\Auditor;
use App\Auth\Role;
use App\Clients\ClientTiers;
use App\Enums\OutboundMessageStatus;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ManageOutboundMessages;
use App\Filament\Admin\Widgets\OutboundMessagesWidget;
use App\Jobs\SendOutboundMessage;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Messaging\SmsDeliveryChecks;
use App\Models\AuditLog;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    $this->sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $this->sms);
});

it('lists messages and lets an admin retry a failed one and cancel a queued one', function (): void {
    $failed = OutboundMessage::factory()->sms()->failed()->create();
    $queued = OutboundMessage::factory()->sms()->create();

    Livewire::test(ManageOutboundMessages::class)
        ->assertCanSeeTableRecords([$failed, $queued])
        ->callTableAction('retry', $failed)
        ->callTableAction('cancel', $queued);

    expect($failed->fresh()->status)->toBe(OutboundMessageStatus::Sent)
        ->and($queued->fresh()->status)->toBe(OutboundMessageStatus::Cancelled);
});

it('finds messages by a fragment of the queued or sent timestamp as it is shown', function (): void {
    $october = OutboundMessage::factory()->sms()->sent()->create(['created_at' => '2026-10-23 09:54:00', 'sent_at' => '2026-10-23 09:55:10']);
    $september = OutboundMessage::factory()->sms()->create(['created_at' => '2026-09-13 14:05:00']);

    foreach (['2026. 10. 23.', '10. 23. 09:5', '09:55', '2026-10-23', '23. 09:54'] as $fragment) {
        Livewire::test(ManageOutboundMessages::class)
            ->searchTable($fragment)
            ->assertCanSeeTableRecords([$october])
            ->assertCanNotSeeTableRecords([$september]);
    }

    Livewire::test(OutboundMessagesWidget::class)
        ->searchTable('09. 13.')
        ->assertCanSeeTableRecords([$september])
        ->assertCanNotSeeTableRecords([$october]);

    Livewire::test(ManageOutboundMessages::class)
        ->searchTable($october->recipient)
        ->assertCanSeeTableRecords([$october]);
});

it('wipes the secret body and meta when a queued message is cancelled from the panel', function (): void {
    expect(MessageKey::PinSms->carriesSecret())->toBeTrue();

    $queued = OutboundMessage::factory()->sms()->create([
        'template_key' => MessageKey::PinSms,
        'body' => 'Az új PIN-kódja: 123456',
        'meta' => ['pin' => '123456'],
    ]);

    Livewire::test(ManageOutboundMessages::class)->callTableAction('cancel', $queued);

    $cancelled = $queued->fresh();

    expect($cancelled->status)->toBe(OutboundMessageStatus::Cancelled)
        ->and($cancelled->body)->toBe('')
        ->and($cancelled->meta)->toBeNull()
        ->and($cancelled->redacted_at)->not->toBeNull();

    (new SendOutboundMessage($queued->id))->handle(app('mailer'), $this->sms, app(Auditor::class), app(ClientTiers::class), app(SmsDeliveryChecks::class));

    expect($queued->fresh()->status)->toBe(OutboundMessageStatus::Cancelled)
        ->and($this->sms->sent)->toBe([]);
});

it('shows the retry button disabled for a redacted failed message but enabled for a plain failed one', function (): void {
    $redacted = OutboundMessage::factory()->sms()->failed()->redacted()->create();
    $retryable = OutboundMessage::factory()->sms()->failed()->create();

    Livewire::test(ManageOutboundMessages::class)
        ->assertTableActionVisible('retry', $redacted)
        ->assertTableActionDisabled('retry', $redacted)
        ->assertTableActionEnabled('retry', $retryable);
});

it('does not retry a redacted failed message from the panel and sends nothing', function (): void {
    $redacted = OutboundMessage::factory()->sms()->failed()->redacted()->create();

    Livewire::test(ManageOutboundMessages::class)->callTableAction('retry', $redacted);

    expect($redacted->fresh()->status)->toBe(OutboundMessageStatus::Failed)
        ->and($this->sms->sent)->toBe([]);
});

it('is read-only for a supervisor', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());
    $failed = OutboundMessage::factory()->failed()->create();
    $queued = OutboundMessage::factory()->create();

    $this->get('/admin/content/outbound-messages')->assertOk();
    Livewire::test(ManageOutboundMessages::class)
        ->assertTableActionHidden('retry', $failed)
        ->assertTableActionHidden('cancel', $queued);
});

it('retries the selected failed messages and cancels the selected queued ones in bulk', function (): void {
    $failed = OutboundMessage::factory()->sms()->failed()->count(2)->create();
    $redacted = OutboundMessage::factory()->sms()->failed()->redacted()->create();
    $queued = OutboundMessage::factory()->sms()->count(2)->create();

    Livewire::test(ManageOutboundMessages::class)
        ->selectTableRecords([...$failed->all(), $redacted])
        ->callAction(TestAction::make('retry')->table()->bulk())
        ->assertNotified(__(':n message(s) queued again', ['n' => 2]));

    expect($failed->map->fresh()->pluck('status')->unique()->all())->toBe([OutboundMessageStatus::Sent])
        ->and($redacted->fresh()->status)->toBe(OutboundMessageStatus::Failed, 'wiped content cannot be sent again')
        ->and($this->sms->sent)->toHaveCount(2);

    Livewire::test(ManageOutboundMessages::class)
        ->selectTableRecords($queued->all())
        ->callAction(TestAction::make('cancel')->table()->bulk())
        ->assertNotified(__(':n message(s) cancelled', ['n' => 2]));

    expect($queued->map->fresh()->pluck('status')->unique()->all())->toBe([OutboundMessageStatus::Cancelled])
        ->and(AuditLog::query()->where('event', 'message.cancelled_selected')->sole()->context['count'])->toBe(2);
});

it('retries every failed message and sends the queued ones from the browser without a worker', function (): void {
    OutboundMessage::factory()->sms()->failed()->count(3)->create();
    OutboundMessage::factory()->sms()->failed()->redacted()->create();

    Livewire::test(ManageOutboundMessages::class)
        ->callAction(TestAction::make('retryAllFailed')->table())
        ->assertNotified(__(':n message(s) queued again', ['n' => 3]));

    expect(OutboundMessage::query()->where('status', OutboundMessageStatus::Sent)->count())->toBe(3)
        ->and(OutboundMessage::query()->where('status', OutboundMessageStatus::Failed)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'message.retried_all')->sole()->context['count'])->toBe(3);

    // Queued rows the worker never picked up: created without dispatching.
    $queued = OutboundMessage::factory()->sms()->count(2)->create();

    Livewire::test(ManageOutboundMessages::class)
        ->callAction(TestAction::make('sendQueuedNow')->table())
        ->assertNotified(__(':sent sent, :failed failed, :retrying to retry, :remaining still queued', ['sent' => 2, 'failed' => 0, 'retrying' => 0, 'remaining' => 0]));

    expect($queued->map->fresh()->pluck('status')->unique()->all())->toBe([OutboundMessageStatus::Sent])
        ->and($this->sms->sent)->toHaveCount(5)
        ->and(AuditLog::query()->where('event', 'message.flushed')->sole()->context)->toMatchArray(['sent' => 2, 'processed' => 2]);
});

it('sends at most the limit per click and leaves the rest queued', function (): void {
    OutboundMessage::factory()->sms()->count(Messenger::SEND_NOW_LIMIT + 1)->create();

    $result = app(Messenger::class)->sendQueuedNow();

    expect($result)->toMatchArray(['sent' => Messenger::SEND_NOW_LIMIT, 'remaining' => 1]);
});

it('offers the same message table with its actions on the Queues page', function (): void {
    $failed = OutboundMessage::factory()->sms()->failed()->create(['recipient' => '+36301234567']);

    Livewire::test(OutboundMessagesWidget::class)
        ->assertCanSeeTableRecords([$failed])
        ->mountAction(TestAction::make('view')->table($failed))
        ->assertSee('+36301234567');

    Livewire::test(OutboundMessagesWidget::class)->callTableAction('retry', $failed);

    expect($failed->fresh()->status)->toBe(OutboundMessageStatus::Sent);
});

it('hides the bulk and header message actions from a supervisor', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());
    OutboundMessage::factory()->failed()->create();

    Livewire::test(ManageOutboundMessages::class)
        ->assertActionHidden(TestAction::make('retryAllFailed')->table())
        ->assertActionHidden(TestAction::make('sendQueuedNow')->table())
        ->assertActionHidden(TestAction::make('retry')->table()->bulk())
        ->assertActionHidden(TestAction::make('cancel')->table()->bulk());
});
