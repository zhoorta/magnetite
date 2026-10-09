<?php

use App\Models\Track;
use App\Models\User;
use App\Services\FerriteException;
use App\Services\FerriteStore;
use App\Services\LibraryScanner;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['magnetite.ferrite.cache_max_gb' => 10]);
    $this->user = User::factory()->create(['username' => 'remote', 'store' => ['driver' => 'ferrite', 'url' => FERRITE, 'token' => 'secret-token']]);
});

it('is chosen by the user\'s store setting, and keeps the token encrypted', function () {
    expect($this->user->store())->toBeInstanceOf(FerriteStore::class);

    $raw = DB::table('users')->where('id', $this->user->id)->value('store');
    expect($raw)->not->toContain('secret-token')->and($this->user->toArray())->not->toHaveKey('store');
});

it('lists music files with ids and hashes, paging until the cursor ends', function () {
    Http::fake([
        FERRITE.'/api/v1/files' => Http::response(['data' => [['id' => 1, 'path' => 'a.mp3', 'size' => 3, 'sha256' => str_repeat('a', 64), 'mtime' => 5]], 'next_cursor' => 1]),
        FERRITE.'/api/v1/files?cursor=1' => Http::response(['data' => [['id' => 2, 'path' => 'notes.txt', 'size' => 1, 'sha256' => str_repeat('b', 64), 'mtime' => 6], ['id' => 3, 'path' => 'd/b.flac', 'size' => 4, 'sha256' => str_repeat('c', 64), 'mtime' => 7]], 'next_cursor' => null]),
    ]);

    $files = collect($this->user->store()->files());

    expect($files->pluck('path')->all())->toBe(['a.mp3', 'd/b.flac'])
        ->and($files[0]->key)->toBe('1')->and($files[0]->hash)->toBe(str_repeat('a', 64));
    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer secret-token'));
});

it('scans a Ferrite library into tracks and skips unchanged files the second time', function () {
    fakeFerrite();

    $first = app(LibraryScanner::class)->scan($this->user);
    $second = app(LibraryScanner::class)->scan($this->user);

    expect($first['added'])->toBe(5)->and($first['failed'])->toBe(0)
        ->and($second)->toMatchArray(['added' => 0, 'updated' => 0, 'unchanged' => 5]);

    $track = Track::where('title', 'First Song')->sole();
    expect($track->store_key)->not->toBeNull()->and($track->store_hash)->toHaveLength(64);

    $downloads = fn () => collect(Http::recorded())->filter(fn ($pair) => str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH), '/content'))->count();
    expect($downloads())->toBe(5); // each new file once; the second scan downloads nothing
});

it('re-reads a file whose hash changed and drops one that disappeared', function () {
    fakeFerrite();
    app(LibraryScanner::class)->scan($this->user);

    $rows = collect(ferriteListing());
    $rows = $rows->map(fn ($r) => $r['path'] === 'Loose/07_no_tags.mp3' ? [...$r, 'sha256' => str_repeat('f', 64)] : $r)
        ->reject(fn ($r) => $r['path'] === 'Compilation/02.mp3')->values()->all();
    fakeFerrite($rows);

    $counts = app(LibraryScanner::class)->scan($this->user);

    expect($counts['removed'])->toBe(1)->and($counts['failed'])->toBe(1); // the changed hash does not match the bytes served
});

it('refuses bytes that do not match the listed checksum', function () {
    $rows = [['id' => 1, 'path' => 'a.mp3', 'size' => 3, 'sha256' => str_repeat('0', 64), 'mtime' => 1]];
    fakeFerrite($rows, fn () => Http::response('abc'));

    $store = $this->user->store();
    iterator_to_array($store->files());

    expect(fn () => $store->fetch('a.mp3'))->toThrow(FerriteException::class, 'checksum');
    expect(glob(Storage::disk('local')->path('ferrite-cache').'/*'))->toBe([]);
});

