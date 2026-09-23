<?php

namespace App\Reporting;

/**
 * Parts of the user report a supervisor can tick. Each part yields one or
 * more tables; the order here is the order in the file.
 */
enum ReportSection: string
{
    case Users = 'users';
    case Calls = 'calls';
    case CallsByDay = 'calls_by_day';
    case Identifications = 'identifications';
    case Activity = 'activity';

    public function label(): string
    {
        return match ($this) {
            self::Users => __('User master data'),
            self::Calls => __('Calls by agent'),
            self::CallsByDay => __('Calls by day'),
            self::Identifications => __('Identifications by agent'),
            self::Activity => __('Activity'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Users => __('Name, e-mail, roles, status, last login, EMD title and department, handled levels.'),
            self::Calls => __('Calls taken per agent: total, by queue, by level, by day, average duration, missed calls marked handled.'),
            self::CallsByDay => __('Every call per day: total, taken by an agent, missed, of which marked handled.'),
            self::Identifications => __('Identification attempts per agent by method and outcome, with the success rate.'),
            self::Activity => __('Logins and audited actions per user in the period.'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $section) {
            $options[$section->value] = $section->label();
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
