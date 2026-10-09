<?php

namespace App\Jobs;

use App\Models\Track;
use App\Models\User;
use App\Services\TrackAnalyzer;
use App\Support\AnalysisStatus;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Measures the tracks of one user that have no (current) analysis yet, one after the other; the same work as `magnetite:analyze`.
 * With an album or a track the run is limited to that (the "Analyse" entries of the library menus).
 */
class AnalyzeTracks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $maxExceptions = 1;

    public function __construct(public int $userId, public ?int $albumId = null, public ?int $trackId = null) {}

    /** Ask for an analysis. One already waiting in the queue will see every track, so nothing is added. */
    public static function request(User $user, ?int $albumId = null, ?int $trackId = null): void
    {
        // A waiting run over everything covers a limited one too; a limited run waiting would not cover anything else.
        if (AnalysisStatus::pending($user->id) && config('queue.default') !== 'sync' && $albumId === null && $trackId === null) {
            return;
        }

        AnalysisStatus::queued($user->id);
        static::dispatch($user->id, $albumId, $trackId);
    }

    /** Tracks never measured, or measured by an older version of the measurements. */
    public static function missing(User $user, ?int $albumId = null, ?int $trackId = null)
    {
        return Track::query()->where('user_id', $user->id)
            ->when($albumId !== null, fn ($q) => $q->where('album_id', $albumId))
            ->when($trackId !== null, fn ($q) => $q->whereKey($trackId))
            ->where(fn ($q) => $q->whereNull('analysis_version')->orWhere('analysis_version', '<', TrackAnalyzer::VERSION));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("magnetite-analyze-{$this->userId}"))->releaseAfter(15)->expireAfter(6 * 3600)];
    }

    /** A job that finds the lock taken is released and tried again; without this it would fail on the first release (one attempt by default). */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(TrackAnalyzer $analyzer): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }

        $tracks = static::missing($user, $this->albumId, $this->trackId)->with('user')->orderBy('id')->get();
        AnalysisStatus::started($user->id, $tracks->count());

        $done = $failed = 0;
        foreach ($tracks as $track) {
            AnalysisStatus::progress($user->id, $done + $failed, $failed, $track->title ?: basename($track->path));

            try {
                $analyzer->analyze($track);
                $done++;
            } catch (Throwable $e) {
                // One broken file must not stop the rest.
                Log::warning('Analysis failed for a file', ['path' => $track->path, 'error' => $e->getMessage()]);
                $failed++;
            }

        }

        AnalysisStatus::progress($user->id, $done + $failed, $failed);

        AnalysisStatus::finished($user->id);
    }

    public function failed(Throwable $e): void
    {
        AnalysisStatus::finished($this->userId);
    }
}
