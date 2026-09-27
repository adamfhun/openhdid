<?php

namespace Database\Factories;

use App\Enums\PhoneNumberSource;
use App\Enums\PhoneVerificationSource;
use App\Identification\PinService;
use App\Models\Client;
use App\Models\ExternalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'explicit_package' => 'Premium',
        ];
    }

    public function premium(): static
    {
        return $this->state(['explicit_package' => 'Premium', 'implicit_package' => null]);
    }

    public function standard(): static
    {
        return $this->state(['explicit_package' => null, 'implicit_package' => 'Basic']);
    }

    public function unentitled(): static
    {
        return $this->state(['explicit_package' => null, 'implicit_package' => null]);
    }

    /**
     * Linked to a directory record that carries the client's own e-mail,
     * whatever e-mail the caller finally passes to create().
     */
    public function synced(): static
    {
        return $this
            ->state(fn (array $attributes): array => [
                'external_record_id' => ExternalRecord::factory()->client()->state(['email' => $attributes['email']]),
            ])
            ->afterCreating(function (Client $client): void {
                $client->externalRecord?->update(['email' => $client->email, 'email_domain' => ExternalRecord::domainOf($client->email)]);
            });
    }

    /**
     * One registered, directory-verified number: what a PIN needs, since the
     * phone menu recognises the caller by number before it asks for the PIN.
     */
    public function withPhone(?string $number = null): static
    {
        return $this->afterCreating(fn (Client $client) => $client->phoneNumbers()->create([
            'number_e164' => $number ?? '+3630'.fake()->unique()->numerify('#######'),
            'source' => PhoneNumberSource::Sync,
            'is_primary' => true,
            'verified_at' => now(),
            'verified_via' => PhoneVerificationSource::Directory,
        ]));
    }

    public function closed(string $reason = 'test'): static
    {
        return $this->state(['closed_at' => now(), 'closed_reason' => $reason]);
    }

    public function withPin(string $pin = '123456'): static
    {
        return $this->state(['pin_hash' => Hash::make($pin), 'pin_lookup' => app(PinService::class)->lookupOf($pin), 'pin_set_at' => now()]);
    }
}
