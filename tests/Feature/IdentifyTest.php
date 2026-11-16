<?php

use App\Jobs\IdentifyAlbums;
use App\Models\Album;
use App\Models\AlbumSuggestion;
use App\Models\Track;
use App\Models\User;
use App\Services\AlbumIdentifier;
use App\Services\Fingerprinter;
use App\Services\LibraryScanner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
    $this->alice = User::factory()->create(['username' => 'alice']);
    $this->bob = User::factory()->create(['username' => 'bob']);
});

function looseTrack(User $user, string $path, float $duration): Track
{
    return Track::create([
        'user_id' => $user->id, 'path' => $path, 'title' => pathinfo($path, PATHINFO_FILENAME), 'duration' => $duration,
        'mime' => 'audio/flac', 'size' => 1, 'mtime' => 1,
    ]);
}

function discovery(array $lengths = [200000, 240000, 180000]): array
{
    return [
        'musicbrainz.org/ws/2/release/?*' => Http::response(['releases' => [['id' => '11111111-2222-3333-4444-555555555555', 'score' => 100, 'track-count' => count($lengths)]]]),
        'musicbrainz.org/ws/2/release/1111*' => Http::response([
            'id' => '11111111-2222-3333-4444-555555555555', 'title' => 'Discovery', 'date' => '2001-03-12',
            'artist-credit' => [['name' => 'Daft Punk']],
            'media' => [['position' => 1, 'tracks' => collect($lengths)->map(fn ($l, $i) => [
                'position' => $i + 1, 'title' => ['One More Time', 'Aerodynamic', 'Digital Love'][$i] ?? "T{$i}", 'length' => $l, 'artist-credit' => [['name' => 'Daft Punk']],
            ])->all()]],
        ]),
    ];
}

function threeTracks(User $user): void
{
    looseTrack($user, 'Daft Punk - Discovery (2001)/01 - a.flac', 200.5);
    looseTrack($user, 'Daft Punk - Discovery (2001)/02 - b.flac', 239.0);
    looseTrack($user, 'Daft Punk - Discovery (2001)/03 - c.flac', 181.0);
    looseTrack($user, 'loose.flac', 100.0); // library root: not an album
}

it('suggests the folder names and a release whose lengths fit', function () {
    Http::fake(discovery());
    threeTracks($this->alice);

    $groups = app(AlbumIdentifier::class)->pending($this->alice);
    expect($groups->keys()->all())->toBe(['Daft Punk - Discovery (2001)']);

    $s = app(AlbumIdentifier::class)->suggest($this->alice, 'Daft Punk - Discovery (2001)', $groups->first());
    [$mb, $path] = $s->candidates;

    expect($mb['source'])->toBe('musicbrainz')
        ->and($mb['score'])->toEqual(1)
        ->and($mb['title'])->toBe('Discovery')->and($mb['year'])->toBe(2001)
        ->and(array_column($mb['tracks'], 'title'))->toBe(['One More Time', 'Aerodynamic', 'Digital Love'])
        ->and($path['source'])->toBe('path')->and($path['artist'])->toBe('Daft Punk')
        ->and(array_column($path['tracks'], 'title'))->toBe(['a', 'b', 'c']);

    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'release:"Discovery" AND artist:"Daft Punk"'));
});

it('keeps the file name for a track whose length does not fit', function () {
    Http::fake(discovery([200000, 300000, 180000]));
    threeTracks($this->alice);

    $s = app(AlbumIdentifier::class)->suggest($this->alice, 'Daft Punk - Discovery (2001)', app(AlbumIdentifier::class)->pending($this->alice)->first());

    expect($s->candidates[0]['matched'])->toBe(2)
        ->and(array_column($s->candidates[0]['tracks'], 'title'))->toBe(['One More Time', 'b', 'Digital Love']);
});

it('offers no release below half the tracks, but still the path', function () {
    Http::fake(discovery([60000, 70000, 80000]));
    threeTracks($this->alice);

    $s = app(AlbumIdentifier::class)->suggest($this->alice, 'Daft Punk - Discovery (2001)', app(AlbumIdentifier::class)->pending($this->alice)->first());

    expect($s->candidates)->toHaveCount(1)->and($s->candidates[0]['source'])->toBe('path');
});

it('falls back to the path when MusicBrainz is down', function () {
    Http::fake(['*' => Http::response('', 503)]);
    threeTracks($this->alice);

    $s = app(AlbumIdentifier::class)->suggest($this->alice, 'Daft Punk - Discovery (2001)', app(AlbumIdentifier::class)->pending($this->alice)->first());

    expect($s->candidates)->toHaveCount(1);
});

