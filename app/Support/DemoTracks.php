<?php

namespace App\Support;

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

    /** The shape the player takes, plus the credit the page shows. */
    public static function toPlayer(array $track): array
    {
        return [
            'id' => $track['id'],
            'title' => $track['title'],
            'artist' => $track['artist'],
            'album' => $track['album'],
            'year' => $track['year'],
            'album_id' => null,
            'cover' => asset('demo-cover.png'),
            'duration' => $track['duration'],
            'codec' => 'flac',
            'playable' => true,
            'url' => route('demo', $track['id']),
            'demo' => true,
        ];
    }
}
