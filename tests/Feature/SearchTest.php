<?php

use App\Models\Album;
use App\Models\SharedItem;
use App\Models\Track;
use App\Models\User;
use App\Support\AlbumKey;

beforeEach(function () {
    $this->alice = User::factory()->create(['username' => 'alice']);
    $this->bob = User::factory()->create(['username' => 'bob']);
});

function searchFor(User $user, string $q): array
{
    return test()->getJson('/api/v1/search?q='.urlencode($q), ['Authorization' => 'Bearer '.$user->createToken('t', ['*'])->plainTextToken])->assertOk()->json();
}

function searchTrack(User $user, string $title, ?string $artist = null, ?Album $album = null, ?string $path = null): Track
{
    return Track::create(['user_id' => $user->id, 'album_id' => $album?->id, 'path' => $path ?? 'f/'.uniqid().'.mp3', 'title' => $title, 'artist' => $artist, 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1]);
}

it('folds case and accents', function () {
    expect(AlbumKey::fold('  Jáfumega  Ç'))->toBe('jafumega c')
        ->and(AlbumKey::searchKey('Heróis do Mar', 'Rádio Macau'))->toBe('herois do mar radio macau');
});

it('finds a track when the words are spread over its title and its artist', function () {
    searchTrack($this->alice, 'Heroes', 'David Bowie');
    searchTrack($this->alice, 'Heroes', 'Wallflowers');
    searchTrack($this->alice, 'Starman', 'David Bowie');

    $titles = collect(searchFor($this->alice, 'bowie heroes')['tracks']);

    expect($titles)->toHaveCount(1)->and($titles[0]['artist'])->toBe('David Bowie')->and($titles[0]['title'])->toBe('Heroes');
});

it('finds a track by the name of its album and an album by its artist and title together', function () {
    $album = Album::create(['user_id' => $this->alice->id, 'title' => 'Recados', 'artist' => 'Jáfumega']);
    searchTrack($this->alice, 'Primeira', 'Jáfumega', $album);
    searchTrack($this->alice, 'Recados Outros', 'Someone Else');

    $found = searchFor($this->alice, 'recados primeira');
    expect(collect($found['tracks'])->pluck('title')->all())->toBe(['Primeira']);

    $albums = searchFor($this->alice, 'jafumega recados')['albums'];
    expect($albums)->toHaveCount(1)->and($albums[0]['title'])->toBe('Recados');
});

it('does not care about accents or case, in the text or in the data', function () {
    searchTrack($this->alice, 'Coração', 'Amália');

    expect(searchFor($this->alice, 'CORACAO amalia')['tracks'])->toHaveCount(1)
        ->and(searchFor($this->alice, 'coração AMÁLIA')['tracks'])->toHaveCount(1);
});

it('ranks the exact title, then a title that starts with the text, then the rest, and your own before shared', function () {
    SharedItem::create(['user_id' => $this->bob->id, 'track_id' => searchTrack($this->bob, 'Moon', 'Shared Artist')->id]);
    searchTrack($this->alice, 'Blue Moon Rising');
    searchTrack($this->alice, 'Moonlight');
    searchTrack($this->alice, 'Something', 'The Moon Band');
    searchTrack($this->alice, 'Moon');

    $titles = collect(searchFor($this->alice, 'moon')['tracks'])->map(fn ($t) => $t['title'].'|'.$t['artist'])->all();

    expect($titles)->toBe(['Moon|', 'Moon|Shared Artist', 'Moonlight|', 'Blue Moon Rising|', 'Something|The Moon Band']);
});

it('keeps searching only what the person may use', function () {
    searchTrack($this->bob, 'Private Heroes', 'Bowie');
    searchTrack($this->alice, 'Heroes', 'Bowie');

    expect(collect(searchFor($this->alice, 'bowie heroes')['tracks'])->pluck('title')->all())->toBe(['Heroes']);
});

it('keeps the search text up to date when a title or an artist is edited', function () {
    $track = searchTrack($this->alice, 'Old Name', 'Old Artist');
    $track->update(['title' => 'Nómada', 'artist' => 'New Band']);

    expect(searchFor($this->alice, 'old name')['tracks'])->toHaveCount(0)
        ->and(searchFor($this->alice, 'nomada band')['tracks'])->toHaveCount(1);

    $album = Album::create(['user_id' => $this->alice->id, 'title' => 'First', 'artist' => 'A']);
    $album->update(['title' => 'Última', 'artist' => 'B']);
    expect(searchFor($this->alice, 'ultima')['albums'])->toHaveCount(1);
});

it('ignores words beyond six and finds nothing for a text made of spaces', function () {
    searchTrack($this->alice, 'one two three four five six', null);

    expect(searchFor($this->alice, 'one two three four five six seven eight')['tracks'])->toHaveCount(1);
    $this->getJson('/api/v1/search?q=%20%20', ['Authorization' => 'Bearer '.$this->alice->createToken('t', ['*'])->plainTextToken])->assertUnprocessable();
});
