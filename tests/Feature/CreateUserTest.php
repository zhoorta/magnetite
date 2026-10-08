<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a user with a lowercase username and a hashed password', function () {
    $this->artisan('magnetite:user', ['username' => 'Jose', '--password' => 'long-enough'])
        ->expectsOutputToContain("User 'jose' created.")
        ->assertSuccessful();

    $user = User::where('username', 'jose')->sole();
    expect(Hash::check('long-enough', $user->password))->toBeTrue()->and($user->email)->toBeNull();
});

it('asks for the password twice when it is not given', function () {
    $this->artisan('magnetite:user', ['username' => 'ana'])
        ->expectsQuestion('Password (at least 8 characters)', 'long-enough')
        ->expectsQuestion('Repeat the password', 'long-enough')
        ->assertSuccessful();

    expect(User::where('username', 'ana')->exists())->toBeTrue();
});

it('refuses passwords that do not match, are too short, or usernames with odd characters', function () {
    $this->artisan('magnetite:user', ['username' => 'ana'])
        ->expectsQuestion('Password (at least 8 characters)', 'long-enough')
        ->expectsQuestion('Repeat the password', 'different-one')
        ->assertFailed();

    $this->artisan('magnetite:user', ['username' => 'ana', '--password' => 'short'])->assertFailed();
    $this->artisan('magnetite:user', ['username' => 'a b/c', '--password' => 'long-enough'])->assertFailed();

    expect(User::count())->toBe(0);
});

it('does not overwrite an existing user unless asked to reset', function () {
    User::factory()->create(['username' => 'jose', 'password' => 'old-password']);

    $this->artisan('magnetite:user', ['username' => 'jose', '--password' => 'new-password'])->assertFailed();
    expect(Hash::check('old-password', User::where('username', 'jose')->sole()->password))->toBeTrue();

    $this->artisan('magnetite:user', ['username' => 'jose', '--password' => 'new-password', '--reset' => true])->assertSuccessful();
    expect(Hash::check('new-password', User::where('username', 'jose')->sole()->password))->toBeTrue();

    $this->artisan('magnetite:user', ['username' => 'ghost', '--password' => 'new-password', '--reset' => true])->assertFailed();
});
