<?php

namespace App\Enums;

/**
 * What a sync run did to one directory record, in one word, for the list and
 * its filter. The row's `changes` carry the field-level detail.
 */
enum SyncChangeKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Closed = 'closed';
    case Reopened = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::Created => __('New record'),
            self::Updated => __('Changed'),
            self::Closed => __('Account closed'),
            self::Reopened => __('Account reopened'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Created => 'success',
            self::Updated => 'gray',
            self::Closed => 'danger',
            self::Reopened => 'info',
        };
    }
}
