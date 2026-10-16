<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeTracks;
use App\Models\Album;
use App\Models\Track;
use App\Services\TrackAnalyzer;
use App\Support\AnalysisStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Starts and follows the analysis of the signed-in user's own tracks: all of them, or one album's or one track's (`album_id` / `track_id`). */
class AnalysisController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(AnalysisStatus::get($request->user()->id));
    }

    public function store(Request $request, TrackAnalyzer $analyzer): JsonResponse
    {
        if ($missing = $analyzer->missing()) {
            return response()->json(['message' => 'The server lacks: '.implode(', ', $missing).'.'], 422);
        }

        // The sync driver would run the whole analysis inside this request: minutes of nothing to poll, and a single-threaded dev server that answers nobody else meanwhile.
        if (config('queue.default') === 'sync') {
            return response()->json(['message' => 'The analysis needs a queue worker. Set QUEUE_CONNECTION=database and run `composer dev` (or `php artisan queue:work`).'], 422);
        }

        $data = $request->validate(['album_id' => ['nullable', 'integer'], 'track_id' => ['nullable', 'integer']]);
        $albumId = $data['album_id'] ?? null;
        $trackId = $data['track_id'] ?? null;

        // Somebody else's album or track is answered like one that does not exist.
        $albumId === null || Album::where('user_id', $request->user()->id)->findOrFail($albumId);
        $trackId === null || Track::where('user_id', $request->user()->id)->findOrFail($trackId);

        // Requests queue behind a run that is going; the jobs never overlap (see AnalyzeTracks::middleware).
        AnalyzeTracks::request($request->user(), $albumId, $trackId);

        return response()->json(AnalysisStatus::get($request->user()->id), 202);
    }
}
