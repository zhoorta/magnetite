<?php

use App\Models\Playlist;
use App\Models\SharedItem;
use App\Models\Track;
use App\Models\User;
use App\Services\LibraryScanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->alice = makeLibrary('alice');
    $this->bob = User::factory()->create(['username' => 'bob']);
    File::copyDirectory(base_path('tests/Fixtures/music'), $this->music.'/users/bob');
    foreach ([$this->alice, $this->bob] as $user) {
        app(LibraryScanner::class)->scan($user);
    }

    $this->one = Track::where('user_id', $this->alice->id)->where('title', 'First Song')->sole();
    $this->two = Track::where('user_id', $this->alice->id)->where('title', 'Second Song')->sole();
    $this->bobTrack = Track::where('user_id', $this->bob->id)->where('title', 'First Song')->sole();
});

afterEach(fn () => File::deleteDirectory($this->music));

it('needs a signed-in user', function () {
    $this->get(route('playlists'))->assertRedirect(route('login'));
    $this->postJson(route('playlists.store'), ['name' => 'x'])->assertUnauthorized();
});

it('creates, renames and deletes a playlist', function () {
    $this->actingAs($this->alice);

    $id = $this->postJson(route('playlists.store'), ['name' => '  Road trip '])->assertCreated()->json('id');
    expect(Playlist::find($id)->name)->toBe('Road trip');

    $this->patchJson(route('playlists.update', $id), ['name' => 'Night drive'])->assertOk();
    expect(Playlist::find($id)->name)->toBe('Night drive');

    $this->get(route('playlists'))->assertOk()->assertSee('Night drive')->assertSee('0 tracks');

    $this->deleteJson(route('playlists.destroy', $id))->assertOk();
    expect(Playlist::count())->toBe(0);

    $this->postJson(route('playlists.store'), ['name' => ''])->assertUnprocessable();
});

it('adds tracks in order, once each, and can create a playlist with a first track', function () {
    $this->actingAs($this->alice);
    $playlist = $this->alice->playlists()->create(['name' => 'Mix']);

    $this->postJson(route('playlists.tracks.store', $playlist), ['track_id' => $this->two->id])->assertOk()->assertJson(['added' => true]);
    $this->postJson(route('playlists.tracks.store', $playlist), ['track_id' => $this->one->id])->assertOk()->assertJson(['added' => true]);
    $this->postJson(route('playlists.tracks.store', $playlist), ['track_id' => $this->one->id])->assertOk()->assertJson(['added' => false]);

    expect($playlist->tracks->pluck('title')->all())->toBe(['Second Song', 'First Song']);

    $id = $this->postJson(route('playlists.store'), ['name' => 'New', 'track_id' => $this->one->id])->assertCreated()->json('id');
    expect(Playlist::find($id)->tracks)->toHaveCount(1);
});

it('reorders and removes tracks', function () {
    $this->actingAs($this->alice);
    $playlist = $this->alice->playlists()->create(['name' => 'Mix']);
    $playlist->tracks()->attach([$this->one->id => ['position' => 1], $this->two->id => ['position' => 2]]);

    $this->putJson(route('playlists.order', $playlist), ['ids' => [$this->two->id, $this->one->id, 999999]])->assertOk();
    expect($playlist->fresh()->tracks->pluck('id')->all())->toBe([$this->two->id, $this->one->id]);

    $this->deleteJson(route('playlists.tracks.destroy', [$playlist, $this->two->id]))->assertOk();
    expect($playlist->fresh()->tracks->pluck('id')->all())->toBe([$this->one->id]);
});

it('keeps playlists private: another user gets 404 for every action', function () {
    $playlist = $this->alice->playlists()->create(['name' => 'Mine']);
    $playlist->tracks()->attach($this->one->id, ['position' => 1]);

    $this->actingAs($this->bob);

    $this->get(route('playlists.show', $playlist))->assertNotFound();
    $this->patchJson(route('playlists.update', $playlist), ['name' => 'Hijacked'])->assertNotFound();
    $this->deleteJson(route('playlists.destroy', $playlist))->assertNotFound();
    $this->postJson(route('playlists.tracks.store', $playlist), ['track_id' => $this->bobTrack->id])->assertNotFound();
    $this->deleteJson(route('playlists.tracks.destroy', [$playlist, $this->one->id]))->assertNotFound();
    $this->putJson(route('playlists.order', $playlist), ['ids' => [$this->one->id]])->assertNotFound();

    $this->get(route('playlists'))->assertDontSee('Mine');
    expect($playlist->fresh()->name)->toBe('Mine')->and($playlist->tracks)->toHaveCount(1);
});

