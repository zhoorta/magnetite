<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\TrackResource;
use App\Models\Album;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /** Tracks and albums the user may use (their own and the shared area) whose title or artist contains the text. */
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim($request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $like = '%'.addcslashes($q, '%_\\').'%';
        $me = $request->user();

        $albums = Album::visibleTo($me)->withCount('tracks')
            ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('artist', 'like', $like))
            ->orderBy('title')->limit(25)->get();

        $tracks = Track::visibleTo($me)->with('album')
            ->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('artist', 'like', $like))
            ->orderBy('title')->limit(50)->get();

        return response()->json(['albums' => AlbumResource::collection($albums), 'tracks' => TrackResource::collection($tracks)]);
    }
}
