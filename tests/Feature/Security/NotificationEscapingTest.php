<?php

use App\Auth\Role;
use App\CallCenter\CallCenterService;
use App\Clients\ClientLinks;
use App\Filament\Admin\Pages\Identify;
use App\Filament\Admin\Pages\ManageSettings;
use App\Filament\Admin\Pages\VerifyCode;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\Clients\Pages\EditClient;
use App\Filament\Admin\Widgets\OngoingCallsWidget;
use App\Filament\NotificationText;
use App\Identification\ClientAnswers;
use App\Identification\MobileOtpService;
use App\Identification\QaSessionEngine;
use App\Models\Call;
use App\Models\Client;
use App\Models\Question;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * Filament draws a notification's title and body as sanitized HTML, and its
 * filter keeps outside links, images and the style attribute. A name or an
 * e-mail written in EMD (or by an admin) reaches these notifications, so
 * each must arrive as text: NotificationText::of escapes it once. The static
 * side (every dynamic title and body goes through the helper) is the gate in
 * OutputEscapingTest.
 */
beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('escapes markup once and keeps quotes in a notification text', function (): void {
    expect(NotificationText::of(null))->toBeNull()
        ->and(NotificationText::of("O'Hara \"Kis\" R&D <b>"))->toBe("O'Hara \"Kis\" R&amp;D &lt;b&gt;")
        ->and(NotificationText::of("bad \xC3 byte"))->toBe("bad \u{FFFD} byte");
});

it('renders the client name as text in the identified notification of the code check', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());
    $client = Client::factory()->synced()->create(['name' => 'Kis Anna'.XSS_SURVIVOR_CANARY]);
    $code = app(MobileOtpService::class)->issue($client)['code'];

    Livewire::test(VerifyCode::class)->set('data.code', $code)->assertSet('failed', false);

    expectCanaryAsText(renderedNotifications());
});

it('renders the colleague name as text when another agent is already identifying the client', function (): void {
    $agent = User::factory()->withRole(Role::Agent)->create();
    $colleague = User::factory()->withRole(Role::Agent)->create(['name' => 'Kiss Béla'.XSS_SURVIVOR_CANARY]);
    $client = Client::factory()->synced()->create();
    foreach (Question::factory()->count(5)->create() as $i => $question) {
        app(ClientAnswers::class)->save($client, $question, 'válasz '.$i);
    }
    app(QaSessionEngine::class)->start($client, $colleague);

    $this->actingAs($agent);
    Livewire::test(Identify::class, ['client' => $client->id])->call('startQa')->assertSet('sessionId', null);

    expectCanaryAsText(renderedNotifications());
});

it('renders the holder name as text when the call was taken meanwhile', function (): void {
    $agent = User::factory()->withRole(Role::Agent)->create();
    $colleague = User::factory()->withRole(Role::Agent)->create(['name' => 'Kiss Béla'.XSS_SURVIVOR_CANARY]);
    $this->actingAs($agent);
    $call = Call::factory()->create();

    $widget = Livewire::test(OngoingCallsWidget::class)->assertTableActionHasLabel('take', 'Identify', $call);
    app(CallCenterService::class)->claim($call, $colleague);
    $widget->callTableAction('take', $call)->assertNoRedirect();

    expect($call->fresh()->agent_user_id)->toBe($colleague->id);
    expectCanaryAsText(renderedNotifications());
});

it('renders client names as text in the package list errors of the settings save', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null, 'name' => 'Prémium Pál'.XSS_SURVIVOR_CANARY]);

    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::PackagesPremium) => ['Gold'], ManageSettings::fieldName(SettingKey::PackagesStandard) => ['Basic']])
        ->call('save')
        ->assertHasFormErrors([ManageSettings::fieldName(SettingKey::PackagesPremium)]);

    expect(app(Settings::class)->array(SettingKey::PackagesPremium))->toBe(['Premium']);
    expectCanaryAsText(renderedNotifications());
});

it('renders an EMD-supplied ID as text when the ID list is refused', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Http::preventStrayRequests();
    config()->set([
        'hdid.sync.id_list_url' => 'https://directory.test/organizations',
        'hdid.sync.login_url' => 'https://directory.test/login',
        'hdid.sync.refresh_url' => 'https://directory.test/refresh',
        'hdid.sync.username' => 'u', 'hdid.sync.password' => 'p',
    ]);
    app(Settings::class)->set(SettingKey::SyncIdListPayload, '{"from":"{{ now }}"}');
    Http::fake([
        'https://directory.test/login' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh']),
        'https://directory.test/organizations' => Http::response(['statusCode' => 200, 'result' => [['data' => ['id' => '<img/src=https://hdid-canary.example/p.png>,', 'name' => 'Öt Kft.']]]]),
    ]);

    Livewire::test(ManageSettings::class)->callAction(TestAction::make('fetchIdList')->table());

    // The message cuts the ID at 40 characters, so the domain is the marker.
    $html = renderedNotifications();
    expect($html)->toContain(__('The ID list could not be fetched'));
    expectCanaryAsText($html, 'hdid-canary.example');
});

it('renders the client e-mail as text in the login link notification', function (): void {
    Mail::fake();
    config()->set('app.debug', false);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $client = Client::factory()->synced()->create(['email' => '"<img/src=https://hdid-canary.example/p.png>"@acme.hu']);

    Livewire::test(ClientResource::getPages()['index']->getPage())->callTableAction('sendMagicLink', $client);

    expectCanaryAsText(renderedNotifications(), 'hdid-canary.example');
});

it('shows the directory link text and the reopened-links sentence once escaped in the saved notification', function (): void {
    // Translations with markup characters: escaping twice would show "&amp;" and "&lt;" as text.
    $reopened = ':n linked client(s) closed with this sponsor reopened, because their link ended.';
    $directory = 'Not linked to EMD yet: no EMD record carries this e-mail. The account cannot sign in until an EMD sync brings a record with the same e-mail and links it.';
    $directoryMeanwhile = 'Not linked to EMD yet: no EMD record carries this e-mail. Meanwhile :how; an EMD sync that brings a record with this e-mail links the account.';
    $path = storage_path('framework/testing/notification-escaping-'.getmypid());
    File::ensureDirectoryExists($path);
    File::put($path.'/en.json', json_encode([
        $reopened => ':n reopened: Tom & <Jerry>',
        $directory => 'No EMD record: R&D <unlinked>',
        $directoryMeanwhile => 'No EMD record: R&D <unlinked>',
    ]));
    app('translator')->addJsonPath($path);
    app('translator')->setLoaded([]);

    try {
        $this->actingAs(User::factory()->withRole(Role::Admin)->create());
        $sponsor = Client::factory()->create(['implicit_package' => 'Premium', 'explicit_package' => null, 'name' => 'Lezárt Főügyfél']);
        $dependent = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium', 'name' => 'Kapcsolt Kató']);
        app(ClientLinks::class)->link($sponsor, $dependent);
        $sponsor->close('admin');

        Livewire::test(EditClient::class, ['record' => $sponsor->id])
            ->fillForm(['implicit_package' => 'Basic'])
            ->mountAction('save')
            ->callMountedAction()
            ->assertHasNoFormErrors();

        expect($dependent->fresh()->isClosed())->toBeFalse();
        $html = renderedNotifications();
        expect($html)
            ->toContain('No EMD record: R&amp;D &lt;unlinked&gt; 1 reopened: Tom &amp; &lt;Jerry&gt;')
            ->not->toContain('&amp;amp;')
            ->not->toContain('&amp;lt;');
    } finally {
        File::deleteDirectory($path);
    }
});
