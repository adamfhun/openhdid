<?php

use App\Auth\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

it('lets an admin into both panels', function (): void {
    $user = User::factory()->withRole(Role::Admin)->create();

    $this->actingAs($user)->get('/admin')->assertOk();
    $this->actingAs($user)->get('/admin/accounts/users')->assertOk();
});

it('lets an agent into the panel but not into admin-only resources', function (): void {
    $user = User::factory()->withRole(Role::Agent)->create();

    $this->actingAs($user)->get('/admin')->assertOk();
    $this->actingAs($user)->get('/admin/accounts/users')->assertForbidden();
    $this->actingAs($user)->get('/admin/system/settings')->assertForbidden();
});

it('keeps a closed account out of every panel', function (): void {
    $user = User::factory()->withRole(Role::Admin)->closed()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('closing an account revokes its api tokens', function (): void {
    $user = User::factory()->create();
    $user->createToken('mobile');

    $user->close('sync.missing');

    expect($user->fresh()->isClosed())->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);
});
