<?php

namespace App\Support;

use App\Models\Track;
use Illuminate\Support\Collection;

/**
 * What the folder and file names say about an untagged album: artist, album, year, and per track the number, the
 * disc and the title. Pure text work, no network. Typical input: `Daft Punk - Discovery (2001) [FLAC]/03 - Digital Love.flac`.
 */
class PathGuess
{
    /**
     * Folder that makes one album: the track's folder, or the parent of a `CD1`/`Disc 2` folder.
     *
     * @return array{0: string, 1: int|null} the folder key ('.' for the library root) and the disc number
     */
    public static function groupKey(string $path): array
    {
        $dir = dirname($path);

        if ($dir !== '.' && preg_match('/^(?:cd|disc|disk|vol(?:ume)?)[\s._-]*(\d{1,2})\b/i', basename($dir), $m)) {
            return [dirname($dir), (int) $m[1]];
        }

        return [$dir, null];
    }

    /**
     * Artist, album and year from a folder name and its parent: "Artist - Album (2004) [FLAC]", "2004 - Album", or
     * just "Album" inside an "Artist" folder.
     *
     * @return array{artist: string|null, album: string, year: int|null}
     */
    public static function folder(string $key): array
    {
        if ($key === '.' || $key === '') {
            return ['artist' => null, 'album' => '', 'year' => null];
        }

        $name = basename($key);
        $year = null;

        // Years and format notes in brackets: (2004), [FLAC], {24-96}.
        $name = preg_replace_callback('/\s*[\(\[\{]([^\)\]\}]*)[\)\]\}]/u', function ($m) use (&$year) {
            if (preg_match('/^\s*(1[89]\d\d|20\d\d)\s*$/', $m[1], $y)) {
                $year ??= (int) $y[1];
            }

            return '';
        }, $name);
        $name = trim(preg_replace('/\s+/u', ' ', str_replace('_', ' ', $name)));

        $parts = array_values(array_filter(array_map('trim', preg_split('/\s+-\s+/u', $name)), fn ($p) => $p !== ''));

        // A bare year is a year, not a part of the name.
        $parts = array_values(array_filter($parts, function ($p) use (&$year) {
            if (preg_match('/^(1[89]\d\d|20\d\d)$/', $p)) {
                $year ??= (int) $p;

                return false;
            }

            return true;
        }));

        $artist = null;
        if (count($parts) >= 2) {
            $artist = $parts[0];
            $album = implode(' - ', array_slice($parts, 1));
        } else {
            $album = $parts[0] ?? '';
            $parent = dirname($key);
            $artist = $parent !== '.' ? basename($parent) : null;
        }

        return ['artist' => $artist ?: null, 'album' => $album, 'year' => $year];
    }

    /**
     * File name to number, disc and title: "03 - Title", "03. Title", "1-03 Title", "Artist - Title".
     *
     * @return array{number: int|null, disc: int|null, title: string, artist: string|null}
     */
    public static function file(string $path): array
    {
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $number = $disc = null;

        if (preg_match('/^(\d)-(\d{1,3})[\s._-]+(?=\S)/u', $stem, $m)) {
            [$disc, $number] = [(int) $m[1], (int) $m[2]];
            $stem = substr($stem, strlen($m[0]));
        } elseif (preg_match('/^(\d{1,3})[\s._-]+(?=\S)/u', $stem, $m)) {
            $number = (int) $m[1];
            $stem = substr($stem, strlen($m[0]));
        }

        $title = trim(preg_replace('/\s+/u', ' ', str_replace('_', ' ', $stem)));

        return ['number' => $number, 'disc' => $disc, 'title' => $title, 'artist' => null];
    }

    /**
     * The guess for one folder of tracks. A "Artist - " prefix shared by every file name is the artist, not part
     * of the titles. Tracks come back in playing order: disc, number, then file name.
     *
     * @param  Collection<int, Track>  $tracks
     * @return array{folder: string, artist: string|null, album: string, year: int|null, tracks: list<array{id: int, path: string, number: int|null, disc: int|null, title: string, artist: string|null}>}
     */
    public static function group(string $key, Collection $tracks): array
    {
        $guess = self::folder($key);

        $rows = $tracks->map(function ($t) {
            [, $folderDisc] = self::groupKey($t->path);
            $f = self::file($t->path);

            return ['id' => $t->id, 'path' => $t->path, 'number' => $f['number'], 'disc' => $f['disc'] ?? $folderDisc, 'title' => $f['title'], 'artist' => null];
        })->values()->all();

        $prefixes = array_map(fn ($r) => preg_match('/^(.+?)\s+-\s+(.+)$/u', $r['title'], $m) ? mb_strtolower($m[1]) : null, $rows);
        if ($rows !== [] && count(array_unique($prefixes)) === 1 && $prefixes[0] !== null) {
            foreach ($rows as $i => $r) {
                preg_match('/^(.+?)\s+-\s+(.+)$/u', $r['title'], $m);
                $rows[$i]['title'] = $m[2];
                $guess['artist'] ??= $m[1];
            }
        }

        usort($rows, fn ($a, $b) => [$a['disc'] ?? 1, $a['number'] ?? PHP_INT_MAX] <=> [$b['disc'] ?? 1, $b['number'] ?? PHP_INT_MAX]
            ?: strnatcasecmp($a['path'], $b['path']));

        return ['folder' => $key] + $guess + ['tracks' => $rows];
    }
}