it('only lets you add tracks you can play', function () {
    $this->actingAs($this->bob);
    $playlist = $this->bob->playlists()->create(['name' => 'Bob']);

    $this->postJson(route('playlists.tracks.store', $playlist), ['track_id' => $this->one->id])->assertNotFound();
    $this->postJson(route('playlists.store'), ['name' => 'Sneaky', 'track_id' => $this->one->id])->assertNotFound();

    SharedItem::create(['user_id' => $this->alice->id, 'track_id' => $this->one->id]);
    $this->postJson(route('playlists.tracks.store', $playlist), ['track_id' => $this->one->id])->assertOk()->assertJson(['added' => true]);
});

it('hides tracks that stopped being shared, and brings them back when shared again', function () {
    $share = SharedItem::create(['user_id' => $this->alice->id, 'track_id' => $this->one->id]);
    $playlist = $this->bob->playlists()->create(['name' => 'Bob']);
    $playlist->tracks()->attach([$this->one->id => ['position' => 1], $this->bobTrack->id => ['position' => 2]]);

    $this->actingAs($this->bob)->get(route('playlists.show', $playlist))->assertOk()->assertDontSee('can no longer be played by you');
    expect($playlist->playableBy($this->bob))->toHaveCount(2);

    $share->delete();

    $page = $this->get(route('playlists.show', $playlist))->assertOk()->assertSee('can no longer be played by you');
    expect($playlist->playableBy($this->bob)->pluck('id')->all())->toBe([$this->bobTrack->id])
        ->and($page->viewData('hidden'))->toBe(1);

    // The stream is closed too, whatever the playlist says.
    $this->get(route('stream', $this->one))->assertNotFound();

    SharedItem::create(['user_id' => $this->alice->id, 'track_id' => $this->one->id]);
    expect($playlist->playableBy($this->bob))->toHaveCount(2);
});

it('offers the add-to-playlist button in the library and the shared music, and the shortcut list in the player', function () {
    SharedItem::create(['user_id' => $this->alice->id, 'track_id' => $this->one->id]);
    $this->alice->playlists()->create(['name' => 'Mix']);

    $this->actingAs($this->alice);
    $this->get(route('library'))->assertOk()->assertSee('+ Playlist')->assertSee('Mix')->assertSee('Keyboard shortcuts');
    $this->get(route('shared'))->assertOk()->assertSee('+ Playlist');
});

it('deletes playlists and their rows with the user or the track', function () {
    $playlist = $this->alice->playlists()->create(['name' => 'Mix']);
    $playlist->tracks()->attach($this->one->id, ['position' => 1]);

    $this->one->delete();
    expect($playlist->tracks()->count())->toBe(0);

    $this->alice->delete();
    expect(Playlist::count())->toBe(0);
});

