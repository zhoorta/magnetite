<?php

namespace App\Http\Controllers;

use App\Models\Track;
use App\Services\TranscodeCache;
use App\Support\TrackLibrary;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StreamController extends Controller
{
    public function __invoke(Request $request, Track $track, TranscodeCache $transcodes): BinaryFileResponse
    {
        // Someone else's unshared track is answered like one that does not exist, so ids reveal nothing.
        abort_unless($request->user()->can('view', $track), 404);

        $path = TrackLibrary::resolve($track->path, $track->user);
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
