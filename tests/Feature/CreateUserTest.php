<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
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

it('makes a random password with --generate, prints it once and stores only its hash', function () {
    $this->artisan('magnetite:user', ['username' => 'ana', '--generate' => true, '--admin' => true])
        ->expectsOutputToContain("User 'ana' created.")
        ->assertSuccessful();

    $user = User::where('username', 'ana')->sole();
    expect($user->is_admin)->toBeTrue()->and(strlen($user->password))->toBeGreaterThan(40);

    // The printed password is the one that was stored.
    Artisan::call('magnetite:user', ['username' => 'bo', '--generate' => true]);
    preg_match('/Password: (\S+)/', Artisan::output(), $m);
    expect($m[1] ?? '')->toHaveLength(20)->and(Hash::check($m[1], User::where('username', 'bo')->sole()->password))->toBeTrue();
});

it('does nothing, successfully, with --if-missing when the user exists, and keeps the password', function () {
    $this->artisan('magnetite:user', ['username' => 'ana', '--password' => 'long-enough'])->assertSuccessful();

    $this->artisan('magnetite:user', ['username' => 'ana', '--password' => 'another-one', '--if-missing' => true])
        ->expectsOutputToContain('exists already')
        ->assertSuccessful();

    expect(Hash::check('long-enough', User::where('username', 'ana')->sole()->password))->toBeTrue();

    $this->artisan('magnetite:user', ['username' => 'bo', '--password' => 'long-enough', '--if-missing' => true])->assertSuccessful();
    expect(User::where('username', 'bo')->exists())->toBeTrue();
});
