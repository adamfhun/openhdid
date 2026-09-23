<?php

namespace App\Enums;

enum SyncSource: string
{
    case Api = 'api';
    case Csv = 'csv';
    case Xlsx = 'xlsx';
}
