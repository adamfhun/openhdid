<?php

use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Clients\ClientLinks;
use App\Enums\PrincipalType;
use App\Enums\SyncSource;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientLink;
use App\Models\ExternalRecord;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\AccountProvisioner;
use App\Sync\Contracts\SourceReader;
use App\Sync\ExternalRecordDto;
use App\Sync\SyncExternalRecords;

/**
 * NYT-06.1: while a sponsor is closed and its link is active, the linked
 * client is closed too, whatever path would reopen it (its record returning
 * to the directory, its classification flipping back, the sponsor's
 * reopening after the directory reclassified it) and whatever order a sync
 * run handles the two records in.
 *
 * @param  list<ExternalRecordDto>  $rows
 */
function sponsorClosureReader(array $rows): SourceReader
{
    return new class($rows) implements SourceReader
    {
        /**
         * @param  list<ExternalRecordDto>  $rows
         */
        public function __construct(private array $rows) {}

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
            yield from $this->rows;
        }

        public function details(): array
        {
            return [];
        }
    };
}

function sponsorClosureRow(int $id, string $email, ?string $implicit, ?string $explicit): ExternalRecordDto
{
    return new ExternalRecordDto($id, 'Name '.$id, $email, 'Acme', [], [], $implicit, $explicit);
}

/**
 * Rows that stay in every run, so that the shrink guard lets a run without
 * the sponsor and its linked client through.
 *
 * @return list<ExternalRecordDto>
 */
function sponsorClosureFillers(): array
{
    return [
        sponsorClosureRow(11, 'egy@x.hu', 'Basic', null),
        sponsorClosureRow(12, 'ketto@x.hu', 'Basic', null),
        sponsorClosureRow(13, 'harom@x.hu', 'Basic', null),
    ];
}

beforeEach(function (): void {
    app(Settings::class)->set(SettingKey::SyncUserDomains, ['staff.hu']);
    app(Settings::class)->set(SettingKey::SyncClientDomains, ['x.hu']);
});

it('keeps a linked client closed when only its record returns after both left the directory, whichever record the run handles first', function (bool $sponsorFirst): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 1);
    // A run handles the missing records in primary-key order, the sponsor's
    // first or the linked client's first, within one chunk.
    [$sponsorKey, $dependentKey] = $sponsorFirst
        ? ['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002']
        : ['00000000-0000-4000-8000-000000000002', '00000000-0000-4000-8000-000000000001'];
    ExternalRecord::factory()->client()->create(['id' => $sponsorKey, 'external_id' => 1, 'email' => 'fo@x.hu']);
    ExternalRecord::factory()->client()->create(['id' => $dependentKey, 'external_id' => 2, 'email' => 'kap@x.hu']);
    $sponsorRow = sponsorClosureRow(1, 'fo@x.hu', 'Premium', null);
    $dependentRow = sponsorClosureRow(2, 'kap@x.hu', 'Basic', 'Premium');
    $sync = app(SyncExternalRecords::class);
    $sync->run(sponsorClosureReader([$sponsorRow, $dependentRow, ...sponsorClosureFillers()]));
    $sponsor = Client::query()->where('email', 'fo@x.hu')->firstOrFail();
    $dependent = Client::query()->where('email', 'kap@x.hu')->firstOrFail();
    $link = app(ClientLinks::class)->link($sponsor, $dependent);

    // Both leave the directory in one run.
    $sync->run(sponsorClosureReader(sponsorClosureFillers()));

    expect($sponsor->fresh()->closed_reason)->toBe(AccountProvisioner::CLOSE_REASON_MISSING)
        ->and($dependent->fresh()->isClosed())->toBeTrue();
    if ($sponsorFirst) {
        // The sponsor's closure closed the linked client; the copy the run had
        // loaded before must not close it again for its own reason.
        expect($dependent->fresh()->closed_reason)->toBe(ClientLinks::CLOSE_REASON_SPONSOR)
            ->and(AuditLog::query()->where('event', 'account.closed')->where('subject_id', $dependent->id)->where('context->reason', AccountProvisioner::CLOSE_REASON_MISSING)->exists())->toBeFalse();
    }

    // Only the linked client returns: its sponsor is still closed, so it stays closed.
    $sync->run(sponsorClosureReader([$dependentRow, ...sponsorClosureFillers()]));

    expect($dependent->fresh()->isClosed())->toBeTrue()
        ->and($dependent->fresh()->closed_reason)->toBe(ClientLinks::CLOSE_REASON_SPONSOR)
        ->and($link->fresh()->isActive())->toBeTrue()
        ->and($sponsor->fresh()->isClosed())->toBeTrue()
        ->and(fn () => app(AccountLogin::class)->resolveByEmail(PrincipalType::Client, 'kap@x.hu', 'magic_link'))->toThrow(LoginRejectedException::class);
    if (! $sponsorFirst) {
        expect(AuditLog::query()->where('event', 'account.kept_closed')->where('subject_id', $dependent->id)->where('context->reason', ClientLinks::CLOSE_REASON_SPONSOR)->exists())->toBeTrue();
    }

    // The sponsor returns: the linked client reopens with it.
    $sync->run(sponsorClosureReader([$sponsorRow, $dependentRow, ...sponsorClosureFillers()]));

    expect($sponsor->fresh()->isClosed())->toBeFalse()
        ->and($dependent->fresh()->isClosed())->toBeFalse();
})->with([
    'the sponsor handled first' => true,
    'the linked client handled first' => false,
]);

