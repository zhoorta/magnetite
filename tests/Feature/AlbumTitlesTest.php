<?php

use App\Models\Album;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
    $this->alice = User::factory()->create(['username' => 'alice']);
    $this->bob = User::factory()->create(['username' => 'bob']);
    $this->album = Album::create(['user_id' => $this->alice->id, 'title' => 'Discovery', 'artist' => 'Daft Punk']);
    foreach ([[1, 200.5], [2, 239.0], [3, 181.0]] as [$n, $seconds]) {
        Track::create([
            'user_id' => $this->alice->id, 'album_id' => $this->album->id, 'path' => "Discovery/track{$n}.flac", 'title' => "Track {$n}",
            'track_number' => $n, 'duration' => $seconds, 'mime' => 'audio/flac', 'size' => 1, 'mtime' => 1,
        ]);
    }
});

/** MusicBrainz answers for Discovery, with a label. */
function discoveryWithLabel(array $lengths = [200000, 240000, 180000]): array
{
    return [
        'musicbrainz.org/ws/2/release/?*' => Http::response(['releases' => [['id' => '11111111-2222-3333-4444-555555555555', 'score' => 100, 'track-count' => count($lengths)]]]),
        'musicbrainz.org/ws/2/release/1111*' => Http::response([
            'id' => '11111111-2222-3333-4444-555555555555', 'title' => 'Discovery', 'date' => '2001-03-12',
            'artist-credit' => [['name' => 'Daft Punk']], 'label-info' => [['label' => ['name' => 'Virgin']]],
            'media' => [['position' => 1, 'tracks' => collect($lengths)->map(fn ($l, $i) => [
                'position' => $i + 1, 'title' => ['One More Time', 'Aerodynamic', 'Digital Love'][$i], 'length' => $l, 'artist-credit' => [['name' => 'Daft Punk']],
            ])->all()]],
        ]),
    ];
}

it('proposes the titles of a release that fits the files, without changing anything', function () {
    Http::fake(discoveryWithLabel());

    $response = $this->actingAs($this->alice)->postJson(route('library.albums.titles.find', $this->album))->assertOk();

    $candidate = $response->json('candidates.0');
    expect(array_column($candidate['tracks'], 'title'))->toBe(['One More Time', 'Aerodynamic', 'Digital Love'])
        ->and(array_column($candidate['tracks'], 'current'))->toBe(['Track 1', 'Track 2', 'Track 3'])
        ->and($candidate['label'])->toBe('Virgin')->and($candidate['matched'])->toBe(3);
    expect(Track::pluck('title')->all())->toBe(['Track 1', 'Track 2', 'Track 3']);
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'release:"Discovery" AND artist:"Daft Punk"'));
});

it('applies the ticked parts of the chosen candidate: titles only where the length fits, the date, the label', function () {
    Http::fake(discoveryWithLabel([200000, 300000, 180000]));
    $this->actingAs($this->alice)->postJson(route('library.albums.titles.find', $this->album))->assertOk()
        ->assertJsonPath('candidates.0.date', '2001-03-12')->assertJsonPath('candidates.0.label', 'Virgin');

    $this->postJson(route('library.albums.titles.apply', $this->album), ['candidate' => 0, 'parts' => ['titles', 'date', 'label']])
        ->assertOk()->assertJson(['done' => ['titles' => 2, 'date' => true, 'label' => true]]);

    expect(Track::orderBy('track_number')->pluck('title')->all())->toBe(['One More Time', 'Track 2', 'Digital Love'])
        ->and(Track::where('identified', false)->count())->toBe(0);
    $album = $this->album->fresh();
    expect($album->title)->toBe('Discovery')->and($album->label)->toBe('Virgin')->and($album->release_date)->toBe('2001-03-12')->and($album->year)->toBe(2001);
});

it('changes only what was ticked, and overwrites a value the owner chose to replace', function () {
    Http::fake(discoveryWithLabel());
    $this->album->update(['label' => 'Mine', 'year' => 2005]);

    $this->actingAs($this->alice)->postJson(route('library.albums.titles.find', $this->album))
        ->assertJsonPath('album.label', 'Mine')->assertJsonPath('album.release_date', '2005');
    $this->postJson(route('library.albums.titles.apply', $this->album), ['candidate' => 0, 'parts' => ['date']])->assertOk();

    $album = $this->album->fresh();
    expect($album->label)->toBe('Mine')->and($album->release_date)->toBe('2001-03-12')->and($album->year)->toBe(2001)
        ->and(Track::pluck('title')->all())->toBe(['Track 1', 'Track 2', 'Track 3']);
});

it('finds nothing when no release fits the lengths', function () {
    Http::fake(discoveryWithLabel([60000, 70000, 80000]));

    $this->actingAs($this->alice)->postJson(route('library.albums.titles.find', $this->album))->assertOk()->assertJson(['candidates' => []]);
});

it('says so when MusicBrainz is down', function () {
    Http::fake(['musicbrainz.org/*' => Http::response('', 503)]);

    $this->actingAs($this->alice)->postJson(route('library.albums.titles.find', $this->album))->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'MusicBrainz'));
});

it('only applies what was found, and only for a while', function () {
    Http::fake(discoveryWithLabel());
    $this->actingAs($this->alice);

    $this->postJson(route('library.albums.titles.apply', $this->album), ['candidate' => 0, 'parts' => ['titles']])->assertStatus(422);

    $this->postJson(route('library.albums.titles.find', $this->album))->assertOk();
    $this->postJson(route('library.albums.titles.apply', $this->album), ['candidate' => 5, 'parts' => ['titles']])->assertStatus(422);
    $this->postJson(route('library.albums.titles.apply', $this->album), ['candidate' => 0, 'parts' => []])->assertStatus(422);
    $this->postJson(route('library.albums.titles.apply', $this->album), ['candidate' => 0, 'parts' => ['cover']])->assertStatus(422);
});

it('is the owner\'s alone and needs a signed-in user', function () {
    Http::fake(discoveryWithLabel());

    $this->postJson(route('library.albums.titles.find', $this->album))->assertUnauthorized();
    $this->actingAs($this->bob)->postJson(route('library.albums.titles.find', $this->album))->assertNotFound();
    $this->postJson(route('library.albums.titles.apply', $this->album), ['candidate' => 0, 'parts' => ['titles']])->assertNotFound();
    Http::assertNothingSent();
});

it('reports what each search step did', function () {
    Http::fake(discoveryWithLabel([60000, 70000, 80000]));

    $report = $this->actingAs($this->alice)->postJson(route('library.albums.titles.find', $this->album))->assertOk()->json('report');

    expect(collect($report)->pluck('step')->all())->toBe(['Album name', 'Track titles', 'Fingerprints'])
        ->and(collect($report)->firstWhere('step', 'Fingerprints')['status'])->toBe('skipped');
});

it('asks MusicBrainz again when it is busy', function () {
    Http::fake(['musicbrainz.org/*' => Http::sequence()->push('', 503)->push(['releases' => []])]);

    $this->actingAs($this->alice)->postJson(route('library.albums.titles.find', $this->album))->assertOk()->assertJson(['candidates' => []]);
});
