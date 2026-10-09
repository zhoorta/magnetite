<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Track;
use App\Services\LibraryRemover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Removes the signed-in user's own albums and tracks from the library, files included. Anyone else's: 404. */
class LibraryRemoveController extends Controller
{
    public function album(Request $request, Album $album, LibraryRemover $remover): JsonResponse
    {
        abort_unless($request->user()->can('delete', $album), 404);

        return $this->respond($remover->removeAlbum($request->user(), $album));
    }

    public function track(Request $request, Track $track, LibraryRemover $remover): JsonResponse
    {
        abort_unless($request->user()->can('delete', $track), 404);

        return $this->respond($remover->remove($request->user(), collect([$track])));
    }

    private function respond(array $result): JsonResponse
    {
        // Nothing removed because of an error: not a success. Some removed: the page reloads and shows what is left.
        return response()->json($result, $result['error'] !== null && $result['removed'] === 0 ? 502 : 200);
    }
}
