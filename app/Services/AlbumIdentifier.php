<?php

namespace App\Services;

use App\Models\Album;
use App\Models\AlbumSuggestion;
use App\Models\Track;
use App\Models\User;
use App\Support\PathGuess;
use App\Support\ReleaseMatch;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Suggests what a folder of tracks without an album tag is (docs/identify-albums.md): the guess from the folder and
 * file names, and releases from MusicBrainz whose track count and lengths fit the files. Nothing is applied until the
 * owner accepts a candidate. Only guessed names and a release id travel; no audio and no fingerprints.
 */
class AlbumIdentifier
{
    /** Candidates shown at most, and the least share of tracks that must fit for a release to be offered. */
    private const MAX_CANDIDATES = 3;

    private const MIN_SCORE = 0.5;

    /** A release is only looked at when its track count is this close to the number of files. */
    private const COUNT_SLACK = 2;

    /** A release that fits at least this share of the tracks ends the search for more. */
    private const GOOD_SCORE = 0.8;

    /** When the last MusicBrainz request went out; they allow one a second. */
    private float $last = 0;

    public function __construct(private Fingerprinter $fingerprints, private CoverStore $covers) {}

    /**
     * The user's tracks without an album, grouped by folder. The library root is left out: loose files there are not an album.
     *
     * @return Collection<string, Collection<int, Track>>
     */
    public function groups(User $user): Collection
    {
        return Track::where('user_id', $user->id)->whereNull('album_id')->get()
            ->groupBy(fn (Track $t) => PathGuess::groupKey($t->path)[0])
            ->reject(fn ($tracks, $key) => $key === '.')
            ->sortKeys();
    }

    /**
     * Folders that still need a look: no suggestion yet, or (with $retry) one that was dismissed or found nothing.
     *
     * @return Collection<string, Collection<int, Track>>
     */
    public function pending(User $user, bool $retry = false): Collection
    {
        $known = AlbumSuggestion::where('user_id', $user->id)->get()->keyBy('folder');

        return $this->groups($user)->reject(function ($tracks, $key) use ($known, $retry) {
            $s = $known->get($key);

            return $s !== null && ! ($retry && ($s->dismissed_at !== null || count($s->candidates) < 2));
        });
    }

    /** Looks at one folder and keeps what it found. */
    public function suggest(User $user, string $key, Collection $tracks): AlbumSuggestion
    {
        $guess = PathGuess::group($key, $tracks);
        $byId = $tracks->keyBy('id');

        $candidates = [$this->fromPath($guess)];
        try {
            foreach ($this->fromMusicBrainz($user, $guess, $byId) as $c) {
                $candidates[] = $c;
            }
        } catch (Throwable $e) {
            // MusicBrainz down or odd: the guess from the path is still offered.
            Log::info('MusicBrainz lookup failed', ['folder' => $key, 'error' => $e->getMessage()]);
        }

        // Releases whose lengths fit best come first; the path guess closes the list as the fallback.
        usort($candidates, fn ($a, $b) => ($b['score'] ?? -1) <=> ($a['score'] ?? -1));

        return AlbumSuggestion::updateOrCreate(
            ['user_id' => $user->id, 'folder' => $key],
            ['guess' => Arr::except($guess, 'tracks'), 'candidates' => array_values($candidates), 'checked_at' => now(), 'dismissed_at' => null],
        );
    }

    /**
     * Gives the tracks of the folder the album, titles and numbers of one candidate. A release title or number is
     * used only for a track whose length matched; the others keep what the file names said.
     */
    public function apply(AlbumSuggestion $suggestion, int $index): Album
    {
        $candidate = $suggestion->candidates[$index] ?? throw new \InvalidArgumentException('No such candidate.');

        return DB::transaction(function () use ($suggestion, $candidate) {
            $identity = ['user_id' => $suggestion->user_id, 'artist' => $candidate['artist'], 'title' => $candidate['title']];
            if ($candidate['artist'] === null) {
                $identity['folder'] = $suggestion->folder; // as the scanner does for albums without an album artist
            }

            $album = Album::firstOrCreate($identity, ['year' => $candidate['year']]);

            // Only tracks that are still the owner's and still without an album.
            $tracks = Track::where('user_id', $suggestion->user_id)->whereNull('album_id')
                ->whereIn('id', array_column($candidate['tracks'], 'id'))->get()->keyBy('id');

            foreach ($candidate['tracks'] as $row) {
                $tracks->get($row['id'])?->update([
                    'album_id' => $album->id,
                    'identified' => true,
                    'title' => $row['title'],
                    'artist' => $row['artist'],
                    'track_number' => $row['number'],
                    'disc_number' => $row['disc'],
                ]);
            }

            $suggestion->delete();

            // A cover in the folder (or none): the owner's own file wins over anything fetched later.
            if ($album->cover === null && ($first = $tracks->first()) !== null) {
                $image = $suggestion->user->store()->folderCover($first->path);
                $name = $image !== null ? $this->covers->store($image) : null;
                $name !== null && $album->update(['cover' => $name, 'cover_source' => null]);
            }

            return $album;
        });
    }

