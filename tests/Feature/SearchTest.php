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

it('filters by year, on albums and on the tracks of those albums', function () {
    $old = Album::create(['user_id' => $this->alice->id, 'title' => 'Old Songs', 'artist' => 'Band', 'year' => 1969]);
    $new = Album::create(['user_id' => $this->alice->id, 'title' => 'New Songs', 'artist' => 'Band', 'year' => 1983]);
    searchTrack($this->alice, 'Songs Of Old', 'Band', $old);
    searchTrack($this->alice, 'Songs Of New', 'Band', $new);

    expect(collect(searchFor($this->alice, 'songs year:1983')['albums'])->pluck('title')->all())->toBe(['New Songs'])
        ->and(collect(searchFor($this->alice, 'songs year:1983')['tracks'])->pluck('title')->all())->toBe(['Songs Of New'])
        ->and(collect(searchFor($this->alice, 'songs year:1960..1969')['albums'])->pluck('title')->all())->toBe(['Old Songs'])
        ->and(searchFor($this->alice, 'songs year:..1960')['albums'])->toHaveCount(0)
        ->and(searchFor($this->alice, 'songs year:>1900')['albums'])->toHaveCount(2)
        ->and(searchFor($this->alice, 'songs year:<=1969')['albums'])->toHaveCount(1);
});

it('filters tracks by codec and by dynamic range, and then answers with tracks only', function () {
    searchTrack($this->alice, 'Loud One', 'X')->update(['codec' => 'flac', 'dr' => 5]);
    searchTrack($this->alice, 'Quiet One', 'X')->update(['codec' => 'FLAC', 'dr' => 14]);
    searchTrack($this->alice, 'Lossy One', 'X')->update(['codec' => 'mp3']);
    Album::create(['user_id' => $this->alice->id, 'title' => 'One Album', 'artist' => 'X']);

    $titles = fn (string $q) => collect(searchFor($this->alice, $q)['tracks'])->pluck('title')->sort()->values()->all();

    expect($titles('one codec:flac'))->toBe(['Loud One', 'Quiet One'])
        ->and($titles('one codec:mp3,alac'))->toBe(['Lossy One'])
        ->and($titles('one dr:<8'))->toBe(['Loud One'])
        ->and($titles('one dr:>=12'))->toBe(['Quiet One'])
        ->and($titles('one dr:5..14 codec:flac'))->toBe(['Loud One', 'Quiet One'])
        ->and(searchFor($this->alice, 'one codec:flac')['albums'])->toHaveCount(0)
        ->and(searchFor($this->alice, 'one')['albums'])->toHaveCount(1);
});

it('does not let a dr filter see other people\'s measurements', function () {
    $theirs = searchTrack($this->bob, 'Shared One', 'X');
    $theirs->update(['dr' => 5]);
    SharedItem::create(['user_id' => $this->bob->id, 'track_id' => $theirs->id]);

    expect(searchFor($this->alice, 'one dr:<8')['tracks'])->toHaveCount(0)
        ->and(searchFor($this->alice, 'one')['tracks'])->toHaveCount(1);
});

it('filters by artist and by album, in words and in quotes', function () {
    $album = Album::create(['user_id' => $this->alice->id, 'title' => 'Heroes', 'artist' => 'David Bowie']);
    searchTrack($this->alice, 'Heroes', 'David Bowie', $album);
    searchTrack($this->alice, 'Heroes', 'Wallflowers');
    searchTrack($this->alice, 'Bowie Tribute', 'Someone');

    $titles = fn (string $q) => collect(searchFor($this->alice, $q)['tracks'])->map(fn ($t) => $t['title'].'|'.$t['artist'])->all();

    expect($titles('heroes artist:bowie'))->toBe(['Heroes|David Bowie'])
        ->and($titles('artist:"david bowie"'))->toBe(['Heroes|David Bowie'])
        ->and($titles('bowie'))->toHaveCount(2)
        ->and($titles('album:heroes'))->toBe(['Heroes|David Bowie'])
        ->and(collect(searchFor($this->alice, 'artist:bowie')['albums'])->pluck('title')->all())->toBe(['Heroes']);
});

it('keeps a token that is not a filter as a plain word', function () {
    searchTrack($this->alice, 'Highway', 'AC:DC');
    searchTrack($this->alice, 'Other', 'Soon');

    expect(searchFor($this->alice, 'ac:dc')['tracks'])->toHaveCount(1)
        ->and(searchFor($this->alice, 'other year:soon')['tracks'])->toHaveCount(0); // "year:soon" is a word nothing contains
});

it('pages the results and says when there are more', function () {
    foreach (range(1, 60) as $i) {
        searchTrack($this->alice, sprintf('Page Song %02d', $i), 'Pager');
    }

    $one = searchFor($this->alice, 'page song');
    $two = test()->getJson('/api/v1/search?q=page+song&page=2', ['Authorization' => 'Bearer '.$this->alice->createToken('t', ['*'])->plainTextToken])->assertOk()->json();

    expect($one['tracks'])->toHaveCount(50)->and($one['more'])->toBe(['albums' => false, 'tracks' => true])->and($one['page'])->toBe(1)
        ->and($two['tracks'])->toHaveCount(10)->and($two['more']['tracks'])->toBeFalse()->and($two['page'])->toBe(2)
        ->and(collect($one['tracks'])->pluck('id')->intersect(collect($two['tracks'])->pluck('id')))->toHaveCount(0);
});

it('takes a search made only of filters', function () {
    $album = Album::create(['user_id' => $this->alice->id, 'title' => 'Only Year', 'artist' => 'Band', 'year' => 1977]);
    searchTrack($this->alice, 'Something', 'Band', $album);

    expect(searchFor($this->alice, 'year:1977')['albums'])->toHaveCount(1);
});
