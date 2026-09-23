<?php

use App\Auth\Permission;
use App\Auth\Role;
use App\Clients\ClientExporter;
use App\Clients\ClientExportField;
use App\Filament\Admin\Resources\Clients\Pages\ListClients;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Identification\ClientAnswers;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Question;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

it('gives the export permission to admins only by default', function (): void {
    expect(Role::Admin->permissions())->toContain(Permission::ClientsExport)
        ->and(Role::SuperAdmin->permissions())->toContain(Permission::ClientsExport)
        ->and(Role::Supervisor->permissions())->not->toContain(Permission::ClientsExport)
        ->and(Role::Agent->permissions())->not->toContain(Permission::ClientsExport);

    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());
    Livewire::test(ListClients::class)->assertActionHidden('exportClients');

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Livewire::test(ListClients::class)->assertActionVisible('exportClients');
});

it('never offers security answers or the PIN value, only whether a PIN is set', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $keys = array_merge(...array_values(array_map('array_keys', app(ClientExporter::class)->options($admin))));

    expect($keys)->toContain('name', 'pin_set', 'answers_count')
        ->and(array_filter($keys, fn (string $k) => str_contains($k, 'answer') && $k !== 'answers_count'))->toBe([])
        ->and(array_filter($keys, fn (string $k) => str_contains($k, 'pin') && ! in_array($k, ['pin_set', 'pin_set_at', 'pin_locked_until'], true)))->toBe([]);

    $client = Client::factory()->synced()->withPin('654321')->create(['name' => 'Titkos Tamás']);
    $question = Question::factory()->create();
    app(ClientAnswers::class)->save($client, $question, 'Bodri kutya');

    $row = app(ClientExporter::class)->row($client->fresh(), array_map(fn (ClientExportField $f) => $f->value, ClientExportField::cases()));

    expect(implode(' ', $row))->not->toContain('654321', 'Bodri', 'bodri')
        ->and($row['pin_set'])->toBe(__('Yes'))
        ->and($row['answers_count'])->toBe('1');
});

it('exports every client or only the filtered list with the ticked columns, and audits it', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);

    $open = Client::factory()->synced()->create(['name' => 'Nyitott Nóra', 'implicit_package' => 'Basic', 'explicit_package' => 'Premium']);
    $closed = Client::factory()->synced()->closed('manual')->create(['name' => 'Zárt Zoltán']);
    $directoryOnly = Client::factory()->synced()->create(['name' => 'Csak Nyilvántartás', 'implicit_package' => null, 'explicit_package' => null]);

    $response = Livewire::test(ListClients::class)
        ->callAction('exportClients', ['filtered_only' => false, 'fields' => ['client' => ['name', 'email'], 'packages' => [], 'status' => ['status'], 'security' => ['pin_set']]])
        ->assertHasNoActionErrors()
        ->assertFileDownloaded();

    $body = csvBody($response);

    expect($body)->toContain('Nyitott Nóra', 'Zárt Zoltán', 'Csak Nyilvántartás')
        ->and(explode("\n", trim($body))[0])->toContain(__('Name'), __('E-mail'), __('Status'), __('PIN set'))
        ->and($body)->not->toContain(__('Implicit package'));

    $audit = AuditLog::query()->where('event', 'client.exported')->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($admin->id)
        ->and($audit->context)->toMatchArray(['fields' => ['name', 'email', 'status', 'pin_set'], 'filtered_only' => false, 'rows' => 3]);

    // The default list filter shows relevant clients only; the toggle honours it.
    $filtered = csvBody(Livewire::test(ListClients::class)
        ->callAction('exportClients', ['filtered_only' => true, 'fields' => ['client' => ['name'], 'packages' => [], 'status' => [], 'security' => []]])
        ->assertFileDownloaded());

    expect($filtered)->toContain('Nyitott Nóra', 'Zárt Zoltán')
        ->and($filtered)->not->toContain('Csak Nyilvántartás');
});

it('drops columns the form should not have offered and refuses an empty selection', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $exporter = app(ClientExporter::class);

    expect($exporter->allowedKeys(['name', 'answers', 'pin_hash', 'directory:nope', 'name'], $admin))->toBe(['name']);

    $this->actingAs($admin);
    Livewire::test(ListClients::class)
        ->callAction('exportClients', ['filtered_only' => false, 'fields' => ['client' => [], 'packages' => [], 'status' => [], 'security' => []]])
        ->assertNotified(__('Tick at least one column.'));

    expect(AuditLog::query()->where('event', 'client.exported')->count())->toBe(0);
});

