<?php

use App\Models\Album;
use App\Models\Folder;
use App\Models\SharedItem;
use App\Models\Track;
use App\Models\User;
use App\Services\LibraryScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
    Queue::fake();
    config(['queue.default' => 'database']);
    $this->alice = makeLibrary('alice');
    $this->bob = User::factory()->create(['username' => 'bob']);
    File::copyDirectory(base_path('tests/Fixtures/music'), $this->music.'/users/bob');

    foreach ([$this->alice, $this->bob] as $user) {
        app(LibraryScanner::class)->scan($user);
    }

    $this->album = Album::where('user_id', $this->alice->id)->where('title', 'First Album')->first() ?? Album::where('user_id', $this->alice->id)->whereHas('tracks', fn ($q) => $q->where('title', 'First Song'))->firstOrFail();
    $this->bobAlbum = Album::where('user_id', $this->bob->id)->whereHas('tracks', fn ($q) => $q->where('title', 'First Song'))->firstOrFail();
    $this->bobTrack = $this->bobAlbum->tracks()->first();
});

afterEach(fn () => File::deleteDirectory($this->music));

function writer(User $user, bool $readOnly = false): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('t', $readOnly ? ['library:read'] : ['*'])->plainTextToken];
}

function audioUpload(string $name = 'new-song.mp3'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, file_get_contents(base_path('tests/Fixtures/music/Loose/07_no_tags.mp3')));
}

describe('abilities', function () {
    it('refuses writes and deletes to a read-only token but lets it read', function () {
        $h = writer($this->alice, readOnly: true);

        $this->getJson('/api/v1/folders', $h)->assertOk();
        $this->postJson('/api/v1/folders', ['name' => 'Jazz'], $h)->assertForbidden();
        $this->patchJson("/api/v1/albums/{$this->album->id}", ['title' => 'x'], $h)->assertForbidden();
        $this->deleteJson("/api/v1/albums/{$this->album->id}", [], $h)->assertForbidden();
        $this->post('/api/v1/uploads', ['files' => [audioUpload()]], $h + ['Accept' => 'application/json'])->assertForbidden();
    });

    it('hands out a read-only token when asked', function () {
        $this->alice->update(['password' => 'secret-pass']);

        $this->postJson('/api/v1/token', ['username' => 'alice', 'password' => 'secret-pass', 'device_name' => 'ro', 'read_only' => true])->assertCreated();

        expect($this->alice->tokens()->where('name', 'ro')->sole()->abilities)->toBe(['library:read']);
    });
});

describe('folders', function () {
    it('creates, lists, renames, moves an album into and deletes a folder', function () {
        $h = writer($this->alice);

        $id = $this->postJson('/api/v1/folders', ['name' => 'Jazz'], $h)->assertCreated()->json('folder.id');
        $this->patchJson("/api/v1/folders/{$id}", ['name' => 'Classic jazz'], $h)->assertOk();
        $this->putJson("/api/v1/albums/{$this->album->id}/folder", ['folder_id' => $id], $h)->assertOk();

        $this->getJson('/api/v1/folders', $h)->assertOk()
            ->assertJsonPath('data.0.name', 'Classic jazz')->assertJsonPath('data.0.album_count', 1);
        $this->getJson("/api/v1/albums/{$this->album->id}", $h)->assertJsonPath('data.folder_id', $id);

        $this->deleteJson("/api/v1/folders/{$id}", [], $h)->assertOk();
        expect($this->album->fresh()->folder_id)->toBeNull();
    });

    it('does not show or change another user\'s folders', function () {
        $folder = Folder::create(['user_id' => $this->bob->id, 'name' => 'Private']);
        $h = writer($this->alice);

        $this->getJson('/api/v1/folders', $h)->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/folders/{$folder->id}", ['name' => 'Mine'], $h)->assertNotFound();
        $this->deleteJson("/api/v1/folders/{$folder->id}", [], $h)->assertNotFound();
        $this->putJson("/api/v1/albums/{$this->album->id}/folder", ['folder_id' => $folder->id], $h)->assertUnprocessable();
    });
});

describe('scan', function () {
    it('queues a scan and reports its status', function () {
        $h = writer($this->alice);

        $this->postJson('/api/v1/scan', [], $h)->assertStatus(202)->assertJsonPath('state', 'queued');
        $this->getJson('/api/v1/scan', $h)->assertOk()->assertJsonPath('state', 'queued');
    });
});