it('applies a candidate: album, titles and numbers', function () {
    Http::fake(discovery());
    threeTracks($this->alice);
    $s = app(AlbumIdentifier::class)->suggest($this->alice, 'Daft Punk - Discovery (2001)', app(AlbumIdentifier::class)->pending($this->alice)->first());

    $this->actingAs($this->alice)->postJson(route('identify.accept', $s), ['candidate' => 0])->assertOk();

    $album = Album::where('user_id', $this->alice->id)->first();
    expect($album->title)->toBe('Discovery')->and($album->artist)->toBe('Daft Punk')->and($album->year)->toBe(2001);

    $t = Track::where('path', 'like', '%02 - b.flac')->first();
    expect($t->album_id)->toBe($album->id)->and($t->title)->toBe('Aerodynamic')->and($t->track_number)->toBe(2)->and($t->identified)->toBeTrue();
    expect(AlbumSuggestion::count())->toBe(0)
        ->and(Track::whereNull('album_id')->pluck('path')->all())->toBe(['loose.flac']);
});

it('does not touch tracks that are not the owner\'s or are no longer loose', function () {
    Http::fake(discovery());
    threeTracks($this->alice);
    $s = app(AlbumIdentifier::class)->suggest($this->alice, 'Daft Punk - Discovery (2001)', app(AlbumIdentifier::class)->pending($this->alice)->first());

    $other = Album::create(['user_id' => $this->alice->id, 'title' => 'Elsewhere']);
    Track::where('path', 'like', '%01 - a.flac')->update(['album_id' => $other->id]);

    app(AlbumIdentifier::class)->apply($s, 0);

    expect(Track::where('path', 'like', '%01 - a.flac')->first()->album_id)->toBe($other->id)
        ->and(Track::where('path', 'like', '%01 - a.flac')->first()->identified)->toBeFalse();
});

it('keeps suggestions private: 404 for another user', function () {
    Http::fake(discovery());
    threeTracks($this->alice);
    $s = app(AlbumIdentifier::class)->suggest($this->alice, 'Daft Punk - Discovery (2001)', app(AlbumIdentifier::class)->pending($this->alice)->first());

    $this->actingAs($this->bob)->postJson(route('identify.accept', $s), ['candidate' => 0])->assertNotFound();
    $this->actingAs($this->bob)->deleteJson(route('identify.dismiss', $s))->assertNotFound();
    $this->actingAs($this->alice)->postJson(route('identify.accept', $s), ['candidate' => 9])->assertStatus(422);
});

it('dismisses a suggestion and does not look again unless retried', function () {
    Http::fake(discovery());
    threeTracks($this->alice);
    $identifier = app(AlbumIdentifier::class);
    $s = $identifier->suggest($this->alice, 'Daft Punk - Discovery (2001)', $identifier->pending($this->alice)->first());

    $this->actingAs($this->alice)->deleteJson(route('identify.dismiss', $s))->assertOk();

    expect($identifier->pending($this->alice))->toBeEmpty()
        ->and($identifier->pending($this->alice, retry: true))->toHaveCount(1);
});

it('runs as a job and shows the suggestion in the library', function () {
    Http::fake(discovery());
    threeTracks($this->alice);

    IdentifyAlbums::request($this->alice);

    expect(AlbumSuggestion::count())->toBe(1);
    $this->actingAs($this->alice)->getJson(route('identify.show'))->assertJson(['state' => 'done', 'found' => 1]);
    $this->actingAs($this->alice)->getJson(route('library'))->assertOk()
        ->assertJsonPath('suggestions.0.candidates.0.title', 'Discovery')->assertJsonPath('suggestions.0.candidates.0.matched', 3);
    $this->actingAs($this->bob)->getJson(route('library'))->assertOk()->assertJsonCount(0, 'suggestions');
    $this->actingAs($this->bob)->get(route('library'))->assertOk()->assertDontSee('Discovery');
});

it('keeps what the owner confirmed when the library is scanned again', function () {
    Storage::fake('local');
    makeLibrary();
    $scanner = app(LibraryScanner::class);
    $scanner->scan($this->user);

    $loose = Track::whereNull('album_id')->firstOrFail();
    $album = Album::create(['user_id' => $this->user->id, 'title' => 'Confirmed', 'artist' => 'Someone']);
    $loose->update(['album_id' => $album->id, 'identified' => true, 'title' => 'Chosen title', 'track_number' => 7, 'size' => 0]);

    $scanner->scan($this->user, force: true);

    $loose->refresh();
    expect($loose->album_id)->toBe($album->id)->and($loose->title)->toBe('Chosen title')->and($loose->track_number)->toBe(7)
        ->and($loose->size)->toBeGreaterThan(0); // technical fields are refreshed

    File::deleteDirectory($this->music);
});

