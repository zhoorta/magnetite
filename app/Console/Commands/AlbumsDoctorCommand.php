<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AlbumDoctor;
use App\Services\AlbumMerger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('magnetite:albums:doctor {username? : Only this user (everyone when left out)} {--fix : Merge the split albums that are certain (same folder, or same artist with numbers that do not clash)} {--all : With --fix, also merge the doubtful ones}')]
#[Description('Find albums that were split in two (same title, same folder or artist) and, with --fix, join them')]
class AlbumsDoctorCommand extends Command
{
    public function handle(AlbumDoctor $doctor, AlbumMerger $merger): int
    {
        $name = $this->argument('username');
        $users = User::query()->when($name, fn ($q) => $q->where('username', mb_strtolower(trim($name))))->orderBy('username')->get();

        if ($users->isEmpty()) {
            $this->error($name ? "No such user: {$name}." : 'There are no users yet. Create one with magnetite:user.');

            return self::FAILURE;
        }

        foreach ($users as $user) {
            $suspects = $doctor->suspects($user);
            $this->info("{$user->username}: ".(count($suspects) ?: 'no').' split album(s) found.');

            foreach ($suspects as $s) {
                $this->line(sprintf('  [%s] "%s" (#%d, %d tracks) into "%s" (#%d, %d tracks): %s', $s['confidence'], $s['merge']->title, $s['merge']->id, $s['merge']->tracks->count(), $s['keep']->title, $s['keep']->id, $s['keep']->tracks->count(), implode('; ', $s['reasons'])));

                if ($this->option('fix') && ($s['confidence'] === 'high' || $this->option('all'))) {
                    $merger->merge($s['merge'], $s['keep']);
                    $this->line('      merged.');
                }
            }

            if (! $this->option('fix') && $suspects) {
                $this->line('  Nothing was changed. Run again with --fix to merge the certain ones (--all for the doubtful ones too).');
            }
        }

        return self::SUCCESS;
    }
}
