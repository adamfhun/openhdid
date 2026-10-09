<?php

use App\Settings\JsonFault;

it('names the line, the column and the usual mistake of an invalid JSON text', function (string $json, string $expected): void {
    expect(JsonFault::describe($json))->toContain($expected);
})->with([
    'trailing comma in an object' => ['{"a": 1,}', 'Line 1, column 9: a comma before the closing "}" is not allowed'],
    'trailing comma in an array' => ["[1, 2,\n]", 'Line 2, column 1: a comma before the closing "]" is not allowed'],
    'apostrophes' => ["{\n  'a': 1\n}", 'Line 2, column 3: use double quotes, not apostrophes'],
    'bare key' => ["{\n  a: 1\n}", 'Line 2, column 3: the key must be in double quotes'],
    'missing comma' => ['{"a": 1 "b": 2}', 'Line 1, column 9: a comma or "}" is expected'],
    'missing colon' => ['{"a" 1}', 'Line 1, column 6: a colon is expected after the key'],
    'unclosed string' => ['{"a": "x}', 'Line 1, column 7: the string is not closed'],
    'bare text value' => ['{"a": premium}', 'Line 1, column 7: a text value must be in double quotes'],
    'unclosed structure' => ['{"a": [1, 2', 'Line 1, column 12: the text ends before "]" closes the structure'],
    'content after the end' => ['{"a": 1} x', 'Line 1, column 10: unexpected content after the end of the JSON'],
    'comment' => ["{\n  // note\n  \"a\": 1\n}", 'Line 2, column 3: the key must be in double quotes'],
    'accented text before the fault' => ['{"név": "Árvíztűrő", "b": }', 'Line 1, column 27: a value is expected'],
]);

it('reports an empty text', function (): void {
    expect(JsonFault::describe("  \n"))->toBe('The text is empty.');
});
