<?php

namespace App\Reporting;

enum ReportFormat: string
{
    case Html = 'html';
    case Xlsx = 'xlsx';

    public function label(): string
    {
        return match ($this) {
            self::Html => __('HTML (opens in the browser, printable)'),
            self::Xlsx => __('Excel (XLSX, one sheet per part)'),
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Html => 'text/html; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
