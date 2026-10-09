<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Track;
use App\Models\User;
use App\Support\TrackLibrary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

#[Signature('magnetite:claim {username : The user who gets the library that was there before users had their own}')]
#[Description('Move the music folder\'s existing contents into one user\'s folder and make them the owner of the unowned rows')]
class ClaimLibrary extends Command
{
    public function handle(): int
    {
        $user = User::where('username', mb_strtolower(trim((string) $this->argument('username'))))->first();
        if ($user === null) {
            $this->error('No such user. Create one with magnetite:user first.');

            return self::FAILURE;
        }

        $root = realpath(TrackLibrary::path());
        if ($root === false) {
            $this->error('Music folder not found: '.TrackLibrary::path());

            return self::FAILURE;
        }

        $target = $user->libraryPath();
        $loose = collect(File::glob($root.'/*'))->reject(fn ($p) => basename($p) === 'users')->values();

        // Rows without an owner are claimed even when no files are left to move (a second run, or an interrupted first one).
        if ($loose->isNotEmpty()) {
            if (File::isDirectory($target) && count(File::glob($target.'/*')) > 0) {
                $this->error("{$target} already has files; refusing to mix two libraries.");

                return self::FAILURE;
            }

            File::ensureDirectoryExists($target);

            foreach ($loose as $path) {
                if (! rename($path, $target.'/'.basename($path))) {
                    $this->error('Could not move '.basename($path).'. Nothing else was changed in the database.');

                    return self::FAILURE;
                }
            }
        }

        $rows = DB::transaction(fn () => Track::whereNull('user_id')->update(['user_id' => $user->id])
            + Album::whereNull('user_id')->update(['user_id' => $user->id]));

        $this->info("Moved {$loose->count()} item(s) into {$target}; {$rows} row(s) now belong to '{$user->username}'.");

        return self::SUCCESS;
    }
}
