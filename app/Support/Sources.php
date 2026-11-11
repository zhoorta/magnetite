<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Playlist;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The shared area and the playlists, in the shape of the library's own data (folders, albums, loose tracks), so the dense library can
 * show them as sources with the same tree, tiles and track list. People are the "folders"; a playlist is an "album" with `playlist: true`.
 *
 * Privacy: nothing here carries file-truth findings (a hash must never reveal a file someone keeps privately), and a track that the
 * viewer cannot play is sent without a stream url, as `open: false`.
 */
class Sources
{
    /** Everything in the shared area, from all users (yours included), grouped by who shares it. */
    public static function shared(User $me): array
    {
        $albums = Album::with(['tracks', 'user'])->shared()->get()
            ->each(fn (Album $a) => $a->tracks->each->setRelation('album', $a));
        $singles = Track::sharedSingles()->get();

        $owners = $albums->pluck('user')->merge($singles->pluck('user'))->filter()->unique('id');

        return [
            'folders' => self::people($owners, $me),
            'albums' => $albums->map(fn (Album $a) => self::album($a, $a->user_id, $a->user_id === $me->id) + [
                'tracks' => $a->tracks->map(fn (Track $t) => self::track($t))->values(),
            ])->values(),
            'loose' => $singles->map(fn (Track $t) => self::track($t) + ['owner_id' => $t->user_id, 'mine' => $t->user_id === $me->id])->values(),
        ];
    }

    /** The user's own playlists and the ones shared with everybody, grouped by owner. */
    public static function playlists(User $me): array
    {
        $own = $me->playlists()->with('share')->orderBy('name')->get();
        $others = Playlist::shared()->where('user_id', '!=', $me->id)->get();
        $all = $own->concat($others)->unique('id');

        return [
            'folders' => self::people($all->pluck('user')->push($me)->filter()->unique('id'), $me),
            'albums' => $all->map(fn (Playlist $p) => self::playlist($p, $me))->values(),
            'loose' => [],
        ];
    }

    /** One playlist as the library's "album": its tracks in order, each playable or not for this person. */
    public static function playlist(Playlist $playlist, User $me): array
    {
        $mine = $playlist->user_id === $me->id;
        $tracks = $playlist->tracksFor($me);
        $closed = $mine ? $playlist->unsharedOwnTracks()->pluck('tracks.id') : collect();

        $rows = $tracks->filter(fn (Track $t) => $mine ? $t->open : true)->map(fn (Track $t) => $t->open
            ? self::track($t) + ['open' => true, 'closed' => $closed->contains($t->id)]
            : ['id' => $t->id, 'title' => $t->title, 'artist' => $t->artist ?? $t->album?->artist, 'duration' => $t->duration, 'open' => false, 'truth' => ['analyzed' => false, 'flags' => []], 'playable' => false]
        )->values();

        return [
            'id' => $playlist->id,
            'title' => $playlist->name,
            'artist' => $mine ? null : $playlist->user?->username,
            'year' => null,
            'label' => null,
            'dr' => null,
            'dr_class' => null,
            'cover' => null,
            'cover_source' => false,
            'folder_id' => $playlist->user_id,
            'needs_analysis' => false,
            'edit' => null,
            'playlist' => true,
            'mine' => $mine,
            'shared' => $mine ? $playlist->share()->exists() : true,
            'hidden' => $mine ? $tracks->where('open', false)->count() : 0,
            'unshared' => $closed->count(),
            'tracks' => $rows,
        ];
    }

    /** People as top-level folders: you first, then by name. */
    private static function people(Collection $users, User $me): array
    {
        return $users->sortBy(fn (User $u) => [$u->id === $me->id ? 0 : 1, mb_strtolower($u->username)])
            ->map(fn (User $u) => ['id' => $u->id, 'parent_id' => null, 'name' => $u->id === $me->id ? 'You' : $u->username, 'depth' => 1])
            ->values()->all();
    }

    private static function album(Album $a, int $ownerId, bool $mine): array
    {
        return [
            'id' => $a->id,
            'title' => $a->title,
            'artist' => $a->displayArtist,
            'year' => $a->year,
            'label' => $a->label,
            'dr' => null,
            'dr_class' => null,
            'cover' => $a->cover ? route('cover', $a).'?v='.substr((string) $a->cover, 0, 10) : null,
            'cover_source' => false,
            'folder_id' => $ownerId,
            'needs_analysis' => false,
            'edit' => null,
            'mine' => $mine,
        ];
    }

    /** The player's shape, with the findings left out. */
    private static function track(Track $t): array
    {
        return $t->toPlayer() + ['truth' => ['analyzed' => false, 'flags' => []]];
    }
}
