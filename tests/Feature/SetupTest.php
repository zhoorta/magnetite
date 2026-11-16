<?php

use App\Models\User;

it('sends the start, sign-in and library pages to the set-up form while there is no user', function () {
    foreach (['/', '/login', '/library'] as $path) {
        $this->get($path)->assertRedirect(route('setup'));
    }

    $this->get(route('setup'))->assertOk()->assertSee('becomes the administrator')->assertDontSee('Set-up token');
});

it('creates the administrator, signs them in and closes the page', function () {
    $this->post(route('setup.store'), ['username' => 'Jose', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'])
        ->assertRedirect(route('library'));

    $user = User::where('username', 'jose')->sole();
    expect($user->is_admin)->toBeTrue();
    $this->assertAuthenticatedAs($user);

    $this->get(route('setup'))->assertNotFound();
    $this->post(route('setup.store'), ['username' => 'eve', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'])->assertNotFound();
    expect(User::count())->toBe(1);
});

it('checks the password rules and the confirmation', function () {
    $this->post(route('setup.store'), ['username' => 'jose', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    $this->post(route('setup.store'), ['username' => 'jose', 'password' => 'long-enough', 'password_confirmation' => 'other-thing'])->assertSessionHasErrors('password');
    $this->post(route('setup.store'), ['username' => '../x', 'password' => 'long-enough', 'password_confirmation' => 'long-enough'])->assertSessionHasErrors('username');
    expect(User::count())->toBe(0);
});

it('asks for the token only when one is configured', function () {
    config(['magnetite.setup_token' => 'letmein']);

    $this->get(route('setup'))->assertSee('Set-up token');

    $this->post(route('setup.store'), ['username' => 'jose', 'password' => 'long-enough', 'password_confirmation' => 'long-enough', 'setup_token' => 'nope'])
        ->assertSessionHasErrors('setup_token');
    expect(User::count())->toBe(0);

    $this->post(route('setup.store'), ['username' => 'jose', 'password' => 'long-enough', 'password_confirmation' => 'long-enough', 'setup_token' => 'letmein'])
        ->assertRedirect(route('library'));
    expect(User::count())->toBe(1);
});

it('leaves the normal pages alone once a user exists', function () {
    User::factory()->create();

    $this->get('/')->assertOk();
    $this->get(route('login'))->assertOk();
});

it('says where to set up when there is no user', function () {
    $this->artisan('magnetite:setup-hint')->expectsOutputToContain('/setup')->assertSuccessful();

    User::factory()->create();
    $this->artisan('magnetite:setup-hint')->doesntExpectOutputToContain('/setup')->assertSuccessful();
});
