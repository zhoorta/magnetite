<?php

use App\Jobs\PrefetchTrack;
use App\Models\Track;
use App\Models\User;
use App\Services\FerriteStore;
use App\Services\LibraryScanner;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
    config(['magnetite.ferrite.cache_max_gb' => 10, 'queue.default' => 'database']);
    $this->user = User::factory()->create(['username' => 'remote', 'store' => ['driver' => 'ferrite', 'url' => FERRITE, 'token' => 'secret-token']]);
    fakeFerrite();
    app(LibraryScanner::class)->scan($this->user);
    $this->track = Track::where('title', 'First Song')->sole();
    $this->cache = Storage::disk('local')->path('ferrite-cache');
    File::deleteDirectory($this->cache); // the scan filled it; start from nothing

    $this->contentRequests = fn () => collect(Http::recorded())->filter(fn ($pair) => str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH), '/content'))->count();
});

it('queues a prefetch and answers 202 at once, only once per track', function () {
    Queue::fake();

    $this->actingAs($this->user)->postJson(route('prefetch', $this->track))->assertStatus(202);
    $this->actingAs($this->user)->postJson(route('prefetch', $this->track))->assertStatus(202);

    Queue::assertPushed(PrefetchTrack::class, 1);
});

it('does nothing on the sync queue, which would download inside the request', function () {
    config(['queue.default' => 'sync']);
    Queue::fake();

    $this->actingAs($this->user)->postJson(route('prefetch', $this->track))->assertStatus(202);

    Queue::assertNothingPushed();
});

it('does nothing for a local library', function () {
    $local = makeLibrary();
    File::deleteDirectory($this->music);
    $track = Track::create(['user_id' => $local->id, 'path' => 'x.mp3', 'title' => 'x', 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1]);
    Queue::fake();

    $this->actingAs($local)->postJson(route('prefetch', $track))->assertStatus(202);

    Queue::assertNothingPushed();
});

it('answers 404 for a track the person may not use, and needs a sign-in', function () {
    $stranger = User::factory()->create(['username' => 'stranger']);
    Queue::fake();

    $this->actingAs($stranger)->postJson(route('prefetch', $this->track))->assertNotFound();
    Queue::assertNothingPushed();

    auth()->logout();
    $this->postJson(route('prefetch', $this->track))->assertUnauthorized();
});

it('lets a friend prefetch a shared track of someone else\'s Ferrite library', function () {
    $friend = User::factory()->create(['username' => 'friend']);
    $this->track->share()->create(['user_id' => $this->user->id]);
    Queue::fake();

    $this->actingAs($friend)->postJson(route('prefetch', $this->track))->assertStatus(202);

    Queue::assertPushed(PrefetchTrack::class);
});

it('downloads the track into the cache, and a stream then needs no request to Ferrite', function () {
    PrefetchTrack::dispatchSync($this->track->id);

    $cached = glob("{$this->cache}/*.mp3");
    expect($cached)->toHaveCount(1)->and(glob("{$this->cache}/*.part"))->toBe([]);

    Http::swap(new Factory);
    Http::fake();
    $this->actingAs($this->user)->get(route('stream', $this->track), ['Range' => 'bytes=0-9'])->assertStatus(206);
    Http::assertNothingSent();
});

it('does not download a track that is cached already', function () {
    PrefetchTrack::dispatchSync($this->track->id);
    $before = ($this->contentRequests)();

    PrefetchTrack::dispatchSync($this->track->id);

    expect(($this->contentRequests)())->toBe($before);
});

it('skips a file larger than the whole cache', function () {
    config(['magnetite.ferrite.cache_max_gb' => 0.000001]); // about 1 KB; the fixture is larger

    PrefetchTrack::dispatchSync($this->track->id);

    expect(glob("{$this->cache}/*"))->toBe([]);
});

it('never leaves half a file where /stream could find it, and survives Ferrite failing', function () {
    Http::swap(new Factory);
    Http::fake([FERRITE.'/*' => Http::response('', 500)]);

    PrefetchTrack::dispatchSync($this->track->id); // logs and carries on, no exception

    expect(glob("{$this->cache}/*"))->toBe([]);
});

it('does not remove the prefetched or the playing track to make room', function () {
    PrefetchTrack::dispatchSync($this->track->id);
    $kept = glob("{$this->cache}/*.mp3")[0];
    touch($kept, time() - 3600); // the oldest file: first in line to go

    config(['magnetite.ferrite.cache_max_gb' => 0.000001]);
    $other = Track::where('title', '!=', 'First Song')->first();
    app(FerriteStore::class, ['user' => $this->user, 'url' => FERRITE, 'token' => 'secret-token'])->fetch($other->path);

    expect(file_exists($kept))->toBeTrue();
});

it('does nothing for a track that no longer exists', function () {
    PrefetchTrack::dispatchSync(999999);

    expect(glob("{$this->cache}/*"))->toBe([]);
});

it('sends one download when two prefetches of the same file overlap', function () {
    // Both see an empty cache; the lock makes the second wait and then find the file.
    $before = ($this->contentRequests)();
    $store = $this->user->store();
    $store->prefetch($this->track);
    $store2 = $this->user->fresh()->store();
    $store2->prefetch($this->track);

    expect(($this->contentRequests)() - $before)->toBe(1);
});
