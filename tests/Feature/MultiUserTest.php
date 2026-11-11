<?php

use App\Models\Album;
use App\Models\SharedItem;
use App\Models\Track;
use App\Models\User;
use App\Services\LibraryScanner;
use App\Support\FileTruth;
use Illuminate\Http\UploadedFile;
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

    $this->aliceTrack = Track::where('user_id', $this->alice->id)->where('title', 'First Song')->sole();
    $this->aliceAlbum = $this->aliceTrack->album;
});

afterEach(fn () => File::deleteDirectory($this->music));

describe('libraries', function () {
    it('keeps two users with the same files apart', function () {
        expect(Track::where('user_id', $this->alice->id)->count())->toBe(5)
            ->and(Track::where('user_id', $this->bob->id)->count())->toBe(5)
            ->and(Album::where('title', 'Test Album')->count())->toBe(2)
            ->and(Album::where('user_id', $this->bob->id)->where('title', 'Test Album')->sole()->tracks)->toHaveCount(2);

        // A rescan of one user leaves the other's rows alone, and removing a file affects only its owner.
        File::delete($this->library.'/Loose/07_no_tags.mp3');
        app(LibraryScanner::class)->scan($this->alice);

        expect(Track::where('user_id', $this->alice->id)->count())->toBe(4)
            ->and(Track::where('user_id', $this->bob->id)->count())->toBe(5);
    });

    it('lists only your own music in the library, never the shared music of others', function () {
        SharedItem::create(['user_id' => $this->bob->id, 'album_id' => Album::where('user_id', $this->bob->id)->where('title', 'Mixtape')->sole()->id]);
        File::deleteDirectory($this->library.'/Compilation'); // only bob has Mixtape now
        app(LibraryScanner::class)->scan($this->alice);

        $this->actingAs($this->alice)->get(route('library'))
            ->assertOk()
            ->assertSee('Test Album')
            ->assertDontSee('Mixtape');
    });

    it('does not create a folder for a user with no uploads, and does not wipe a library whose folder vanished', function () {
        $carol = User::factory()->create(['username' => 'carol']);

        expect(app(LibraryScanner::class)->scan($carol)['added'])->toBe(0);

        File::deleteDirectory($this->library);

        expect(fn () => app(LibraryScanner::class)->scan($this->alice))->toThrow(RuntimeException::class)
            ->and(Track::where('user_id', $this->alice->id)->count())->toBe(5);
    });
});

