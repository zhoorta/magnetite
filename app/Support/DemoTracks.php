<?php

namespace App\Support;

use App\Models\Track;

/**
 * The free music offered on the start page. The list (with its credits and licences) is in git,
 * `resources/demo/tracks.json`; the audio is not: `bin/prepare-demo.sh` writes it to `storage/app/demo`
 * and it is copied to the server by hand. A track whose file is missing is left out.
 *
 * Only ids from the list can be streamed, so no path ever comes from the request.
 */
class DemoTracks
{
    /** @return array<string, array<string, mixed>> the tracks whose file is present, by id */
    public static function available(): array
    {
        $list = json_decode((string) @file_get_contents(resource_path('demo/tracks.json')), true)['tracks'] ?? [];
        $found = [];

        foreach ($list as $track) {
            if (is_file(self::path($track['file']))) {
                $found[$track['id']] = $track;
            }
        }

        return $found;
    }

    public static function path(string $file): string
    {
        return rtrim(config('magnetite.demo_path'), '/').'/'.basename($file);
    }

    /** The shape the player takes, plus the credit the page shows. `$albumId`: its album in the demo library (visitors only). */
    public static function toPlayer(array $track, ?int $albumId = null): array
    {
        return [
            'id' => $track['id'],
            'title' => $track['title'],
            'artist' => $track['artist'],
            'album' => $track['album'],
            'year' => $track['year'],
            'album_id' => $albumId,
            'cover' => asset('demo-cover.png'),
            'duration' => $track['duration'],
            'codec' => 'flac',
            'playable' => true,
            'url' => route('demo', $track['id']),
            'demo' => true,
        ];
    }

    public static function analysisPath(): string
    {
        return resource_path('demo/analysis.json');
    }

    /** @return array<string, array<string, mixed>> what `magnetite:demo-analyze` measured, by track id */
    public static function analysis(): array
    {
        return json_decode((string) @file_get_contents(self::analysisPath()), true) ?: [];
    }

    /**
     * The id of each track's album in the demo library, by track id: -1, -2... in the order of the list (negative, so
     * they never meet a real album), and null for a track without an album.
     *
     * @return array<string, int|null>
     */
    public static function albumIds(): array
    {
        $ids = [];
        $byTitle = [];
        foreach (self::available() as $id => $track) {
            $title = trim((string) ($track['album'] ?? ''));
            $ids[$id] = $title === '' ? null : ($byTitle[$title] ??= -(count($byTitle) + 1));
        }

        return $ids;
    }

    /**
     * The demo tracks as the library page's data (the shape of LibraryController::pageData), for a visitor who is not
     * signed in: read only, nothing from the database. The findings come from the same rules as a real library.
     */
    public static function library(): array
    {
        $measured = self::analysis();
        $albumIds = self::albumIds();
        $albums = [];
        $loose = [];

        foreach (self::available() as $id => $track) {
            $analysis = $measured[$id] ?? null;
            $model = (new Track)->forceFill([
                'title' => $track['title'],
                'artist' => $track['artist'],
                'codec' => 'flac',
                'analysis' => $analysis,
                'dr' => isset($analysis['dr']['rounded']) ? max(0, (int) $analysis['dr']['rounded']) : null,
            ]);
            $truth = $model->toTruth();
            $row = self::toPlayer($track, $albumIds[$id]) + ['truth' => $truth + ['spectrum_points' => count($truth['spectrum'] ?? [])]];

            if ($albumIds[$id] === null) {
                $loose[] = $row;

                continue;
            }

            $albums[$albumIds[$id]] ??= [
                'id' => $albumIds[$id],
                'title' => $track['album'],
                'artist' => $track['artist'],
                'year' => $track['year'],
                'label' => null,
                'cover' => asset('demo-cover.png'),
                'cover_source' => false,
                'folder_id' => null,
                'needs_analysis' => false,
                'tracks' => [],
            ];
            $albums[$albumIds[$id]]['tracks'][] = $row;
        }

        foreach ($albums as &$album) {
            $scores = array_filter(array_map(fn ($t) => $t['truth']['dr'] ?? null, $album['tracks']), fn ($v) => $v !== null);
            $album['dr'] = $scores ? (int) round(array_sum($scores) / count($scores)) : null;
            $album['dr_class'] = FileTruth::drClass($album['dr']);
        }
        unset($album);

        return [
            'demo' => true,
            'folders' => [],
            'albums' => array_values($albums),
            'loose' => $loose,
            'shared' => ['albums' => [], 'tracks' => []],
            'suggestions' => [],
            'to_identify' => 0,
            'trash_only' => false,
        ];
    }
}
