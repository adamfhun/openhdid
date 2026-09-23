<?php

namespace App\System;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';

    public function label(): string
    {
        return match ($this) {
            self::Ok => __('OK'),
            self::Warn => __('Warning'),
            self::Fail => __('Failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Warn => 'warning',
            self::Fail => 'danger',
        };
    }
}
