<?php

namespace App\Filament\Admin;

use Filament\Support\Contracts\Collapsible;
use Filament\Support\Contracts\HasLabel;

/**
 * Sidebar groups in display order. Everyday helpdesk work stays open;
 * the administrative clusters share one collapsed group.
 */
enum NavigationGroup: string implements Collapsible, HasLabel
{
    case Helpdesk = 'helpdesk';
    case Identification = 'identification';
    case Administration = 'administration';

    public function getLabel(): string
    {
        return match ($this) {
            self::Helpdesk => __('Helpdesk'),
            self::Identification => __('Identification'),
            self::Administration => __('Administration'),
        };
    }

    public function isCollapsible(): bool
    {
        return true;
    }

    public function isCollapsed(): bool
    {
        return match ($this) {
            self::Helpdesk, self::Identification => false,
            default => true,
        };
    }
}
