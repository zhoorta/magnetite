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

/** Measures the tracks of one user that have no (current) analysis yet, one after the other; the same work as `magnetite:analyze`. */
class AnalyzeTracks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    public int $maxExceptions = 1;

    public function __construct(public int $userId) {}

    /** Ask for an analysis. One already waiting in the queue will see every track, so nothing is added. */
    public static function request(User $user): void
    {
        if (AnalysisStatus::pending($user->id) && config('queue.default') !== 'sync') {
            return;
        }

        AnalysisStatus::queued($user->id);
        static::dispatch($user->id);
    }

    /** Tracks never measured, or measured by an older version of the measurements. */
    public static function missing(User $user)
    {
        return Track::query()->where('user_id', $user->id)
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

        $tracks = static::missing($user)->with('user')->orderBy('id')->get();
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
