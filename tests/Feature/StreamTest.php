<?php

use App\Models\Album;
use App\Models\Track;
use App\Models\User;
use App\Services\LibraryScanner;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Storage::fake('local');
    config(['magnetite.music_path' => base_path('tests/Fixtures/music')]);
    app(LibraryScanner::class)->scan();
});

it('streams a track inline with safe headers', function () {
    $track = Track::where('title', 'First Song')->sole();

    $this->get(route('stream', $track))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/mpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Disposition', 'inline');
});

it('answers range requests so seeking works', function () {
    $track = Track::where('title', 'First Song')->sole();

    $response = $this->get(route('stream', $track), ['Range' => 'bytes=100-199'])
        ->assertStatus(206)
        ->assertHeader('Content-Range', "bytes 100-199/{$track->size}");

    expect($response->baseResponse->getFile()->getSize())->toBe($track->size);
});

it('never serves a path that escapes the music folder', function () {
    $track = Track::create([
        'path' => '../../../../.env', 'title' => 'x', 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1,
    ]);

    $this->get(route('stream', $track))->assertNotFound();
});

it('does not serve non-audio files even if a row points at one', function () {
    $track = Track::create([
        'path' => 'Loose/notes.txt', 'title' => 'x', 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1,
    ]);

    $this->get(route('stream', $track))->assertNotFound();
});

it('returns 404 when the file has disappeared', function () {
    $track = Track::create([
        'path' => 'Tagged/Album/gone.mp3', 'title' => 'x', 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1,
    ]);

    $this->get(route('stream', $track))->assertNotFound();
});

it('serves album covers as immutable jpegs', function () {
    $album = Album::where('title', 'Test Album')->sole();

    $this->get(route('cover', $album))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('rejects a cover name that is not a content hash', function () {
    $album = Album::create(['title' => 'Evil', 'cover' => '../../.env']);

    $this->get(route('cover', $album))->assertNotFound();
});

it('lists albums and untagged tracks on the library page', function () {
    $this->get(route('library'))
        ->assertOk()
        ->assertSee('Test Album')
        ->assertSee('The Testers')
        ->assertSee('Without album tag');
});
