<?php

namespace App\Http\Controllers;

use App\Models\Track;
use App\Services\FerriteException;
use App\Services\FerriteStore;
use App\Services\TranscodeCache;
use App\Support\TrackLibrary;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class StreamController extends Controller
{
    public function __invoke(Request $request, Track $track, TranscodeCache $transcodes): Response
    {
        // Someone else's unshared track is answered like one that does not exist, so ids reveal nothing.
        abort_unless($request->user()->can('view', $track), 404);

        $store = $track->user->store();

        // A Ferrite track that is not in the cache is passed through with Range, so it starts at once and seeks.
        // Converted tracks (ALAC) need the whole file anyway, so they go through the cache below.
        if ($store instanceof FerriteStore && ! $transcodes->shouldTranscode($track) && $store->cachedFor($track) === null) {
            return $store->proxy($request, $track, TrackLibrary::contentType($track->path) ?? abort(404));
        }

        try {
            $path = $store->fetch($track->path);
        } catch (FerriteException $e) {
            abort(502, $e->getMessage());
        }
        abort_if($path === null, 404);

        $type = TrackLibrary::contentType($path);

        if ($transcodes->shouldTranscode($track)) {
            try {
                $path = $transcodes->ensure($track, $path);
                $type = TranscodeCache::MIME;
            } catch (RuntimeException) {
                abort(503, 'Could not prepare this track for playback.');
            }
        }

        // BinaryFileResponse handles Range requests (206), which seeking needs.
        $response = response()->file($path, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
        ]);
        $response->headers->set('Content-Disposition', 'inline');

        return $response;
    }
}
