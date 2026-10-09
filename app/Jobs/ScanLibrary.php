<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\LibraryScanner;
use App\Support\ScanStatus;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Syncs one user's tracks with their folder in the background. */
class ScanLibrary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;

    // retryUntil() lets a job retry for hours; a real error should fail it at once instead.
    public int $maxExceptions = 1;

    public function __construct(public int $userId, public bool $force = false) {}

    /**
     * Ask for a scan. If one is already waiting in the queue it will see every file written so far, so nothing is added;
     * a scan that is already running may have passed the new files, so a second one is queued behind it.
     */
    public static function request(User $user, bool $force = false): void
    {
        // With the sync driver the job runs inside dispatch(), so a leftover flag is from a request that died, never a waiting job.
        if (ScanStatus::pending($user->id) && ! $force && config('queue.default') !== 'sync') {
            return;
        }

        ScanStatus::queued($user->id);
        static::dispatch($user->id, $force);
    }

    /** One scan at a time per user; a second job waits and retries instead of running next to the first. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("magnetite-library-scan-{$this->userId}"))->releaseAfter(15)->expireAfter(6 * 3600)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(LibraryScanner $scanner): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return; // deleted while the job waited
        }

        ScanStatus::started($user->id);

        $processed = 0;
        $lastWrite = 0.0;

        $counts = $scanner->scan($user, $this->force, function () use ($user, &$processed, &$lastWrite) {
            $processed++;

            // The cache may be the database: do not write on every file.
            if (microtime(true) - $lastWrite >= 1.0) {
                ScanStatus::progress($user->id, $processed);
                $lastWrite = microtime(true);
            }
        });

        ScanStatus::progress($user->id, $processed);
        ScanStatus::finished($user->id, $counts);
    }

    public function failed(Throwable $e): void
    {
        ScanStatus::failed($this->userId, $e->getMessage());
    }
}
