<?php

namespace App\Enums;

use App\Models\Client;
use App\Models\User;

enum PrincipalType: string
{
    case User = 'user';
    case Client = 'client';

    /**
     * @return class-string<User|Client>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::User => User::class,
            self::Client => Client::class,
        };
    }

    public function guard(): string
    {
        return match ($this) {
            self::User => 'web',
            self::Client => 'client',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::User => __('User (staff)'),
            self::Client => __('Client'),
        };
    }
}
