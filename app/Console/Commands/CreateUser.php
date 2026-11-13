<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('magnetite:user {username : Name to sign in with} {--password= : Password (asked for when left out, which keeps it out of the shell history)} {--generate : Make a random password and print it once} {--if-missing : Do nothing, successfully, when the user already exists (for set-up scripts)} {--reset : Change the password of an existing user} {--admin : Make the user an administrator}')]
#[Description('Create a user, or reset the password of an existing one')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $username = mb_strtolower(trim((string) $this->argument('username')));
        $existing = User::where('username', $username)->first();

        if ($existing && $this->option('if-missing')) {
            $this->line("User '{$username}' exists already.");

            return self::SUCCESS;
        }

        if ($existing && $this->option('admin') && ! $this->option('reset') && $this->option('password') === null) {
            $existing->forceFill(['is_admin' => true])->save();
            $this->info("'{$username}' is an administrator now.");

            return self::SUCCESS;
        }

        if ($existing && ! $this->option('reset')) {
            $this->error("User '{$username}' already exists. Use --reset to change the password.");

            return self::FAILURE;
        }

        if (! $existing && $this->option('reset')) {
            $this->error("User '{$username}' does not exist.");

            return self::FAILURE;
        }

        $generated = $this->option('generate') && $this->option('password') === null;
        $password = $generated ? Str::password(20, symbols: false) : ($this->option('password') ?? $this->askForPassword());

        $validator = Validator::make(
            ['username' => $username, 'password' => $password],
            ['username' => ['required', 'string', 'max:64', 'regex:/^[\p{L}\p{N}][\p{L}\p{N}._-]*$/u'], 'password' => ['required', 'string', 'min:8']],
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
            $generated && $this->line("Password: {$password}");

            return self::SUCCESS;
        }

        $user = new User(['name' => $username, 'username' => $username, 'password' => $password]);
        $user->is_admin = (bool) $this->option('admin');
        $user->save();
        $this->info("User '{$username}' created.");

        if ($generated) {
            $this->line("Password: {$password}");
            $this->line('It is shown only now. Change it with: php artisan magnetite:user '.$username.' --reset');
        }

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
