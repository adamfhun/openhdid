<?php

use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Auth\Permission;
use App\Auth\Role;
use App\Enums\ApiKeyScope;
use App\Enums\PhoneNumberSource;
use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Filament\Admin\Pages\ManageSettings;
use App\Filament\Admin\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Admin\Resources\ExternalRecords\Pages\ManageExternalRecords;
use App\Filament\Admin\Resources\NewsPosts\Pages\ManageNewsPosts;
use App\Filament\Admin\Resources\Questions\Pages\ListQuestions;
use App\Filament\Admin\Resources\Roles\Pages\ManageRoles;
use App\Filament\Admin\Resources\SyncRuns\Pages\ManageSyncRuns;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Jobs\RunDirectorySync;
use App\Mail\MagicLinkMail;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ExternalRecord;
use App\Models\NewsPost;
use App\Models\Question;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use App\Sync\QueuedSyncs;
use App\System\CheckStatus;
use App\System\HealthChecks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
});

it('renders every admin index page', function (string $path): void {
    $this->get('/admin/'.$path)->assertOk();
})->with(['', 'accounts/users', 'accounts/roles', 'clients', 'system/external-records', 'system/sync-runs', 'system/api-keys', 'system/status', 'questions', 'id-sessions', 'calls', 'system/audit-logs', 'system/settings', 'content/news-posts', 'content/message-templates', 'content/outbound-messages', 'verify-code', 'search-clients', 'reports', 'shared-phone-numbers']);

it('hides admin resources from an agent', function (): void {
    $agent = User::factory()->withRole(Role::Agent)->create();
    $this->flushSession();

    $this->actingAs($agent)->get('/admin/accounts/users')->assertForbidden();
});

it('creates a staff user with roles', function (): void {
    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'New Agent', 'email' => 'new@corp.hu', 'password' => 'a-long-password-1', 'roles' => [Spatie\Permission\Models\Role::findByName('agent')->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('email', 'new@corp.hu')->first()->hasRole('agent'))->toBeTrue();
});

it('saves settings from the settings page and audits the change', function (): void {
    Livewire::test(ManageSettings::class)
        ->fillForm([ManageSettings::fieldName(SettingKey::QaMaxQuestionsPerSession) => 6, ManageSettings::fieldName(SettingKey::PinAgentVerificationEnabled) => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(Settings::class)->int(SettingKey::QaMaxQuestionsPerSession))->toBe(6)
        ->and(app(Settings::class)->bool(SettingKey::PinAgentVerificationEnabled))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'setting.changed')->where('actor_id', $this->admin->id)->count())->toBe(2);
});

it('creates a question and publishes a new version from the admin', function (): void {
    Livewire::test(ListQuestions::class)
        ->callAction('create', ['text' => 'Frist pet?', 'hint' => null, 'is_active' => true]);

    $question = Question::query()->first();
    expect($question->text)->toBe('Frist pet?');

    Livewire::test(ListQuestions::class)
        ->callTableAction('newVersion', $question, ['text' => 'First pet?', 'hint' => 'dog or cat', 'keeps_answers' => true]);

    expect($question->fresh()->text)->toBe('First pet?')
        ->and($question->fresh()->currentVersion->version)->toBe(2)
        ->and($question->versions()->count())->toBe(2);
});

it('sets a pin from the admin and sends it by sms to the primary number', function (): void {
    $sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $sms);
    $client = Client::factory()->create();
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => PhoneNumberSource::Admin, 'is_primary' => true]);

    Livewire::test(ClientResource::getPages()['index']->getPage())
        ->callTableAction('setPin', $client, ['pin' => '654321', 'send_sms' => true]);

    expect($client->fresh()->hasPin())->toBeTrue()
        ->and(Hash::check('654321', $client->fresh()->pin_hash))->toBeTrue()
        ->and($sms->lastTo('+36301234567'))->toContain('654321');
});

