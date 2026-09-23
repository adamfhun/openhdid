<?php

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;

it('records model lifecycle with the acting user and without hidden attributes', function (): void {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    $client = Client::factory()->withPin()->create(['name' => 'Anna']);
    $client->update(['name' => 'Anna B']);
    $client->delete();

    $events = AuditLog::query()->where('subject_id', $client->id)->orderBy('id')->pluck('event')->all();
    expect($events)->toBe(['client.created', 'client.updated', 'client.deleted']);

    $created = AuditLog::query()->where('event', 'client.created')->first();
    expect($created->actor_id)->toBe($admin->id)
        ->and($created->actor_type)->toBe(User::class)
        ->and($created->context['attributes'])->not->toHaveKey('pin_hash');

    $updated = AuditLog::query()->where('event', 'client.updated')->first();
    expect($updated->context)->toBe(['from' => ['name' => 'Anna'], 'to' => ['name' => 'Anna B']]);
});
