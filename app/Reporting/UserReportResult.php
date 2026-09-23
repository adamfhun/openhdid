<?php

namespace App\Reporting;

use App\Models\User;
use Carbon\CarbonImmutable;

final class UserReportResult
{
    /**
     * @param  list<ReportTable>  $tables
     */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $until,
        public readonly array $tables,
        public readonly User $generatedBy,
        public readonly CarbonImmutable $generatedAt,
    ) {}

    public function rowCount(): int
    {
        return array_sum(array_map(fn (ReportTable $t) => $t->rowCount(), $this->tables));
    }

    /**
     * @return list<string>
     */
    public function sectionValues(): array
    {
        return array_values(array_unique(array_map(fn (ReportTable $t) => $t->section->value, $this->tables)));
    }
}
