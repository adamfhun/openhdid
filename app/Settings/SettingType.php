<?php

namespace App\Settings;

enum SettingType: string
{
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Text = 'text';
    case LongText = 'long_text';
    case Color = 'color';
    case Json = 'json';
    case Image = 'image';

    public function cast(mixed $value): mixed
    {
        return match ($this) {
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOL),
            self::Integer => (int) $value,
            self::Text, self::LongText, self::Color, self::Image => $value === null || $value === '' ? null : (string) $value,
            self::Json => is_string($value) ? json_decode($value, true) : $value,
        };
    }
}
