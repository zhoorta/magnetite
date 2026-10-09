<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Playlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaylistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $playlists = $request->user()->playlists()->withCount('tracks')->with('share')->orderBy('name')->get();

        return response()->json(['data' => $playlists->map(fn (Playlist $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'track_count' => $p->tracks_count,
            'shared' => $p->share !== null,
        ])]);
    }

    /** Yours, or a shared one. Tracks the viewer cannot play come back with `open: false` and no stream url. */
    public function show(Request $request, Playlist $playlist): JsonResponse
    {
        $me = $request->user();
        abort_unless($me->can('view', $playlist), 404);

        return response()->json(['data' => [
            'id' => $playlist->id,
            'name' => $playlist->name,
            'mine' => $playlist->user_id === $me->id,
            'tracks' => TrackResource::collection($playlist->tracksFor($me)),
        ]]);
    }
}
