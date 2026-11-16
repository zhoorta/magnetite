<?php

use App\Models\Album;
use App\Models\Track;
use App\Models\User;
use App\Services\Fingerprinter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
    $this->alice = User::factory()->create(['username' => 'alice']);
    $this->bob = User::factory()->create(['username' => 'bob']);
    $this->album = Album::create(['user_id' => $this->alice->id, 'title' => 'Animals', 'artist' => 'Pink Floyd']);
    $this->track = Track::create([
        'user_id' => $this->alice->id, 'album_id' => $this->album->id, 'path' => 'Animals/2.opus', 'title' => '2',
        'track_number' => 9, 'duration' => 1025.0, 'mime' => 'audio/ogg', 'size' => 1, 'mtime' => 1,
    ]);

    // A file the owner's local store can find (the fingerprinter is faked, so its content does not matter).
    $dir = $this->alice->libraryPath().'/Animals';
    File::ensureDirectoryExists($dir);
    File::put($dir.'/2.opus', 'x');

    $this->app->instance(Fingerprinter::class, new class extends Fingerprinter
    {
        public function available(): bool
        {
            return true;
        }

        public function fingerprint(string $file): ?array
        {
            return ['duration' => 1025, 'fingerprint' => 'AQAA'];
        }
    });
    $this->user = $this->alice;
});

function acoustidDogs(): array
{
    $pf = [['id' => '83d91898-7763-47d7-b03b-b92132375c47', 'name' => 'Pink Floyd']];
    $release = fn (string $id, string $title, int $year, int $position) => [
        'id' => $id, 'title' => $title, 'artists' => $pf, 'date' => ['year' => $year], 'country' => 'US', 'track_count' => 5,
        'mediums' => [['format' => 'CD', 'position' => 1, 'tracks' => [['position' => $position]]]],
    ];

    return ['api.acoustid.org/*' => Http::response(['results' => [
        ['score' => 0.98, 'recordings' => [['id' => '93972412-fe18-4656-98ed-82995598d8df', 'title' => 'Dogs', 'artists' => $pf, 'duration' => 1025.2, 'releases' => [
            $release('11111111-2222-3333-4444-555555555555', 'Echoes: The Best of', 2001, 3),
            $release('8ce2b094-cf95-4c99-a5ba-6ac03859f53b', 'Animals', 2011, 2),
        ]]]],
        ['score' => 0.3, 'recordings' => [['id' => '22222222-2222-3333-4444-555555555555', 'title' => 'Weak', 'artists' => $pf, 'releases' => []]]],
    ]])];
}

it('looks one track up by its fingerprint, the album\'s own release first, weak matches left out', function () {
    Http::fake(acoustidDogs());
    $this->actingAs($this->alice);

    $r = $this->postJson(route('library.tracks.lookup', $this->track))->assertOk();

    expect($r->json('candidates'))->toHaveCount(1)
        ->and($r->json('candidates.0.title'))->toBe('Dogs')
        ->and($r->json('candidates.0.artist'))->toBe('Pink Floyd')
        ->and($r->json('candidates.0.releases.0.title'))->toBe('Animals')
        ->and($r->json('candidates.0.releases.0.number'))->toBe(2);
    expect($this->track->fresh()->title)->toBe('2'); // nothing applied yet
    Http::assertSent(fn ($req) => $req['meta'] === 'recordings releases tracks' && $req['duration'] === 1025);
});

it('applies only the ticked parts, and only for the owner', function () {
    Http::fake(acoustidDogs());
    $this->actingAs($this->alice);
    $this->postJson(route('library.tracks.lookup', $this->track))->assertOk();

    $this->postJson(route('library.tracks.lookup.apply', $this->track), ['candidate' => 0, 'release' => 0, 'parts' => ['title', 'number']])->assertOk()->assertJson(['done' => ['title', 'track_number']]);

    $t = $this->track->fresh();
    expect($t->title)->toBe('Dogs')->and($t->track_number)->toBe(2)->and($t->artist)->toBeNull()->and($t->identified)->toBeTrue();

    $this->actingAs($this->bob)->postJson(route('library.tracks.lookup', $this->track))->assertNotFound();
    $this->actingAs($this->bob)->getJson(route('library.tracks.lookup.progress', $this->track))->assertNotFound();
    $this->actingAs($this->bob)->postJson(route('library.tracks.lookup.apply', $this->track), ['candidate' => 0, 'parts' => ['title']])->assertNotFound();
});

it('refuses to apply what was not found', function () {
    $this->actingAs($this->alice)->postJson(route('library.tracks.lookup.apply', $this->track), ['candidate' => 0, 'parts' => ['title']])->assertStatus(422);
    $this->actingAs($this->alice)->postJson(route('library.tracks.lookup.apply', $this->track), ['candidate' => 0, 'parts' => ['path']])->assertStatus(422);
});

it('says so when AcoustID cannot be reached', function () {
    Http::fake(['api.acoustid.org/*' => Http::response('', 500)]);

    $this->actingAs($this->alice)->postJson(route('library.tracks.lookup', $this->track))->assertStatus(502)->assertJsonPath('message', fn ($m) => str_contains($m, 'AcoustID'));
});