describe('visibility', function () {
    it('hides another user\'s unshared track and cover with a 404', function () {
        $this->actingAs($this->bob);

        $this->get(route('stream', $this->aliceTrack))->assertNotFound();
        $this->get(route('cover', $this->aliceAlbum))->assertNotFound();
        $this->get(route('stream', 99999))->assertNotFound(); // same answer as for a missing id
    });

    it('lets the owner stream and see covers', function () {
        $this->actingAs($this->alice);

        $this->get(route('stream', $this->aliceTrack))->assertOk();
        $this->get(route('cover', $this->aliceAlbum))->assertOk();
    });

    it('shares a whole album live: its tracks and cover are open to others, including tracks added later', function () {
        SharedItem::create(['user_id' => $this->alice->id, 'album_id' => $this->aliceAlbum->id]);

        $this->actingAs($this->bob);
        $this->get(route('stream', $this->aliceTrack))->assertOk();
        $this->get(route('cover', $this->aliceAlbum))->assertOk();

        copy($this->library.'/Tagged/Album/01-first.mp3', $this->library.'/Tagged/Album/03-later.mp3');
        app(LibraryScanner::class)->scan($this->alice);
        $later = Track::where('user_id', $this->alice->id)->where('path', 'Tagged/Album/03-later.mp3')->sole();

        // Both the scope and the policy agree.
        expect(Track::visibleTo($this->bob)->whereKey($later->id)->exists())->toBeTrue()
            ->and($this->bob->can('view', $later))->toBeTrue();
        $this->get(route('stream', $later))->assertOk();
    });

    it('shares one track without sharing the rest of its album', function () {
        $other = Track::where('user_id', $this->alice->id)->where('title', 'Second Song')->sole();
        SharedItem::create(['user_id' => $this->alice->id, 'track_id' => $this->aliceTrack->id]);

        $this->actingAs($this->bob);
        $this->get(route('stream', $this->aliceTrack))->assertOk();
        $this->get(route('cover', $this->aliceAlbum))->assertOk(); // the player shows the cover of what plays
        $this->get(route('stream', $other))->assertNotFound();

        expect(Track::visibleTo($this->bob)->whereKey($other->id)->exists())->toBeFalse();
    });

    it('closes the door again when the share is removed or the album is deleted', function () {
        $share = SharedItem::create(['user_id' => $this->alice->id, 'album_id' => $this->aliceAlbum->id]);
        $this->actingAs($this->bob)->get(route('stream', $this->aliceTrack))->assertOk();

        $share->delete();
        $this->get(route('stream', $this->aliceTrack))->assertNotFound();

        $share = SharedItem::create(['user_id' => $this->alice->id, 'album_id' => $this->aliceAlbum->id]);
        $this->aliceAlbum->delete();
        expect(SharedItem::whereKey($share->id)->exists())->toBeFalse();
    });

    it('does not serve a track whose owner is missing', function () {
        $orphan = Track::create(['path' => 'Tagged/Album/01-first.mp3', 'title' => 'x', 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1]);

        $this->actingAs($this->alice)->get(route('stream', $orphan))->assertNotFound();
    });
});

