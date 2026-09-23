<?php

use App\Audit\Auditor;
use App\Auth\Permission;
use App\Auth\Role;
use App\CallCenter\CallCenterService;
use App\Enums\CallStatus;
use App\Enums\ClientTier;
use App\Enums\IdMethod;
use App\Enums\IdSessionStatus;
use App\Filament\Admin\Pages\Reports;
use App\Models\AuditLog;
use App\Models\Call;
use App\Models\IdSession;
use App\Models\User;
use App\Reporting\ReportPeriodTooLongException;
use App\Reporting\ReportSection;
use App\Reporting\UserReport;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\HuDate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
});

function downloadBody(Testable $test): string
{
    return base64_decode((string) data_get($test->effects, 'download.content'));
}

it('opens the reports page for supervisors and admins only', function (): void {
    expect(Role::Supervisor->permissions())->toContain(Permission::ReportsExport)
        ->and(Role::Admin->permissions())->toContain(Permission::ReportsExport)
        ->and(Role::Agent->permissions())->not->toContain(Permission::ReportsExport);

    $this->actingAs(User::factory()->withRole(Role::Agent)->create())->get('/admin/reports')->assertForbidden();

    $this->flushSession();
    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create())->get('/admin/reports')->assertOk()->assertSee(__('Download report'));
});

it('builds every part of the report from calls, sessions and the audit log', function (): void {
    $supervisor = User::factory()->withRole(Role::Supervisor)->create(['name' => 'Vezető Vera']);
    $anna = User::factory()->withRole(Role::Agent)->synced()->create(['name' => 'Kovács Anna', 'last_login_at' => now()]);
    $bela = User::factory()->withRole(Role::Agent)->closed('left')->create(['name' => 'Nagy Béla']);

    Call::factory()->create(['agent_user_id' => $anna->id, 'queue' => 'Ügyfélszolgálat', 'status' => CallStatus::Ended, 'arrived_at' => today()->setTime(9, 0), 'answered_at' => today()->setTime(9, 0), 'ended_at' => today()->setTime(9, 2)]);
    Call::factory()->create(['agent_user_id' => $anna->id, 'queue' => 'Ügyfélszolgálat', 'status' => CallStatus::Ended, 'arrived_at' => today()->setTime(10, 0), 'answered_at' => today()->setTime(10, 0), 'ended_at' => today()->setTime(10, 4)]);
    $missed = Call::factory()->create(['status' => CallStatus::Missed, 'arrived_at' => today()->subDay()->setTime(9, 0), 'ended_at' => today()->subDay()->setTime(9, 1)]);
    Call::factory()->create(['status' => CallStatus::Ended, 'arrived_at' => today()->subDay()->setTime(11, 0), 'ended_at' => today()->subDay()->setTime(11, 1)]);
    $missed->markHandled($bela);
    // Pruned by the short retention: still counted.
    Call::query()->whereNotNull('agent_user_id')->delete();

    IdSession::factory()->create(['agent_user_id' => $anna->id, 'method' => IdMethod::QuestionAnswer, 'status' => IdSessionStatus::Passed]);
    IdSession::factory()->create(['agent_user_id' => $anna->id, 'method' => IdMethod::Pin, 'status' => IdSessionStatus::Failed]);
    IdSession::factory()->create(['agent_user_id' => $anna->id, 'method' => IdMethod::Manual, 'status' => IdSessionStatus::Cancelled]);
    IdSession::factory()->create(['agent_user_id' => $bela->id, 'method' => IdMethod::QuestionAnswer, 'status' => IdSessionStatus::Passed, 'started_at' => today()->subDays(40)]);

    AuditLog::query()->delete();
    app(Auditor::class)->record('login.succeeded', $anna, [], $anna);
    app(Auditor::class)->record('client.viewed', null, [], $anna);
    app(Auditor::class)->record('login.succeeded', $bela, [], $bela);

    $report = app(UserReport::class)->build($supervisor, today()->subDays(29), today(), ReportSection::cases());
    $tables = collect($report->tables)->keyBy('title');

    $users = $tables[ReportSection::Users->label()];
    expect($users->headers[0])->toBe(__('Name'))
        ->and(array_column($users->rows, 0))->toContain('Kovács Anna', 'Nagy Béla', 'Vezető Vera')
        ->and(collect($users->rows)->firstWhere(0, 'Nagy Béla')[3])->toContain(__('Closed'))
        ->and(collect($users->rows)->firstWhere(0, 'Kovács Anna')[2])->toBe('agent');

    $calls = $tables[ReportSection::Calls->label()];
    expect($calls->headers)->toContain(__('Queue: :name', ['name' => 'Ügyfélszolgálat']), __('Level: :name', ['name' => ClientTier::Premium->label()]))
        ->and($calls->rows[0][0])->toBe('Kovács Anna')
        ->and($calls->rows[0][1])->toBe(2)
        ->and($calls->rows[0][2])->toBe(2)
        ->and(Arr::last($calls->rows[0]))->toBe(0)
        ->and($calls->rows[0][count($calls->headers) - 2])->toBe('03:00')
        ->and(collect($calls->rows)->firstWhere(0, 'Nagy Béla'))->not->toBeNull()
        ->and(Arr::last(collect($calls->rows)->firstWhere(0, 'Nagy Béla')))->toBe(1)
        ->and($calls->totals[1])->toBe(2);

    $daily = $tables[__('Calls taken by agent, per day')];
    expect($daily->headers)->toHaveCount(32)
        ->and(Arr::last($daily->rows[0]))->toBe(2)
        ->and($daily->rows[0][30])->toBe(2);

    $byDay = $tables[ReportSection::CallsByDay->label()];
    $yesterday = collect($byDay->rows)->firstWhere(0, today()->subDay()->format(HuDate::DATE));
    expect($byDay->rows)->toHaveCount(30)
        ->and($yesterday)->toBe([today()->subDay()->format(HuDate::DATE), 2, 0, 2, 1])
        ->and($byDay->totals)->toBe([__('Total'), 4, 2, 2, 1]);

    $ids = $tables[ReportSection::Identifications->label()];
    expect($ids->rows)->toHaveCount(1)
        ->and($ids->rows[0][0])->toBe('Kovács Anna')
        ->and($ids->rows[0][1])->toBe(3)
        ->and(array_slice($ids->rows[0], -5))->toBe([1, 1, 0, 1, '33.3']);

    $activity = $tables[ReportSection::Activity->label()];
    expect(collect($activity->rows)->firstWhere(0, 'Kovács Anna'))->toMatchArray([1 => 1, 2 => 2])
        ->and(collect($activity->rows)->firstWhere(0, 'Nagy Béla'))->toMatchArray([1 => 1, 2 => 1]);
});

