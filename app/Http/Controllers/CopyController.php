<?php

namespace App\Http\Controllers;

use App\Jobs\ScanLibrary;
use App\Models\Album;
use App\Models\Track;
use App\Services\FerriteException;
use App\Services\LibraryUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Copies shared music of other users into the signed-in user's own library, as real files under `From {owner}/` with
 * the owner's folder structure. What may be copied is exactly what the user may play (`visibleTo`); anything else is a
 * 404. The copy is then the user's own: it is scanned like an upload and no longer follows the owner's sharing.
 */
class CopyController extends Controller
{
    public function store(Request $request, string $kind, int $id, LibraryUploader $uploader): JsonResponse
    {
        $me = $request->user();

        $tracks = match ($kind) {
            'albums' => Track::visibleTo($me)->where('album_id', Album::visibleTo($me)->findOrFail($id)->id)->get(),
            'tracks' => Track::visibleTo($me)->whereKey($id)->get(),
            default => abort(404),
        };

        $tracks = $tracks->where('user_id', '!=', $me->id)->load('user');
        abort_if($tracks->isEmpty(), 404);

        set_time_limit(0);

        $items = [];
        foreach ($tracks as $track) {
            $source = $track->user->store()->fetch($track->path);

            $source !== null && $items[] = ['source' => $source, 'path' => 'From '.$track->user->username.'/'.$track->path];
        }
        $missing = $tracks->count() - count($items);

        // The cover image that sits beside the music (cover.jpg, folder.png...), once per folder.
        foreach ($tracks->unique(fn ($t) => $t->user_id.'|'.dirname($t->path)) as $track) {
            $cover = $track->user->store()->folderCover($track->path);

            $folder = dirname($track->path) === '.' ? '' : dirname($track->path).'/';
            $cover !== null && $items[] = ['cover' => $cover, 'path' => 'From '.$track->user->username.'/'.$folder.'cover'];
        }

        try {
            $result = $uploader->copyIn($me, $items);
        } catch (RuntimeException|FerriteException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $result['failed'] += $missing;
        $result['added'] > 0 && ScanLibrary::request($me);

        return response()->json($result, 201);
    }
}
