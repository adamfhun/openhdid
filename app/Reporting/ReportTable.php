<?php

namespace App\Reporting;

/**
 * One table of a report: a title, a header row, data rows and an optional
 * totals row. Cells are scalars so the same table renders to HTML and XLSX.
 */
final class ReportTable
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<int|float|string|null>>  $rows
     * @param  list<int|float|string|null>|null  $totals
     */
    public function __construct(
        public readonly ReportSection $section,
        public readonly string $title,
        public readonly array $headers,
        public readonly array $rows,
        public readonly ?array $totals = null,
        public readonly ?string $note = null,
    ) {}

    public function rowCount(): int
    {
        return count($this->rows);
    }
}
