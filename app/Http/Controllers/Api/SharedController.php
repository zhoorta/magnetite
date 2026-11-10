<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\TrackResource;
use App\Models\Album;
use App\Models\Playlist;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SharedController extends Controller
{
    /** The shared area, as /shared shows it: albums, single tracks and playlists, with who shares them. */
    public function __invoke(Request $request): JsonResponse
    {
        $albums = Album::withCount('tracks')->shared()->get();
        $singles = Track::sharedSingles()->get();
        $playlists = Playlist::shared()->get();

        return response()->json([
            'albums' => AlbumResource::collection($albums)->map(fn ($r) => $r->resolve() + ['shared_by' => $r->user->username]),
            'tracks' => $singles->map(fn (Track $t) => (new TrackResource($t))->resolve() + ['shared_by' => $t->user->username]),
            'playlists' => $playlists->map(fn (Playlist $p) => ['id' => $p->id, 'name' => $p->name, 'track_count' => $p->tracks_count, 'shared_by' => $p->user->username]),
        ]);
    }
}