it('downloads the report as HTML or XLSX from the page and audits it', function (): void {
    $supervisor = User::factory()->withRole(Role::Supervisor)->create(['name' => 'Vezető Vera']);
    $this->actingAs($supervisor);
    $anna = User::factory()->withRole(Role::Agent)->create(['name' => 'Kovács Anna']);
    Call::factory()->create(['agent_user_id' => $anna->id, 'queue' => 'Ügyfélszolgálat', 'status' => CallStatus::Ended]);

    $html = Livewire::test(Reports::class)
        ->fillForm(['from' => today()->subDays(6)->toDateString(), 'until' => today()->toDateString(), 'sections' => ['calls', 'calls_by_day'], 'format' => 'html'])
        ->call('download')
        ->assertHasNoFormErrors()
        ->assertFileDownloaded();

    $body = downloadBody($html);
    expect($body)->toContain('<!doctype html>', 'Kovács Anna', ReportSection::Calls->label(), ReportSection::CallsByDay->label(), 'Vezető Vera')
        ->and($body)->not->toContain(ReportSection::Users->label());

    $audit = AuditLog::query()->where('event', 'report.exported')->latest('id')->first();
    expect($audit->actor_id)->toBe($supervisor->id)
        ->and($audit->context)->toMatchArray(['format' => 'html', 'sections' => ['calls', 'calls_by_day'], 'from' => today()->subDays(6)->toDateString()]);

    $xlsx = Livewire::test(Reports::class)
        ->fillForm(['from' => today()->subDays(6)->toDateString(), 'until' => today()->toDateString(), 'sections' => ['users', 'calls'], 'format' => 'xlsx'])
        ->call('download')
        ->assertHasNoFormErrors()
        ->assertFileDownloaded();

    $path = tempnam(sys_get_temp_dir(), 'hdid-test').'.xlsx';
    file_put_contents($path, downloadBody($xlsx));
    $reader = SimpleExcelReader::create($path)->noHeaderRow();
    $sheets = [];
    foreach ($reader->getSheetNames() as $name) {
        $sheets[$name] = SimpleExcelReader::create($path)->fromSheetName($name)->noHeaderRow()->getRows()->all();
    }
    unlink($path);

    expect(array_keys($sheets))->toBe([__('Summary'), ReportSection::Users->label(), ReportSection::Calls->label(), __('Calls taken by agent, per day')])
        ->and($sheets[ReportSection::Users->label()][0][0])->toBe(__('Name'))
        ->and(array_column($sheets[ReportSection::Users->label()], 0))->toContain('Kovács Anna')
        ->and($sheets[ReportSection::Calls->label()][1][0])->toBe('Kovács Anna')
        ->and($sheets[ReportSection::Calls->label()][1][1])->toBe(1);
});

