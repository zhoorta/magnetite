<?php

namespace App\Console\Commands;

use App\Jobs\IdentifyAlbums;
use App\Models\User;
use App\Services\AlbumIdentifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('magnetite:identify {username? : Only this user (everyone when left out)} {--retry : Also look again at folders that were dismissed or found nothing online} {--queue : Hand the search to the queue worker instead of running it here}')]
#[Description('Suggest albums for folders of tracks without an album tag (folder names, then MusicBrainz by track count and length)')]
class IdentifyAlbumsCommand extends Command
{
    public function handle(AlbumIdentifier $identifier): int
    {
        $name = $this->argument('username');
        $users = User::query()->when($name, fn ($q) => $q->where('username', mb_strtolower(trim($name))))->orderBy('username')->get();

        if ($users->isEmpty()) {
            $this->error($name ? "No such user: {$name}." : 'There are no users yet. Create one with magnetite:user.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            if ($this->option('queue')) {
                IdentifyAlbums::request($user, (bool) $this->option('retry'));
                $this->info("Album search queued for {$user->username}. A queue worker has to be running.");

                continue;
            }

            $folders = $identifier->pending($user, (bool) $this->option('retry'));
            $this->info("{$user->username}: {$folders->count()} folder(s) without an album tag to look at.");

            foreach ($folders as $key => $tracks) {
                $best = $identifier->suggest($user, $key, $tracks)->candidates[0];
                $this->line(sprintf('  %s → %s — %s (%s)', $key, $best['artist'] ?? '?', $best['title'],
                    $best['source'] === 'musicbrainz' ? "MusicBrainz, {$best['matched']}/{$tracks->count()} tracks fit" : 'from the names'));
            }
        }

        return self::SUCCESS;
    }
}
