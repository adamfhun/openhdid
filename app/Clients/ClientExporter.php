<?php

namespace App\Clients;

use App\Audit\Auditor;
use App\Auth\Permission;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Builds the rows of the client export from a chosen set of columns: the
 * fixed fields of ClientExportField plus the directory columns the
 * operator surfaced under Settings › Clients › Directory fields
 * (`directory:<column>` keys). Fields the user may not export are dropped
 * here, whatever the form posted.
 */
class ClientExporter
{
    public const DIRECTORY_PREFIX = 'directory:';

    public function __construct(
        private readonly Settings $settings,
        private readonly Auditor $auditor,
    ) {}

    /**
     * Options for the export dialog, grouped: group key => [field key => label].
     *
     * @return array<string, array<string, string>>
     */
    public function options(User $user): array
    {
        $groups = [];

        foreach (ClientExportField::cases() as $field) {
            if ($field->requires() !== null && ! $user->can($field->requires()->value)) {
                continue;
            }

            $groups[$field->group()][$field->value] = $field->label();
        }

        foreach ($this->directoryFields() as $column => $label) {
            $groups['directory'][self::DIRECTORY_PREFIX.$column] = $label;
        }

        return $groups;
    }

    /**
     * Directory columns surfaced on the client page, list and export:
     * source column => label, as configured.
     *
     * @return array<string, string>
     */
    public function directoryFields(): array
    {
        $fields = [];

        foreach ($this->settings->array(SettingKey::ClientsDirectoryFields) as $column => $label) {
            $column = trim((string) $column);
            if ($column === '') {
                continue;
            }
            $fields[$column] = trim((string) $label) !== '' ? trim((string) $label) : Str::headline($column);
        }

        return $fields;
    }

    /**
     * Keep only the keys the user may export, in the order they were given.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function allowedKeys(array $keys, User $user): array
    {
        $allowed = array_merge(...array_values(array_map('array_keys', $this->options($user))) ?: [[]]);

        return array_values(array_filter(array_unique($keys), fn ($key) => is_string($key) && in_array($key, $allowed, true)));
    }

    /**
     * Column headings for the chosen keys.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public function headings(array $keys): array
    {
        $directory = $this->directoryFields();
        $headings = [];

        foreach ($keys as $key) {
            $headings[$key] = str_starts_with($key, self::DIRECTORY_PREFIX)
                ? ($directory[substr($key, strlen(self::DIRECTORY_PREFIX))] ?? $key)
                : (ClientExportField::tryFrom($key)?->label() ?? $key);
        }

        return $headings;
    }

    /**
     * One CSV row keyed by field id; labels may repeat without losing values.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public function row(Client $client, array $keys): array
    {
        $row = [];

        foreach ($keys as $key) {
            $row[$key] = str_starts_with($key, self::DIRECTORY_PREFIX)
                ? (string) ($client->externalRecord?->attribute(substr($key, strlen(self::DIRECTORY_PREFIX))) ?? '')
                : (ClientExportField::tryFrom($key)?->value($client) ?? '');
        }

        return $row;
    }

    /**
     * The query the export walks, with the relations the fields read.
     *
     * @param  Builder<Client>  $query
     * @return Builder<Client>
     */
    public function prepare(Builder $query): Builder
    {
        return $query->with(['externalRecord', 'phoneNumbers', 'linkWarningMutedBy']);
    }

    /**
     * @param  list<string>  $keys
     */
    public function recordExport(User $user, array $keys, bool $filteredOnly, int $rows, bool $truncated = false): void
    {
        $this->auditor->record('client.exported', null, ['fields' => $keys, 'filtered_only' => $filteredOnly, 'rows' => $rows, 'truncated' => $truncated], $user);
    }

    public function permission(): Permission
    {
        return Permission::ClientsExport;
    }
}
