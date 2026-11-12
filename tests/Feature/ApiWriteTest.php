<?php

use App\Models\Album;
use App\Models\Folder;
use App\Models\Playlist;
use App\Models\SharedItem;
use App\Models\Track;
use App\Models\User;
use App\Services\LibraryScanner;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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

describe('playlist order', function () {
    beforeEach(function () {
        $this->playlist = Playlist::create(['user_id' => $this->alice->id, 'name' => 'Mine']);
        $this->one = Track::where('user_id', $this->alice->id)->where('title', 'First Song')->sole();
        $this->two = Track::where('user_id', $this->alice->id)->where('title', 'Second Song')->sole();
        $this->playlist->tracks()->attach($this->one->id, ['position' => 1]);
        $this->playlist->tracks()->attach($this->two->id, ['position' => 2]);
    });

    it('reorders my playlist', function () {
        $this->putJson("/api/v1/playlists/{$this->playlist->id}/order", ['ids' => [$this->two->id, $this->one->id]], writer($this->alice))->assertOk();

        expect($this->playlist->fresh()->tracks->pluck('title')->all())->toBe(['Second Song', 'First Song']);
    });

    it('refuses a read-only token', function () {
        $this->putJson("/api/v1/playlists/{$this->playlist->id}/order", ['ids' => [$this->two->id, $this->one->id]], writer($this->alice, readOnly: true))->assertForbidden();
    });

    it('answers 404 for someone else\'s playlist', function () {
        $this->putJson("/api/v1/playlists/{$this->playlist->id}/order", ['ids' => [$this->two->id, $this->one->id]], writer($this->bob))->assertNotFound();
    });
});