it('filters external records by e-mail domain', function (): void {
    ExternalRecord::factory()->create(['email' => 'a@acme.hu']);
    $other = ExternalRecord::factory()->create(['email' => 'b@other.hu']);

    Livewire::test(ManageExternalRecords::class)
        ->filterTable('email_domain', 'other.hu')
        ->assertCanSeeTableRecords([$other])
        ->assertCountTableRecords(1);
});

it('queues a csv import from the sync runs page for the worker and removes the upload once read', function (): void {
    Storage::fake('local');
    Queue::fake();
    config()->set(['queue.default' => 'database', 'queue.connections.database.retry_after' => 1260]);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['x.hu']);
    $file = UploadedFile::fake()->createWithContent('clients.csv', "external_id,name,email,company,phone,implicit_package\n1,Anna,anna@x.hu,Acme,+36301234567,Basic\n");

    Livewire::test(ManageSyncRuns::class)->callAction('importFile', ['file' => $file])->assertNotified();

    $run = SyncRun::query()->sole();
    expect($run->status)->toBe(SyncRunStatus::Queued)->and($run->source)->toBe(SyncSource::Csv)
        ->and(Client::query()->where('email', 'anna@x.hu')->exists())->toBeFalse('nothing runs inside the request');
    Queue::assertPushed(RunDirectorySync::class, function (RunDirectorySync $job) use ($run): bool {
        expect(Storage::disk('local')->exists($job->upload))->toBeTrue();
        (new RunDirectorySync($job->runId, $job->upload))->handle(app(QueuedSyncs::class));
        expect(Storage::disk('local')->exists($job->upload))->toBeFalse('the upload is removed once read');

        return $job->runId === $run->id;
    });

    expect(Client::query()->where('email', 'anna@x.hu')->first()->implicit_package)->toBe('Basic')
        ->and($run->fresh()->status)->toBe(SyncRunStatus::Completed);
});

it('closes and reopens a client from the table', function (): void {
    $client = Client::factory()->create();

    Livewire::test(ClientResource::getPages()['index']->getPage())
        ->callTableAction('close', $client);
    expect($client->fresh()->isClosed())->toBeTrue();

    Livewire::test(ClientResource::getPages()['index']->getPage())
        ->callTableAction('reopen', $client);
    expect($client->fresh()->isClosed())->toBeFalse();
});

it('renders the roles page and lets an admin change a role permissions', function (): void {
    $this->get('/admin/accounts/roles')->assertOk();

    $agent = Spatie\Permission\Models\Role::findByName('agent');
    $auditView = Spatie\Permission\Models\Permission::findByName(Permission::AuditView->value);

    Livewire::test(ManageRoles::class)
        ->callTableAction('edit', $agent, ['name' => 'agent', 'permissions' => [$auditView->id]])
        ->assertHasNoTableActionErrors();

    expect($agent->fresh()->permissions->pluck('name')->all())->toBe([Permission::AuditView->value]);
});

it('sends a magic link to a client from the admin and shows it in debug mode', function (): void {
    Mail::fake();
    config()->set('app.debug', true);
    $client = Client::factory()->synced()->create();

    Livewire::test(ClientResource::getPages()['index']->getPage())
        ->callTableAction('sendMagicLink', $client)
        ->assertNotified();

    Mail::assertSent(MagicLinkMail::class, fn ($mail) => $mail->hasTo($client->email));
    expect(AuditLog::query()->where('event', 'login.magic_link_sent')->first()->context['by'])->toBe($this->admin->email);
});

it('reveals a sent login link in the panel only in debug mode or with the preview setting on', function (): void {
    $login = app(ClientPasswordlessLogin::class);
    config()->set('app.debug', false);

    expect($login->revealsLinkInPanel())->toBeFalse();

    app(Settings::class)->set(SettingKey::ClientLoginMagicLinkPreviewInPanel, true);
    expect($login->revealsLinkInPanel())->toBeTrue()
        ->and(app(HealthChecks::class)->loginLinkPreview()->status)->toBe(CheckStatus::Warn);

    app(Settings::class)->set(SettingKey::ClientLoginMagicLinkPreviewInPanel, false);
    expect(app(HealthChecks::class)->loginLinkPreview()->status)->toBe(CheckStatus::Ok);

    config()->set('app.debug', true);
    expect($login->revealsLinkInPanel())->toBeTrue();
});

