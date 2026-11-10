<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Track;
use App\Services\LibrarySearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The library's search box, for the signed-in browser (the apps use `Api\SearchController` with a token; the work, the filters and
 * the ranking are `LibrarySearch`'s for both). Own music and the shared area, never anybody's private music. The shape is the
 * player's, so a hit can be played as it is; the dynamic range is only sent for the user's own tracks.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request, LibrarySearch $search): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $me = $request->user();
        $page = (int) ($data['page'] ?? 1);
        $found = $search->for($me, trim($data['q']), $page);

        return response()->json([
            'albums' => $found['albums']->map(fn (Album $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'artist' => $a->displayArtist,
                'year' => $a->year,
                'tracks' => $a->tracks_count,
                'mine' => $a->user_id === $me->id,
                'dr' => $a->user_id === $me->id ? $a->dr : null,
            ])->values(),
            'tracks' => $found['tracks']->map(fn (Track $t) => $t->toPlayer() + [
                'year' => $t->album?->year,
                'mine' => $t->user_id === $me->id,
                'dr' => $t->user_id === $me->id ? $t->dr : null,
            ])->values(),
            'page' => $page,
            'more' => $found['more'],
        ]);
    }
}
