<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeTracks;
use App\Services\TrackAnalyzer;
use App\Support\AnalysisStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Starts and follows the analysis of the signed-in user's own tracks. */
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

        AnalyzeTracks::request($request->user());

        return response()->json(AnalysisStatus::get($request->user()->id), 202);
    }
}
