<?php

use App\Jobs\ScanLibrary;
use App\Models\SharedItem;
use App\Models\Track;
use App\Models\User;
use App\Services\LibraryScanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->alice = makeLibrary('alice');
    $this->bob = User::factory()->create(['username' => 'bob']);
    app(LibraryScanner::class)->scan($this->alice);

    $this->track = Track::where('user_id', $this->alice->id)->where('title', 'First Song')->sole();
    $this->album = $this->track->album;
});

afterEach(fn () => File::deleteDirectory($this->music));

it('copies a shared album into the library of the user as real files and scans them', function () {
    Queue::fake();
    SharedItem::create(['user_id' => $this->alice->id, 'album_id' => $this->album->id]);

    $this->actingAs($this->bob)->postJson(route('copy.store', ['albums', $this->album->id]))
        ->assertCreated()->assertJson(['added' => $this->album->tracks()->count(), 'existing' => 0, 'failed' => 0]);

    expect(File::allFiles($this->music.'/users/bob/From alice'))->toHaveCount($this->album->tracks()->count());
    Queue::assertPushed(ScanLibrary::class);

    // Again: nothing is replaced and nothing is added twice.
    $this->postJson(route('copy.store', ['albums', $this->album->id]))->assertCreated()->assertJson(['added' => 0, 'existing' => $this->album->tracks()->count()]);

    // After the scan the copies are Bob's own rows and no longer depend on Alice's sharing.
    app(LibraryScanner::class)->scan($this->bob);
    SharedItem::query()->delete();
    expect(Track::where('user_id', $this->bob->id)->where('title', 'First Song')->exists())->toBeTrue();
});

it('copies one shared track', function () {
    Queue::fake();
    SharedItem::create(['user_id' => $this->alice->id, 'track_id' => $this->track->id]);

    $this->actingAs($this->bob)->postJson(route('copy.store', ['tracks', $this->track->id]))->assertCreated()->assertJson(['added' => 1]);

    expect(File::allFiles($this->music.'/users/bob/From alice'))->toHaveCount(1);
});

it('copies only the shared tracks of an album when just some are shared', function () {
    Queue::fake();
    SharedItem::create(['user_id' => $this->alice->id, 'track_id' => $this->track->id]);

    $this->actingAs($this->bob)->postJson(route('copy.store', ['albums', $this->album->id]))->assertCreated()->assertJson(['added' => 1]);
});

it('answers 404 for music that is not shared, is your own, or does not exist', function () {
    $this->actingAs($this->bob)->postJson(route('copy.store', ['tracks', $this->track->id]))->assertNotFound();
    $this->postJson(route('copy.store', ['albums', $this->album->id]))->assertNotFound();
    $this->postJson(route('copy.store', ['tracks', 99999]))->assertNotFound();

    SharedItem::create(['user_id' => $this->alice->id, 'track_id' => $this->track->id]);
    $this->actingAs($this->alice)->postJson(route('copy.store', ['tracks', $this->track->id]))->assertNotFound();

    expect(is_dir($this->music.'/users/bob/From alice'))->toBeFalse();
});

it('refuses a copy that goes over the quota and needs a signed-in user', function () {
    SharedItem::create(['user_id' => $this->alice->id, 'track_id' => $this->track->id]);
    $this->postJson(route('copy.store', ['tracks', $this->track->id]))->assertUnauthorized();

    $this->bob->forceFill(['quota_mb' => 0])->save();
    $this->actingAs($this->bob)->postJson(route('copy.store', ['tracks', $this->track->id]))->assertStatus(422);
});

it('copies the cover image that sits beside the music', function () {
    Queue::fake();
    $folder = dirname($this->track->path);
    $dir = $this->music.'/users/alice/'.($folder === '.' ? '' : $folder);
    File::put($dir.'/folder.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='));
    SharedItem::create(['user_id' => $this->alice->id, 'track_id' => $this->track->id]);

    $this->actingAs($this->bob)->postJson(route('copy.store', ['tracks', $this->track->id]))->assertCreated()->assertJson(['added' => 1, 'failed' => 0]);

    expect(File::glob($this->music.'/users/bob/From alice/'.($folder === '.' ? '' : $folder.'/').'cover.*'))->toHaveCount(1);
});

it('offers the copy in the + menu of other people\'s shared music, and not of your own', function () {
    SharedItem::create(['user_id' => $this->alice->id, 'album_id' => $this->album->id]);

    $this->actingAs($this->bob)->get(route('shared'))->assertOk()->assertSee('Copy to my library')->assertSee("copy(&#039;albums&#039;, {$this->album->id})", false);
    $this->actingAs($this->alice)->get(route('shared'))->assertOk()->assertDontSee('Copy to my library');
});
