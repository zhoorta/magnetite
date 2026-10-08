<?php

use App\Models\Album;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(fn () => $this->user = User::factory()->create(['username' => 'jose', 'password' => 'secret-pass']));

it('shows the start page to everyone', function () {
    $this->get('/')->assertOk()->assertSee('Open the library');
});

it('sends guests to the sign-in page for the library, the files and the upload', function () {
    $album = Album::create(['title' => 'A', 'artist' => 'B']);

    $this->get(route('library'))->assertRedirect(route('login'));
    $this->get(route('upload'))->assertRedirect(route('login'));
    $this->get(route('stream', 1))->assertRedirect(route('login'));
    $this->get(route('cover', $album))->assertRedirect(route('login'));
    $this->postJson(route('upload.store'))->assertUnauthorized();
});

it('shows a sign-in page with just the logo and two fields', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('/logo.svg', false)
        ->assertSee('name="username"', false)
        ->assertSee('name="password"', false)
        ->assertDontSee('Library');
});

it('signs in with the username and goes where the visitor was heading', function () {
    $this->get(route('upload'));

    $this->post(route('login.store'), ['username' => ' Jose ', 'password' => 'secret-pass'])
        ->assertRedirect(route('upload'));

    $this->assertAuthenticatedAs($this->user);
});

it('goes to the library after signing in directly', function () {
    $this->post(route('login.store'), ['username' => 'jose', 'password' => 'secret-pass'])
        ->assertRedirect(route('library'));
});

it('refuses a wrong password without saying which part was wrong', function () {
    $this->from(route('login'))->post(route('login.store'), ['username' => 'jose', 'password' => 'nope'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['username' => 'Wrong username or password.']);

    $this->post(route('login.store'), ['username' => 'nobody', 'password' => 'nope'])
        ->assertSessionHasErrors(['username' => 'Wrong username or password.']);

    $this->assertGuest();
});

it('locks a username out for a while after five wrong attempts', function () {
    RateLimiter::clear('jose|127.0.0.1');

    foreach (range(1, 5) as $_) {
        $this->post(route('login.store'), ['username' => 'jose', 'password' => 'nope']);
    }

    $this->post(route('login.store'), ['username' => 'jose', 'password' => 'secret-pass'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('signs out', function () {
    $this->actingAs($this->user)->post(route('logout'))->assertRedirect(route('welcome'));

    $this->assertGuest();
});

it('keeps a signed-in user away from the sign-in page', function () {
    $this->actingAs($this->user)->get(route('login'))->assertRedirect(route('library'));
});