it('keeps a scan failing with a plain reason and leaves the old data when Ferrite says 401', function () {
    fakeFerrite();
    app(LibraryScanner::class)->scan($this->user);

    Http::swap(new Factory);
    Http::fake([FERRITE.'/*' => Http::response('', 401)]);

    expect(fn () => app(LibraryScanner::class)->scan($this->user))->toThrow(RuntimeException::class, 'revoked or has expired');
    expect($this->user->tracks()->count())->toBe(5);
});

it('does not follow redirects', function () {
    Http::fake([FERRITE.'/*' => Http::response('', 302, ['Location' => 'https://evil.test/'])]);

    expect(fn () => $this->user->store()->root())->toThrow(FerriteException::class, '302');
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'evil.test'));
});

it('streams an uncached track through the proxy with Range and our own headers', function () {
    fakeFerrite();
    app(LibraryScanner::class)->scan($this->user);
    $track = Track::where('title', 'First Song')->sole();
    File::deleteDirectory(Storage::disk('local')->path('ferrite-cache'));
    $this->actingAs($this->user);

    $response = $this->get(route('stream', $track), ['Range' => 'bytes=10-19'])
        ->assertStatus(206)
        ->assertHeader('Content-Type', 'audio/mpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'inline')
        ->assertHeader('Content-Range', 'bytes 10-19/'.$track->size);

    expect(strlen($response->streamedContent()))->toBe(10);
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/content') && ($r->header('Range')[0] ?? null) === 'bytes=10-19');
    expect(glob(Storage::disk('local')->path('ferrite-cache').'/*.mp3'))->toBe([]); // playback does not fill the cache
});

it('serves a cached track from the local file', function () {
    fakeFerrite();
    app(LibraryScanner::class)->scan($this->user); // the scan downloaded every file into the cache
    $track = Track::where('title', 'First Song')->sole();
    $this->actingAs($this->user);
    Http::swap(new Factory);
    Http::fake(); // from here on any request to Ferrite would be recorded

    $this->get(route('stream', $track), ['Range' => 'bytes=0-9'])->assertStatus(206);

    Http::assertNothingSent();
});

it('answers 502 with a message when Ferrite is down and the track is not cached', function () {
    fakeFerrite();
    app(LibraryScanner::class)->scan($this->user);
    $track = Track::where('title', 'First Song')->sole();
    File::deleteDirectory(Storage::disk('local')->path('ferrite-cache'));
    Http::swap(new Factory);
    Http::fake([FERRITE.'/*' => Http::response('', 500)]);
    $this->actingAs($this->user);

    $this->get(route('stream', $track))->assertStatus(502);
});

it('removes the least recently used cache files over the limit, keeping the new one', function () {
    config(['magnetite.ferrite.cache_max_gb' => 0.00001]); // ~10 KB
    fakeFerrite();

    app(LibraryScanner::class)->scan($this->user);

    $left = glob(Storage::disk('local')->path('ferrite-cache').'/*');
    expect(count($left))->toBeLessThan(5)->and(count($left))->toBeGreaterThan(0);
});

it('checks the connection and switches a user with the storage command', function () {
    fakeFerrite();
    $local = User::factory()->create(['username' => 'plain']);

    $this->artisan('magnetite:storage', ['username' => 'plain', '--ferrite' => FERRITE, '--token' => 'tok'])->assertSuccessful();
    expect($local->fresh()->store())->toBeInstanceOf(FerriteStore::class);

    $this->artisan('magnetite:storage', ['username' => 'plain', '--local' => true])->assertSuccessful();
    expect($local->fresh()->store())->not->toBeInstanceOf(FerriteStore::class);

    $this->artisan('magnetite:storage', ['username' => 'plain', '--ferrite' => 'ftp://nope', '--token' => 'tok'])->assertFailed();
    $this->artisan('magnetite:storage', ['username' => 'ghost'])->assertFailed();
});
