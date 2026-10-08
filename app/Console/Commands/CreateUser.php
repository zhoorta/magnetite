<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('magnetite:user {username : Name to sign in with} {--password= : Password (asked for when left out, which keeps it out of the shell history)} {--reset : Change the password of an existing user}')]
#[Description('Create a user, or reset the password of an existing one')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $username = mb_strtolower(trim((string) $this->argument('username')));
        $existing = User::where('username', $username)->first();

        if ($existing && ! $this->option('reset')) {
            $this->error("User '{$username}' already exists. Use --reset to change the password.");

            return self::FAILURE;
        }

        if (! $existing && $this->option('reset')) {
            $this->error("User '{$username}' does not exist.");

            return self::FAILURE;
        }

        $password = $this->option('password') ?? $this->askForPassword();

        $validator = Validator::make(
            ['username' => $username, 'password' => $password],
            ['username' => ['required', 'string', 'max:64', 'regex:/^[\p{L}\p{N}._-]+$/u'], 'password' => ['required', 'string', 'min:8']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ($existing) {
            $existing->update(['password' => $password]);
            $this->info("Password changed for '{$username}'.");

            return self::SUCCESS;
        }

        User::create(['name' => $username, 'username' => $username, 'password' => $password]);
        $this->info("User '{$username}' created.");

        return self::SUCCESS;
    }

    private function askForPassword(): string
    {
        $password = (string) $this->secret('Password (at least 8 characters)');

        if ($password !== (string) $this->secret('Repeat the password')) {
            $this->error('The passwords do not match.');

            return '';
        }

        return $password;
    }
}