it('records that a login link was shown in the panel and names the staff member on the sign-in it leads to', function (): void {
    Mail::fake();
    config()->set('app.debug', false);
    app(Settings::class)->set(SettingKey::ClientLoginMagicLinkPreviewInPanel, true);
    $client = Client::factory()->synced()->create();

    Livewire::test(ClientResource::getPages()['index']->getPage())
        ->callTableAction('sendMagicLink', $client)
        ->assertNotified();

    $url = null;
    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });

    $sent = AuditLog::query()->where('event', 'login.magic_link_sent')->sole();
    expect($sent->context['revealed'])->toBeTrue()->and($sent->context['by'])->toBe($this->admin->email);

    auth('web')->logout();
    $this->withHeader('Referer', config('app.url'));
    signInWithLink($url)->assertOk();

    $login = AuditLog::query()->where('event', 'login.succeeded')->where('context->method', 'magic_link')->sole();
    expect($login->context['link_revealed_to'])->toBe($this->admin->email);
});

it('keeps a login link that was only e-mailed free of any panel mark', function (): void {
    Mail::fake();
    config()->set('app.debug', false);
    $client = Client::factory()->synced()->create();

    Livewire::test(ClientResource::getPages()['index']->getPage())
        ->callTableAction('sendMagicLink', $client);

    $url = null;
    Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$url): bool {
        $url = $mail->url;

        return true;
    });

    expect(AuditLog::query()->where('event', 'login.magic_link_sent')->sole()->context)->not->toHaveKey('revealed');

    auth('web')->logout();
    $this->withHeader('Referer', config('app.url'));
    signInWithLink($url)->assertOk();

    expect(AuditLog::query()->where('event', 'login.succeeded')->where('context->method', 'magic_link')->sole()->context)->not->toHaveKey('link_revealed_to');
});

it('creates a mobile backend key with its signing secret and keeps that secret in place', function (): void {
    Livewire::test(ManageApiKeys::class)
        ->callAction('create', ['name' => 'Mobil kiszolgáló', 'scope' => ApiKeyScope::MobileBackend->value])
        ->assertNotified(__('API key and signing secret created'));

    $mobile = ApiKey::query()->where('name', 'Mobil kiszolgáló')->sole();
    $ivr = ApiKey::factory()->signed()->create();
    expect($mobile->requiresSignature())->toBeTrue();

    Livewire::test(ManageApiKeys::class)
        ->assertActionDisabled(TestAction::make('removeSecret')->table($mobile))
        ->assertActionEnabled(TestAction::make('removeSecret')->table($ivr))
        ->callAction(TestAction::make('removeSecret')->table($mobile));

    expect($mobile->fresh()->requiresSignature())->toBeTrue('a mobile backend key always signs');
});

it('keeps the sidebar short: helpdesk items first, administrative clusters last', function (): void {
    $html = $this->withCookie('locale', 'en')->get('/admin')->getContent();
    preg_match_all('/fi-sidebar-item-label[^>]*>\s*([^<]+?)\s*</', $html, $items);

    expect($items[1])->toBe(['Dashboard', 'Find client', 'Verify code', 'Clients', 'Shared phone numbers', 'Calls', 'Reports', 'Questions', 'Identification sessions', 'Content', 'Accounts', 'System']);
});

it('lets an admin publish news with the author recorded', function (): void {
    Livewire::test(ManageNewsPosts::class)
        ->callAction('create', ['title' => 'Maintenance', 'body' => 'Tonight 22:00', 'published_at' => now()->subMinute()->format('Y-m-d H:i:s')])
        ->assertHasNoActionErrors();

    $post = NewsPost::query()->first();
    expect($post->author_user_id)->toBe($this->admin->id)->and($post->isPublished())->toBeTrue();
    $this->get('/admin/content/news-posts')->assertOk();
});
