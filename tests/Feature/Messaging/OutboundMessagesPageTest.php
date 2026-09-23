<?php

use App\Auth\Role;
use App\Enums\OutboundMessageStatus;
use App\Filament\Admin\Resources\OutboundMessages\Pages\ManageOutboundMessages;
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
    app()->instance(SmsSender::class, new FakeSmsSender);
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

it('is read-only for a supervisor', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());
    $failed = OutboundMessage::factory()->failed()->create();

    $this->get('/admin/content/outbound-messages')->assertOk();
    Livewire::test(ManageOutboundMessages::class)->assertTableActionHidden('retry', $failed);
});
