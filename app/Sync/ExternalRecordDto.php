<?php

namespace App\Sync;

/**
 * One row of the external directory in the shape the importer understands.
 */
final readonly class ExternalRecordDto
{
    /** The directory's identifier is one to seven digits. */
    public const EXTERNAL_ID_PATTERN = '/^\d{1,7}$/';

    /**
     * @param  list<string>  $phones  raw phone strings, normalised by the importer
     * @param  array<string, mixed>  $attributes  everything else from the source
     */
    public function __construct(
        public int $externalId,
        public string $name,
        public string $email,
        public ?string $company,
        public array $phones = [],
        public array $attributes = [],
        public ?string $implicitPackage = null,
        public ?string $explicitPackage = null,
        public ?string $title = null,
        public ?string $department = null,
        public ?string $loginName = null,
        public ?string $room = null,
        public ?string $employmentStatus = null,
    ) {}

    /**
     * Build from an associative row using a column mapping
     * (`target => source column`). A mapped column that the row does not
     * carry falls back to the field's own name, so a file with the default
     * headings still loads. Unmapped columns become attributes.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string|list<string>>  $mapping
     */
    public static function fromRow(array $row, array $mapping): ?self
    {
        $pick = static function (string $target) use ($row, $mapping): ?string {
            $column = $mapping[$target] ?? $target;
            $value = is_string($column) ? ($row[$column] ?? $row[$target] ?? null) : null;

            return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
        };

        $externalId = $pick('external_id');
        $email = $pick('email');

        if ($externalId === null || $email === null || ! preg_match(self::EXTERNAL_ID_PATTERN, $externalId)) {
            return null;
        }

        $phoneColumns = (array) ($mapping['phones'] ?? 'phone');
        $phones = [];
        foreach ($phoneColumns as $column) {
            $value = $row[$column] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            foreach (preg_split('/[;,\r\n]/', (string) $value) ?: [] as $part) {
                if (trim($part) !== '') {
                    $phones[] = trim($part);
                }
            }
        }

        $mappedColumns = array_merge(
            array_values(array_filter($mapping, 'is_string')),
            array_keys($mapping),
            ['external_id', 'name', 'email', 'company', 'implicit_package', 'explicit_package', 'title', 'department', 'login_name', 'room', 'employment_status'],
            $phoneColumns,
        );

        return new self(
            externalId: (int) $externalId,
            name: $pick('name') ?? $email,
            email: mb_strtolower($email),
            company: $pick('company'),
            phones: $phones,
            attributes: array_diff_key($row, array_flip($mappedColumns)),
            implicitPackage: $pick('implicit_package'),
            explicitPackage: $pick('explicit_package'),
            title: $pick('title'),
            department: $pick('department'),
            loginName: $pick('login_name'),
            room: $pick('room'),
            employmentStatus: $pick('employment_status'),
        );
    }
}
