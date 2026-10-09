<?php

namespace App\Http\Controllers;

use App\Jobs\PrefetchTrack;
use App\Models\Track;
use App\Services\FerriteStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * The player asks for the next track ahead of time. Answers 202 at once and never says whether anything was fetched;
 * an invisible track is a 404, like for /stream.
 */
class PrefetchController extends Controller
{
    public function __invoke(Request $request, Track $track): Response
    {
        abort_unless($request->user()->can('view', $track), 404);

        // The sync queue would download inside this request and stall the server (and the audio playing from it).
        $queued = config('queue.default') !== 'sync'
            && $track->user->store() instanceof FerriteStore
            && Cache::add("magnetite.prefetch.{$track->id}", true, 120);

        if ($queued) {
            PrefetchTrack::dispatch($track->id);
        }

        return response()->noContent(202);
    }
}