it('does not let a copy loaded before the sponsor closed overwrite why its linked client was closed', function (): void {
    $sponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $dependent = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium']);
    app(ClientLinks::class)->link($sponsor, $dependent);
    $stale = ExternalRecord::query()->with(['user', 'client'])->findOrFail($dependent->external_record_id);

    $sponsor->close(AccountProvisioner::CLOSE_REASON_MISSING);
    $closed = app(AccountProvisioner::class)->closeAccounts($stale);

    expect($closed)->toBe(0)
        ->and($dependent->fresh()->closed_reason)->toBe(ClientLinks::CLOSE_REASON_SPONSOR)
        ->and($stale->client->closed_reason)->toBe(ClientLinks::CLOSE_REASON_SPONSOR, 'the copy takes the stored closure over')
        ->and(AuditLog::query()->where('event', 'account.closed')->where('subject_id', $dependent->id)->where('context->reason', AccountProvisioner::CLOSE_REASON_MISSING)->exists())->toBeFalse();
});

it('keeps a linked client closed when its record returns after an administrator closed the sponsor', function (): void {
    app(Settings::class)->set(SettingKey::SyncMissedRunsBeforeClose, 1);
    $sponsorRow = sponsorClosureRow(1, 'fo@x.hu', 'Premium', null);
    $dependentRow = sponsorClosureRow(2, 'kap@x.hu', 'Basic', 'Premium');
    $sync = app(SyncExternalRecords::class);
    $sync->run(sponsorClosureReader([$sponsorRow, $dependentRow, ...sponsorClosureFillers()]));
    $sponsor = Client::query()->where('email', 'fo@x.hu')->firstOrFail();
    $dependent = Client::query()->where('email', 'kap@x.hu')->firstOrFail();
    app(ClientLinks::class)->link($sponsor, $dependent);

    // The linked client leaves the directory first; then an administrator closes the sponsor.
    $sync->run(sponsorClosureReader([$sponsorRow, ...sponsorClosureFillers()]));
    expect($dependent->fresh()->closed_reason)->toBe(AccountProvisioner::CLOSE_REASON_MISSING);
    $sponsor->fresh()->close('admin');

    $sync->run(sponsorClosureReader([$sponsorRow, $dependentRow, ...sponsorClosureFillers()]));

    expect($dependent->fresh()->isClosed())->toBeTrue()
        ->and($dependent->fresh()->closed_reason)->toBe(ClientLinks::CLOSE_REASON_SPONSOR)
        ->and($sponsor->fresh()->closed_reason)->toBe('admin')
        ->and(AuditLog::query()->where('event', 'account.kept_closed')->where('subject_id', $dependent->id)
            ->where('context->reason', ClientLinks::CLOSE_REASON_SPONSOR)->where('context->sponsor_id', $sponsor->id)->exists())->toBeTrue()
        ->and(fn () => app(AccountLogin::class)->resolveByEmail(PrincipalType::Client, 'kap@x.hu', 'magic_link'))->toThrow(LoginRejectedException::class);

    // Reopening the sponsor brings the linked client back.
    $sponsor->fresh()->reopen();

    expect($dependent->fresh()->isClosed())->toBeFalse();
});

