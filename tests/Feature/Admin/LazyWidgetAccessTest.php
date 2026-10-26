<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\Clients\Pages\ViewClient;
use App\Filament\Admin\Resources\Clients\RelationManagers\PhoneNumbersRelationManager;
use App\Filament\Admin\Widgets\AgentCallsChartWidget;
use App\Filament\Admin\Widgets\AgentCallsTableWidget;
use App\Filament\Admin\Widgets\AuditEventsWidget;
use App\Filament\Admin\Widgets\Concerns\AuthorizesEveryRequest;
use App\Filament\Admin\Widgets\FailedJobsWidget;
use App\Filament\Admin\Widgets\IdentificationsChartWidget;
use App\Filament\Admin\Widgets\MissedCallsWidget;
use App\Filament\Admin\Widgets\OngoingCallsWidget;
use App\Filament\Admin\Widgets\OutboundMessagesWidget;
use App\Filament\Admin\Widgets\OverviewStatsWidget;
use App\Filament\Admin\Widgets\PendingJobsWidget;
use App\Filament\Concerns\RequiresServedOwner;
use App\Models\Client;
use App\Models\FailedJob;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Features\SupportEvents\SupportEvents;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Widgets and tabs are lazy: the page sends a placeholder whose snapshot
 * loads them later, and Livewire skips the hydrate hooks on that request.
 * Filament 5.9 asked canView() only in a hydrate hook; the subclasses below
 * switch Filament's own check off, so these tests prove the application's
 * check whatever the installed Filament version does.
 */

class WidgetWithoutFilamentCheck extends FailedJobsWidget
{
    public function bootCanAuthorizeAccess(): void {}
}

class TabWithoutFilamentCheck extends PhoneNumbersRelationManager
{
    public function bootCanAuthorizeAccess(): void {}
}

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withRole(Role::Admin)->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    config()->set('queue.default', 'database');

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendOutboundMessage', 'data' => []]),
        'exception' => 'RuntimeException: SECRET-EWS-ERROR in /app/Mail/EwsTransport.php',
        'failed_at' => now(),
    ]);
});

/**
 * The component as the page sends it: a placeholder with the snapshot
 * that loads it.
 *
 * @param  array<string, mixed>  $params
 * @return array{0: Testable, 1: string}
 */
function lazyPlaceholder(string $component, array $params = []): array
{
    $test = Livewire::test($component, $params + ['lazy' => true]);

    preg_match('/__lazyLoad\((?:\'|&#039;)([^\'&]+)(?:\'|&#039;)\)/', $test->html(), $match);
    expect($match[1] ?? null)->not->toBeNull('the component rendered as a lazy placeholder');

    return [$test, $match[1]];
}

it('does not load the failed jobs to a user who lost the permission while the page stayed open', function (string $widget): void {
    [$test, $encoded] = lazyPlaceholder($widget);

    $this->admin->syncRoles([Role::Supervisor->value]);

    $test->call('__lazyLoad', $encoded)->assertForbidden()->assertDontSee('SECRET-EWS-ERROR');
})->with([
    'the widget' => FailedJobsWidget::class,
    'without Filament\'s own check' => WidgetWithoutFilamentCheck::class,
]);

it('does not render the failed jobs when the placeholder snapshot is replayed without the lazy load', function (): void {
    [$test] = lazyPlaceholder(WidgetWithoutFilamentCheck::class);

    $this->admin->syncRoles([Role::Supervisor->value]);

    $test->call('$refresh')->assertForbidden()->assertDontSee('SECRET-EWS-ERROR');
});

it('still loads the failed jobs for an administrator', function (): void {
    [$test, $encoded] = lazyPlaceholder(WidgetWithoutFilamentCheck::class);

    $test->call('__lazyLoad', $encoded)->assertOk()->assertSee('SECRET-EWS-ERROR');
});

it('guards the error details of a failed job like its other actions', function (): void {
    $widget = Livewire::test(FailedJobsWidget::class)->instance();
    $details = fn () => $widget->getTable()->getAction('details')->record(FailedJob::query()->firstOrFail());

    expect($details()->isAuthorized())->toBeTrue();

    $this->admin->syncRoles([Role::Supervisor->value]);

    expect($details()->isAuthorized())->toBeFalse();
});

it('refuses the first load of every widget to a user without its permission', function (string $widget): void {
    [$test, $encoded] = lazyPlaceholder($widget);

    $this->admin->syncRoles([]);

    $test->call('__lazyLoad', $encoded)->assertForbidden();
})->with([
    AgentCallsChartWidget::class,
    AgentCallsTableWidget::class,
    AuditEventsWidget::class,
    FailedJobsWidget::class,
    IdentificationsChartWidget::class,
    MissedCallsWidget::class,
    OngoingCallsWidget::class,
    OutboundMessagesWidget::class,
    OverviewStatsWidget::class,
    PendingJobsWidget::class,
]);

it('refuses a client tab to a user who lost the permission behind it, without Filament\'s own check', function (): void {
    $client = Client::factory()->synced()->premium()->create();
    $client->phoneNumbers()->create(['number_e164' => '+36301112233', 'source' => 'admin']);
    [$test, $encoded] = lazyPlaceholder(TabWithoutFilamentCheck::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class]);

    $this->admin->syncRoles([]);

    $test->call('__lazyLoad', $encoded)->assertForbidden()->assertDontSee('+36301112233');
});

it('gives the guarded tabs and widgets no event listener, which a lazy placeholder request would run before the checks', function (): void {
    // On a lazy placeholder request Livewire runs a dispatched event's listener before the
    // call hooks of RequiresServedOwner and AuthorizesEveryRequest; a new #[On] listener
    // on one of these needs its own check first, and this test is the reminder.
    $guarded = collect([
        ...File::allFiles(app_path('Filament/Admin/Widgets')),
        ...File::allFiles(app_path('Filament/Admin/Resources/Clients/RelationManagers')),
    ])->map(fn ($file): string => 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file->getPathname(), app_path().'/')))
        ->filter(fn (string $class): bool => class_exists($class) && ! (new ReflectionClass($class))->isAbstract())
        ->filter(fn (string $class): bool => array_intersect([RequiresServedOwner::class, AuthorizesEveryRequest::class], class_uses_recursive($class)) !== []);

    expect($guarded)->not->toBeEmpty();
    foreach ($guarded as $class) {
        expect(SupportEvents::getListenerEventNames(new $class))->toBe([], "{$class} listens to events");
    }
});
