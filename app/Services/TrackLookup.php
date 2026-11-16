<?php

namespace App\Services;

use App\Models\Track;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Information about one track: its Chromaprint fingerprint is looked up at AcoustID, which names the recordings it
 * matches (title, artist) and the releases they appear on (album, year, disc and track number). Nothing is applied here;
 * `TrackLookupController` lets the owner tick title, artist and track number. What leaves the server: a fingerprint and
 * the length of this one track, never audio.
 */
class TrackLookup
{
    /** Recordings offered, best match first. */
    public const MAX_RECORDINGS = 5;

    /** Releases listed per recording. */
    public const MAX_RELEASES = 12;

    /** Below this a fingerprint match says too little to show. */
    public const MIN_SCORE = 0.5;

    public function __construct(private Fingerprinter $fingerprints) {}

    /** @param  callable(string): void|null  $say */
    public function onProgress(?callable $say): void
    {
        $this->fingerprints->progress = $say;
    }

    /**
     * @return list<array{id: string, title: string, artist: ?string, duration: ?int, score: float, release_count: int, releases: list<array>}>
     */
    public function find(Track $track): array
    {
        if (! $this->fingerprints->available()) {
            throw new RuntimeException('Fingerprinting is not set up on this server (fpcalc and an AcoustID key).');
        }

        $file = $track->user->store()->fetch($track->path);
        if (! $file) {
            throw new RuntimeException('The file could not be read.');
        }

        $this->say('Fingerprinting the track…');
        $print = $this->fingerprints->fingerprint($file);
        if ($print === null) {
            throw new RuntimeException('The file could not be fingerprinted.');
        }

        $this->say('Asking AcoustID…');
        $results = Http::withUserAgent(config('magnetite.covers.user_agent'))->timeout(30)->withOptions(['allow_redirects' => false])
            ->asForm()->post('https://api.acoustid.org/v2/lookup', [
                'client' => config('magnetite.acoustid.key'),
                'duration' => $print['duration'],
                'fingerprint' => $print['fingerprint'],
                'meta' => 'recordings releases tracks',
            ])->throw()->json('results', []);

        return $this->recordings($results, (string) $track->album?->title);
    }

    /**
     * Recordings of an AcoustID answer: one entry per recording with its best score, the releases that carry the
     * album's own title first.
     *
     * @return list<array>
     */
    public function recordings(array $results, string $albumTitle = ''): array
    {
        $found = [];
        foreach ($results as $result) {
            $score = (float) ($result['score'] ?? 0);
            if ($score < self::MIN_SCORE) {
                continue;
            }
            foreach ($result['recordings'] ?? [] as $recording) {
                $id = (string) ($recording['id'] ?? '');
                if (! preg_match('/^[0-9a-f-]{36}$/', $id) || trim((string) ($recording['title'] ?? '')) === '') {
                    continue;
                }
                if (isset($found[$id]) && $found[$id]['score'] >= $score) {
                    continue;
                }
                $releases = $this->releases($recording['releases'] ?? [], $albumTitle);
                $found[$id] = [
                    'id' => $id,
                    'title' => mb_substr(trim($recording['title']), 0, 255),
                    'artist' => $this->artists($recording['artists'] ?? []),
                    'duration' => isset($recording['duration']) ? (int) round($recording['duration']) : null,
                    'score' => round($score, 3),
                    'release_count' => count($releases),
                    'releases' => array_slice($releases, 0, self::MAX_RELEASES),
                ];
            }
        }

        usort($found, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice(array_values($found), 0, self::MAX_RECORDINGS);
    }

    /** @return list<array{title: string, artist: ?string, year: ?int, country: ?string, format: ?string, disc: ?int, number: ?int, tracks: ?int}> */
    private function releases(array $releases, string $albumTitle): array
    {
        $out = [];
        foreach ($releases as $release) {
            $medium = $release['mediums'][0] ?? [];
            $position = $medium['tracks'][0]['position'] ?? null;
            $out[] = [
                'title' => mb_substr(trim((string) ($release['title'] ?? '')), 0, 255),
                'artist' => $this->artists($release['artists'] ?? []),
                'year' => isset($release['date']['year']) ? (int) $release['date']['year'] : null,
                'country' => isset($release['country']) ? (string) $release['country'] : null,
                'format' => isset($medium['format']) ? (string) $medium['format'] : null,
                'disc' => isset($medium['position']) ? (int) $medium['position'] : null,
                'number' => $position !== null ? (int) $position : null,
                'tracks' => isset($release['track_count']) ? (int) $release['track_count'] : null,
            ];
        }

        $own = mb_strtolower($albumTitle);
        usort($out, fn ($a, $b) => [mb_strtolower($a['title']) === $own ? 0 : 1, $a['year'] ?? 9999] <=> [mb_strtolower($b['title']) === $own ? 0 : 1, $b['year'] ?? 9999]);

        return $out;
    }

    private function artists(array $artists): ?string
    {
        $names = array_filter(array_map(fn ($a) => trim((string) ($a['name'] ?? '')), $artists));

        return $names === [] ? null : mb_substr(implode(', ', $names), 0, 255);
    }

    private function say(string $text): void
    {
        if ($this->fingerprints->progress !== null) {
            ($this->fingerprints->progress)($text);
        }
    }
}
