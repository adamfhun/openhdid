<?php

namespace App\Settings;

use RuntimeException;

/**
 * Where and why a JSON text is invalid, for people editing a request body by
 * hand: PHP's parser only says "Syntax error", this scanner names the line,
 * the column and the usual mistake (a trailing comma, apostrophes, a bare
 * key or value, an unclosed string). Only ever called on invalid input.
 */
class JsonFault
{
    private int $i = 0;

    private readonly int $length;

    private function __construct(private readonly string $json)
    {
        $this->length = strlen($json);
    }

    public static function describe(string $json): string
    {
        if (trim($json) === '') {
            return __('The text is empty.');
        }

        $scanner = new self($json);

        try {
            $scanner->value();
            $scanner->whitespace();

            if ($scanner->i < $scanner->length) {
                $scanner->fail(__('unexpected content after the end of the JSON'));
            }
        } catch (RuntimeException $fault) {
            return $fault->getMessage();
        }

        return __('The JSON is valid.');
    }

    private function value(): void
    {
        $this->whitespace();
        $char = $this->peek();

        if ($char === null) {
            $this->fail(__('a value is expected'));
        }

        match (true) {
            $char === '{' => $this->object(),
            $char === '[' => $this->list(),
            $char === '"' => $this->string(),
            $char === "'" => $this->fail(__('use double quotes, not apostrophes')),
            $char === '-' || ctype_digit($char) => $this->number(),
            in_array($char, ['}', ']', ',', ':'], true) => $this->fail(__('a value is expected')),
            default => $this->literal($char),
        };
    }

    private function object(): void
    {
        $this->i++;
        $this->whitespace();

        if ($this->peek() === '}') {
            $this->i++;

            return;
        }

        while (true) {
            $this->whitespace();
            $char = $this->peek();

            if ($char === null) {
                $this->fail(__('the text ends before ":char" closes the structure', ['char' => '}']));
            }
            if ($char === '}') {
                $this->fail(__('a comma before the closing ":char" is not allowed', ['char' => '}']));
            }
            if ($char === "'") {
                $this->fail(__('use double quotes, not apostrophes'));
            }
            if ($char !== '"') {
                $this->fail(__('the key must be in double quotes'));
            }

            $this->string();
            $this->whitespace();

            if ($this->peek() !== ':') {
                $this->fail(__('a colon is expected after the key'));
            }

            $this->i++;
            $this->value();
            $this->whitespace();
            $char = $this->peek();

            if ($char === ',') {
                $this->i++;

                continue;
            }
            if ($char === '}') {
                $this->i++;

                return;
            }

            $this->fail($char === null
                ? __('the text ends before ":char" closes the structure', ['char' => '}'])
                : __('a comma or ":char" is expected', ['char' => '}']));
        }
    }

    private function list(): void
    {
        $this->i++;
        $this->whitespace();

        if ($this->peek() === ']') {
            $this->i++;

            return;
        }

        while (true) {
            $this->whitespace();

            if ($this->peek() === ']') {
                $this->fail(__('a comma before the closing ":char" is not allowed', ['char' => ']']));
            }

            $this->value();
            $this->whitespace();
            $char = $this->peek();

            if ($char === ',') {
                $this->i++;

                continue;
            }
            if ($char === ']') {
                $this->i++;

                return;
            }

            $this->fail($char === null
                ? __('the text ends before ":char" closes the structure', ['char' => ']'])
                : __('a comma or ":char" is expected', ['char' => ']']));
        }
    }

    private function string(): void
    {
        $start = $this->i;
        $this->i++;

        while ($this->i < $this->length) {
            $char = $this->json[$this->i];

            if ($char === '"') {
                $this->i++;

                return;
            }

            $this->i += $char === '\\' ? 2 : 1;
        }

        $this->i = $start;
        $this->fail(__('the string is not closed'));
    }

    private function number(): void
    {
        if (preg_match('/-?\d+(\.\d+)?([eE][+-]?\d+)?/A', $this->json, $match, 0, $this->i) !== 1) {
            $this->fail(__('unexpected character ":char"', ['char' => $this->json[$this->i]]));
        }

        $this->i += strlen($match[0]);
    }

    private function literal(string $char): void
    {
        if (preg_match('/true|false|null/A', $this->json, $match, 0, $this->i) === 1) {
            $this->i += strlen($match[0]);

            return;
        }

        $this->fail(ctype_alpha($char)
            ? __('a text value must be in double quotes')
            : __('unexpected character ":char"', ['char' => $char]));
    }

    private function whitespace(): void
    {
        while ($this->i < $this->length && str_contains(" \t\r\n", $this->json[$this->i])) {
            $this->i++;
        }
    }

    private function peek(): ?string
    {
        return $this->i < $this->length ? $this->json[$this->i] : null;
    }

    private function fail(string $problem): never
    {
        $before = substr($this->json, 0, $this->i);
        $lineStart = strrpos($before, "\n");
        $line = substr_count($before, "\n") + 1;
        $column = mb_strlen($lineStart === false ? $before : substr($before, $lineStart + 1)) + 1;

        throw new RuntimeException(__('Line :line, column :column: :problem', ['line' => $line, 'column' => $column, 'problem' => $problem]));
    }
}
