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
     * artist (a track also in its album's), and `key:value` filters (year, dr, codec, artist, album). 25 albums and 50 tracks a
     * page (`page`). The work, the filters and the ranking are `LibrarySearch`'s.
     */
    public function __invoke(Request $request, LibrarySearch $search): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $page = (int) ($data['page'] ?? 1);
        $found = $search->for($request->user(), trim($data['q']), $page);

        return response()->json([
            'albums' => AlbumResource::collection($found['albums']), 'tracks' => TrackResource::collection($found['tracks']),
            // `more`: there are further results on the next page (the ranking sees the best 1000 of each, so at most 20 pages)
            'page' => $page, 'more' => $found['more'],
        ]);
    }
}
