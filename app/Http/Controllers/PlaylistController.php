<?php

namespace App\Http\Controllers;

use App\Models\Playlist;
use App\Models\SharedItem;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlaylistController extends Controller
{
    public function index(Request $request): View
    {
        $playlists = $request->user()->playlists()->withCount('tracks')->with('share')->orderBy('name')->get();

        return view('pages.playlists', ['playlists' => $playlists]);
    }

    /** Your own playlist to edit, or a shared one to play (read only). Anything else is a 404. */
    public function show(Request $request, Playlist $playlist): View
    {
        $me = $request->user();
        abort_unless($me->can('view', $playlist), 404);

        $tracks = $playlist->tracksFor($me);

        if ($playlist->user_id === $me->id) {
            // `closed`: the owner's own track that nobody else can play yet (what the share warning counts).
            $closed = $playlist->unsharedOwnTracks()->pluck('tracks.id');

            return view('pages.playlist', [
                'playlist' => $playlist,
                'tracks' => $tracks->where('open', true)->values()->map(fn ($t) => $t->toPlayer() + ['closed' => $closed->contains($t->id)]),
                'hidden' => $tracks->where('open', false)->count(),
                'shared' => $playlist->share()->exists(),
            ]);
        }

        return view('pages.shared-playlist', ['playlist' => $playlist->load('user'), 'tracks' => $tracks]);
    }

    /** Create a playlist, optionally with a first track (the "new playlist" entry of the add menu). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'track_id' => ['nullable', 'integer']]);

        $playlist = $request->user()->playlists()->create(['name' => trim($data['name'])]);

        if (isset($data['track_id'])) {
            $this->attach($request, $playlist, (int) $data['track_id']);
        }

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
        $data = $request->validate(['track_id' => ['required', 'integer']]);

        return response()->json(['added' => $this->attach($request, $playlist, (int) $data['track_id'])]);
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

    /** Adds a track the user may play; false when it was already in the playlist. */
    private function attach(Request $request, Playlist $playlist, int $trackId): bool
    {
        $track = Track::visibleTo($request->user())->findOrFail($trackId);

        if ($playlist->tracks()->whereKey($track->id)->exists()) {
            return false;
        }

        $playlist->tracks()->attach($track->id, ['position' => $playlist->nextPosition()]);

        return true;
    }
}