it('finds a release by track titles when the folder name says nothing', function () {
    Http::fake([
        'musicbrainz.org/ws/2/release/?*' => Http::response(['releases' => []]),
        'musicbrainz.org/ws/2/recording/*' => Http::response(['recordings' => [['id' => 'r', 'releases' => [['id' => '11111111-2222-3333-4444-555555555555', 'track-count' => 3]]]]]),
    ] + discovery());
    looseTrack($this->alice, 'New folder/01 - One More Time.flac', 200.5);
    looseTrack($this->alice, 'New folder/02 - Aerodynamic.flac', 239.0);
    looseTrack($this->alice, 'New folder/03 - Digital Love.flac', 181.0);

    $identifier = app(AlbumIdentifier::class);
    $s = $identifier->suggest($this->alice, 'New folder', $identifier->pending($this->alice)->first());

    expect($s->candidates[0]['via'])->toBe('titles')->and($s->candidates[0]['title'])->toBe('Discovery');
});

it('ignores titles that are only numbers', function () {
    Http::fake(['*' => Http::response(['releases' => [], 'recordings' => []])]);
    looseTrack($this->alice, 'New folder/01.flac', 200.5);
    looseTrack($this->alice, 'New folder/Track 02.flac', 239.0);

    $identifier = app(AlbumIdentifier::class);
    $identifier->suggest($this->alice, 'New folder', $identifier->pending($this->alice)->first());

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/recording/'));
});

it('uses fingerprints for the releases when names and titles found nothing', function () {
    config(['magnetite.acoustid.key' => 'key']);
    $this->app->instance(Fingerprinter::class, new class extends Fingerprinter
    {
        public function available(): bool
        {
            return true;
        }

        public function releases(User $user, array $tracks): array
        {
            return ['11111111-2222-3333-4444-555555555555' => count($tracks)];
        }
    });
    Http::fake([
        'musicbrainz.org/ws/2/release/?*' => Http::response(['releases' => []]),
        'musicbrainz.org/ws/2/recording/*' => Http::response(['recordings' => []]),
    ] + discovery());
    looseTrack($this->alice, 'Rip 7/01.flac', 200.5);
    looseTrack($this->alice, 'Rip 7/02.flac', 239.0);
    looseTrack($this->alice, 'Rip 7/03.flac', 181.0);

    $identifier = app(AlbumIdentifier::class);
    $s = $identifier->suggest($this->alice, 'Rip 7', $identifier->pending($this->alice)->first());

    expect($s->candidates[0]['via'])->toBe('fingerprint')->and($s->candidates[0]['matched'])->toBe(3)->and($s->candidates[0]['votes'])->toBe(3);
});

it('reads the AcoustID answer and ignores weak matches', function () {
    config(['magnetite.acoustid.key' => 'key']);
    Http::fake(['api.acoustid.org/*' => Http::response(['results' => [
        ['score' => 0.95, 'recordings' => [['releases' => [['id' => '11111111-2222-3333-4444-555555555555'], ['id' => 'not-an-id']]]]],
        ['score' => 0.4, 'recordings' => [['releases' => [['id' => '22222222-2222-3333-4444-555555555555']]]]],
        // the shape AcoustID really answers with for meta=releaseids
        ['score' => 0.9, 'releases' => [['id' => '33333333-2222-3333-4444-555555555555']]],
    ]])]);

    expect((new Fingerprinter)->lookup(200, 'AQAA'))->toBe(['11111111-2222-3333-4444-555555555555', '33333333-2222-3333-4444-555555555555']);
    Http::assertSent(fn ($r) => $r['client'] === 'key' && $r['meta'] === 'releaseids');
});

it('does nothing without fpcalc or a key', function () {
    config(['magnetite.acoustid.key' => null]);

    expect((new Fingerprinter)->available())->toBeFalse()
        ->and((new Fingerprinter)->releases($this->alice, [new Track]))->toBe([]);
});

it('takes the cover from the folder when a candidate is accepted', function () {
    Storage::fake('local');
    $user = makeLibrary('carol', fixtures: false);
    $folder = config('magnetite.music_path').'/users/carol/Band - Record';
    mkdir($folder);
    ob_start();
    imagejpeg(imagecreatetruecolor(8, 8));
    file_put_contents("{$folder}/cover.jpg", ob_get_clean());
    touch("{$folder}/01 - Song.flac");

    Http::fake(['*' => Http::response(['releases' => [], 'recordings' => []])]);
    looseTrack($user, 'Band - Record/01 - Song.flac', 100.0);

    $identifier = app(AlbumIdentifier::class);
    $s = $identifier->suggest($user, 'Band - Record', $identifier->pending($user)->first());
    $album = $identifier->apply($s, 0);

    expect($album->fresh()->cover)->toMatch('/^[a-f0-9]{40}\.jpg$/')->and($album->cover_source)->toBeNull();

    File::deleteDirectory(config('magnetite.music_path'));
});
