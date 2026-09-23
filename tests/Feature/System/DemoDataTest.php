<?php

use App\Auth\AccountLogin;
use App\Auth\Role;
use App\Enums\PrincipalType;
use App\Models\Call;
use App\Models\Client;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Faker\Generator;

it('loads the documented demo accounts without the Faker dev dependency', function (): void {
    // The container image has no Faker: any factory call inside the seeder must fail here.
    app()->bind(Generator::class.':'.config('app.faker_locale'), fn () => throw new LogicException('Faker is not installed in the image.'));

    $this->seed(DemoSeeder::class);

    $agent = User::query()->where('email', 'agent@example.test')->sole();
    $anna = Client::query()->where('email', 'anna@example.test')->sole();
    expect($agent->hasRole(Role::Agent->value))->toBeTrue()
        ->and($agent->externalRecord?->email)->toBe('agent@example.test')
        ->and($anna->externalRecord?->external_id)->toBe(100001)
        ->and($anna->explicit_package)->toBe('Premium+')
        ->and($anna->pin_hash)->not->toBeNull()
        ->and(Client::query()->where('email', 'bela@example.test')->sole()->implicit_package)->toBe('Basic')
        ->and(User::query()->where('email', 'like', '%@example.test')->count())->toBe(3)
        ->and(Call::withTrashed()->count())->toBeGreaterThan(3);

    expect(app(AccountLogin::class)->assertEligible($agent, PrincipalType::User, 'password'))->toBe($agent);
});

it('refuses to load demo data in production', function (): void {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(DemoSeeder::class)->run())->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
});
