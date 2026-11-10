<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Track;
use App\Models\User;
use App\Support\AlbumKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Search over what one person may use (their own music and the shared area). The text is split into words and folded (case,
 * accents); every word must be found in the item's title or artist, or, for a track, in its album's. So `bowie heroes` finds
 * the track *Heroes* by Bowie, `jafumega` finds Jáfumega, and a track is found by the name of its album. Results are ranked:
 * the exact title first, then a title that starts with the text, then one that contains every word, then matches that come
 * from the artist or the album; your own music before shared music; then by title.
 */
class LibrarySearch
{
    /** More words than this are ignored: nobody means them, and each one is a condition on the query. */
    private const MAX_TERMS = 6;

    /** Rows read before ranking: the ranking is done here, so the best of a very common word is not lost to the title order. */
    private const CANDIDATES = 300;

    /** @return array{albums: Collection<int, Album>, tracks: Collection<int, Track>} */
    public function for(User $me, string $text, int $albumLimit = 25, int $trackLimit = 50): array
    {
        $query = AlbumKey::fold($text);
        $terms = array_slice(array_values(array_unique(preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [])), 0, self::MAX_TERMS);

        if ($terms === []) {
            return ['albums' => collect(), 'tracks' => collect()];
        }

        $albums = Album::visibleTo($me)->withCount('tracks')
            ->where(fn (Builder $w) => $this->has($w, 'albums.search_key', $terms))
            ->orderBy('title')->limit(self::CANDIDATES)->get();

        $tracks = Track::visibleTo($me)->with('album')
            ->where(fn (Builder $w) => $this->hasAll($w, $terms))
            ->orderBy('title')->limit(self::CANDIDATES)->get();

        return [
            'albums' => $this->rank($albums, $me, $query, $terms)->take($albumLimit)->values(),
            'tracks' => $this->rank($tracks, $me, $query, $terms)->take($trackLimit)->values(),
        ];
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
