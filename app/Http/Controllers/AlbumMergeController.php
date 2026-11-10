<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Services\AlbumMerger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The owner joins an album that was split into the album it belongs to (docs/editing.md). Both must be theirs: anything else is a 404. */
class AlbumMergeController extends Controller
{
    public function store(Request $request, Album $album, AlbumMerger $merger): JsonResponse
    {
        abort_unless($request->user()->can('update', $album), 404);

        $into = Album::findOrFail($request->validate(['into' => ['required', 'integer']])['into']);
        abort_unless($request->user()->can('update', $into), 404);

        $kept = $merger->merge($album, $into);

        return response()->json(['album' => $kept->only(['id', 'title', 'artist', 'year', 'release_date', 'label']) + ['track_count' => $kept->tracks()->count()]]);
    }
}
