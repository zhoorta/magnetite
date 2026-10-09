<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Album;
use App\Models\Track;
use App\Services\AlbumMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Put a file in an album, or take it out. The file moves; it is never deleted. Owner only. */
class AlbumTrackController extends Controller
{
    public function store(Request $request, Album $album, AlbumMembership $membership): JsonResponse
    {
        abort_unless($request->user()->can('update', $album), 404);

        $data = $request->validate(['track_id' => ['required', 'integer', Rule::exists('tracks', 'id')->where('user_id', $request->user()->id)]]);

        return $this->run(fn () => $membership->add($request->user(), $album, Track::findOrFail($data['track_id'])), 201);
    }

    public function destroy(Request $request, Album $album, Track $track, AlbumMembership $membership): JsonResponse
    {
        abort_unless($request->user()->can('update', $album) && $request->user()->can('update', $track), 404);

        return $this->run(fn () => $membership->remove($request->user(), $album, $track));
    }

    private function run(callable $move, int $status = 200): JsonResponse
    {
        try {
            $track = $move()->load('album');
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new TrackResource($track))->response()->setStatusCode($status);
    }
}
