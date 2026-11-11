<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Playlist;
use App\Models\SharedItem;
use App\Models\Track;
use App\Services\AlbumIdentifier;
use App\Support\Sources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlaylistController extends Controller
{
    /** Your playlists: the library page opened on its Playlists source. */
    public function index(Request $request, AlbumIdentifier $identifier): View
    {
        return $this->page($request, $identifier, null);
    }

    /** Your own playlist to edit, or a shared one to play (read only), selected in the Playlists source. Anything else is a 404. */
    public function show(Request $request, AlbumIdentifier $identifier, Playlist $playlist): View
    {
        abort_unless($request->user()->can('view', $playlist), 404);

        return $this->page($request, $identifier, $playlist->id);
    }

    private function page(Request $request, AlbumIdentifier $identifier, ?int $select): View
    {
        $data = app(LibraryController::class)->pageData($request, $identifier) + [
            'source' => 'playlists',
            'select' => $select,
            'sources' => ['playlists' => Sources::playlists($request->user())],
        ];

        return view('pages.library', ['data' => $data]);
    }

    /** Create a playlist, optionally with a first track (the "new playlist" entry of the add menu). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'track_id' => ['nullable', 'integer'], 'album_id' => ['nullable', 'integer']]);

        // Look the first item up before creating anything, so a bad id leaves no empty playlist behind.
        $track = isset($data['track_id']) ? Track::visibleTo($request->user())->findOrFail($data['track_id']) : null;
        $album = isset($data['album_id']) ? Album::visibleTo($request->user())->findOrFail($data['album_id']) : null;

        $playlist = $request->user()->playlists()->create(['name' => trim($data['name'])]);

        $track && $this->attach($playlist, $track);
        $album && $this->attachAlbum($request, $playlist, $album);

        return response()->json(['id' => $playlist->id, 'name' => $playlist->name, 'url' => route('playlists.show', $playlist)], 201);
    }

    public function update(Request $request, Playlist $playlist): JsonResponse
    {
        $this->own($request, $playlist);
        $playlist->update($request->validate(['name' => ['required', 'string', 'max:120']]));

        return response()->json(['name' => $playlist->name]);
    }

    public function destroy(Request $request, Playlist $playlist): JsonResponse
    {
        $this->own($request, $playlist);
        $playlist->delete();

        return response()->json(['deleted' => true]);
    }

    /** Share the owner's own tracks of this playlist that are not shared yet, so others can play all of it. */
    public function shareTracks(Request $request, Playlist $playlist): JsonResponse
    {
        $this->own($request, $playlist);

        $ids = $playlist->unsharedOwnTracks()->pluck('tracks.id');
        foreach ($ids as $id) {
            SharedItem::firstOrCreate(['track_id' => $id], ['user_id' => $request->user()->id]);
        }

        return response()->json(['shared' => $ids->count()]);
    }

    /** A copy of a shared playlist in your own playlists, with the tracks you can play. */
    public function copy(Request $request, Playlist $playlist): JsonResponse
    {
        $me = $request->user();
        abort_unless($me->can('view', $playlist), 404);

        $copy = $me->playlists()->create(['name' => $playlist->name]);
        $position = 1;
        foreach ($playlist->tracksFor($me)->where('open', true) as $track) {
            $copy->tracks()->attach($track->id, ['position' => $position++]);
        }

        return response()->json(['id' => $copy->id, 'url' => route('playlists.show', $copy), 'tracks' => $position - 1], 201);
    }

    public function addTrack(Request $request, Playlist $playlist): JsonResponse
    {
        $this->own($request, $playlist);
        $data = $request->validate(['track_id' => ['required_without:album_id', 'integer'], 'album_id' => ['required_without:track_id', 'integer']]);

        if (isset($data['album_id'])) {
            $album = Album::visibleTo($request->user())->findOrFail($data['album_id']);

            return response()->json(['added' => $this->attachAlbum($request, $playlist, $album)]);
        }

        $track = Track::visibleTo($request->user())->findOrFail($data['track_id']);

        return response()->json(['added' => $this->attach($playlist, $track)]);
    }

    public function removeTrack(Request $request, Playlist $playlist, int $track): JsonResponse
    {
        $this->own($request, $playlist);
        $playlist->tracks()->detach($track);

        return response()->json(['removed' => true]);
    }

    /** The new order, as the list of track ids; ids that are not in the playlist are ignored. */
    public function reorder(Request $request, Playlist $playlist): JsonResponse
    {
        $this->own($request, $playlist);
        $data = $request->validate(['ids' => ['required', 'array', 'max:5000'], 'ids.*' => ['integer']]);

        DB::transaction(function () use ($playlist, $data) {
            $position = 1;
            foreach (array_unique($data['ids']) as $id) {
                $position += DB::table('playlist_track')->where('playlist_id', $playlist->id)->where('track_id', $id)->update(['position' => $position]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /** 404 for someone else's playlist, like every other thing that is not yours. */
    private function own(Request $request, Playlist $playlist): void
    {
        abort_unless($request->user()->can('manage', $playlist), 404);
    }

    /** Adds a track (already checked as playable by the user); false when it was already in the playlist. */
    private function attach(Playlist $playlist, Track $track): bool
    {
        if ($playlist->tracks()->whereKey($track->id)->exists()) {
            return false;
        }

        $playlist->tracks()->attach($track->id, ['position' => $playlist->nextPosition()]);

        return true;
    }

    /** Adds the tracks of an album that the user may play, in album order, skipping those already in the playlist. Returns how many. */
    private function attachAlbum(Request $request, Playlist $playlist, Album $album): int
    {
        $added = 0;

        foreach ($album->tracks()->visibleTo($request->user())->get() as $track) {
            $added += (int) $this->attach($playlist, $track);
        }

        return $added;
    }
}