it('surfaces configured directory columns on the client page, the list and the export', function (): void {
    app(Settings::class)->set(SettingKey::ClientsDirectoryFields, ['Department' => 'Osztály', 'cost_center' => '']);
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);

    $client = Client::factory()->synced()->create(['name' => 'Osztályos Olga']);
    $client->externalRecord->update(['attributes' => ['department' => 'Pénzügy', 'cost_center' => 'CC-42', 'title' => 'Vezető']]);

    expect(app(ClientExporter::class)->directoryFields())->toBe(['Department' => 'Osztály', 'cost_center' => 'Cost Center'])
        ->and(app(ClientExporter::class)->options($admin)['directory'])->toBe(['directory:Department' => 'Osztály', 'directory:cost_center' => 'Cost Center']);

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertSee('Osztály')->assertSee('Pénzügy')->assertSee('CC-42');

    Livewire::test(ListClients::class)->assertTableColumnExists('directory_department');

    $body = csvBody(Livewire::test(ListClients::class)
        ->callAction('exportClients', ['filtered_only' => false, 'fields' => ['client' => ['name'], 'packages' => [], 'status' => [], 'security' => [], 'directory' => ['directory:Department', 'directory:cost_center']]])
        ->assertFileDownloaded());

    expect($body)->toContain('Osztály', 'Pénzügy', 'CC-42')->and($body)->not->toContain('Vezető');
});

/**
 * Body of the CSV a Livewire test downloaded (Livewire keeps it base64 encoded in the download effect).
 */
function csvBody(Testable $test): string
{
    return base64_decode((string) data_get($test->effects, 'download.content'));
}

it('shows the directory job title and department on the client and in the export', function (): void {
    $admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($admin);

    $client = Client::factory()->synced()->create(['name' => 'Beosztott Béla']);
    $client->externalRecord->update(['external_id' => 7, 'title' => 'Ügyintéző', 'department' => 'Back office']);

    Livewire::test(ViewClient::class, ['record' => $client->id])
        ->assertSee('Ügyintéző')->assertSee('Back office');

    $body = csvBody(Livewire::test(ListClients::class)
        ->callAction('exportClients', ['filtered_only' => false, 'fields' => ['client' => ['external_id', 'job_title', 'department'], 'packages' => [], 'status' => [], 'security' => []]])
        ->assertFileDownloaded());

    expect(explode("\n", trim($body))[0])->toContain(__('External id'), __('Job title'), __('Department'))
        ->and($body)->toContain('7;', 'Ügyintéző', 'Back office');
});

it('preserves two selected export columns with the same displayed heading', function (): void {
    app(Settings::class)->set(SettingKey::ClientsDirectoryFields, ['other_name' => __('Name')]);
    $client = Client::factory()->synced()->create(['name' => 'Client Name']);
    $client->externalRecord->update(['attributes' => ['other_name' => 'Directory Name']]);

    $row = app(ClientExporter::class)->row($client->fresh(), ['name', 'directory:other_name']);

    expect(array_values($row))->toContain('Client Name', 'Directory Name');

    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    $body = csvBody(Livewire::test(ListClients::class)
        ->callAction('exportClients', ['fields' => ['client' => ['name'], 'packages' => [], 'status' => [], 'security' => [], 'directory' => ['directory:other_name']]])
        ->assertFileDownloaded());
    $lines = explode("\n", trim($body));

    expect(str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), ';', '"', ''))->toBe([__('Name'), __('Name')])
        ->and(str_getcsv($lines[1], ';', '"', ''))->toBe(['Client Name', 'Directory Name']);
});

it('exports imported formula shaped names and headings as literal text', function (string $value): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Client::factory()->synced()->create(['name' => $value]);
    app(Settings::class)->set(SettingKey::ClientsDirectoryFields, ['custom' => '=1+1']);

    $download = Livewire::test(ListClients::class)
        ->callAction('exportClients', ['fields' => ['client' => ['name'], 'packages' => [], 'status' => [], 'security' => [], 'directory' => ['directory:custom']]])
        ->assertFileDownloaded();
    $body = base64_decode(data_get($download->effects, 'download.content'));
    $lines = explode("\n", trim($body));

    expect(str_getcsv($lines[0], ';', '"', '')[1])->toBe("'=1+1")
        ->and(str_getcsv($lines[1], ';', '"', '')[0])->toBe("'".$value);
})->with(['=1+1', '+36301234567', '-1+1', '@SUM(1)', '  =1+1', "\t=1+1"]);

it('records in the audit log that an export was not truncated', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Client::factory()->synced()->create();

    Livewire::test(ListClients::class)
        ->callAction('exportClients', ['filtered_only' => false, 'fields' => ['client' => ['name'], 'packages' => [], 'status' => [], 'security' => []]])
        ->assertHasNoActionErrors();

    expect(AuditLog::query()->where('event', 'client.exported')->latest('id')->first()->context)->toMatchArray(['truncated' => false]);
});