it('keeps a linked client closed when its classification flips back while its sponsor is closed', function (): void {
    $sponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $dependent = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium']);
    app(ClientLinks::class)->link($sponsor, $dependent);
    // Closed as reclassified first; the sponsor's closure then passes it over.
    $dependent->close(AccountProvisioner::CLOSE_REASON_KIND_CHANGED);
    $sponsor->close('admin');

    app(AccountProvisioner::class)->syncAccount($dependent->externalRecord->fresh());

    expect($dependent->fresh()->isClosed())->toBeTrue()
        ->and($dependent->fresh()->closed_reason)->toBe(ClientLinks::CLOSE_REASON_SPONSOR)
        ->and(AuditLog::query()->where('event', 'account.kept_closed')->where('subject_id', $dependent->id)
            ->where('context->previous_reason', AccountProvisioner::CLOSE_REASON_KIND_CHANGED)->exists())->toBeTrue();

    $sponsor->fresh()->reopen();

    expect($dependent->fresh()->isClosed())->toBeFalse();
});

it('keeps a linked client closed for its new classification when the sponsor no longer holds it', function (string $how): void {
    $sponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $dependent = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium']);
    $link = app(ClientLinks::class)->link($sponsor, $dependent);
    $sponsor->close('admin');
    // Meanwhile the directory reclassified the linked client's record as staff for good.
    $dependent->externalRecord->forceFill(['kind' => PrincipalType::User])->save();

    match ($how) {
        'reopened' => $sponsor->fresh()->reopen(),
        'stops sponsoring' => $sponsor->fresh()->update(['implicit_package' => 'Basic']),
    };

    expect($dependent->fresh()->isClosed())->toBeTrue()
        ->and($dependent->fresh()->closed_reason)->toBe(AccountProvisioner::CLOSE_REASON_KIND_CHANGED)
        ->and(AuditLog::query()->where('event', 'account.kept_closed')->where('subject_id', $dependent->id)
            ->where('context->reason', AccountProvisioner::CLOSE_REASON_KIND_CHANGED)->exists())->toBeTrue()
        ->and(fn () => app(AccountLogin::class)->resolveByEmail(PrincipalType::Client, $dependent->email, 'magic_link'))->toThrow(LoginRejectedException::class);

    // Back as a client: the sync reopens it (its sponsor is open, or the link has ended).
    $dependent->externalRecord->forceFill(['kind' => PrincipalType::Client])->save();
    if ($how === 'reopened') {
        expect($link->fresh()->isActive())->toBeTrue();
    }
    app(AccountProvisioner::class)->syncAccount($dependent->externalRecord->fresh());

    expect($dependent->fresh()->isClosed())->toBeFalse();
})->with(['reopened', 'stops sponsoring']);

it('does not count an active link to an open sponsor or an ended link as holding the linked client closed', function (): void {
    $sponsor = Client::factory()->synced()->create(['implicit_package' => 'Premium', 'explicit_package' => null]);
    $dependent = Client::factory()->synced()->create(['implicit_package' => 'Basic', 'explicit_package' => 'Premium']);
    $link = app(ClientLinks::class)->link($sponsor, $dependent);
    $links = app(ClientLinks::class);

    expect($links->closedSponsorLink($dependent))->toBeNull();

    $sponsor->close('admin');
    expect($links->closedSponsorLink($dependent)?->is($link))->toBeTrue();

    $links->unlink($link->fresh());
    expect($links->closedSponsorLink($dependent))->toBeNull()
        ->and(ClientLink::query()->active()->count())->toBe(0);
});
