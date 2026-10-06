<?php

namespace App\Sync;

/**
 * An EMD request body: the export and the ID list query. The stored JSON may
 * carry placeholders like `{{ now }}` inside string values; they are filled in
 * right before each request, JSON-escaped, so the body stays valid JSON.
 * Nothing is evaluated.
 */
class RequestPayload
{
    private const PLACEHOLDER = '/\{\{\s*([a-z_]+)\s*\}\}/i';

    /**
     * Placeholders of the export body; `ids` is the selected part of the ID list.
     *
     * @var list<string>
     */
    public const EXPORT_PLACEHOLDERS = ['now', 'ids'];

    /**
     * Placeholders of the ID list query body.
     *
     * @var list<string>
     */
    public const ID_LIST_PLACEHOLDERS = ['now'];

    public static function isJsonObject(?string $payload): bool
    {
        return is_string($payload) && json_validate($payload) && json_decode($payload) instanceof \stdClass;
    }

    public static function uses(?string $payload, string $name): bool
    {
        return in_array($name, self::placeholdersIn((string) $payload), true);
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function unknownPlaceholderMessage(string $payload, array $allowed): ?string
    {
        $unknown = array_values(array_diff(self::placeholdersIn($payload), $allowed));

        return $unknown === [] ? null : __('Unknown placeholder: :list. Allowed: :allowed', [
            'list' => implode(', ', $unknown),
            'allowed' => implode(', ', $allowed),
        ]);
    }

    /**
     * @param  array<string, string>  $values  placeholder values besides `now`
     */
    public static function render(string $payload, array $values = []): string
    {
        $values += [
            'now' => now()->utc()->format('Y-m-d\TH:i:s.000\Z'),
        ];

        return (string) preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($values): string {
            $value = $values[strtolower($match[1])] ?? $match[0];

            return substr((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);
        }, $payload);
    }

    /**
     * @return list<string>
     */
    private static function placeholdersIn(string $payload): array
    {
        preg_match_all(self::PLACEHOLDER, $payload, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }
}
