<?php

namespace Database\Seeders;

use App\Auth\Role;
use App\Enums\CallStatus;
use App\Enums\ClientTier;
use App\Enums\PhoneNumberSource;
use App\Enums\PrincipalType;
use App\Identification\ClientAnswers;
use App\Identification\PinService;
use App\Identification\QuestionCatalog;
use App\Models\Call;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\NewsPost;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo data: an agent, a few questions in a published pool, two clients (one
 * fully set up) and a ringing call. Accounts are built without factories,
 * because the container image ships without the Faker dev dependency and
 * seeds its demo mode from there. Refuses to run in production.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data must not be loaded in production (APP_ENV=production).');
        }

        $this->call(RolesAndPermissionsSeeder::class);

        app(Settings::class)->set(SettingKey::PackagesPremium, ['Premium+']);
        app(Settings::class)->set(SettingKey::PackagesStandard, ['Basic']);
        app(Settings::class)->set(SettingKey::SupportPremiumPhone, '+36 1 800 1000');
        app(Settings::class)->set(SettingKey::SupportPremiumEmail, 'premium@example.test');
        app(Settings::class)->set(SettingKey::SupportPremiumHours, 'H–V 0–24');
        app(Settings::class)->set(SettingKey::SupportStandardPhone, '+36 1 800 2000');
        app(Settings::class)->set(SettingKey::SupportStandardEmail, 'ugyfelszolgalat@example.test');
        app(Settings::class)->set(SettingKey::SupportStandardHours, 'H–P 8–18');
        app(Settings::class)->set(SettingKey::SyncClientDomains, ['acme.hu']);
        app(Settings::class)->set(SettingKey::SyncUserDomains, ['helpdesk.hu']);
        app(Settings::class)->set(SettingKey::PortalLegalName, 'Helpdesk Zrt.');
        app(Settings::class)->set(SettingKey::PortalFooterText, "Az ügyfélportál használata önkéntes. Az itt megadott adatokat kizárólag a telefonos azonosításhoz használjuk.\nÜgyfélszolgálat: hétköznap 8–18 óra között.");
        app(Settings::class)->set(SettingKey::PortalPrivacyUrl, 'https://example.test/adatkezeles');
        app(Settings::class)->set(SettingKey::PortalTermsUrl, 'https://example.test/feltetelek');

        self::agent(200001, 'Ági Ügynök', 'agent@example.test', 'password-agent-1');

        $questions = collect([
            'Mi volt az első háziállata neve?',
            'Melyik városban született?',
            'Mi a kedvenc étele?',
            'Milyen márkájú volt az első autója?',
            'Hogy hívták az általános iskoláját?',
            'Mi az édesanyja leánykori neve?',
        ])->map(fn (string $text) => app(QuestionCatalog::class)->create($text));

        NewsPost::query()->create([
            'title' => 'Új azonosítási mód: mobilos kód',
            'body' => "Mostantól a **mobilapp** egyszeri kódjával is azonosíthatod magad hívás közben.\n\n- Nyisd meg az appot\n- Generálj kódot\n- Diktáld be az ügyintézőnek",
            'published_at' => now()->subDays(2),
        ]);
        NewsPost::query()->create([
            'title' => 'Karbantartás pénteken',
            'body' => 'Pénteken 22:00 és 23:00 között az ügyfélportál rövid ideig nem lesz elérhető.',
            'published_at' => now()->subHours(3),
        ]);

        $anna = self::client(100001, 'Kovács Anna', 'anna@example.test', 'Basic', 'Premium+', 'Acme Kft.');
        $anna->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Sync, 'is_primary' => true, 'verified_at' => now()]);
        foreach ($questions->take(5) as $i => $question) {
            app(ClientAnswers::class)->save($anna, $question, ['Rex', 'Szeged', 'Gulyás', 'Suzuki', 'Petőfi'][$i]);
        }
        app(PinService::class)->setPin($anna, '123456');

        $bela = self::client(100002, 'Nagy Béla', 'bela@example.test', 'Basic', null, 'Acme Kft.');
        $bela->phoneNumbers()->create(['number_e164' => '+36209876543', 'source' => PhoneNumberSource::Sync, 'is_primary' => true]);

        Call::query()->create([
            'external_call_id' => 'demo-call-1',
            'caller_number_raw' => '06301234567',
            'caller_number_e164' => '+36301234567',
            'client_id' => $anna->id,
            'status' => CallStatus::Ringing,
            'queue' => 'Ügyfélszolgálat',
            'tier' => ClientTier::Premium,
            'arrived_at' => now()->subMinute(),
        ]);
        Call::query()->create([
            'external_call_id' => 'demo-call-0',
            'caller_number_raw' => '06209876543',
            'caller_number_e164' => '+36209876543',
            'client_id' => $bela->id,
            'status' => CallStatus::Missed,
            'queue' => 'Ügyfélszolgálat',
            'tier' => ClientTier::Standard,
            'arrived_at' => now()->subMinutes(20),
            'ended_at' => now()->subMinutes(19),
        ]);
        Call::query()->create([
            'external_call_id' => 'demo-call-2',
            'caller_number_raw' => '06701112222',
            'caller_number_e164' => '+36701112222',
            'status' => CallStatus::Ringing,
            'queue' => 'Ügyfélszolgálat',
            'tier' => ClientTier::Premium,
            'arrived_at' => now(),
        ]);

        $this->call(DemoCallHistorySeeder::class);
    }

    /**
     * A demo agent with the directory record the login rule requires.
     */
    public static function agent(int $externalId, string $name, string $email, string $password): User
    {
        $user = User::query()->create([
            'name' => $name, 'email' => $email, 'password' => $password,
            'external_record_id' => self::directoryRecord($externalId, PrincipalType::User, $name, $email)->id,
        ]);
        $user->assignRole(Role::Agent->value);

        return $user;
    }

    /**
     * A demo client as the EMD sync would create it, packages on both sides.
     */
    public static function client(int $externalId, string $name, string $email, ?string $implicitPackage, ?string $explicitPackage, string $company): Client
    {
        $record = self::directoryRecord($externalId, PrincipalType::Client, $name, $email, [
            'company' => $company, 'implicit_package' => $implicitPackage, 'explicit_package' => $explicitPackage,
        ]);

        return Client::query()->create([
            'name' => $name, 'email' => $email, 'external_record_id' => $record->id,
            'implicit_package' => $implicitPackage, 'explicit_package' => $explicitPackage,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function directoryRecord(int $externalId, PrincipalType $kind, string $name, string $email, array $attributes = []): ExternalRecord
    {
        return ExternalRecord::query()->create([
            'external_id' => $externalId, 'kind' => $kind, 'name' => $name, 'email' => $email,
            'email_domain' => ExternalRecord::domainOf($email), 'phones' => [], 'attributes' => [],
            ...$attributes,
        ]);
    }
}