describe('sharing', function () {
    it('shares and unshares an album and a track, for the owner only', function () {
        $this->actingAs($this->alice);

        $this->postJson(route('share.store', ['albums', $this->aliceAlbum->id]))->assertOk()->assertJson(['shared' => true]);
        $this->postJson(route('share.store', ['albums', $this->aliceAlbum->id]))->assertOk(); // twice is fine
        $this->postJson(route('share.store', ['tracks', $this->aliceTrack->id]))->assertOk();

        expect(SharedItem::count())->toBe(2)
            ->and(SharedItem::where('album_id', $this->aliceAlbum->id)->sole()->user_id)->toBe($this->alice->id);

        $this->actingAs($this->bob);
        $this->deleteJson(route('share.destroy', ['albums', $this->aliceAlbum->id]))->assertNotFound();
        $this->postJson(route('share.store', ['tracks', Track::where('user_id', $this->alice->id)->where('title', 'Second Song')->value('id')]))->assertNotFound();
        expect(SharedItem::count())->toBe(2);

        $this->actingAs($this->alice);
        $this->deleteJson(route('share.destroy', ['albums', $this->aliceAlbum->id]))->assertOk()->assertJson(['shared' => false]);
        $this->deleteJson(route('share.destroy', ['tracks', $this->aliceTrack->id]))->assertOk();
        expect(SharedItem::count())->toBe(0);
    });

    it('needs a signed-in user', function () {
        $this->postJson(route('share.store', ['albums', $this->aliceAlbum->id]))->assertUnauthorized();
        $this->get(route('shared'))->assertRedirect(route('login'));
    });

    it('lists what everyone shares on /shared, with who shares it, and keeps it apart from the library', function () {
        $bobAlbum = Album::where('user_id', $this->bob->id)->where('title', 'Mixtape')->sole();
        SharedItem::create(['user_id' => $this->alice->id, 'album_id' => $this->aliceAlbum->id]);
        SharedItem::create(['user_id' => $this->bob->id, 'album_id' => $bobAlbum->id]);
        SharedItem::create(['user_id' => $this->bob->id, 'track_id' => Track::where('user_id', $this->bob->id)->where('title', 'no tags')->value('id')]);

        $shared = $this->actingAs($this->alice)->getJson(route('sources', 'shared'))->assertOk()->json();
        $owner = collect($shared['folders'])->pluck('name', 'id');

        expect(collect($shared['albums'])->pluck('title')->sort()->values()->all())->toBe(['Mixtape', 'Test Album'])
            ->and($owner[collect($shared['albums'])->firstWhere('title', 'Test Album')['folder_id']])->toBe('You')
            ->and($owner[collect($shared['albums'])->firstWhere('title', 'Mixtape')['folder_id']])->toBe('bob')
            ->and(collect($shared['albums'])->firstWhere('title', 'Test Album')['mine'])->toBeTrue()
            ->and(collect($shared['albums'])->firstWhere('title', 'Mixtape')['mine'])->toBeFalse()
            ->and(collect($shared['loose'])->pluck('title')->all())->toBe(['no tags']);

        // /shared is the library page opened on that source, and the library's own data stays apart from it
        $this->get(route('shared'))->assertOk()->assertSee('"source":"shared"', false);
        $library = $this->getJson(route('library'))->assertOk()->json();
        expect(collect($library['albums'])->pluck('id')->all())->not->toContain($bobAlbum->id);
    });

    it('offers no Unshare and no file-truth findings for other people\'s music', function () {
        SharedItem::create(['user_id' => $this->alice->id, 'album_id' => $this->aliceAlbum->id]);
        $this->aliceTrack->forceFill(['analysis' => ['loudness' => ['integrated' => -9.0]], 'dr' => 5])->save();

        $json = $this->actingAs($this->bob)->getJson(route('sources', 'shared'))->assertOk();
        $album = $json->json('albums.0');

        expect($album['mine'])->toBeFalse()
            ->and($album['dr'])->toBeNull()
            ->and(collect($album['tracks'])->every(fn ($t) => $t['truth'] === ['analyzed' => false, 'flags' => []]))->toBeTrue();
        // no measurement of the file anywhere in the answer, nor in the page
        expect($json->getContent())->not->toContain('lufs')->not->toContain('integrated');
        $this->get(route('shared'))->assertOk()->assertDontSee('integrated');
    });

    it('keeps duplicate detection to your own library', function () {
        foreach ([$this->alice, $this->bob] as $user) {
            Track::where('user_id', $user->id)->update(['pcm_sha1' => str_repeat('a', 40)]);
        }
        Track::where('user_id', $this->bob->id)->where('title', 'First Song')->update(['pcm_sha1' => str_repeat('b', 40)]);

        Track::query()->update(['analysis' => ['loudness' => ['integrated' => -9.0]]]); // findings only exist for analysed tracks

        $page = $this->actingAs($this->bob)->getJson(route('library'))->assertOk()->json();
        $flags = collect($page['albums'])->flatMap(fn ($a) => $a['tracks'])->merge($page['loose'])->flatMap(fn ($t) => $t['truth']['flags'])->where('code', 'duplicate');
        $own = collect(FileTruth::copies(Track::where('user_id', $this->bob->id)->get()))->flatten(1)->where('exact', true);

        // exactly the duplicates inside bob's own library: alice's identical files add none
        expect($flags->count())->toBe($own->count());
    });
});

describe('uploads', function () {
    function oneSongZip(): UploadedFile
    {
        $path = test()->music.'/'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('Mine/one.mp3', file_get_contents(base_path('tests/Fixtures/music/Tagged/Album/01-first.mp3')));
        $zip->close();

        return new UploadedFile($path, 'Mine.zip', 'application/zip', null, true);
    }

    it('writes only into the uploader\'s own folder, creating it on the first upload', function () {
        $carol = User::factory()->create(['username' => 'carol']);

        $this->actingAs($carol)->postJson(route('upload.store'), ['archives' => [oneSongZip()]])->assertOk()->assertJsonPath('archives.0.ok', true);

        expect($this->music.'/users/carol/Mine/one.mp3')->toBeFile()
            ->and($this->music.'/Mine')->not->toBeDirectory()
            ->and($this->library.'/Mine')->not->toBeDirectory();
    });

    it('refuses an upload over the user\'s quota and writes nothing', function () {
        $this->alice->forceFill(['quota_mb' => 0])->save();

        $this->actingAs($this->alice)->postJson(route('upload.store'), ['archives' => [oneSongZip()]])
            ->assertOk()->assertJsonPath('archives.0.ok', false);

        expect($this->library.'/Mine')->not->toBeDirectory();
    });
});