it('styles the HTML report with the configured brand palette', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::BrandingPrimaryColor, '#123456');
    $settings->set(SettingKey::BrandingBackgroundColor, '#ABCDEF');
    $settings->set(SettingKey::BrandingMutedTextColor, '#707070');
    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());

    $body = downloadBody(Livewire::test(Reports::class)
        ->fillForm(['from' => today()->subDays(6)->toDateString(), 'until' => today()->toDateString(), 'sections' => ['users'], 'format' => 'html'])
        ->call('download')
        ->assertFileDownloaded());

    expect($body)->toContain('background: #123456', 'background: #ABCDEF', 'color: #707070')
        ->and($body)->not->toContain('#f4f5f6', '#5e6975');
});

it('accepts today as the end of the period even when the picker sends a time', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());

    Livewire::test(Reports::class)
        ->fillForm(['from' => today()->subDays(6)->toDateString(), 'until' => today()->setTime(17, 40)->format('Y-m-d H:i:s'), 'sections' => ['users'], 'format' => 'html'])
        ->call('download')
        ->assertHasNoFormErrors()
        ->assertFileDownloaded();

    Livewire::test(Reports::class)
        ->fillForm(['from' => today()->toDateString(), 'until' => today()->addDay()->toDateString(), 'sections' => ['users'], 'format' => 'html'])
        ->call('download')
        ->assertHasFormErrors(['until']);
});

it('rejects a period longer than the limit and an empty part list', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());

    Livewire::test(Reports::class)
        ->fillForm(['from' => today()->subDays(500)->toDateString(), 'until' => today()->toDateString(), 'sections' => ['users'], 'format' => 'html'])
        ->call('download')
        ->assertHasErrors(['data.until']);

    Livewire::test(Reports::class)
        ->fillForm(['sections' => []])
        ->call('download')
        ->assertHasFormErrors(['sections']);

    expect(AuditLog::query()->where('event', 'report.exported')->exists())->toBeFalse();
});

it('refuses the download without the permission even when the page is called directly', function (): void {
    $this->actingAs(User::factory()->withRole(Role::Agent)->create());

    Livewire::test(Reports::class)->assertForbidden();
    expect(AuditLog::query()->where('event', 'report.exported')->exists())->toBeFalse();
});

it('accepts a one day report when the starting picker carries a later time', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->withRole(Role::Supervisor)->create());

    Livewire::test(Reports::class)
        ->fillForm(['from' => today()->setTime(17, 40)->format('Y-m-d H:i:s'), 'until' => today()->toDateString(), 'sections' => ['users'], 'format' => 'html'])
        ->call('download')
        ->assertHasNoFormErrors()
        ->assertFileDownloaded();
});

