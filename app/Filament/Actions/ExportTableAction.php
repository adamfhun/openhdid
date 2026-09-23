<?php

namespace App\Filament\Actions;

use App\Support\SpreadsheetCells;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the current table (filters and sort applied) as CSV. Row mapping
 * is given per resource so the file carries readable columns, not ids.
 */
class ExportTableAction
{
    public const MAX_ROWS = 50_000;

    /**
     * @param  Closure(Model): array<string, mixed>  $row
     */
    public static function make(string $fileName, Closure $row): Action
    {
        return Action::make('export')
            ->label(__('Export CSV'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function (ListRecords $livewire) use ($fileName, $row): StreamedResponse {
                /** @var Builder<Model> $query */
                $query = $livewire->getFilteredSortedTableQuery();

                return static::stream($query, $row, $fileName);
            });
    }

    /**
     * CSV download of a query, at most MAX_ROWS rows. The optional `$done`
     * callback receives the number of rows written and whether the file was
     * cut at the cap, for auditing.
     *
     * @param  Builder<Model>  $query
     * @param  Closure(Model): array<string, mixed>  $row
     * @param  Closure(int, bool): void|null  $done
     * @param  list<string>|null  $headers
     */
    public static function stream(Builder $query, Closure $row, string $fileName, ?Closure $done = null, ?array $headers = null): StreamedResponse
    {
        // A file cut at the cap looks complete. Say so before the download
        // starts, and record it, instead of handing over a silent excerpt.
        $truncated = self::exceedsCap($query);

        if ($truncated) {
            Notification::make()
                ->title(__('The export was cut at :n rows', ['n' => self::MAX_ROWS]))
                ->body(__('Narrow the list with filters and export it in parts, otherwise rows are missing from the file.'))
                ->warning()
                ->persistent()
                ->send();
        }

        return response()->streamDownload(function () use ($query, $row, $done, $headers, $truncated): void {
            // BOM + ';' delimiter: opens correctly in Hungarian Excel out of the box.
            $writer = SimpleExcelWriter::create('php://output', 'csv', delimiter: ';')->noHeaderRow();
            $count = 0;
            // lazy() keeps the table's eager loads and sort order; cursor() would not.
            $query->limit(self::MAX_ROWS)->lazy(1000)->each(function (Model $record) use ($writer, $row, $headers, &$count): void {
                $values = $row($record);
                if ($count === 0) {
                    $writer->addHeader(array_map(SpreadsheetCells::csv(...), $headers ?? array_keys($values)));
                }
                $writer->addRow(array_map(SpreadsheetCells::csv(...), array_values($values)));
                $count++;
            });
            $writer->close();

            if ($done !== null) {
                $done($count, $truncated);
            }
        }, $fileName.'-'.now()->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Counts at most one row past the cap, so the check stays cheap on a
     * large table.
     *
     * @param  Builder<Model>  $query
     */
    private static function exceedsCap(Builder $query): bool
    {
        $bounded = (clone $query)->toBase()->reorder()->limit(self::MAX_ROWS + 1)->select(DB::raw('1 as hit'));

        return DB::query()->fromSub($bounded, 'capped')->count() > self::MAX_ROWS;
    }
}
