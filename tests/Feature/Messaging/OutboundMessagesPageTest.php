<?php

use App\Auth\Role;
use App\Enums\OutboundMessageStatus;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ManageOutboundMessages;
use App\Jobs\SendOutboundMessage;
use App\Messaging\MessageKey;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use Database\Seeders\RolesAndPermissionsSeeder;
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

    (new SendOutboundMessage($queued->id))->handle(app('mailer'), $this->sms);

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
