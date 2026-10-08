<?php

namespace App\Jobs;

use App\Services\LibraryScanner;
use App\Support\ScanStatus;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Syncs the tracks table with the music folder in the background. */
class ScanLibrary implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $timeout = 0;

    // retryUntil() lets a job retry for hours; a real error should fail it at once instead.
    public int $maxExceptions = 1;

    public function __construct(public bool $force = false) {}

    /**
     * Ask for a scan. If one is already waiting in the queue it will see every file written so far, so nothing is added;
     * a scan that is already running may have passed the new files, so a second one is queued behind it.
     */
    public static function request(bool $force = false): void
    {
        if (ScanStatus::pending() && ! $force) {
            return;
        }

        ScanStatus::queued();
        static::dispatch($force);
    }

    /** One scan at a time; a second job waits and retries instead of running next to the first. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('magnetite-library-scan'))->releaseAfter(15)->expireAfter(6 * 3600)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(LibraryScanner $scanner): void
    {
        ScanStatus::started();

        $processed = 0;
        $lastWrite = 0.0;

        $counts = $scanner->scan($this->force, function () use (&$processed, &$lastWrite) {
            $processed++;

            // The cache may be the database: do not write on every file.
            if (microtime(true) - $lastWrite >= 1.0) {
                ScanStatus::progress($processed);
                $lastWrite = microtime(true);
            }
        });

        ScanStatus::progress($processed);
        ScanStatus::finished($counts);
    }

    public function failed(Throwable $e): void
    {
        ScanStatus::failed($e->getMessage());
    }
}
