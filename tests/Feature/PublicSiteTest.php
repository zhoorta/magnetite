<?php

use App\Models\Album;
use App\Models\Track;
use App\Models\User;

beforeEach(fn () => $this->user = User::factory()->create());

it('shows the public pages by default', function () {
    $this->get('/')->assertOk();
    $this->get(route('guide'))->assertOk();
});

it('sends visitors to the sign-in page when the public site is off', function () {
    config(['magnetite.public_site' => false]);

    foreach (['/', '/library', '/docs', '/docs/install', '/themes'] as $path) {
        $this->get($path)->assertRedirect(route('login'));
    }
    $this->get(route('login'))->assertOk();
});

it('keeps the documentation for signed-in users and sends them from the start page to the library', function () {
    config(['magnetite.public_site' => false]);

    $this->actingAs($this->user)->get('/')->assertRedirect(route('library'));
    $this->actingAs($this->user)->get(route('guide'))->assertOk();
});

it('shows the audio size of each user to administrators only', function () {
    $admin = User::factory()->create(['username' => 'boss', 'is_admin' => true]);
    $this->actingAs($this->user)->get(route('admin.users'))->assertNotFound();
    $this->get(route('admin.users'))->assertNotFound();

    $this->actingAs($admin)->get(route('admin.users'))->assertOk()->assertSee('boss')->assertSee($this->user->username);
});

it('adds up the size of the tracks per user', function () {
    $admin = User::factory()->create(['username' => 'boss', 'is_admin' => true]);
    $album = Album::create(['title' => 'A', 'artist' => 'B', 'user_id' => $this->user->id]);
    foreach ([3 * 1024 ** 2, 2 * 1024 ** 2] as $i => $bytes) {
        $track = new Track(['album_id' => $album->id, 'path' => "a/{$i}.mp3", 'title' => 'x', 'mime' => 'audio/mpeg', 'size' => $bytes, 'mtime' => 1]);
        $track->user_id = $this->user->id;
        $track->save();
    }

    $this->actingAs($admin)->get(route('admin.users'))->assertSee('5 MB')->assertSee('0 KB');
});
