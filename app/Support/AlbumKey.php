<?php

namespace App\Support;

use Normalizer;

/**
 * What two album titles (or artists) are compared by: the same text up to case, surrounding and repeated spaces, curly
 * versus straight quotes and dashes, and Unicode composition. A file whose tag says `Just for Love ` (or `Just For Love`
 * with an apostrophe of another shape) is the same album as its neighbours.
 */
final class AlbumKey
{
    public static function title(?string $text): string
    {
        $text = (string) $text;

        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }

        $text = strtr($text, ['’' => "'", '‘' => "'", '´' => "'", '`' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', "\u{00A0}" => ' ']);

        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? $text);
    }

    /** As `title()`, and null for no artist at all. */
    public static function artist(?string $text): ?string
    {
        $key = self::title($text);

        return $key === '' ? null : $key;
    }
}
