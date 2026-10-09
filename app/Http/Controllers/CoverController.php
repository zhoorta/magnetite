<?php

namespace App\Http\Controllers;

use App\Models\Album;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CoverController extends Controller
{
    public function __invoke(Request $request, Album $album): BinaryFileResponse
    {
        // Covers are named by content hash and may be shared by users, so access is decided here by the album.
        abort_unless($request->user()->can('view', $album), 404);

        // Covers are always re-encoded JPEGs named by content hash, so they never change and can be cached hard.
        abort_if($album->cover === null || ! preg_match('/^[a-f0-9]{40}\.jpg$/', $album->cover), 404);

        $path = Storage::disk('local')->path("covers/{$album->cover}");
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
