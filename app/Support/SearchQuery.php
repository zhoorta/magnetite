<?php

namespace App\Support;

/**
 * What a person typed into the search, taken apart: plain words, and filters written `key:value`.
 *
 *   year:1983  year:1960..1969  year:..1969  year:1990..  year:>=1990     the album's year
 *   dr:<8  dr:>=12  dr:8..10                                              the track's dynamic range (your own tracks only)
 *   codec:flac  codec:flac,alac                                           the track's format
 *   artist:bowie  artist:"david bowie"                                    words that must be in the artist
 *   album:recados                                                         words that must be in the album's title
 *
 * A token that is not one of these (`ac:dc`, `year:soon`) stays a plain word, and `"two words"` in quotes are words too.
 * Everything is folded (case, accents) like the text it is compared with.
 */
final class SearchQuery
{
    /** @param list<string> $words  @param list<string> $codecs  @param list<string> $artist  @param list<string> $album */
    private function __construct(
        public readonly array $words,
        public readonly ?array $year,
        public readonly ?array $dr,
        public readonly array $codecs,
        public readonly array $artist,
        public readonly array $album,
    ) {}

    public static function parse(string $raw): self
    {
        $words = $artist = $album = $codecs = [];
        $year = $dr = null;

        preg_match_all('/(?:(\w+):)?(?:"([^"]*)"|(\S+))/u', $raw, $tokens, PREG_SET_ORDER);

        foreach ($tokens as $t) {
            [$key, $value] = [mb_strtolower($t[1] ?? ''), ($t[2] ?? '') !== '' ? $t[2] : ($t[3] ?? '')];
            $range = in_array($key, ['year', 'dr'], true) ? self::range($value) : null;

            match (true) {
                $key === 'year' && $range !== null => $year = $range,
                $key === 'dr' && $range !== null => $dr = $range,
                $key === 'codec' && $value !== '' => $codecs = array_merge($codecs, array_filter(array_map('trim', explode(',', mb_strtolower($value))))),
                $key === 'artist' && self::split($value) !== [] => $artist = array_merge($artist, self::split($value)),
                $key === 'album' && self::split($value) !== [] => $album = array_merge($album, self::split($value)),
                // not a filter: the whole token, key and all, is a word (`ac:dc`)
                default => $words = array_merge($words, self::split(($key !== '' ? $t[1].':' : '').$value)),
            };
        }

        return new self(array_values(array_unique($words)), $year, $dr, array_values(array_unique($codecs)), array_values(array_unique($artist)), array_values(array_unique($album)));
    }

    /** @return list<string> the folded words of a text */
    private static function split(string $text): array
    {
        return preg_split('/\s+/u', AlbumKey::fold($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** `1983`, `a..b`, `..b`, `a..`, `>n`, `>=n`, `<n`, `<=n` as [min, max] (either may be null), or null when it is none of them. */
    private static function range(string $v): ?array
    {
        $v = trim($v);

        return match (true) {
            (bool) preg_match('/^(\d{1,4})$/', $v, $m) => [(int) $m[1], (int) $m[1]],
            (bool) preg_match('/^(\d{1,4})?\.\.(\d{1,4})?$/', $v, $m) && ($m[1] ?? '').($m[2] ?? '') !== '' => [($m[1] ?? '') !== '' ? (int) $m[1] : null, ($m[2] ?? '') !== '' ? (int) $m[2] : null],
            (bool) preg_match('/^>=(\d{1,4})$/', $v, $m) => [(int) $m[1], null],
            (bool) preg_match('/^>(\d{1,4})$/', $v, $m) => [(int) $m[1] + 1, null],
            (bool) preg_match('/^<=(\d{1,4})$/', $v, $m) => [null, (int) $m[1]],
            (bool) preg_match('/^<(\d{1,4})$/', $v, $m) => [null, (int) $m[1] - 1],
            default => null,
        };
    }

    /** Filters that only mean something for a track: the answer is tracks, not albums. */
    public function tracksOnly(): bool
    {
        return $this->dr !== null || $this->codecs !== [];
    }

    /** Words for the database to narrow by: the plain ones, and those of the artist and album filters (checked exactly afterwards). */
    public function narrowing(): array
    {
        return array_values(array_unique(array_merge($this->words, $this->artist, $this->album)));
    }

    /** True when there is nothing to look for. */
    public function isEmpty(): bool
    {
        return $this->narrowing() === [] && $this->year === null && $this->dr === null && $this->codecs === [];
    }
}