    /** The folder and file names as a candidate. */
    private function fromPath(array $guess): array
    {
        return [
            'source' => 'path',
            'via' => null,
            'mbid' => null,
            'title' => $guess['album'] !== '' ? $guess['album'] : basename($guess['folder']),
            'artist' => $guess['artist'],
            'year' => $guess['year'],
            'score' => null,
            'matched' => null,
            'release_tracks' => null,
            'tracks' => array_map(fn ($t) => [
                'id' => $t['id'], 'from' => basename($t['path']), 'title' => $t['title'], 'artist' => null,
                'number' => $t['number'], 'disc' => $t['disc'], 'matched' => null,
            ], $guess['tracks']),
        ];
    }

    /**
     * Releases whose lengths fit the files, looked for in three ways, each only if the one before found nothing good:
     * the guessed album name, then the titles of a few tracks, then fingerprints (when fpcalc and a key are set up).
     *
     * @param  Collection<int, Track>  $byId
     * @return list<array>
     */
    private function fromMusicBrainz(User $user, array $guess, Collection $byId): array
    {
        $local = array_map(fn ($t) => $byId[$t['id']]->duration, $guess['tracks']);
        $out = [];
        $seen = [];

        $steps = [
            'album' => fn () => $guess['album'] !== '' ? $this->releasesByAlbum($guess, count($local)) : [],
            'titles' => fn () => $this->releasesByTitles($guess, count($local)),
            'fingerprint' => fn () => $this->fingerprints->available()
                ? $this->releasesByFingerprint($user, $guess, $byId)
                : [],
        ];

        foreach ($steps as $via => $find) {
            if ($this->good($out)) {
                break;
            }

            $ids = array_values(array_diff($find(), $seen));
            foreach (array_slice($ids, 0, self::MAX_CANDIDATES + 2) as $id) {
                $seen[] = $id;
                if (($candidate = $this->score($id, $via, $guess, $local)) !== null) {
                    $out[] = $candidate;
                }
            }
        }

        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, self::MAX_CANDIDATES);
    }

    /** A release that fits nearly every track is good enough to stop looking. */
    private function good(array $candidates): bool
    {
        return collect($candidates)->contains(fn ($c) => $c['score'] >= self::GOOD_SCORE);
    }

    /** @return list<string> release ids, the nearest track count first */
    private function releasesByAlbum(array $guess, int $count): array
    {
        $query = 'release:"'.addcslashes($guess['album'], '"\\').'"'
            .($guess['artist'] ? ' AND artist:"'.addcslashes($guess['artist'], '"\\').'"' : '');

        $found = $this->get('https://musicbrainz.org/ws/2/release/', ['query' => $query, 'fmt' => 'json', 'limit' => 10])['releases'] ?? [];

        return collect($found)
            ->filter(fn ($r) => $this->validId($r['id'] ?? '') && abs(($r['track-count'] ?? 0) - $count) <= self::COUNT_SLACK)
            ->sortBy([fn ($a, $b) => abs($a['track-count'] - $count) <=> abs($b['track-count'] - $count), fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0)])
            ->pluck('id')->values()->all();
    }

    /**
     * For folders whose name says nothing: search recordings by the title of up to three tracks and keep the releases
     * that come up for several of them.
     *
     * @return list<string>
     */
    private function releasesByTitles(array $guess, int $count): array
    {
        $titles = collect($guess['tracks'])->pluck('title')->filter(fn ($t) => $this->meaningful($t))->unique()->take(3)->values();
        if ($titles->isEmpty()) {
            return [];
        }

        $votes = [];
        foreach ($titles as $title) {
            $query = 'recording:"'.addcslashes($title, '"\\').'"'.($guess['artist'] ? ' AND artist:"'.addcslashes($guess['artist'], '"\\').'"' : '');
            $recordings = $this->get('https://musicbrainz.org/ws/2/recording/', ['query' => $query, 'fmt' => 'json', 'limit' => 10])['recordings'] ?? [];

            $ids = [];
            foreach ($recordings as $recording) {
                foreach ($recording['releases'] ?? [] as $release) {
                    if ($this->validId($release['id'] ?? '') && abs(($release['track-count'] ?? $count) - $count) <= self::COUNT_SLACK) {
                        $ids[$release['id']] = true;
                    }
                }
            }
            foreach (array_keys($ids) as $id) {
                $votes[$id] = ($votes[$id] ?? 0) + 1;
            }
        }

        // With several titles a release must be on at least two of them; one title alone proves little.
        $need = min(2, $titles->count());

        return collect($votes)->filter(fn ($v) => $v >= $need)->sortDesc()->keys()->all();
    }

    /** @return list<string> */
    private function releasesByFingerprint(User $user, array $guess, Collection $byId): array
    {
        $tracks = array_map(fn ($t) => $byId[$t['id']], $guess['tracks']);

        return array_keys($this->fingerprints->releases($user, $tracks));
    }

    /** Titles like "Track 01" or "07" say nothing and would only find noise. */
    private function meaningful(string $title): bool
    {
        return mb_strlen($title) >= 3 && ! preg_match('/^(?:track|faixa|audio|titel|piste)?[\s._-]*\d*$/iu', $title);
    }

    /** One release looked up and held against the files; null when fewer than half the tracks fit. */
    private function score(string $id, string $via, array $guess, array $local): ?array
    {
        $detail = $this->get("https://musicbrainz.org/ws/2/release/{$id}", ['inc' => 'recordings+artist-credits', 'fmt' => 'json']);
        $remote = $this->releaseTracks($detail);
        $match = ReleaseMatch::compare($local, array_column($remote, 'length'));
        if ($match['score'] < self::MIN_SCORE) {
            return null;
        }

        $artist = $this->credit($detail['artist-credit'] ?? []);

        return [
            'source' => 'musicbrainz',
            'via' => $via,
            'mbid' => $detail['id'],
            'title' => $detail['title'] ?? $guess['album'],
            'artist' => $artist,
            'year' => preg_match('/^(\d{4})/', $detail['date'] ?? '', $y) ? (int) $y[1] : $guess['year'],
            'score' => $match['score'],
            'matched' => $match['count'],
            'release_tracks' => count($remote),
            'tracks' => array_map(function ($t, $i) use ($match, $remote, $artist) {
                $hit = $match['matched'][$i] ? $remote[$i] : null;

                return [
                    'id' => $t['id'], 'from' => basename($t['path']),
                    'title' => $hit['title'] ?? $t['title'],
                    // A track artist only where it differs from the album's (compilations).
                    'artist' => $hit && $hit['artist'] !== null && Str::lower($hit['artist']) !== Str::lower((string) $artist) ? $hit['artist'] : null,
                    'number' => $hit['number'] ?? $t['number'],
                    'disc' => $hit['disc'] ?? $t['disc'],
                    'matched' => $match['matched'][$i],
                ];
            }, $guess['tracks'], array_keys($guess['tracks'])),
        ];
    }

    private function validId(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f-]{36}$/', $id);
    }

    /** A MusicBrainz request, paced to one a second. */
    private function get(string $url, array $query): array
    {
        $wait = 1.0 - (microtime(true) - $this->last);
        if ($wait > 0 && $this->last > 0) {
            Sleep::for((int) ceil($wait * 1000))->milliseconds();
        }

        try {
            return $this->http()->get($url, $query)->throw()->json() ?? [];
        } finally {
            $this->last = microtime(true);
        }
    }

    /** All tracks of a release in playing order, discs after each other. */
    private function releaseTracks(array $release): array
    {
        $tracks = [];
        foreach ($release['media'] ?? [] as $medium) {
            foreach ($medium['tracks'] ?? [] as $t) {
                $tracks[] = [
                    'title' => (string) ($t['title'] ?? ''),
                    'length' => isset($t['length']) ? $t['length'] / 1000 : null,
                    'number' => (int) ($t['position'] ?? 0) ?: null,
                    'disc' => count($release['media']) > 1 ? (int) ($medium['position'] ?? 1) : null,
                    'artist' => isset($t['artist-credit']) ? $this->credit($t['artist-credit']) : null,
                ];
            }
        }

        return $tracks;
    }

    private function credit(array $credit): ?string
    {
        $name = collect($credit)->map(fn ($c) => ($c['name'] ?? '').($c['joinphrase'] ?? ''))->implode('');

        return $name !== '' ? $name : null;
    }

    private function http()
    {
        return Http::withUserAgent(config('magnetite.covers.user_agent'))->timeout(10)->withOptions(['allow_redirects' => false]);
    }
}
