<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use App\Models\User;
use App\Support\AlbumKey;
use App\Support\SearchQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Search over what one person may use (their own music and the shared area). The text is split into words and folded (case,
 * accents), with `key:value` filters (`SearchQuery`: year, dr, codec, artist, album); every word must be found in the item's
 * title or artist, or, for a track, in its album's. So `bowie heroes` finds
 * the track *Heroes* by Bowie, `jafumega` finds Jáfumega, and a track is found by the name of its album. Results are ranked:
 * the exact title first, then a title that starts with the text, then one that contains every word, then matches that come
 * from the artist or the album; your own music before shared music; then by title.
 */
class LibrarySearch
{
    /** More words than this are ignored: nobody means them, and each one is a condition on the query. */
    private const MAX_TERMS = 6;

    /** Rows read before ranking: the ranking is done here, so the best of a very common word is not lost to the title order. */
    private const CANDIDATES = 1000;

    /** Pages beyond this are not served: the ranking only sees the candidates above. */
    private const MAX_PAGE = 20;

    /**
     * @return array{albums: Collection<int, Album>, tracks: Collection<int, Track>, more: array{albums: bool, tracks: bool}}
     */
    public function for(User $me, string $text, int $page = 1, int $albumSize = 25, int $trackSize = 50): array
    {
        $q = SearchQuery::parse($text);
        $none = ['albums' => collect(), 'tracks' => collect(), 'more' => ['albums' => false, 'tracks' => false]];
        $page = max(1, min($page, self::MAX_PAGE));

        if ($q->isEmpty()) {
            return $none;
        }

        $terms = array_slice($q->narrowing(), 0, self::MAX_TERMS);
        $query = implode(' ', $q->words);

        $albums = $q->tracksOnly() ? collect() : Album::visibleTo($me)->withCount('tracks')
            ->where(fn (Builder $w) => $this->has($w, 'albums.search_key', $terms))
            ->when($q->year, fn (Builder $w, array $y) => $this->between($w, 'albums.year', $y))
            ->orderBy('title')->limit(self::CANDIDATES)->get()
            ->filter(fn (Album $a) => $this->only($a->artist, $q->artist, true) && $this->only($a->title, $q->album));

        $tracks = Track::visibleTo($me)->with('album')
            ->where(fn (Builder $w) => $this->hasAll($w, $terms))
            ->when($q->year, fn (Builder $w, array $y) => $w->whereHas('album', fn (Builder $a) => $this->between($a, 'albums.year', $y)))
            ->when($q->codecs, fn (Builder $w, array $c) => $w->whereIn(DB::raw('lower(tracks.codec)'), $c))
            // the dynamic range is the owner's own measurement: other people's tracks never match a `dr:` filter
            ->when($q->dr, fn (Builder $w, array $d) => $this->between($w->where('tracks.user_id', $me->id), 'tracks.dr', $d))
            ->orderBy('title')->limit(self::CANDIDATES)->get()
            ->filter(fn (Track $t) => $this->only($t->artist ?? $t->album?->artist, $q->artist, true) && $this->only($t->album?->title, $q->album));

        $albums = $this->rank($albums, $me, $query, $terms);
        $tracks = $this->rank($tracks, $me, $query, $terms);

        return [
            'albums' => $albums->slice(($page - 1) * $albumSize, $albumSize)->values(),
            'tracks' => $tracks->slice(($page - 1) * $trackSize, $trackSize)->values(),
            'more' => ['albums' => $albums->count() > $page * $albumSize, 'tracks' => $tracks->count() > $page * $trackSize],
        ];
    }

    /** A number column within [min, max] (either may be null). */
    private function between(Builder $w, string $column, array $range): Builder
    {
        [$min, $max] = $range;

        return $w->when($min !== null, fn (Builder $q) => $q->where($column, '>=', $min))->when($max !== null, fn (Builder $q) => $q->where($column, '<=', $max));
    }

    /**
     * Do all the words appear in the text? A filter with no words always passes. `$orUnknown`: a missing text (an album with
     * no album artist) is not held against the item; the database already matched its words elsewhere.
     */
    private function only(?string $text, array $words, bool $orUnknown = false): bool
    {
        if ($words === []) {
            return true;
        }

        if ($text === null || $text === '') {
            return $orUnknown;
        }

        $folded = AlbumKey::fold($text);

        return collect($words)->every(fn ($w) => str_contains($folded, $w));
    }

    /** Every term in one column. */
    private function has(Builder $w, string $column, array $terms): void
    {
        foreach ($terms as $term) {
            $w->where($column, 'like', $this->like($term));
        }
    }

    /** Every term in the track's own text or in its album's. */
    private function hasAll(Builder $w, array $terms): void
    {
        foreach ($terms as $term) {
            $like = $this->like($term);
            $w->where(fn (Builder $t) => $t->where('tracks.search_key', 'like', $like)
                ->orWhereHas('album', fn (Builder $a) => $a->where('albums.search_key', 'like', $like)));
        }
    }

    private function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }

    /**
     * @param  Collection<int, Album|Track>  $items
     * @return Collection<int, Album|Track>
     */
    private function rank(Collection $items, User $me, string $query, array $terms): Collection
    {
        $score = function (Album|Track $item) use ($query, $terms): int {
            $title = AlbumKey::fold($item->title);
            $own = $title.' '.AlbumKey::fold($item->artist);
            $in = fn (string $text) => collect($terms)->every(fn ($t) => str_contains($text, $t));

            return match (true) {
                $title === $query => 0,
                str_starts_with($title, $query) => 1,
                $in($title) => 2,
                $in($own) => 3,
                default => 4,
            };
        };

        return $items->sortBy([
            fn ($a, $b) => $score($a) <=> $score($b),
            fn ($a, $b) => ($a->user_id === $me->id ? 0 : 1) <=> ($b->user_id === $me->id ? 0 : 1),
            fn ($a, $b) => strnatcasecmp($a->title, $b->title),
        ])->values();
    }
}
