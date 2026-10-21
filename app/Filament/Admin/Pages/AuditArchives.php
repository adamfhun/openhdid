<?php

namespace App\Filament\Admin\Pages;

use App\Audit\AuditArchive;
use App\Audit\Auditor;
use App\Auth\Permission;
use App\Filament\Admin\Clusters\System;
use App\Models\AuditLog;
use App\Support\HuDate;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The cold half of the audit log: the daily archive files the nightly task
 * wrote, with their checksums, for download. Reading them needs the same
 * permission as the audit log itself; every download is audited.
 *
 * @phpstan-import-type ArchiveFile from AuditArchive
 */
class AuditArchives extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $cluster = System::class;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'audit-archive';

    protected string $view = 'filament.admin.audit-archives';

    public static function getNavigationLabel(): string
    {
        return __('Audit archive');
    }

    public function getTitle(): string
    {
        return __('Audit archive');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::AuditView->value) ?? false;
    }

    /**
     * @return list<ArchiveFile>
     */
    public function getFiles(): array
    {
        return app(AuditArchive::class)->files();
    }

    /**
     * @return array{hot_days: int, rows: int, oldest: ?string, last_run: ?string, cutoff: string}
     */
    public function getState(): array
    {
        $archive = app(AuditArchive::class);
        $oldest = AuditLog::query()->min('created_at');

        return [
            'hot_days' => $archive->hotDays(),
            'rows' => AuditLog::query()->count(),
            'oldest' => $oldest ? Carbon::parse($oldest)->format(HuDate::DATETIME) : null,
            'last_run' => $archive->lastRunAt()?->format(HuDate::DATETIME),
            'cutoff' => $archive->cutoff()->format(HuDate::DATE),
        ];
    }

    public function download(string $name): ?StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $archive = app(AuditArchive::class);
        $file = $archive->file($name);

        if ($file === null) {
            abort(404);
        }

        app(Auditor::class)->record('audit.archive_downloaded', context: ['file' => $file['name'], 'day' => $file['day'], 'bytes' => $file['bytes'], 'sha256' => $file['sha256']]);

        return $archive->disk()->download($file['path'], $file['name']);
    }

    public function downloadChecksum(string $name): ?StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $file = app(AuditArchive::class)->file($name);

        if ($file === null) {
            abort(404);
        }

        return app(AuditArchive::class)->disk()->download($file['path'].'.sha256', $file['name'].'.sha256');
    }
}
