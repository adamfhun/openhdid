<?php

namespace App\Support;

/**
 * The sample texts of the input fields, one for each kind of field on the
 * portal and the panel alike (the portal's Vue views carry the same values;
 * PlaceholderConsistencyTest keeps them together).
 */
final class Placeholders
{
    /** A phone number as people type it; stored in E.164 whatever the spacing. */
    public const PHONE = '+36 30 123 4567';

    /** The optional label of a phone number. */
    public static function phoneLabel(): string
    {
        return __('mobile, office…');
    }
}
