<?php

namespace App\Sync;

/**
 * The EMD export request body. The stored JSON may carry placeholders like
 * `{{ now }}` inside string values; they are filled in right before each
 * request, JSON-escaped, so the body stays valid JSON. Nothing is evaluated.
 */
class ExportPayload
{
    private const PLACEHOLDER = '/\{\{\s*([a-z_]+)\s*\}\}/i';

    /** @var list<string> */
    public const PLACEHOLDERS = ['now'];

    /**
     * @return list<string>
     */
    private static function unknownPlaceholders(string $payload): array
    {
        preg_match_all(self::PLACEHOLDER, $payload, $matches);
        $names = array_unique(array_map('strtolower', $matches[1]));

        return array_values(array_diff($names, self::PLACEHOLDERS));
    }

    public static function unknownPlaceholderMessage(string $payload): ?string
    {
        $unknown = self::unknownPlaceholders($payload);

        return $unknown === [] ? null : __('Unknown placeholder: :list. Allowed: :allowed', [
            'list' => implode(', ', $unknown),
            'allowed' => implode(', ', self::PLACEHOLDERS),
        ]);
    }

    public static function render(string $payload): string
    {
        $values = [
            'now' => now()->utc()->format('Y-m-d\TH:i:s.000\Z'),
        ];

        return (string) preg_replace_callback(self::PLACEHOLDER, function (array $match) use ($values): string {
            $value = $values[strtolower($match[1])] ?? $match[0];

            return substr((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);
        }, $payload);
    }
}
