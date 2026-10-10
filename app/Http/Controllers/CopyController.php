<?php

namespace App\Http\Controllers;

use App\Jobs\ScanLibrary;
use App\Models\Album;
use App\Models\Track;
use App\Services\FerriteException;
use App\Services\LibraryUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
            // The file beside the music, else a cover downloaded for the album.
            $cover = $track->user->store()->folderCover($track->path) ?? $this->albumCover($track);

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

    /** The bytes of a cover that was downloaded for the album (it lives only in the cover store; embedded art travels inside the files). */
    private function albumCover(Track $track): ?string
    {
        $name = $track->album?->cover_source !== null ? $track->album->cover : null;

        return $name !== null && preg_match('/^[a-f0-9]{40}\.jpg$/', $name) ? Storage::disk('local')->get("covers/{$name}") : null;
    }
}
