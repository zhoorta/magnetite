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

    /**
     * What a search compares: `title()` without accents and marks (Jáfumega is jafumega), so a person who types without
     * them still finds the music. Without the intl extension only case and spacing are folded.
     */
    public static function fold(?string $text): string
    {
        $key = self::title($text);

        if (class_exists(Normalizer::class) && ($d = Normalizer::normalize($key, Normalizer::FORM_D)) !== false) {
            $key = preg_replace('/\p{Mn}+/u', '', $d) ?? $key;
        }

        return $key;
    }

    /** The one text a search looks in for an item: its title and artist, folded. */
    public static function searchKey(?string $title, ?string $artist): string
    {
        return trim(self::fold($title).' '.self::fold($artist));
    }

    /** As `title()`, and null for no artist at all. */
    public static function artist(?string $text): ?string
    {
        $key = self::title($text);

        return $key === '' ? null : $key;
    }
}
