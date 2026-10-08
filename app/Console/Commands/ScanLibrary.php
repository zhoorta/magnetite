<?php

namespace App\Console\Commands;

use App\Services\LibraryScanner;
use App\Support\TrackLibrary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('magnetite:scan {--force : Re-read every file, not just new or changed ones}')]
#[Description('Scan the music folder and sync tracks, albums and covers')]
class ScanLibrary extends Command
{
    public function handle(LibraryScanner $scanner): int
    {
        if (! is_dir(TrackLibrary::path())) {
            $this->error('Music folder not found: '.TrackLibrary::path());

            return self::FAILURE;
        }

        $this->info('Scanning '.TrackLibrary::path());

        $counts = $scanner->scan((bool) $this->option('force'), fn (string $path) => $this->line("  {$path}", verbosity: 'v'));

        $this->table(array_keys($counts), [array_values($counts)]);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
