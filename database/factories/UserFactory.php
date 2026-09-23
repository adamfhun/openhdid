<?php

namespace Database\Factories;

use App\Auth\Role;
use App\Models\ExternalRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= 'password',
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Linked to a directory record that carries the user's own e-mail,
     * whatever e-mail the caller finally passes to create().
     */
    public function synced(): static
    {
        return $this
            ->state(fn (array $attributes): array => [
                'external_record_id' => ExternalRecord::factory()->user()->state(['email' => $attributes['email']]),
            ])
            ->afterCreating(function (User $user): void {
                $user->externalRecord?->update(['email' => $user->email, 'email_domain' => ExternalRecord::domainOf($user->email)]);
            });
    }

    public function closed(string $reason = 'test'): static
    {
        return $this->state(['closed_at' => now(), 'closed_reason' => $reason]);
    }

    public function withRole(Role $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role->value));
    }
}
