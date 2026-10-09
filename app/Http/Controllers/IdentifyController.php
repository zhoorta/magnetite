<?php

namespace App\Http\Controllers;

use App\Jobs\IdentifyAlbums;
use App\Models\AlbumSuggestion;
use App\Services\AlbumIdentifier;
use App\Support\IdentifyStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Suggestions for the signed-in user's folders of tracks without an album tag; nobody else's are ever reachable (404). */
class IdentifyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(IdentifyStatus::get($request->user()->id));
    }

    public function store(Request $request): JsonResponse
    {
        IdentifyAlbums::request($request->user(), $request->boolean('retry'));

        return response()->json(IdentifyStatus::get($request->user()->id), 202);
    }

    public function accept(Request $request, AlbumSuggestion $suggestion, AlbumIdentifier $identifier): JsonResponse
    {
        abort_unless($suggestion->user_id === $request->user()->id, 404);
        $index = $request->validate(['candidate' => ['required', 'integer', 'min:0']])['candidate'];
        abort_unless(isset($suggestion->candidates[$index]), 422, 'No such candidate.');

        return response()->json(['album' => $identifier->apply($suggestion, $index)->id]);
    }

    public function dismiss(Request $request, AlbumSuggestion $suggestion): JsonResponse
    {
        abort_unless($suggestion->user_id === $request->user()->id, 404);
        $suggestion->update(['dismissed_at' => now()]);

        return response()->json(['dismissed' => true]);
    }
}
