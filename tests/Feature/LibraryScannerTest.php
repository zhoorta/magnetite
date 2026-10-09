<?php

use App\Models\Album;
use App\Models\Track;
use App\Services\LibraryScanner;
use App\Services\TranscodeCache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    makeLibrary();
});

afterEach(fn () => File::deleteDirectory($this->music));

it('imports tags, album and codec for tagged files', function () {
    $counts = app(LibraryScanner::class)->scan($this->user);

    expect($counts)->toMatchArray(['added' => 5, 'failed' => 0]); // notes.txt is ignored

    $album = Album::with('tracks')->where('title', 'Test Album')->sole();
    expect($album->title)->toBe('Test Album')
        ->and($album->artist)->toBe('The Testers')
        ->and($album->year)->toBe(2019)
        ->and($album->tracks->pluck('title')->all())->toBe(['First Song', 'Second Song']);

    $mp3 = Track::where('title', 'First Song')->sole();
    expect($mp3->codec)->toBe('mp3')
        ->and($mp3->track_number)->toBe(1)
        ->and($mp3->disc_number)->toBe(1)
        ->and($mp3->duration)->toBeGreaterThan(0.5)
        ->and($mp3->mime)->toBe('audio/mpeg')
        ->and($mp3->playable)->toBeTrue();

    $alac = Track::where('title', 'Second Song')->sole();
    expect($alac->codec)->toBe('alac')
        ->and($alac->track_number)->toBe(2)
        // Playable only when ffmpeg can convert it for Chrome and Firefox.
        ->and($alac->playable)->toBe(app(TranscodeCache::class)->available());
});

it('keeps untagged files, titled from the file name, outside any album', function () {
    app(LibraryScanner::class)->scan($this->user);

    $loose = Track::where('path', 'Loose/07_no_tags.mp3')->sole();

    expect($loose->album_id)->toBeNull()
        ->and($loose->title)->toBe('no tags');
});

it('stores the embedded cover once as a resized jpeg', function () {
    app(LibraryScanner::class)->scan($this->user);

    $album = Album::where('title', 'Test Album')->sole();

    expect($album->cover)->toMatch('/^[a-f0-9]{40}\.jpg$/');
    Storage::disk('local')->assertExists("covers/{$album->cover}");

    [$w, $h] = getimagesizefromstring(Storage::disk('local')->get("covers/{$album->cover}"));
    expect(max($w, $h))->toBeLessThanOrEqual(500);
});

it('skips unchanged files, re-reads forced ones and drops deleted ones', function () {
    $scanner = app(LibraryScanner::class);
    $scanner->scan($this->user);

    expect($scanner->scan($this->user))->toMatchArray(['added' => 0, 'unchanged' => 5, 'removed' => 0])
        ->and($scanner->scan($this->user, force: true))->toMatchArray(['updated' => 5, 'unchanged' => 0]);

    File::delete("{$this->library}/Loose/07_no_tags.mp3");

    expect($scanner->scan($this->user))->toMatchArray(['removed' => 1, 'unchanged' => 4])
        ->and(Track::count())->toBe(4);
});

it('does not abort the scan on an unreadable file', function () {
    File::put("{$this->library}/Loose/broken.mp3", 'this is not audio');

    $counts = app(LibraryScanner::class)->scan($this->user);

    // getID3 is lenient: a junk file becomes a track without usable tags rather than an exception,
    // but either way the real files must still be imported.
    expect($counts['added'] + $counts['failed'])->toBe(6)
        ->and(Track::where('title', 'First Song')->exists())->toBeTrue();
});

it('groups a compilation without album artist by folder and shows various artists', function () {
    app(LibraryScanner::class)->scan($this->user);

    $mixtape = Album::with('tracks')->where('title', 'Mixtape')->sole();

    expect($mixtape->artist)->toBeNull()
        ->and($mixtape->folder)->toBe('Compilation')
        ->and($mixtape->tracks)->toHaveCount(2)
        ->and($mixtape->displayArtist)->toBe('Various artists');
});
