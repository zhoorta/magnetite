<?php

namespace App\Http\Controllers;

use App\Http\Resources\AlbumResource;
use App\Services\AlbumGrouper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Name an album for tracks that have none (files without an album tag; `LooseTrackController` lists them). Mounted on the web
 * and on the API, like the merge: the work is `AlbumGrouper`'s. The tracks must be the signed-in user's and still without an album.
 */
class AlbumGroupController extends Controller
{
    public function store(Request $request, AlbumGrouper $grouper): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'between:1000,2100'],
            'track_ids' => ['required', 'array', 'min:1', 'max:500'],
            'track_ids.*' => ['integer'],
        ]);
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        try {
            $album = $grouper->group($request->user(), $data['track_ids'], trim($data['title']), $clean($data['artist'] ?? null), $data['year'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new AlbumResource($album->loadCount('tracks')))->response()->setStatusCode(201);
    }
}
