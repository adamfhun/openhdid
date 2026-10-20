<?php

namespace App\Sync;

/**
 * An EMD request body: the export and the ID list query. The stored JSON may
 * carry placeholders like `{{ now }}` inside string values; they are filled in
 * right before each request, JSON-escaped, so the body stays valid JSON.
 * `{{ ids }}` may also stand outside quotes, as a list of JSON values
 * (`"ids": [{{ ids }}]` → `[3,12]`). Nothing is evaluated.
 */
class RequestPayload
{
    private const PLACEHOLDER = '/\{\{\s*([a-z_]+)\s*\}\}/i';

    /** The only placeholder that may stand outside quotes: a list of values. */
    public const LIST_PLACEHOLDER = 'ids';

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

    /**
     * A JSON object once the placeholders are filled in.
     */
    public static function isJsonObject(?string $payload): bool
    {
        return is_string($payload) && self::isValidJson($payload) && json_decode(self::template($payload)) instanceof \stdClass;
    }

    /**
     * Valid JSON once the placeholders are filled in.
     */
    public static function isValidJson(string $payload): bool
    {
        return json_validate(self::template($payload));
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
     * Inside quotes a value is filled in as text (a list comma-separated:
     * `3,12`); `{{ ids }}` outside quotes becomes the list of values, the
     * all-digit IDs as numbers, the others as JSON strings.
     *
     * @param  array<string, string|list<string>>  $values  placeholder values besides `now`
     */
    public static function render(string $payload, array $values = []): string
    {
        $values += [
            'now' => now()->utc()->format('Y-m-d\TH:i:s.000\Z'),
        ];

        return self::replace($payload, function (string $name, string $original, bool $quoted) use ($values): string {
            if (! array_key_exists($name, $values)) {
                return $original;
            }

            $value = $values[$name];

            if ($quoted) {
                return substr((string) json_encode(is_array($value) ? implode(',', $value) : $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);
            }

            return is_array($value)
                ? implode(',', array_map(self::jsonValue(...), $value))
                : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        });
    }

    /**
     * The payload as the JSON it becomes: `{{ ids }}` outside quotes stands
     * for `$ids` values, padded to its own length so a parser error keeps its
     * line and column; every other placeholder stays as written (inside
     * quotes it is text, outside it is not JSON).
     */
    public static function template(string $payload, int $ids = 2): string
    {
        return self::replace($payload, fn (string $name, string $original, bool $quoted): string => ! $quoted && $name === self::LIST_PLACEHOLDER
            ? str_pad(implode(',', array_fill(0, $ids, '0')), strlen($original))
            : $original);
    }

    /**
     * Placeholders written outside quotes that are filled in as text only.
     *
     * @return list<string>
     */
    public static function textPlaceholdersOutsideQuotes(string $payload): array
    {
        $names = [];
        self::replace($payload, function (string $name, string $original, bool $quoted) use (&$names): string {
            if (! $quoted && $name !== self::LIST_PLACEHOLDER) {
                $names[] = $name;
            }

            return $original;
        });

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private static function placeholdersIn(string $payload): array
    {
        preg_match_all(self::PLACEHOLDER, $payload, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * Replaces every placeholder with what the callback returns for it,
     * telling whether it stands inside a JSON string. Quotes and backslashes
     * are ASCII, so a byte scan is safe in UTF-8.
     *
     * @param  callable(string $name, string $original, bool $quoted): string  $replacement
     */
    private static function replace(string $payload, callable $replacement): string
    {
        preg_match_all(self::PLACEHOLDER, $payload, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $result = '';
        $position = 0;
        $quoted = false;
        $escaped = false;

        foreach ($matches as $match) {
            [$original, $offset] = $match[0];

            for ($i = $position; $i < $offset; $i++) {
                $char = $payload[$i];
                if ($escaped) {
                    $escaped = false;
                } elseif ($quoted && $char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $quoted = ! $quoted;
                }
            }

            $result .= substr($payload, $position, $offset - $position).$replacement(strtolower($match[1][0]), $original, $quoted);
            $position = $offset + strlen($original);
        }

        return $result.substr($payload, $position);
    }

    private static function jsonValue(string $id): string
    {
        return preg_match('/^(0|[1-9][0-9]*)$/', $id) === 1
            ? $id
            : (string) json_encode($id, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
