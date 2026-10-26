<?php

namespace App\Sync;

use App\Support\EmailAddressRule;
use App\Support\InputLimits;

/**
 * One row of the external directory in the shape the importer understands.
 *
 * Every text field fits its varchar(255) column: a longer cell is cut and
 * named in $truncated (the run reports it), never skipped, because a
 * skipped row would close the person's account. An e-mail address that is
 * not usable (EmailAddressRule: too long, malformed) skips the row.
 */
final readonly class ExternalRecordDto
{
    /** The directory's identifier is one to seven digits. */
    public const EXTERNAL_ID_PATTERN = '/^\d{1,7}$/';

    /** The text fields kept in varchar(255) columns, cut to fit. */
    private const TEXT_FIELDS = ['name', 'company', 'implicit_package', 'explicit_package', 'title', 'department', 'login_name', 'room', 'employment_status'];

    /**
     * @param  list<string>  $phones  raw phone strings, normalised by the importer
     * @param  array<string, mixed>  $attributes  everything else from the source
     * @param  list<string>  $truncated  the fields whose cell was longer than its column and was cut
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
        public array $truncated = [],
    ) {}

    /**
     * The address as the importer stores and classifies it: a "Name <address>"
     * or "mailto:" form reduced to the address, without whitespace, control or
     * invisible format characters, in lower case. A no-break or zero-width
     * space from a spreadsheet cell does not show on screen, yet it would put
     * the row outside every domain list.
     */
    public static function normalizeEmail(string $value): string
    {
        if (preg_match('/<([^<>]*@[^<>]*)>/u', $value, $match) === 1) {
            $value = $match[1];
        }

        $value = preg_replace('/[\p{Z}\p{C}]+/u', '', $value) ?? trim($value);

        return mb_strtolower((string) preg_replace('/^mailto:/i', '', $value));
    }

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
        $built = self::build($row, $mapping);

        return $built instanceof self ? $built : null;
    }

    /**
     * The record of a row, or the skipped row with the reason it was not
     * usable: no numeric identifier or no address (invalid_row), an
     * address that is no usable e-mail address (invalid_email).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string|list<string>>  $mapping
     */
    public static function fromRowOrSkip(array $row, array $mapping): self|SkippedRow
    {
        $built = self::build($row, $mapping);

        return $built instanceof self ? $built : SkippedRow::fromRow($built, $row, $mapping);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string|list<string>>  $mapping
     * @return self|string the record, or the reason the row is skipped
     */
    private static function build(array $row, array $mapping): self|string
    {
        $truncated = [];
        $pick = static function (string $target) use ($row, $mapping, &$truncated): ?string {
            $column = $mapping[$target] ?? $target;
            $value = is_string($column) ? ($row[$column] ?? $row[$target] ?? null) : null;
            $value = $value === null || trim((string) $value) === '' ? null : trim((string) $value);

            if ($value !== null && in_array($target, self::TEXT_FIELDS, true) && mb_strlen($value) > InputLimits::NAME) {
                $truncated[] = $target;
                $value = mb_substr($value, 0, InputLimits::NAME);
            }

            return $value;
        };

        $externalId = $pick('external_id');
        $email = $pick('email');
        $email = $email === null ? null : (self::normalizeEmail($email) ?: null);

        if ($externalId === null || $email === null || ! preg_match(self::EXTERNAL_ID_PATTERN, $externalId)) {
            return SkippedRow::REASON_INVALID_ROW;
        }

        if (! EmailAddressRule::isValid($email)) {
            return SkippedRow::REASON_INVALID_EMAIL;
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

        // Picked first: each pick notes a cut cell in $truncated.
        $fields = [
            'name' => $pick('name') ?? $email,
            'company' => $pick('company'),
            'implicitPackage' => $pick('implicit_package'),
            'explicitPackage' => $pick('explicit_package'),
            'title' => $pick('title'),
            'department' => $pick('department'),
            'loginName' => $pick('login_name'),
            'room' => $pick('room'),
            'employmentStatus' => $pick('employment_status'),
        ];

        return new self(
            externalId: (int) $externalId,
            name: $fields['name'],
            email: $email,
            company: $fields['company'],
            phones: $phones,
            attributes: array_diff_key($row, array_flip($mappedColumns)),
            implicitPackage: $fields['implicitPackage'],
            explicitPackage: $fields['explicitPackage'],
            title: $fields['title'],
            department: $fields['department'],
            loginName: $fields['loginName'],
            room: $fields['room'],
            employmentStatus: $fields['employmentStatus'],
            truncated: $truncated,
        );
    }
}
