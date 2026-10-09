<?php

namespace App\Services;

use App\Models\Album;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Throwable;

/**
 * Looks up the cover of an album that has none (or another one, when the downloaded cover was wrong): MusicBrainz and the Cover Art Archive first, iTunes second.
 * Only the hosts listed here are ever contacted, redirects included; what comes back is decoded and re-encoded by
 * CoverStore, so remote bytes are never stored or served as they are.
 */
class CoverFinder
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    /** Hosts a download may come from or be redirected to (exact name, or a subdomain of it). */
    private const HOSTS = ['musicbrainz.org', 'coverartarchive.org', 'archive.org', 'itunes.apple.com', 'mzstatic.com'];

    public function __construct(private CoverStore $covers) {}

    /**
     * Finds, stores and records a cover for the album. Returns the source name, or null when nothing matched.
     * With `$another` the album already has a downloaded cover that was wrong: matches tried before, and any that
     * gives the same picture, are skipped, and the old cover stays when there is no other match (the next try then
     * starts over).
     */
    public function fill(Album $album, bool $another = false): ?string
    {
        $album->loadMissing('tracks');
        $title = $album->title;
        $artist = $album->displayArtist;
        $artist = $artist === 'Various artists' ? null : $artist;

        $tried = $another ? array_values(array_unique([...($album->cover_tried ?? []), ...array_filter([$album->cover_ref])])) : [];

        $found = null;
        foreach (['musicbrainz' => $this->musicBrainz(...), 'itunes' => $this->itunes(...)] as $source => $candidates) {
            try {
                foreach ($candidates($title, $artist) as $ref => $url) {
                    if (in_array($ref, $tried, true)) {
                        continue;
                    }

                    $response = $this->http()->get($url);
                    $bytes = $response->successful() ? $this->body($response->body()) : null;
                    $name = $bytes !== null ? $this->covers->store($bytes) : null;

                    if ($name !== null && ! ($another && $name === $album->cover)) {
                        $found = [$name, $source, $ref];
                        break 2;
                    }
                }
            } catch (Throwable) {
                // a source that is down or odd must not stop the next one
            }
        }

        if ($found) {
            $album->update(['cover_checked_at' => now(), 'cover' => $found[0], 'cover_source' => $found[1], 'cover_ref' => $found[2], 'cover_tried' => [...$tried, $found[2]]]);
        } elseif ($another) {
            $album->update(['cover_tried' => []]); // all seen: start over next time
        } else {
            $album->update(['cover_checked_at' => now()]);
        }

        return $found[1] ?? null;
    }

    /** @return iterable<string, string> match reference => image address, best match first */
    private function musicBrainz(string $title, ?string $artist): iterable
    {
        $query = 'releasegroup:"'.$this->quote($title).'"'.($artist ? ' AND artist:"'.$this->quote($artist).'"' : '');
        $groups = $this->http()->get('https://musicbrainz.org/ws/2/release-group/', ['query' => $query, 'fmt' => 'json', 'limit' => 10])
            ->throw()->json('release-groups', []);

        foreach ($groups as $group) {
            $credit = collect($group['artist-credit'] ?? [])->map(fn ($c) => $c['name'] ?? '')->implode(' ');
            if (! $this->same($group['title'] ?? '', $title) || ! $this->sameArtist($credit, $artist) || ! preg_match('/^[0-9a-f-]{36}$/', $group['id'] ?? '')) {
                continue;
            }

            yield "mb:{$group['id']}" => "https://coverartarchive.org/release-group/{$group['id']}/front-500";
        }
    }

    /** @return iterable<string, string> */
    private function itunes(string $title, ?string $artist): iterable
    {
        $results = $this->http()->get('https://itunes.apple.com/search', ['term' => trim("{$artist} {$title}"), 'entity' => 'album', 'limit' => 10])
            ->throw()->json('results', []);

        foreach ($results as $result) {
            if (! $this->same($result['collectionName'] ?? '', $title) || ! $this->sameArtist($result['artistName'] ?? '', $artist)) {
                continue;
            }

            // The search gives a 100px thumbnail; the same address with another size gives the large one.
            $url = preg_replace('#/\d+x\d+(bb)?\.(jpg|png)$#', '/600x600bb.$2', $result['artworkUrl100'] ?? '');
            if (! $this->allowed($url)) {
                continue;
            }

            yield 'itunes:'.($result['collectionId'] ?? md5($url)) => $url;
        }
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent(config('magnetite.covers.user_agent'))->timeout(10)->withOptions([
            'allow_redirects' => [
                'max' => 4,
                'protocols' => ['https'],
                'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) {
                    if (! $this->allowed((string) $uri)) {
                        throw new RuntimeException('Redirect to a host that is not allowed.');
                    }
                },
            ],
            'on_headers' => function (ResponseInterface $response) {
                if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                    throw new RuntimeException('Response too large.');
                }
            },
        ]);
    }

    private function allowed(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        return ($parts['scheme'] ?? '') === 'https'
            && collect(self::HOSTS)->contains(fn ($h) => $host === $h || str_ends_with($host, ".{$h}"));
    }

    private function body(string $bytes): ?string
    {
        return $bytes !== '' && strlen($bytes) <= self::MAX_BYTES ? $bytes : null;
    }

    private function quote(string $value): string
    {
        return addcslashes($value, '"\\');
    }

    /** Same text once case, accents, punctuation and a trailing "(Deluxe Edition)"-style note are ignored. */
    private function same(string $a, string $b): bool
    {
        $a = $this->normalise($a);

        return $a !== '' && $a === $this->normalise($b);
    }

    /** An unknown artist (compilations) matches anything; otherwise one name must contain the other. */
    private function sameArtist(string $found, ?string $wanted): bool
    {
        if ($wanted === null) {
            return true;
        }

        [$a, $b] = [$this->normalise($found), $this->normalise($wanted)];

        return $a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a));
    }

    private function normalise(string $value): string
    {
        $value = preg_replace('/\s*[\(\[][^\)\]]*[\)\]]\s*$/u', '', $value);

        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($value)));
    }
}
