<?php

namespace App\Http\Controllers;

use App\Models\Track;
use App\Services\TranscodeCache;
use App\Support\TrackLibrary;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StreamController extends Controller
{
    public function __invoke(Track $track, TranscodeCache $transcodes): BinaryFileResponse
    {
        $path = TrackLibrary::resolve($track->path);
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