describe('details', function () {
    it('edits an album and a track of one\'s own', function () {
        $h = writer($this->alice);
        $track = $this->album->tracks()->first();

        $this->patchJson("/api/v1/albums/{$this->album->id}", ['title' => 'Renamed', 'label' => 'Indie'], $h)->assertOk()->assertJsonPath('album.label', 'Indie');
        $this->patchJson("/api/v1/tracks/{$track->id}", ['title' => 'New title', 'track_number' => 7], $h)->assertOk();

        expect($track->fresh()->title)->toBe('New title');
    });

    it('answers 404 to another user for edits, deletes and detail', function () {
        $h = writer($this->alice);

        $this->patchJson("/api/v1/albums/{$this->bobAlbum->id}", ['title' => 'x'], $h)->assertNotFound();
        $this->patchJson("/api/v1/tracks/{$this->bobTrack->id}", ['title' => 'x'], $h)->assertNotFound();
        $this->deleteJson("/api/v1/tracks/{$this->bobTrack->id}", [], $h)->assertNotFound();
        $this->getJson("/api/v1/tracks/{$this->bobTrack->id}", $h)->assertNotFound();
    });

    it('cannot be changed by a viewer of a shared album', function () {
        SharedItem::create(['user_id' => $this->bob->id, 'album_id' => $this->bobAlbum->id]);
        $h = writer($this->alice);

        $this->getJson("/api/v1/albums/{$this->bobAlbum->id}", $h)->assertOk();
        $this->patchJson("/api/v1/albums/{$this->bobAlbum->id}", ['title' => 'x'], $h)->assertNotFound();
        $this->putJson("/api/v1/albums/{$this->bobAlbum->id}/cover", [], $h)->assertNotFound();
    });

    it('shows the owner the details of a track and a viewer only the basics', function () {
        $track = $this->album->tracks()->first();

        $own = $this->getJson("/api/v1/tracks/{$track->id}", writer($this->alice))->assertOk();
        expect($own->json('data'))->toHaveKeys(['edit', 'truth', 'bitrate', 'size'])->and($own->json('data.truth'))->not->toHaveKey('spectrum');

        SharedItem::create(['user_id' => $this->bob->id, 'track_id' => $this->bobTrack->id]);
        $seen = $this->getJson("/api/v1/tracks/{$this->bobTrack->id}", writer($this->alice))->assertOk();
        expect($seen->json('data'))->not->toHaveKeys(['edit', 'truth']);
    });
});

