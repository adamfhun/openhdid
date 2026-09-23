<?php

namespace App\Filament\Admin\Resources;

use Filament\Resources\Resource;
use Illuminate\Support\Str;

/**
 * Hungarian titles capitalise only the first letter, never every word.
 */
abstract class BaseResource extends Resource
{
    public static function getTitleCaseModelLabel(): string
    {
        return Str::ucfirst(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
