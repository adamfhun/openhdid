<?php

namespace App\Settings;

use App\Sync\RequestPayload;
use Illuminate\Support\HtmlString;

/**
 * A JSON request body made readable for the settings page: formatted,
 * coloured, with line numbers and its {{ placeholders }} marked (one the body
 * may not use in red). Display only: the stored text is never changed. Every
 * piece of the text is escaped before it is wrapped in markup.
 */
final class JsonOverview
{
    private const PLACEHOLDER = '/\{\{\s*([a-z_]+)\s*\}\}/i';

    private const TOKEN = '/("(?:[^"\\\\]|\\\\.)*")(\s*:)?|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|\b(true|false|null)\b|([{}\[\],:])|(\s+)|(.)/u';

    /**
     * @param  list<HtmlString>  $lines
     * @param  list<string>  $used  the placeholders the body uses
     * @param  list<string>  $unknown  the ones it may not use
     */
    private function __construct(
        public readonly bool $valid,
        public readonly ?string $error,
        public readonly array $lines,
        public readonly string $formatted,
        public readonly array $used,
        public readonly array $unknown,
    ) {}

    /**
     * @param  list<string>  $allowed  the placeholders this body may use
     * @param  ?string  $saveError  what saving the text would say against it
     */
    public static function of(string $payload, array $allowed, ?string $saveError = null): self
    {
        preg_match_all(self::PLACEHOLDER, $payload, $matches);
        $used = array_values(array_unique(array_map('strtolower', $matches[1])));
        $unknown = array_values(array_diff($used, $allowed));

        if (trim($payload) === '') {
            return new self(false, __('The field is empty.'), [], '', [], []);
        }

        $marker = 'hdid-list-'.bin2hex(random_bytes(6));
        $decoded = json_decode(RequestPayload::markListPlaceholder($payload, $marker));

        if (json_last_error() !== JSON_ERROR_NONE) {
            $lines = array_map(
                fn (string $line): HtmlString => new HtmlString(self::markPlaceholders(e($line), $allowed)),
                preg_split('/\R/', $payload) ?: [],
            );

            return new self(false, $saveError ?? __('Invalid JSON. :fault', ['fault' => JsonFault::describe(RequestPayload::template($payload))]), $lines, $payload, $used, $unknown);
        }

        $pretty = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $quotedMarker = (string) json_encode($marker);
        $lines = array_map(
            fn (string $line): HtmlString => new HtmlString(self::highlight($line, $quotedMarker, $allowed)),
            explode("\n", $pretty),
        );

        return new self($saveError === null, $saveError, $lines, str_replace($quotedMarker, self::placeholder(RequestPayload::LIST_PLACEHOLDER), $pretty), $used, $unknown);
    }

    /**
     * How a placeholder is written in a body, for the legend.
     */
    public static function placeholder(string $name): string
    {
        return '{'.'{ '.$name.' }'.'}';
    }

    /**
     * One line of the formatted JSON as markup: keys, strings, numbers,
     * literals and punctuation each in their own colour.
     *
     * @param  list<string>  $allowed
     */
    private static function highlight(string $line, string $quotedMarker, array $allowed): string
    {
        preg_match_all(self::TOKEN, $line, $tokens, PREG_SET_ORDER);
        $html = '';

        foreach ($tokens as $token) {
            [$all, $string, $colon, $number, $literal, $punctuation, $space] = array_pad($token, 7, '');

            $html .= match (true) {
                $string !== '' && $string === $quotedMarker => '<span class="hdid-json-ph">'.e(self::placeholder(RequestPayload::LIST_PLACEHOLDER)).'</span>'.e($colon),
                $string !== '' && $colon !== '' => '<span class="hdid-json-key">'.e($string).'</span><span class="hdid-json-punct">'.e($colon).'</span>',
                $string !== '' => '<span class="hdid-json-str">'.self::markPlaceholders(e($string), $allowed).'</span>',
                $number !== '' => '<span class="hdid-json-num">'.e($number).'</span>',
                $literal !== '' => '<span class="hdid-json-lit">'.e($literal).'</span>',
                $punctuation !== '' => '<span class="hdid-json-punct">'.e($punctuation).'</span>',
                $space !== '' => e($space),
                default => e($all),
            };
        }

        return $html;
    }

    /**
     * Wrap the placeholders of already escaped text; an escaped text keeps
     * braces, letters and spaces as they are, so the markup only ever wraps
     * a placeholder's own characters.
     *
     * @param  list<string>  $allowed
     */
    private static function markPlaceholders(string $escaped, array $allowed): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, fn (array $match): string => '<span class="hdid-json-ph'
            .(in_array(strtolower($match[1]), $allowed, true) ? '' : ' hdid-json-ph-unknown').'">'.$match[0].'</span>', $escaped);
    }
}