describe('commands', function () {
    it('creates an administrator, and promotes an existing user', function () {
        $this->artisan('magnetite:user', ['username' => 'root', '--password' => 'long-enough', '--admin' => true])->assertSuccessful();
        expect(User::where('username', 'root')->sole()->is_admin)->toBeTrue()
            ->and($this->alice->fresh()->is_admin)->toBeFalse();

        $this->artisan('magnetite:user', ['username' => 'alice', '--admin' => true])->assertSuccessful();
        expect($this->alice->fresh()->is_admin)->toBeTrue();
    });

    it('rejects usernames that are not safe folder names', function () {
        foreach (['..', '.', '.hidden', '-x', 'a/b'] as $name) {
            $this->artisan('magnetite:user', ['username' => $name, '--password' => 'long-enough'])->assertFailed();
        }
    });

    it('scans one user or everyone', function () {
        Track::query()->delete();

        $this->artisan('magnetite:scan', ['username' => 'alice'])->assertSuccessful();
        expect(Track::where('user_id', $this->bob->id)->count())->toBe(0)->and(Track::where('user_id', $this->alice->id)->count())->toBe(5);

        $this->artisan('magnetite:scan')->assertSuccessful();
        expect(Track::where('user_id', $this->bob->id)->count())->toBe(5);

        $this->artisan('magnetite:scan', ['username' => 'nobody'])->assertFailed();
    });

    it('moves the old library into a user\'s folder and claims the unowned rows, once', function () {
        $music = sys_get_temp_dir().'/magnetite-claim-'.uniqid();
        File::copyDirectory(base_path('tests/Fixtures/music'), $music);
        config(['magnetite.music_path' => $music]);
        $dave = User::factory()->create(['username' => 'dave']);
        $album = Album::create(['title' => 'Old', 'artist' => 'Band']);
        $track = Track::create(['album_id' => $album->id, 'path' => 'Tagged/Album/01-first.mp3', 'title' => 'x', 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1]);

        $this->artisan('magnetite:claim', ['username' => 'dave'])->assertSuccessful();

        expect($music.'/users/dave/Tagged/Album/01-first.mp3')->toBeFile()
            ->and($music.'/Tagged')->not->toBeDirectory()
            ->and($track->fresh()->user_id)->toBe($dave->id)
            ->and($album->fresh()->user_id)->toBe($dave->id);

        $this->actingAs($dave)->get(route('stream', $track))->assertOk();

        // Nothing left to move: still succeeds and changes nothing.
        $this->artisan('magnetite:claim', ['username' => 'dave'])->assertSuccessful();
        $this->artisan('magnetite:claim', ['username' => 'nobody'])->assertFailed();

        File::deleteDirectory($music);
    });

    it('refuses to mix a claimed library into a folder that already has files', function () {
        $music = sys_get_temp_dir().'/magnetite-claim-'.uniqid();
        File::copyDirectory(base_path('tests/Fixtures/music'), $music);
        File::ensureDirectoryExists($music.'/users/dave');
        File::put($music.'/users/dave/mine.mp3', 'x');
        config(['magnetite.music_path' => $music]);
        User::factory()->create(['username' => 'dave']);

        $this->artisan('magnetite:claim', ['username' => 'dave'])->assertFailed();
        expect($music.'/Tagged')->toBeDirectory();

        File::deleteDirectory($music);
    });
});
