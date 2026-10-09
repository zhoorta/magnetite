<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FerriteException;
use App\Services\FerriteStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('magnetite:storage {username : The user whose library this is} {--ferrite= : Address of the Ferrite to read the library from, e.g. https://files.example.com} {--token= : Ferrite API token (asked for when left out, which keeps it out of the shell history)} {--local : Go back to the local folder} {--test : Only check the connection}')]
#[Description('Choose where a user\'s library lives (local folder or a Ferrite folder) and check the connection')]
class StorageCommand extends Command
{
    public function handle(): int
    {
        $user = User::where('username', mb_strtolower(trim((string) $this->argument('username'))))->first();

        if ($user === null) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        if ($this->option('local')) {
            $user->forceFill(['store' => null])->save();
            $this->info("'{$user->username}' reads the local folder.");

            return self::SUCCESS;
        }

        if ($this->option('ferrite') !== null) {
            $url = rtrim(trim((string) $this->option('ferrite')), '/');

            if (! preg_match('#^https?://[^\s/]+#i', $url)) {
                $this->error('The address must start with https:// (or http:// for a local test).');

                return self::FAILURE;
            }

            $token = (string) ($this->option('token') ?? $this->secret('Ferrite API token'));
            if ($token === '') {
                $this->error('A token is needed.');

                return self::FAILURE;
            }

            $user->forceFill(['store' => ['driver' => 'ferrite', 'url' => $url, 'token' => $token]])->save();
            $this->info("'{$user->username}' reads the library from Ferrite.");
        }

        $store = $user->store();

        if (! $store instanceof FerriteStore) {
            $this->line("'{$user->username}' reads the local folder: ".$user->libraryPath());

            return self::SUCCESS;
        }

        try {
            $root = $store->root();
        } catch (FerriteException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $quota = $root['quota'] ?? [];
        $this->table(['Folder', 'Access', 'Expires', 'Quota used'], [[
            $root['name'] ?? '?',
            $root['access'] ?? '?',
            $root['expires_at'] ?? 'never',
            isset($quota['limit']) ? "{$quota['used']} / {$quota['limit']} bytes" : ($quota['used'] ?? '?').' bytes',
        ]]);

        return self::SUCCESS;
    }
}
