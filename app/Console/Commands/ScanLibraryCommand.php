<?php

namespace App\Console\Commands;

use App\Jobs\ScanLibrary;
use App\Services\LibraryScanner;
use App\Support\TrackLibrary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('magnetite:scan {--force : Re-read every file, not just new or changed ones} {--queue : Hand the scan to the queue worker instead of running it here}')]
#[Description('Scan the music folder and sync tracks, albums and covers')]
class ScanLibraryCommand extends Command
{
    public function handle(LibraryScanner $scanner): int
    {
        if (! is_dir(TrackLibrary::path())) {
            $this->error('Music folder not found: '.TrackLibrary::path());

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            ScanLibrary::request((bool) $this->option('force'));
            $this->info('Scan queued. A queue worker (php artisan queue:work) has to be running.');

            return self::SUCCESS;
        }

        $this->info('Scanning '.TrackLibrary::path());

        $counts = $scanner->scan((bool) $this->option('force'), fn (string $path) => $this->line("  {$path}", verbosity: 'v'));

        $this->table(array_keys($counts), [array_values($counts)]);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
