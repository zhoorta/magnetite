<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Http\Resources\TrackResource;
use App\Services\LibrarySearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Tracks and albums the user may use (their own and the shared area) that match every word of the text in a title or an
     * artist (a track also in its album's). The work, and the ranking, are `LibrarySearch`'s.
     */
    public function __invoke(Request $request, LibrarySearch $search): JsonResponse
    {
        $text = trim($request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);
        $found = $search->for($request->user(), $text);

        return response()->json(['albums' => AlbumResource::collection($found['albums']), 'tracks' => TrackResource::collection($found['tracks'])]);
    }
}
