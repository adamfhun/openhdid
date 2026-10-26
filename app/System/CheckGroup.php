<?php

namespace App\System;

/**
 * The two blocks of the status page. Operation tiles say whether the system
 * runs (database, workers, transports, storage); mode tiles say how it has
 * been set to behave (switches, environment flags, data situations the
 * administrators chose to live with). A yellow mode tile is a reminder, not
 * an outage.
 */
enum CheckGroup: string
{
    case Operation = 'operation';
    case Modes = 'modes';

    /**
     * Check keys that belong to the modes block; everything else is operation.
     */
    private const MODE_KEYS = ['premium_only', 'login_link_preview', 'client_login_emd', 'rate_limit_exemptions', 'adfs_debug', 'package_overrides', 'shared_numbers'];

    public static function forKey(string $key): self
    {
        return in_array($key, self::MODE_KEYS, true) ? self::Modes : self::Operation;
    }

    public function label(): string
    {
        return match ($this) {
            self::Operation => __('System operation'),
            self::Modes => __('Modes and settings'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Operation => __('Is everything running: database, workers, transports, interfaces and storage. Red needs attention now.'),
            self::Modes => __('Deliberate modes and data situations that follow from the settings or the environment. "Information" is normal operation worth knowing about; yellow is a reminder to switch something off before go-live.'),
        };
    }
}
