<?php

namespace App\System;

/**
 * The usage profile a deployment was sized for (HDID_SIZING_PROFILE): the
 * number of clients and, derived from the 5–8 % concurrency assumption, the
 * peak of simultaneously active accounts it is designed to carry. The
 * capacity tile compares the measured activity with this design point so
 * the operator sees when to step up.
 */
enum SizingProfile: string
{
    case Small = '5k';
    /** 10 000 clients on one larger server (profiles/10k/single.yaml), without high availability. */
    case MediumSingle = '10k-single';
    case Medium = '10k';
    case Large = '20k';

    public static function current(): ?self
    {
        $value = config('hdid.profile');

        return is_string($value) && $value !== '' ? self::tryFrom(strtolower(trim($value))) : null;
    }

    public function clients(): int
    {
        return match ($this) {
            self::Small => 5_000,
            self::MediumSingle, self::Medium => 10_000,
            self::Large => 20_000,
        };
    }

    /**
     * Accounts active at the same time the profile is designed for (8 % of
     * the clients, the upper end of the assumed concurrency).
     */
    public function designedConcurrency(): int
    {
        return (int) round($this->clients() * 0.08);
    }

    public function label(): string
    {
        return match ($this) {
            self::Small => __('5k profile (single server)'),
            self::MediumSingle => __('10k profile (single server)'),
            self::Medium => __('10k profile (two application servers)'),
            self::Large => __('20k profile (Kubernetes)'),
        };
    }
}
