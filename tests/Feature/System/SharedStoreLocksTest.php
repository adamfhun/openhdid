<?php

use App\Enums\SyncRunStatus;
use App\Enums\SyncSource;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\Contracts\SourceReader;
use App\Sync\ExternalRecordDto;
use App\Sync\SyncExternalRecords;
use App\System\Installation;
use App\System\InstallationCommandMutex;
use App\System\InstallationEventMutex;
use App\System\InstallationSchedulingMutex;
use Illuminate\Console\CommandMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

/**
 * G31c: two installations on one Valkey/Redis with the same key prefix (the
 * test cache store plays the shared store, the APP_KEY tells them apart).
 * The scheduler's mutexes and the global locks carry the installation id,
 * so each installation runs its own work, while within one installation
 * they still keep a task to one run and one server.
 */
function locksInstallation(string $letter): void
{
    config()->set('app.key', 'base64:'.base64_encode(str_repeat($letter, 32)));
}

function locksPruneEvent(): Event
{
    return collect(app(Schedule::class)->events())->sole(fn (Event $event): bool => str_contains((string) $event->command, 'hdid:prune'));
}

function locksReader(): SourceReader
{
    return new class implements SourceReader
    {
        public function source(): SyncSource
        {
            return SyncSource::Csv;
        }

        public function label(): ?string
        {
            return 'test';
        }

        public function read(): iterable
        {
            yield new ExternalRecordDto(1, 'Name 1', 'a@x.hu', 'Acme', [], [], 'Basic', null);
        }

        public function details(): array
        {
            return [];
        }
    };
}

beforeEach(function (): void {
    locksInstallation('p');
});

it('runs a scheduled task on both installations that share the store, and still once on each', function (): void {
    $event = locksPruneEvent();
    $servers = app(SchedulingMutex::class);
    $minute = now();

    // The production installation runs the nightly prune: its overlap and one-server marks are taken.
    expect($servers)->toBeInstanceOf(InstallationSchedulingMutex::class)
        ->and($event->mutex)->toBeInstanceOf(InstallationEventMutex::class)
        ->and($event->shouldSkipDueToOverlapping())->toBeFalse()
        ->and($servers->create($event, $minute))->toBeTrue();

    locksInstallation('s');
    expect($event->shouldSkipDueToOverlapping())->toBeFalse('the staging installation runs its own prune')
        ->and($servers->create($event, $minute))->toBeTrue();

    locksInstallation('p');
    expect($event->shouldSkipDueToOverlapping())->toBeTrue('a second run of the same installation still waits')
        ->and($servers->create($event, $minute))->toBeFalse('a second server of the same installation still skips');
});

it('lets the EMD sync of each installation run while the other holds its lock', function (): void {
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['x.hu']);
    $production = Cache::lock(Installation::lockName('hdid:sync'), 3600);
    expect($production->get())->toBeTrue();

    expect(fn () => app(SyncExternalRecords::class)->run(locksReader()))->toThrow(RuntimeException::class);

    locksInstallation('s');
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['x.hu']);
    expect(app(SyncExternalRecords::class)->run(locksReader())->status)->toBe(SyncRunStatus::Completed);

    $production->release();
});

it('keeps the isolated migration of one installation from stopping another one\'s', function (): void {
    expect(app(CommandMutex::class))->toBeInstanceOf(InstallationCommandMutex::class);
    $migrate = app(Kernel::class)->all()['migrate'];
    $mutex = app(CommandMutex::class);

    expect($mutex->create($migrate))->toBeTrue();

    locksInstallation('s');
    expect($mutex->create($migrate))->toBeTrue();

    locksInstallation('p');
    expect($mutex->create($migrate))->toBeFalse('the same installation still runs one migration at a time');
});

it('changes only the lock names, not the cached values the installation reads', function (): void {
    Cache::put('hdid.example', 'kept');

    expect(Installation::lockName('hdid:sync'))->toBe('hdid-'.Installation::id().':hdid:sync')
        ->and(Cache::get('hdid.example'))->toBe('kept');
});