it('does not count an answered stale call as a missed call in the report', function (): void {
    $agent = User::factory()->create();
    Call::factory()->create(['agent_user_id' => $agent->id, 'status' => CallStatus::Active, 'arrived_at' => now()->subHours(3), 'answered_at' => now()->subHours(3)]);
    app(CallCenterService::class)->expireStale(2);

    $historical = Call::factory()->create(['agent_user_id' => $agent->id, 'status' => CallStatus::Missed, 'handled_by_user_id' => $agent->id, 'handled_at' => now()]);

    $report = app(UserReport::class)->build($agent, today()->subDay(), today(), [ReportSection::CallsByDay]);

    expect($report->tables[0]->totals)->toBe([__('Total'), 2, 2, 0, 0])
        ->and($historical->isMissed())->toBeFalse()
        ->and(Call::query()->missed()->count())->toBe(0);

    $byAgent = app(UserReport::class)->build($agent, today()->subDay(), today(), [ReportSection::Calls])->tables[0];
    expect(Arr::last($byAgent->rows[0]))->toBe(0)->and(Arr::last($byAgent->totals))->toBe(0);
});

it('writes formula shaped report text as XLSX strings while preserving numeric totals', function (): void {
    $supervisor = User::factory()->withRole(Role::Supervisor)->create(['name' => '=1+1']);
    $this->actingAs($supervisor);
    Call::factory()->create(['agent_user_id' => $supervisor->id, 'queue' => '=2+2', 'status' => CallStatus::Ended]);

    $download = Livewire::test(Reports::class)
        ->fillForm(['sections' => ['calls'], 'format' => 'xlsx'])
        ->call('download')->assertHasNoFormErrors()->assertFileDownloaded();

    $path = tempnam(sys_get_temp_dir(), 'hdid-report-');
    try {
        file_put_contents($path, downloadBody($download));
        $archive = new ZipArchive;
        expect($archive->open($path))->toBeTrue();
        for ($index = 0; $index < $archive->numFiles; $index++) {
            if (str_starts_with($archive->getNameIndex($index), 'xl/worksheets/')) {
                expect($archive->getFromIndex($index))->not->toMatch('/<f(?:\s|>)/');
            }
        }
        $archive->close();

        $rows = SimpleExcelReader::create($path, 'xlsx')->keepFormulas()->fromSheetName(ReportSection::Calls->label())->noHeaderRow()->getRows()->all();
        expect($rows[1][0])->toBe('=1+1')->and($rows[1][1])->toBe(1);
    } finally {
        unlink($path);
    }
});

it('counts a missed call marked handled later on the day the call arrived, in both tables', function (): void {
    $supervisor = User::factory()->withRole(Role::Supervisor)->create();
    $agent = User::factory()->withRole(Role::Agent)->create(['name' => 'Kovács Anna']);

    $missed = Call::factory()->create([
        'status' => CallStatus::Missed,
        'arrived_at' => today()->subDay()->setTime(21, 0),
        'ended_at' => today()->subDay()->setTime(21, 1),
    ]);
    // Handled the next morning, outside the reported period's last day.
    $this->travelTo(today()->setTime(8, 0));
    $missed->markHandled($agent);
    $this->travelBack();

    $report = app(UserReport::class)->build($supervisor, today()->subDays(6), today()->subDay(), [ReportSection::Calls, ReportSection::CallsByDay]);
    $tables = collect($report->tables)->keyBy('title');

    $agentRow = collect($tables[ReportSection::Calls->label()]->rows)->firstWhere(0, 'Kovács Anna');
    $byDay = collect($tables[ReportSection::CallsByDay->label()]->rows)->firstWhere(0, today()->subDay()->format(HuDate::DATE));

    expect(Arr::last($agentRow))->toBe(1, 'the agent table books it on the day the call arrived')
        ->and($byDay[4])->toBe(1)
        ->and(Arr::last($tables[ReportSection::Calls->label()]->totals))->toBe($tables[ReportSection::CallsByDay->label()]->totals[4]);
});

it('enforces the report period limit in the service, not only on the page', function (): void {
    $supervisor = User::factory()->withRole(Role::Supervisor)->create();

    expect(fn () => app(UserReport::class)->build($supervisor, today()->subDays(UserReport::MAX_DAYS), today(), [ReportSection::Users]))
        ->toThrow(ReportPeriodTooLongException::class);

    // Exactly the limit is still served.
    expect(app(UserReport::class)->build($supervisor, today()->subDays(UserReport::MAX_DAYS - 1), today(), [ReportSection::Users])->tables)->not->toBeEmpty();
});
