<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;

final class SpreadsheetCells
{
    /** CSV has no cell types: neutralize formula prefixes in text values. */
    public static function csv(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/^(?:[\t\r\n]|[\s\p{Z}\x{FEFF}]*[=+\-@])/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * Keep XLSX strings literal; the writer otherwise infers formulas from '='.
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function xlsxRow(array $values): Row
    {
        return new Row(array_map(
            fn (mixed $value): Cell => is_string($value) ? new StringCell($value, null) : Cell::fromValue($value),
            array_values($values),
        ));
    }
}
