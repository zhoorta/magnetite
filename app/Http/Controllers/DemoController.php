<?php

namespace App\Http\Controllers;

use App\Support\DemoTracks;
use Symfony\Component\HttpFoundation\Response;

/** Streams one of the start page's demo tracks. Public, but only the ids in the list, never a path from the request. */
class DemoController extends Controller
{
    public function __invoke(string $id): Response
    {
        $track = DemoTracks::available()[$id] ?? abort(404);

        // BinaryFileResponse answers Range requests, which seeking needs.
        $response = response()->file(DemoTracks::path($track['file']), [
            'Content-Type' => 'audio/flac',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
        ]);
        $response->headers->set('Content-Disposition', 'inline');

        return $response;
    }
}
