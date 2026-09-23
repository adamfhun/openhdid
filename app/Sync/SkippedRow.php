<?php

namespace App\Sync;

/**
 * A source row the importer could not or would not use, with the reason,
 * so the run report can say which rows were dropped and why.
 */
final readonly class SkippedRow
{
    public const REASON_INVALID_ROW = 'invalid_row';

    public const REASON_DOMAIN_NOT_ALLOWED = 'domain_not_allowed';

    public const REASON_UNCLASSIFIED = 'unclassified';

    /**
     * @param  array<string, mixed>  $sample  a few identifying columns of the row, never the whole row
     */
    public function __construct(
        public string $reason,
        public array $sample = [],
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string|list<string>>  $mapping
     */
    public static function fromRow(string $reason, array $row, array $mapping): self
    {
        $sample = [];
        foreach (['external_id', 'email', 'name'] as $target) {
            $column = $mapping[$target] ?? $target;
            $column = is_string($column) && array_key_exists($column, $row) ? $column : $target;
            if (array_key_exists($column, $row)) {
                $sample[$target] = mb_substr((string) $row[$column], 0, 80);
            }
        }

        return new self($reason, $sample);
    }

    public static function fromDto(string $reason, ExternalRecordDto $dto): self
    {
        return new self($reason, ['external_id' => (string) $dto->externalId, 'email' => $dto->email, 'name' => $dto->name]);
    }
}