describe('sharing a playlist', function () {
    beforeEach(function () {
        // Alice's playlist: one track shared on its own, one private.
        $this->playlist = $this->alice->playlists()->create(['name' => 'Alice mix']);
        $this->playlist->tracks()->attach([$this->one->id => ['position' => 1], $this->two->id => ['position' => 2]]);
        SharedItem::create(['user_id' => $this->alice->id, 'track_id' => $this->one->id]);
    });

    it('shares and unshares, for the owner only', function () {
        $this->actingAs($this->bob)->postJson(route('share.store', ['playlists', $this->playlist->id]))->assertNotFound();

        $this->actingAs($this->alice);
        $this->postJson(route('share.store', ['playlists', $this->playlist->id]))->assertOk()->assertJson(['shared' => true]);
        $this->postJson(route('share.store', ['playlists', $this->playlist->id]))->assertOk();
        expect(SharedItem::whereNotNull('playlist_id')->count())->toBe(1);

        $this->actingAs($this->bob)->deleteJson(route('share.destroy', ['playlists', $this->playlist->id]))->assertNotFound();

        $this->actingAs($this->alice)->deleteJson(route('share.destroy', ['playlists', $this->playlist->id]))->assertOk()->assertJson(['shared' => false]);
        expect(SharedItem::whereNotNull('playlist_id')->count())->toBe(0);
    });

    it('is a 404 for others until it is shared, then read only', function () {
        $this->actingAs($this->bob)->get(route('playlists.show', $this->playlist))->assertNotFound();

        SharedItem::create(['user_id' => $this->alice->id, 'playlist_id' => $this->playlist->id]);

        $this->get(route('playlists.show', $this->playlist))->assertOk()->assertSee('Alice mix')->assertSee('shared by alice');
        $this->patchJson(route('playlists.update', $this->playlist), ['name' => 'Hijacked'])->assertNotFound();
        $this->deleteJson(route('playlists.destroy', $this->playlist))->assertNotFound();
        $this->postJson(route('playlists.tracks.store', $this->playlist), ['track_id' => $this->bobTrack->id])->assertNotFound();
        $this->postJson(route('playlists.share-tracks', $this->playlist))->assertNotFound();
        expect($this->playlist->fresh()->name)->toBe('Alice mix');
    });

    it('lets a viewer play only what is shared, and opens no file', function () {
        SharedItem::create(['user_id' => $this->alice->id, 'playlist_id' => $this->playlist->id]);

        $page = $this->actingAs($this->bob)->get(route('playlists.show', $this->playlist))->assertOk();
        $tracks = $page->viewData('tracks');

        expect($tracks->firstWhere('id', $this->one->id)->open)->toBeTrue()
            ->and($tracks->firstWhere('id', $this->two->id)->open)->toBeFalse();

        // The page carries no stream url for the private track, and the stream stays closed.
        $streams = fn (int $id) => preg_match('#stream\\\\*/'.$id.'(?!\d)#', $page->getContent()) === 1;
        expect($streams($this->one->id))->toBeTrue()->and($streams($this->two->id))->toBeFalse();
        $this->get(route('stream', $this->one))->assertOk();
        $this->get(route('stream', $this->two))->assertNotFound();
    });

    it('tells the owner how many tracks are private, and shares them on request', function () {
        $this->actingAs($this->alice);
        SharedItem::create(['user_id' => $this->alice->id, 'playlist_id' => $this->playlist->id]);

        $page = $this->get(route('playlists.show', $this->playlist))->assertOk();
        expect(collect($page->viewData('tracks'))->where('closed', true)->pluck('id')->all())->toBe([$this->two->id]);

        $this->postJson(route('playlists.share-tracks', $this->playlist))->assertOk()->assertJson(['shared' => 1]);
        expect(SharedItem::where('track_id', $this->two->id)->exists())->toBeTrue();

        $this->actingAs($this->bob)->get(route('stream', $this->two))->assertOk();
        $this->actingAs($this->alice)->postJson(route('playlists.share-tracks', $this->playlist))->assertOk()->assertJson(['shared' => 0]);
    });

    it('lists shared playlists on /shared with who shares them, and Unshare only for the owner', function () {
        SharedItem::create(['user_id' => $this->alice->id, 'playlist_id' => $this->playlist->id]);

        $this->actingAs($this->bob)->get(route('shared'))->assertOk()->assertSee('Alice mix')->assertSee('shared by alice')->assertDontSee('Unshare');
        $this->actingAs($this->alice)->get(route('shared'))->assertOk()->assertSee('shared by you')->assertSee('Unshare');
        $this->actingAs($this->alice)->get(route('playlists'))->assertSee('shared');
    });

    it('copies a shared playlist with only the tracks the copier can play', function () {
        SharedItem::create(['user_id' => $this->alice->id, 'playlist_id' => $this->playlist->id]);

        $id = $this->actingAs($this->bob)->postJson(route('playlists.copy', $this->playlist))->assertCreated()->assertJson(['tracks' => 1])->json('id');

        $copy = Playlist::find($id);
        expect($copy->user_id)->toBe($this->bob->id)->and($copy->name)->toBe('Alice mix')->and($copy->tracks->pluck('id')->all())->toBe([$this->one->id]);

        // Not shared, no copy.
        SharedItem::where('playlist_id', $this->playlist->id)->delete();
        $this->postJson(route('playlists.copy', $this->playlist))->assertNotFound();
    });

    it('goes away with the playlist', function () {
        SharedItem::create(['user_id' => $this->alice->id, 'playlist_id' => $this->playlist->id]);
        $this->playlist->delete();

        expect(SharedItem::whereNotNull('playlist_id')->count())->toBe(0);
    });
});

