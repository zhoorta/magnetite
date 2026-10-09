<?php

use App\Jobs\FindCovers;
use App\Models\Album;
use App\Models\User;
use App\Support\CoverStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
    Sleep::fake();
    $this->alice = User::factory()->create(['username' => 'alice']);
    $this->album = Album::create(['user_id' => $this->alice->id, 'artist' => 'Daft Punk', 'title' => 'Discovery']);

    ob_start();
    imagejpeg(imagecreatetruecolor(8, 8));
    $this->jpeg = ob_get_clean();
});

function fakeMusicBrainz(string $title = 'Discovery', string $artist = 'Daft Punk'): array
{
    return [
        'musicbrainz.org/*' => Http::response(['release-groups' => [['id' => '11111111-2222-3333-4444-555555555555', 'title' => $title, 'artist-credit' => [['name' => $artist]]]]]),
    ];
}

it('takes the cover from the Cover Art Archive', function () {
    Http::fake(fakeMusicBrainz() + ['coverartarchive.org/*' => Http::response($this->jpeg)]);

    $this->actingAs($this->alice)->postJson(route('cover.find', $this->album))->assertOk()->assertJson(['source' => 'musicbrainz']);

    $album = $this->album->fresh();
    expect($album->cover)->toMatch('/^[a-f0-9]{40}\.jpg$/')
        ->and($album->cover_source)->toBe('musicbrainz')
        ->and($album->cover_checked_at)->not->toBeNull();
    Storage::disk('local')->assertExists("covers/{$album->cover}");
});

it('falls back to iTunes when the archive has no image', function () {
    Http::fake(fakeMusicBrainz() + [
        'coverartarchive.org/*' => Http::response('', 404),
        'itunes.apple.com/*' => Http::response(['results' => [['collectionName' => 'Discovery', 'artistName' => 'Daft Punk', 'artworkUrl100' => 'https://is1-ssl.mzstatic.com/image/a/100x100bb.jpg']]]),
        '*.mzstatic.com/*' => Http::response($this->jpeg),
    ]);

    $this->actingAs($this->alice)->postJson(route('cover.find', $this->album))->assertJson(['source' => 'itunes']);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/600x600bb.jpg'));
});

it('does not accept a different album, and remembers that it looked', function () {
    Http::fake(fakeMusicBrainz('Homework') + ['itunes.apple.com/*' => Http::response(['results' => []])]);

    $this->actingAs($this->alice)->postJson(route('cover.find', $this->album))->assertOk()->assertJson(['source' => null]);

    $album = $this->album->fresh();
    expect($album->cover)->toBeNull()->and($album->cover_checked_at)->not->toBeNull();
});

it('never downloads from a host that is not on the list, and never stores what is not an image', function () {
    Http::fake([
        'musicbrainz.org/*' => Http::response(['release-groups' => []]),
        'itunes.apple.com/*' => Http::response(['results' => [['collectionName' => 'Discovery', 'artistName' => 'Daft Punk', 'artworkUrl100' => 'https://evil.example/100x100bb.jpg']]]),
    ]);
    $this->actingAs($this->alice)->postJson(route('cover.find', $this->album))->assertJson(['source' => null]);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.example'));

    Http::fake(fakeMusicBrainz() + ['coverartarchive.org/*' => Http::response('<html>not an image</html>'), 'itunes.apple.com/*' => Http::response(['results' => []])]);
    $this->actingAs($this->alice)->postJson(route('cover.find', $this->album))->assertJson(['source' => null]);
    expect($this->album->fresh()->cover)->toBeNull();
});

it('survives a source that is down', function () {
    Http::fake(['musicbrainz.org/*' => Http::response('', 503), 'itunes.apple.com/*' => Http::response(['results' => []])]);

    $this->actingAs($this->alice)->postJson(route('cover.find', $this->album))->assertOk()->assertJson(['source' => null]);
});

it('only lets the owner search, and not for an album that has a cover', function () {
    Http::fake();
    $bob = User::factory()->create(['username' => 'bob']);

    $this->actingAs($bob)->postJson(route('cover.find', $this->album))->assertNotFound();

    $this->album->update(['cover' => str_repeat('a', 40).'.jpg']);
    $this->actingAs($this->alice)->postJson(route('cover.find', $this->album))->assertStatus(422);
    Http::assertNothingSent();
});

it('needs a signed-in user', function () {
    $this->postJson(route('cover.find', $this->album))->assertUnauthorized();
    $this->postJson(route('covers.find'))->assertUnauthorized();
});

it('searches only the signed-in user\'s albums without a cover, and not the ones already searched', function () {
    $bob = User::factory()->create(['username' => 'bob']);
    Album::create(['user_id' => $bob->id, 'title' => 'Bob album']);
    Album::create(['user_id' => $this->alice->id, 'title' => 'Seen', 'cover_checked_at' => now()]);
    Album::create(['user_id' => $this->alice->id, 'title' => 'Done', 'cover' => str_repeat('b', 40).'.jpg']);
    Http::fake(fakeMusicBrainz() + ['coverartarchive.org/*' => Http::response($this->jpeg)]);

    FindCovers::dispatchSync($this->alice->id);

    expect(CoverStatus::get($this->alice->id))->toMatchArray(['state' => 'done', 'total' => 1, 'done' => 1, 'found' => 1])
        ->and(Album::where('title', 'Bob album')->first()->cover_checked_at)->toBeNull();

    FindCovers::dispatchSync($this->alice->id, retry: true);
    expect(CoverStatus::get($this->alice->id)['total'])->toBe(1); // "Seen" is searched again, Discovery now has its cover
});

it('queues a single job for the signed-in user', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    $this->actingAs($this->alice)->postJson(route('covers.find'))->assertStatus(202)->assertJson(['state' => 'queued']);
    $this->actingAs($this->alice)->postJson(route('covers.find'))->assertStatus(202);

    Queue::assertPushed(FindCovers::class, 1);
});

it('has a command that searches without the queue', function () {
    Http::fake(fakeMusicBrainz() + ['coverartarchive.org/*' => Http::response($this->jpeg)]);

    $this->artisan('magnetite:covers', ['username' => 'alice'])->expectsOutputToContain('musicbrainz')->assertSuccessful();

    expect($this->album->fresh()->cover_source)->toBe('musicbrainz');
});
