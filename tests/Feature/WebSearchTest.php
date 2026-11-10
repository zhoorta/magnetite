<?php

use App\Models\Album;
use App\Models\SharedItem;
use App\Models\Track;
use App\Models\User;

beforeEach(function () {
    $this->alice = User::factory()->create(['username' => 'alice']);
    $this->bob = User::factory()->create(['username' => 'bob']);
});

function hitTrack(User $user, string $title, ?string $artist = null, ?Album $album = null, ?int $dr = null): Track
{
    return Track::create(['user_id' => $user->id, 'album_id' => $album?->id, 'path' => 'f/'.uniqid().'.mp3', 'title' => $title, 'artist' => $artist, 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1, 'dr' => $dr]);
}

it('needs a signed-in user', function () {
    $this->getJson(route('search', ['q' => 'bowie']))->assertUnauthorized();
});

it('wants two letters or more', function () {
    $this->actingAs($this->alice)->getJson(route('search', ['q' => 'b']))->assertUnprocessable();
    $this->getJson(route('search'))->assertUnprocessable();
});

it('finds your own music, in the shape the player plays', function () {
    $album = Album::create(['user_id' => $this->alice->id, 'title' => 'Heroes', 'artist' => 'David Bowie', 'year' => 1977]);
    $track = hitTrack($this->alice, 'Sons of the Silent Age', 'David Bowie', $album, 12);

    $found = $this->actingAs($this->alice)->getJson(route('search', ['q' => 'silent age']))->assertOk()->json();

    expect($found['tracks'])->toHaveCount(1)
        ->and($found['tracks'][0])->toMatchArray(['id' => $track->id, 'title' => 'Sons of the Silent Age', 'album' => 'Heroes', 'year' => 1977, 'mine' => true, 'dr' => 12])
        ->and($found['tracks'][0]['url'])->toBe(route('stream', $track))
        ->and($found['more'])->toBe(['albums' => false, 'tracks' => false]);

    $albums = $this->getJson(route('search', ['q' => 'bowie heroes']))->json('albums');
    expect($albums)->toHaveCount(1)->and($albums[0])->toMatchArray(['title' => 'Heroes', 'mine' => true, 'tracks' => 1]);
});

it('never shows somebody else\'s private music, and shows what they share without their measurements', function () {
    $private = hitTrack($this->bob, 'Private Song', 'Bob', null, 7);
    $shared = hitTrack($this->bob, 'Shared Song', 'Bob', null, 7);
    SharedItem::create(['user_id' => $this->bob->id, 'track_id' => $shared->id]);

    $titles = collect($this->actingAs($this->alice)->getJson(route('search', ['q' => 'song']))->assertOk()->json('tracks'));

    expect($titles->pluck('title')->all())->toBe(['Shared Song'])
        ->and($titles[0]['mine'])->toBeFalse()->and($titles[0]['dr'])->toBeNull();
    expect($private->fresh())->not->toBeNull();
});

it('takes the filters of the terminal client', function () {
    hitTrack($this->alice, 'Loud One', 'Band', null, 5);
    hitTrack($this->alice, 'Loud Two', 'Band', null, 12);

    $titles = fn (string $q) => collect($this->actingAs($this->alice)->getJson(route('search', ['q' => $q]))->json('tracks'))->pluck('title')->all();

    expect($titles('loud dr:<8'))->toBe(['Loud One'])->and($titles('loud dr:>10'))->toBe(['Loud Two']);
});
