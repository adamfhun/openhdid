<?php

namespace App\Auth\Oidc;

/**
 * Which claim of a company sign-in finds the account (and its EMD record):
 * the e-mail address, or the employee id compared with the EMD record's
 * external id. A deployment value (USER_/CLIENT_SSO_MATCH).
 */
enum SsoMatchMode: string
{
    case Email = 'email';
    case EmployeeId = 'employee_id';

    public function label(): string
    {
        return match ($this) {
            self::Email => __('e-mail address'),
            self::EmployeeId => __('employee id'),
        };
    }

    /**
     * The configured mode; an empty value is the default, an unknown one is
     * null so the provider counts as not configured instead of silently
     * falling back to the e-mail address.
     */
    public static function fromConfig(mixed $value): ?self
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? self::Email : self::tryFrom($value);
    }
}
