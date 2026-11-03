<?php

namespace App\Filament;

/**
 * The text of a Filament notification title or body. Filament draws both as
 * HTML through its sanitizer, which keeps outside links, images and the style
 * attribute, so a name written in EMD or by an admin could cover the panel
 * with a link of its own. Every dynamic title and body goes through here,
 * whole and once (OutputEscapingTest checks it): the text is escaped and the
 * sanitizer gives it back as text. Quotes stay as they are, they mean nothing
 * outside an attribute. Services and exceptions keep plain text; only this
 * output layer escapes.
 */
final class NotificationText
{
    public static function of(?string $text): ?string
    {
        return $text === null ? null : htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
