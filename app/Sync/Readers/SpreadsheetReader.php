<?php

namespace App\Sync\Readers;

use App\Enums\SyncSource;
use App\Sync\Contracts\SourceReader;
use App\Sync\ExternalRecordDto;
use App\Sync\SkippedRow;
use App\Sync\SourceFormatException;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * Reads CSV or XLSX files. The first row must contain column headings that
 * match the configured column mapping; the file is refused up front when
 * the identifying columns are missing. CSV files are converted from the
 * configured character set (Hungarian exports are often Windows-1250).
 */
class SpreadsheetReader implements SourceReader
{
    /**
     * @param  array<string, string|list<string>>  $mapping
     */
    public function __construct(
        private readonly string $path,
        private readonly array $mapping,
        private readonly ?string $label = null,
        private readonly string $encoding = 'UTF-8',
        private readonly ?string $delimiter = null,
    ) {}

    public function source(): SyncSource
    {
        return str_ends_with(mb_strtolower($this->path), '.csv') ? SyncSource::Csv : SyncSource::Xlsx;
    }

    public function label(): ?string
    {
        return $this->label ?? basename($this->path);
    }

    public function read(): iterable
    {
        $reader = SimpleExcelReader::create($this->path)->trimHeaderRow();

        if ($this->source() === SyncSource::Csv && $this->delimiter !== null) {
            $reader->useDelimiter($this->delimiter);
        }

        if ($this->source() === SyncSource::Csv && $this->encoding !== '' && strcasecmp($this->encoding, 'UTF-8') !== 0) {
            $reader->useEncoding($this->encoding);
        }

        $this->assertHeaders($reader->getHeaders() ?? []);

        foreach ($reader->getRows() as $row) {
            yield ExternalRecordDto::fromRow($row, $this->mapping) ?? SkippedRow::fromRow(SkippedRow::REASON_INVALID_ROW, $row, $this->mapping);
        }
    }

    /**
     * @param  list<string>  $headers
     */
    private function assertHeaders(array $headers): void
    {
        $missing = [];
        foreach (['external_id', 'email'] as $target) {
            $column = $this->mapping[$target] ?? $target;
            // The mapped heading, or the field's own name as a fallback (see ExternalRecordDto::fromRow).
            if (is_string($column) && ! in_array($column, $headers, true) && ! in_array($target, $headers, true)) {
                $missing[] = $column;
            }
        }

        if ($missing !== []) {
            throw new SourceFormatException(sprintf(
                'Required column(s) missing from the header row: %s. Found: %s',
                implode(', ', $missing),
                $headers === [] ? '(no header row)' : implode(', ', array_map('strval', $headers)),
            ));
        }
    }
}
