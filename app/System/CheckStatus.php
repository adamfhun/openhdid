<?php

namespace App\System;

enum CheckStatus: string
{
    case Ok = 'ok';

    /**
     * A deliberate mode or a data situation worth knowing about: nothing is
     * broken, so it never fails the health endpoint or the strict command.
     */
    case Info = 'info';

    case Warn = 'warn';
    case Fail = 'fail';

    public function label(): string
    {
        return match ($this) {
            self::Ok => __('OK'),
            self::Info => __('Information'),
            self::Warn => __('Warning'),
            self::Fail => __('Failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Info => 'info',
            self::Warn => 'warning',
            self::Fail => 'danger',
        };
    }
}
