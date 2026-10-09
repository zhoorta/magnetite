<?php

namespace App\Console\Commands;

use App\Jobs\FindCovers;
use App\Models\User;
use App\Services\CoverFinder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

#[Signature('magnetite:covers {username? : Only this user (everyone when left out)} {--retry : Also search albums that were searched before without a match} {--queue : Hand the search to the queue worker instead of running it here}')]
#[Description('Download missing album covers (MusicBrainz / Cover Art Archive, then iTunes)')]
class FindCoversCommand extends Command
{
    public function handle(CoverFinder $finder): int
    {
        $name = $this->argument('username');
        $users = User::query()->when($name, fn ($q) => $q->where('username', mb_strtolower(trim($name))))->orderBy('username')->get();

        if ($users->isEmpty()) {
            $this->error($name ? "No such user: {$name}." : 'There are no users yet. Create one with magnetite:user.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            if ($this->option('queue')) {
                FindCovers::request($user, (bool) $this->option('retry'));
                $this->info("Cover search queued for {$user->username}. A queue worker has to be running.");

                continue;
            }

            $albums = FindCovers::missing($user, (bool) $this->option('retry'))->with('tracks')->get();
            $this->info("{$user->username}: {$albums->count()} album(s) without a cover.");

            foreach ($albums as $i => $album) {
                if ($i > 0) {
                    Sleep::for(1)->second();
                }

                $source = $finder->fill($album);
                $this->line(sprintf('  %s — %s: %s', $album->displayArtist ?? '?', $album->title, $source ?? 'no match'));
            }
        }

        return self::SUCCESS;
    }
}
