<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\User;
use App\Services\AlbumGrouper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('magnetite:albums:number {username : Whose albums} {--album= : Only this album id} {--fix : Write the numbers (without it only the albums that need them are listed)}')]
#[Description('Number the tracks of albums made from untagged files, from their file names, so they play in order and not alphabetically')]
class AlbumsNumberCommand extends Command
{
    public function handle(AlbumGrouper $grouper): int
    {
        $user = User::where('username', mb_strtolower(trim($this->argument('username'))))->first();
        if ($user === null) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        $albums = Album::where('user_id', $user->id)->when($this->option('album'), fn ($q, $id) => $q->whereKey($id))
            ->whereHas('tracks', fn ($q) => $q->whereNull('track_number'))->with('tracks')->get();

        foreach ($albums as $album) {
            $missing = $album->tracks->whereNull('track_number');
            $this->line(sprintf('#%d "%s": %d of %d tracks have no number', $album->id, $album->title, $missing->count(), $album->tracks->count()));

            if ($this->option('fix')) {
                $grouper->number($missing);
                $this->line('    numbered from the file names ('.$album->tracks()->whereNull('track_number')->count().' left without a number).');
            }
        }

        $albums->isEmpty() && $this->info('Every album has its numbers.');
        ! $this->option('fix') && $albums->isNotEmpty() && $this->line('Nothing was changed. Run again with --fix to number them.');

        return self::SUCCESS;
    }
}