describe('covers', function () {
    it('sets an album cover from an image', function () {
        $image = UploadedFile::fake()->image('front.png', 600, 600);

        $r = $this->put("/api/v1/albums/{$this->album->id}/cover", ['image' => $image], writer($this->alice) + ['Accept' => 'application/json']);
        // PUT with multipart is not parsed by PHP; the app sends POST with _method.
        $r = $this->post("/api/v1/albums/{$this->album->id}/cover?_method=PUT", ['image' => $image], writer($this->alice) + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.id', $this->album->id);

        expect($this->album->fresh()->cover)->not->toBeNull()->and($this->album->fresh()->cover_source)->toBeNull();
        Storage::disk('local')->assertExists('covers/'.$this->album->fresh()->cover);
    });

    it('refuses a file that is not an image', function () {
        $this->post("/api/v1/albums/{$this->album->id}/cover?_method=PUT", ['image' => UploadedFile::fake()->create('x.txt', 1, 'text/plain')], writer($this->alice) + ['Accept' => 'application/json'])
            ->assertUnprocessable();
    });
});

describe('uploading audio files', function () {
    it('puts an audio file in the root of the user\'s folder and queues a scan', function () {
        $r = $this->post('/api/v1/uploads', ['files' => [audioUpload('new-song.mp3')]], writer($this->alice) + ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('files.0.status', 'added')->assertJsonPath('scan.state', 'queued');

        expect(is_file($this->library.'/new-song.mp3'))->toBeTrue();
    });

    it('takes audio only: no covers, no zips, no text', function () {
        $h = writer($this->alice) + ['Accept' => 'application/json'];

        $r = $this->post('/api/v1/uploads', ['files' => [UploadedFile::fake()->image('cover.jpg'), UploadedFile::fake()->create('a.zip', 1), UploadedFile::fake()->create('n.txt', 1, 'text/plain')]], $h)->assertCreated();

        expect(collect($r->json('files'))->pluck('ok')->unique()->all())->toBe([false])->and($r->json('scan'))->toBeNull()
            ->and(is_file($this->library.'/cover.jpg'))->toBeFalse();
    });

    it('never overwrites and keeps a hostile name inside the folder', function () {
        $h = writer($this->alice) + ['Accept' => 'application/json'];
        file_put_contents($this->library.'/there.mp3', 'original');

        $this->post('/api/v1/uploads', ['files' => [audioUpload('there.mp3')]], $h)->assertCreated()->assertJsonPath('files.0.status', 'existing');
        expect(file_get_contents($this->library.'/there.mp3'))->toBe('original');

        $this->post('/api/v1/uploads', ['files' => [audioUpload('../../escape.mp3')]], $h)->assertCreated();
        expect(is_file($this->music.'/escape.mp3'))->toBeFalse()->and(is_file($this->library.'/escape.mp3'))->toBeTrue();

        $this->post('/api/v1/uploads', ['files' => [audioUpload('.hidden.mp3')]], $h)->assertCreated()->assertJsonPath('files.0.ok', false);
    });

    it('refuses an upload that goes over the quota', function () {
        $this->alice->forceFill(['quota_mb' => 0])->save();
        file_put_contents($this->library.'/x.bin', 'x');

        $this->post('/api/v1/uploads', ['files' => [audioUpload()]], writer($this->alice) + ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('files.0.ok', false);
    });
});

describe('putting a file in an album and taking it out', function () {
    it('moves a loose file into the album\'s folder', function () {
        $loose = Track::where('user_id', $this->alice->id)->where('path', 'Loose/07_no_tags.mp3')->sole();
        $h = writer($this->alice);

        $this->postJson("/api/v1/albums/{$this->album->id}/tracks", ['track_id' => $loose->id], $h)
            ->assertCreated()->assertJsonPath('data.album_id', $this->album->id);

        $loose->refresh();
        expect($loose->path)->toBe('Tagged/Album/07_no_tags.mp3')->and($loose->identified)->toBeTrue()
            ->and(is_file($this->library.'/Tagged/Album/07_no_tags.mp3'))->toBeTrue()
            ->and(is_file($this->library.'/Loose/07_no_tags.mp3'))->toBeFalse();

        // A rescan keeps the choice.
        app(LibraryScanner::class)->scan($this->alice);
        expect($loose->fresh()->album_id)->toBe($this->album->id);
    });

    it('moves a file out to the folder that holds its folder, without deleting it', function () {
        $track = $this->album->tracks()->where('path', 'Tagged/Album/01-first.mp3')->sole();

        $this->deleteJson("/api/v1/albums/{$this->album->id}/tracks/{$track->id}", [], writer($this->alice))
            ->assertOk()->assertJsonPath('data.album_id', null);

        expect($track->fresh()->path)->toBe('Tagged/01-first.mp3')->and(is_file($this->library.'/Tagged/01-first.mp3'))->toBeTrue();

        app(LibraryScanner::class)->scan($this->alice);
        expect($track->fresh()->album_id)->toBeNull()->and(Track::count())->toBe(Track::where('user_id', '!=', 0)->count());
    });

    it('moves a file at the top of an album to the library root and drops the empty folder and album', function () {
        $album = Album::create(['user_id' => $this->alice->id, 'title' => 'Solo']);
        mkdir($this->library.'/Solo');
        copy($this->library.'/Tagged/Album/01-first.mp3', $this->library.'/Solo/one.mp3');
        $track = Track::create(['user_id' => $this->alice->id, 'album_id' => $album->id, 'path' => 'Solo/one.mp3', 'title' => 'One', 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1]);

        $this->deleteJson("/api/v1/albums/{$album->id}/tracks/{$track->id}", [], writer($this->alice))->assertOk();

        expect($track->fresh()->path)->toBe('one.mp3')->and(is_dir($this->library.'/Solo'))->toBeFalse()->and(Album::find($album->id))->toBeNull();
    });

    it('refuses a name clash and leaves everything where it was', function () {
        $loose = Track::where('user_id', $this->alice->id)->where('path', 'Loose/07_no_tags.mp3')->sole();
        copy($this->library.'/Loose/07_no_tags.mp3', $this->library.'/Tagged/Album/07_no_tags.mp3');

        $this->postJson("/api/v1/albums/{$this->album->id}/tracks", ['track_id' => $loose->id], writer($this->alice))->assertUnprocessable();

        expect($loose->fresh()->path)->toBe('Loose/07_no_tags.mp3')->and($loose->fresh()->album_id)->toBeNull();
    });

    it('is the owner\'s alone', function () {
        $h = writer($this->alice);

        $this->postJson("/api/v1/albums/{$this->bobAlbum->id}/tracks", ['track_id' => $this->bobTrack->id], $h)->assertNotFound();
        $this->postJson("/api/v1/albums/{$this->album->id}/tracks", ['track_id' => $this->bobTrack->id], $h)->assertUnprocessable();
        $this->deleteJson("/api/v1/albums/{$this->bobAlbum->id}/tracks/{$this->bobTrack->id}", [], $h)->assertNotFound();
    });
});

describe('deleting', function () {
    it('deletes an own album and track with the delete ability', function () {
        $h = writer($this->alice);
        $track = $this->album->tracks()->first();

        $this->deleteJson("/api/v1/tracks/{$track->id}", [], $h)->assertOk()->assertJsonPath('removed', 1);
        $this->deleteJson("/api/v1/albums/{$this->album->id}", [], $h)->assertOk();
    });
});