describe('adding a whole album', function () {
    beforeEach(function () {
        $this->album = $this->one->album;
        $this->mine = $this->alice->playlists()->create(['name' => 'Mine']);
    });

    it('adds every track in album order, and again adds nothing', function () {
        $this->actingAs($this->alice);

        $this->postJson(route('playlists.tracks.store', $this->mine), ['album_id' => $this->album->id])->assertOk()->assertJson(['added' => 2]);
        expect($this->mine->tracks->pluck('title')->all())->toBe(['First Song', 'Second Song']);

        $this->postJson(route('playlists.tracks.store', $this->mine), ['album_id' => $this->album->id])->assertOk()->assertJson(['added' => 0]);
        expect($this->mine->tracks()->count())->toBe(2);
    });

    it('only adds the tracks that are not in the playlist yet, after the existing ones', function () {
        $this->actingAs($this->alice);
        $this->mine->tracks()->attach($this->two->id, ['position' => 1]);

        $this->postJson(route('playlists.tracks.store', $this->mine), ['album_id' => $this->album->id])->assertOk()->assertJson(['added' => 1]);

        expect($this->mine->fresh()->tracks->pluck('title')->all())->toBe(['Second Song', 'First Song']);
    });

    it('creates a playlist from an album', function () {
        $id = $this->actingAs($this->alice)->postJson(route('playlists.store'), ['name' => 'From album', 'album_id' => $this->album->id])->assertCreated()->json('id');

        expect(Playlist::find($id)->tracks)->toHaveCount(2);
    });

    it('refuses an album you cannot see, and leaves no empty playlist behind', function () {
        $this->actingAs($this->bob);
        $bobs = $this->bob->playlists()->create(['name' => 'Bob']);

        $this->postJson(route('playlists.tracks.store', $bobs), ['album_id' => $this->album->id])->assertNotFound();
        $this->postJson(route('playlists.store'), ['name' => 'Sneaky', 'album_id' => $this->album->id])->assertNotFound();
        $this->postJson(route('playlists.store'), ['name' => 'Sneaky 2', 'track_id' => $this->one->id])->assertNotFound();

        expect($bobs->tracks()->count())->toBe(0)->and(Playlist::where('name', 'like', 'Sneaky%')->count())->toBe(0);
    });

    it('adds only the tracks you can play from a partly shared album', function () {
        SharedItem::create(['user_id' => $this->alice->id, 'track_id' => $this->one->id]);
        $bobs = $this->bob->playlists()->create(['name' => 'Bob']);

        $this->actingAs($this->bob)->postJson(route('playlists.tracks.store', $bobs), ['album_id' => $this->album->id])->assertOk()->assertJson(['added' => 1]);

        expect($bobs->tracks->pluck('id')->all())->toBe([$this->one->id]);

        // And a whole shared album adds everything.
        SharedItem::create(['user_id' => $this->alice->id, 'album_id' => $this->album->id]);
        $this->postJson(route('playlists.tracks.store', $bobs), ['album_id' => $this->album->id])->assertOk()->assertJson(['added' => 1]);
        expect($bobs->tracks()->count())->toBe(2);
    });

    it('needs a track or an album', function () {
        $this->actingAs($this->alice)->postJson(route('playlists.tracks.store', $this->mine), [])->assertUnprocessable();
    });

    it('offers + Playlist on albums in the library and in the shared music', function () {
        SharedItem::create(['user_id' => $this->alice->id, 'album_id' => $this->album->id]);

        $this->actingAs($this->alice);
        foreach ([route('library'), route('shared')] as $url) {
            $this->get($url)->assertOk()->assertSee('Add every track of this album to a playlist');
        }
    });
});
