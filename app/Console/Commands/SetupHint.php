<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('magnetite:setup-hint')]
#[Description('Say where to create the first user when there is none yet (used by the container start-up)')]
class SetupHint extends Command
{
    public function handle(): int
    {
        if (! User::query()->exists()) {
            $this->line('No users yet: open '.rtrim((string) config('app.url'), '/').'/setup to create the administrator.');
        }

        return self::SUCCESS;
    }
}
