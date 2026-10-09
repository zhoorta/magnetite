<?php

namespace App\Jobs;

use App\Models\Track;
use App\Services\FerriteException;
use App\Services\FerriteStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Fetches the next track of a Ferrite library into the cache while the current one plays, so it starts at once. */
class PrefetchTrack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $trackId) {}

    public function handle(): void
    {
        $track = Track::with('user')->find($this->trackId);
        $store = $track?->user->store();

        // A local library has nothing to fetch; a track removed since the request is gone.
        if (! $store instanceof FerriteStore) {
            return;
        }

        try {
            $store->prefetch($track);
        } catch (FerriteException $e) {
            // Harmless: the track simply streams through the proxy when its turn comes.
            Log::info('Prefetch failed', ['track' => $track->id, 'error' => $e->getMessage()]);
        }
    }
}
