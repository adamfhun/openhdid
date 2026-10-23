<?php

namespace App\Settings;

/**
 * The tabs of the settings page, by the administrator's questions (what the
 * client sees, how callers are identified) rather than by the internal key
 * prefixes (2026-10-23). The blocks of each tab are SettingSection cases.
 */
enum SettingTab: string
{
    case Clients = 'clients';
    case Identification = 'identification';
    case Portal = 'portal';
    case Staff = 'staff';
    case Directory = 'directory';
    case Appearance = 'appearance';
    case Operations = 'operations';

    public function label(): string
    {
        return match ($this) {
            self::Clients => __('Clients and levels'),
            self::Identification => __('Identification'),
            self::Portal => __('Client portal'),
            self::Staff => __('Staff sign-in'),
            self::Directory => __('Enterprise Master Data (EMD)'),
            self::Appearance => __('Appearance'),
            self::Operations => __('Operations and retention'),
        };
    }

    /**
     * @return list<SettingSection>
     */
    public function sections(): array
    {
        return array_values(array_filter(SettingSection::cases(), fn (SettingSection $section): bool => $section->tab() === $this));
    }
}