describe('playlist editing', function () {
    beforeEach(function () {
        $this->one = Track::where('user_id', $this->alice->id)->where('title', 'First Song')->sole();
        $this->playlist = Playlist::create(['user_id' => $this->alice->id, 'name' => 'Mine']);
    });

    it('creates, renames, adds, removes and deletes my playlist', function () {
        $id = $this->postJson('/api/v1/playlists', ['name' => 'Road trip', 'track_id' => $this->one->id], writer($this->alice))->assertCreated()->json('id');
        expect(Playlist::find($id)->tracks)->toHaveCount(1);

        $this->patchJson("/api/v1/playlists/{$id}", ['name' => 'Drive'], writer($this->alice))->assertOk();
        expect(Playlist::find($id)->name)->toBe('Drive');

        $this->postJson("/api/v1/playlists/{$this->playlist->id}/tracks", ['album_id' => $this->album->id], writer($this->alice))->assertOk();
        expect($this->playlist->fresh()->tracks()->count())->toBeGreaterThan(0);

        $this->deleteJson("/api/v1/playlists/{$this->playlist->id}/tracks/{$this->one->id}", [], writer($this->alice))->assertOk();
        expect($this->playlist->fresh()->tracks()->where('tracks.id', $this->one->id)->exists())->toBeFalse();

        $this->deleteJson("/api/v1/playlists/{$id}", [], writer($this->alice))->assertOk();
        expect(Playlist::find($id))->toBeNull();
    });

    it('does not let me add a track I cannot see', function () {
        $this->postJson("/api/v1/playlists/{$this->playlist->id}/tracks", ['track_id' => $this->bobTrack->id], writer($this->alice))->assertNotFound();
    });

    it('answers 404 for someone else\'s playlist', function () {
        $this->patchJson("/api/v1/playlists/{$this->playlist->id}", ['name' => 'x'], writer($this->bob))->assertNotFound();
    });

    it('answers 404 when someone else deletes my playlist', function () {
        $this->deleteJson("/api/v1/playlists/{$this->playlist->id}", [], writer($this->bob))->assertNotFound();
    });

    it('refuses a read-only token', function () {
        $this->postJson('/api/v1/playlists', ['name' => 'x'], writer($this->alice, readOnly: true))->assertForbidden();
    });

    it('refuses a token that may not delete', function () {
        $this->deleteJson("/api/v1/playlists/{$this->playlist->id}", [], writer($this->alice, readOnly: true))->assertForbidden();
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

describe('what the owner sees about a track and an album', function () {
    it('carries duplicate findings, the album DR, and the spectrum only when asked', function () {
        $tracks = $this->album->tracks()->get();
        $analysis = ['loudness' => ['integrated' => -14.0], 'spectrum' => ['cutoff_hz' => 16000, 'content_hz' => 16000, 'step_hz' => 500, 'db' => [-20.0, -30.0]]];
        foreach ($tracks as $t) {
            $t->update(['analysis' => $analysis, 'dr' => 9, 'pcm_sha1' => 'same']);
        }
        $h = writer($this->alice);

        $album = $this->getJson("/api/v1/albums/{$this->album->id}", $h)->assertOk()->assertJsonPath('data.dr', 9);
        expect(collect($album->json('data.tracks.0.truth.flags'))->pluck('code')->all())->toContain('duplicate')
            ->and($album->json('data.tracks.0.truth'))->not->toHaveKey('spectrum');

        $one = $this->getJson("/api/v1/tracks/{$tracks[0]->id}?spectrum=1", $h)->assertOk();
        expect($one->json('data.truth.spectrum'))->toEqual([-20, -30])->and($one->json('data.truth.step_hz'))->toBe(500);

        // The list of albums carries no tracks, but the mean DR of the tracks comes with the query; someone else's albums never carry it.
        $this->getJson('/api/v1/albums', $h)->assertJsonPath('data.0.dr', 9)->assertJsonMissingPath('data.0.tracks.0');
    });

    it('follows and starts an analysis through the API, for one\'s own albums only', function () {
        config(['queue.default' => 'database']);
        $h = writer($this->alice);

        $this->getJson('/api/v1/analysis', $h)->assertOk()->assertJsonStructure(['state']);
        $this->postJson('/api/v1/analysis', ['album_id' => $this->bobAlbum->id], $h)->assertNotFound();
        app('auth')->forgetGuards(); // the guard keeps the user of the previous request
        $this->postJson('/api/v1/analysis', [], writer($this->alice, readOnly: true))->assertForbidden();
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

function apiZip(array $entries, string $name = 'Album.zip'): UploadedFile
{
    $path = sys_get_temp_dir().'/magnetite-api-zip-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    foreach ($entries as $entry => $contents) {
        $zip->addFromString($entry, $contents);
    }
    $zip->close();

    return new UploadedFile($path, $name, 'application/zip', null, true);
}

describe('naming an album for loose tracks', function () {
    beforeEach(function () {
        $this->loose = collect(['a.mp3', 'b.mp3'])->map(fn ($n) => Track::create(['user_id' => $this->alice->id, 'album_id' => null, 'path' => 'Some Folder/'.$n, 'title' => $n, 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1]));
    });

    it('lists loose tracks by folder, with the folder\'s name and no path', function () {
        $r = $this->getJson('/api/v1/tracks/loose?since='.(time() - 3600), writer($this->alice))->assertOk();

        expect(collect($r->json('groups'))->firstWhere('folder', 'Some Folder')['tracks'])->toHaveCount(2)
            ->and(json_encode($r->json()))->not->toContain('Some Folder/');
        $this->getJson('/api/v1/tracks/loose?since='.(time() + 3600), writer($this->alice))->assertJsonCount(0, 'groups');
    });

    it('lists nobody else\'s loose tracks', function () {
        expect(collect($this->getJson('/api/v1/tracks/loose', writer($this->bob))->assertOk()->json('groups'))->pluck('folder')->all())->not->toContain('Some Folder');
    });

    it('makes an album of them, kept through a rescan', function () {
        $r = $this->postJson('/api/v1/albums', ['title' => 'Recados', 'artist' => 'Jáfumega', 'year' => 1983, 'track_ids' => $this->loose->pluck('id')->all()], writer($this->alice))
            ->assertCreated()->assertJsonPath('data.title', 'Recados')->assertJsonPath('data.year', 1983)->assertJsonPath('data.track_count', 2);

        $this->loose->each(fn ($t) => expect($t->fresh())->album_id->toBe($r->json('data.id'))->identified->toBeTrue());
    });

    it('numbers the tracks from their file names, so the album plays in order and not alphabetically', function () {
        $files = ['03 - Alpha.mp3', '01 - Zulu.mp3', '02 - Mike.mp3'];
        $tracks = collect($files)->map(fn ($n) => Track::create(['user_id' => $this->alice->id, 'album_id' => null, 'path' => 'Order Test/'.$n, 'title' => pathinfo($n, PATHINFO_FILENAME), 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1]));
        $tagged = Track::create(['user_id' => $this->alice->id, 'album_id' => null, 'path' => 'Order Test/09 - Tagged.mp3', 'title' => 'Real Title', 'track_number' => 4, 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1]);

        $id = $this->postJson('/api/v1/albums', ['title' => 'Order', 'track_ids' => $tracks->pluck('id')->push($tagged->id)->all()], writer($this->alice))->assertCreated()->json('data.id');

        expect(Album::find($id)->tracks->map(fn ($t) => $t->track_number.':'.$t->title)->all())->toBe(['1:Zulu', '2:Mike', '3:Alpha', '4:Real Title']);
    });

    it('numbers the tracks of an existing album with magnetite:albums:number, only with --fix', function () {
        $album = Album::create(['user_id' => $this->alice->id, 'title' => 'Old', 'artist' => 'Band']);
        $mk = fn ($n) => Track::create(['user_id' => $this->alice->id, 'album_id' => $album->id, 'path' => 'Old/'.$n.'.mp3', 'title' => $n, 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1]);
        $mk('02 Beta');
        $mk('01 Alpha');

        $this->artisan('magnetite:albums:number', ['username' => 'alice', '--album' => $album->id])->assertSuccessful();
        expect($album->tracks()->whereNull('track_number')->count())->toBe(2);

        $this->artisan('magnetite:albums:number', ['username' => 'alice', '--album' => $album->id, '--fix' => true])->assertSuccessful();
        expect($album->fresh()->tracks->pluck('title')->all())->toBe(['Alpha', 'Beta']);
    });

    it('does not take tracks that are another user\'s or already in an album', function () {
        $theirs = Track::where('user_id', $this->bob->id)->first();
        $this->postJson('/api/v1/albums', ['title' => 'Nope', 'track_ids' => [$theirs->id]], writer($this->alice))->assertUnprocessable();

        $mine = Track::where('user_id', $this->alice->id)->whereNotNull('album_id')->first();
        $this->postJson('/api/v1/albums', ['title' => 'Nope', 'track_ids' => [$mine->id]], writer($this->alice))->assertUnprocessable();
        expect($theirs->fresh()->album_id)->not->toBeNull();
    });

    it('needs the write ability', function () {
        $this->postJson('/api/v1/albums', ['title' => 'X', 'track_ids' => [$this->loose[0]->id]], writer($this->alice, readOnly: true))->assertForbidden();
    });
});

describe('uploading zips', function () {
    it('unpacks a zip into the user\'s folder, queues a scan and says where to look for the result', function () {
        $audio = file_get_contents(base_path('tests/Fixtures/music/Loose/07_no_tags.mp3'));
        $before = time();

        $r = $this->post('/api/v1/uploads', ['archives' => [apiZip(['Band - Record/01 One.mp3' => $audio, 'Band - Record/02 Two.mp3' => $audio])]], writer($this->alice) + ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('archives.0.ok', true)->assertJsonPath('archives.0.added', 2)->assertJsonPath('scan.state', 'queued');

        expect(is_file($this->library.'/Band - Record/01 One.mp3'))->toBeTrue()->and($r->json('since'))->toBeGreaterThanOrEqual($before);
    });

    it('takes files and zips in one request and refuses a zip without audio', function () {
        $h = writer($this->alice) + ['Accept' => 'application/json'];

        $r = $this->post('/api/v1/uploads', ['files' => [audioUpload('loose.mp3')], 'archives' => [apiZip(['readme.txt' => 'hi'])]], $h)->assertCreated();

        expect($r->json('files.0.status'))->toBe('added')->and($r->json('archives.0.ok'))->toBeFalse()->and($r->json('archives.0.error'))->toContain('no audio');
    });

    it('refuses an upload with neither, and a zip that tries to leave the folder', function () {
        $h = writer($this->alice) + ['Accept' => 'application/json'];
        $this->post('/api/v1/uploads', [], $h)->assertUnprocessable();

        $audio = file_get_contents(base_path('tests/Fixtures/music/Loose/07_no_tags.mp3'));
        $r = $this->post('/api/v1/uploads', ['archives' => [apiZip(['../../escape.mp3' => $audio, 'ok/a.mp3' => $audio])]], $h)->assertCreated();

        expect($r->json('archives.0.ok'))->toBeFalse()->and(is_file($this->music.'/escape.mp3'))->toBeFalse();
    });

    it('refuses a read-only token', function () {
        $this->post('/api/v1/uploads', ['archives' => [apiZip(['a.mp3' => 'x'])]], writer($this->alice, readOnly: true) + ['Accept' => 'application/json'])->assertForbidden();
    });

    it('lists only the albums that got tracks since a time', function () {
        $h = writer($this->alice);
        $old = Album::where('user_id', $this->alice->id)->count();
        expect($old)->toBeGreaterThan(0);

        $this->getJson('/api/v1/albums?since='.(time() + 3600), $h)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/albums?since='.(time() - 3600), $h)->assertOk()->assertJsonCount($old, 'data');
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

describe('in a Ferrite library', function () {
    beforeEach(function () {
        Http::swap(new Factory);
        $this->remote = User::factory()->create(['username' => 'remote', 'store' => ['driver' => 'ferrite', 'url' => 'https://ferrite.test', 'token' => 'tok']]);
        $this->remoteAlbum = Album::create(['user_id' => $this->remote->id, 'title' => 'Record', 'artist' => 'Band']);
        $row = fn ($path, $key) => Track::create(['user_id' => $this->remote->id, 'album_id' => null, 'path' => $path, 'title' => basename($path), 'mime' => 'audio/flac', 'size' => 1, 'mtime' => 1, 'store_key' => $key, 'store_hash' => str_repeat('a', 64)]);
        $this->inAlbum = $row('Band/Record/01.flac', '11');
        $this->inAlbum->update(['album_id' => $this->remoteAlbum->id]);
        $this->loose = $row('Loose.flac', '13');
        $this->sent = new ArrayObject;
    });

    function fakeFerriteMove(ArrayObject $sent, array $answer, int $status = 200): void
    {
        Http::swap(new Factory); // a new fake would otherwise sit behind the stubs of an earlier one
        Http::fake(['https://ferrite.test/api/v1/files/*' => function (HttpRequest $r) use ($sent, $answer, $status) {
            $sent[] = $r->method().' '.basename($r->url()).' '.json_encode($r->data());

            return Http::response($answer, $status);
        }]);
    }

    it('asks Ferrite to move the file into the album\'s folder and keeps the new path', function () {
        fakeFerriteMove($this->sent, ['id' => 13, 'path' => 'Band/Record/Loose.flac']);

        $this->postJson("/api/v1/albums/{$this->remoteAlbum->id}/tracks", ['track_id' => $this->loose->id], writer($this->remote))
            ->assertCreated()->assertJsonPath('data.album_id', $this->remoteAlbum->id);

        expect($this->sent->getArrayCopy())->toBe(['PATCH 13 {"folder":"Band\/Record"}'])
            ->and($this->loose->fresh()->path)->toBe('Band/Record/Loose.flac')->and($this->loose->fresh()->identified)->toBeTrue();
    });

    it('asks Ferrite to move the file out to the folder that holds its folder', function () {
        fakeFerriteMove($this->sent, ['id' => 11, 'path' => 'Band/01.flac']);

        $this->deleteJson("/api/v1/albums/{$this->remoteAlbum->id}/tracks/{$this->inAlbum->id}", [], writer($this->remote))->assertOk();

        expect($this->sent->getArrayCopy())->toBe(['PATCH 11 {"folder":"Band"}'])
            ->and($this->inAlbum->fresh()->path)->toBe('Band/01.flac')->and($this->inAlbum->fresh()->album_id)->toBeNull();
    });

    it('leaves the track alone when Ferrite refuses: a clash, or a token that cannot write', function () {
        fakeFerriteMove($this->sent, ['error' => 'exists'], 409);
        $this->postJson("/api/v1/albums/{$this->remoteAlbum->id}/tracks", ['track_id' => $this->loose->id], writer($this->remote))->assertUnprocessable();

        fakeFerriteMove($this->sent, [], 403);
        $this->postJson("/api/v1/albums/{$this->remoteAlbum->id}/tracks", ['track_id' => $this->loose->id], writer($this->remote))
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'write'));

        expect($this->loose->fresh()->path)->toBe('Loose.flac')->and($this->loose->fresh()->album_id)->toBeNull();
    });
});
