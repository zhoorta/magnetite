<?php

namespace App\Console\Commands;

use App\Jobs\ScanLibrary;
use App\Models\User;
use App\Services\LibraryScanner;
use App\Support\TrackLibrary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('magnetite:scan {username? : Scan only this user (everyone when left out)} {--force : Re-read every file, not just new or changed ones} {--queue : Hand the scan to the queue worker instead of running it here}')]
#[Description('Scan the users\' folders and sync tracks, albums and covers')]
class ScanLibraryCommand extends Command
{
    public function handle(LibraryScanner $scanner): int
    {
        if (! is_dir(TrackLibrary::path())) {
            $this->error('Music folder not found: '.TrackLibrary::path());

            return self::FAILURE;
        }

        $name = $this->argument('username');
        $users = User::query()->when($name, fn ($q) => $q->where('username', mb_strtolower(trim($name))))->orderBy('username')->get();

        if ($users->isEmpty()) {
            $this->error($name ? "No such user: {$name}." : 'There are no users yet. Create one with magnetite:user.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($users as $user) {
            if ($this->option('queue')) {
                ScanLibrary::request($user, (bool) $this->option('force'));
                $this->info("Scan queued for {$user->username}. A queue worker (php artisan queue:work) has to be running.");

                continue;
            }

            $this->info("Scanning {$user->username}: ".$user->libraryPath());

            try {
                $counts = $scanner->scan($user, (bool) $this->option('force'), fn (string $path) => $this->line("  {$path}", verbosity: 'v'));
            } catch (Throwable $e) {
                $this->error($e->getMessage());
                $failed = true;

                continue;
            }

            $this->table(array_keys($counts), [array_values($counts)]);
            $failed = $failed || $counts['failed'] > 0;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
