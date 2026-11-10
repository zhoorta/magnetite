<?php

use App\Models\Album;
use App\Models\SharedItem;
use App\Models\Track;
use App\Models\User;
use App\Services\AlbumDoctor;
use App\Services\LibraryScanner;
use App\Support\AlbumKey;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->alice = makeLibrary('alice', fixtures: false);
    $this->bob = User::factory()->create(['username' => 'bob']);
});

afterEach(fn () => File::deleteDirectory($this->music));

/** A copy of a fixture mp3 at `$relative` in alice's library with the given ID3 tags (title, artist, album, band = album artist, track). */
function taggedMp3(string $library, string $relative, array $tags): void
{
    require_once base_path('vendor/james-heinrich/getid3/getid3/getid3.php');
    require_once base_path('vendor/james-heinrich/getid3/getid3/write.php');

    $dest = "{$library}/{$relative}";
    File::ensureDirectoryExists(dirname($dest));
    copy(base_path('tests/Fixtures/music/Loose/07_no_tags.mp3'), $dest);

    $writer = new getid3_writetags;
    $writer->filename = $dest;
    $writer->tagformats = ['id3v2.3'];
    $writer->overwrite_tags = true;
    $writer->remove_other_tags = true;
    $writer->tag_encoding = 'UTF-8';
    $writer->tag_data = array_map(fn ($v) => [$v], $tags);
    expect($writer->WriteTags())->toBeTrue(implode('; ', $writer->errors));
}

function scanAlice(): array
{
    return app(LibraryScanner::class)->scan(test()->alice);
}

describe('the scanner', function () {
    it('keeps an album together when one file has an album-artist tag and its neighbours do not, tagged file first', function () {
        taggedMp3($this->library, 'Quicksilver/01 Hat.mp3', ['title' => 'The Hat', 'album' => 'Just For Love', 'band' => 'Quicksilver Messenger Service', 'track' => '4']);
        scanAlice();
        taggedMp3($this->library, 'Quicksilver/02 Cobra.mp3', ['title' => 'Cobra', 'album' => 'Just For Love', 'track' => '3']);
        taggedMp3($this->library, 'Quicksilver/03 Fresh Air.mp3', ['title' => 'Fresh Air', 'album' => 'Just For Love', 'track' => '7']);
        scanAlice();

        $album = Album::where('user_id', $this->alice->id)->sole();
        expect($album->artist)->toBe('Quicksilver Messenger Service')
            ->and($album->tracks)->toHaveCount(3);
    });

    it('keeps an album together when the untagged files come first, and the tagged one fills in the artist', function () {
        taggedMp3($this->library, 'Quicksilver/02 Cobra.mp3', ['title' => 'Cobra', 'album' => 'Just For Love', 'track' => '3']);
        scanAlice();
        expect(Album::where('user_id', $this->alice->id)->sole()->artist)->toBeNull();

        taggedMp3($this->library, 'Quicksilver/01 Hat.mp3', ['title' => 'The Hat', 'album' => 'Just For Love', 'band' => 'Quicksilver Messenger Service', 'track' => '4']);
        scanAlice();

        $album = Album::where('user_id', $this->alice->id)->sole();
        expect($album->artist)->toBe('Quicksilver Messenger Service')->and($album->tracks)->toHaveCount(2);
    });

    it('treats titles that differ only in case, spacing and quote shape as one album', function () {
        taggedMp3($this->library, 'X/01.mp3', ['title' => 'One', 'album' => "Don't Look Back", 'band' => 'Boston']);
        taggedMp3($this->library, 'X/02.mp3', ['title' => 'Two', 'album' => 'Don’t  look back ', 'band' => 'boston']);
        scanAlice();

        expect(Album::where('user_id', $this->alice->id)->count())->toBe(1);
    });

    it('keeps two albums apart when two different album artists share a folder and a title', function () {
        taggedMp3($this->library, 'Mix/01.mp3', ['title' => 'One', 'album' => 'Greatest Hits', 'band' => 'Artist A']);
        taggedMp3($this->library, 'Mix/02.mp3', ['title' => 'Two', 'album' => 'Greatest Hits', 'band' => 'Artist B']);
        scanAlice();

        expect(Album::where('user_id', $this->alice->id)->count())->toBe(2);
    });

    it('does not join a tagged and an untagged file at the top of the library, where unrelated albums share a folder', function () {
        taggedMp3($this->library, '01.mp3', ['title' => 'One', 'album' => 'Greatest Hits', 'band' => 'Artist A']);
        taggedMp3($this->library, '02.mp3', ['title' => 'Two', 'album' => 'Greatest Hits']);
        scanAlice();

        expect(Album::where('user_id', $this->alice->id)->count())->toBe(2);
    });

    it('compares titles the same way the albums table stores them', function () {
        expect(AlbumKey::title("  Don’t  Look\u{00A0}Back "))->toBe("don't look back")
            ->and(AlbumKey::artist('  '))->toBeNull();

        $album = Album::create(['user_id' => $this->alice->id, 'title' => 'Just For Love']);
        expect($album->title_key)->toBe('just for love');
        $album->update(['title' => 'Renamed ']);
        expect($album->fresh()->title_key)->toBe('renamed');
    });
});

/** Album with tracks at `{$dir}/{n}.mp3`; `$numbers` are the track numbers. */
function albumWith(User $user, string $title, ?string $artist, string $dir, array $numbers, ?string $trackArtist = 'Quicksilver Messenger Service'): Album
{
    $album = Album::create(['user_id' => $user->id, 'title' => $title, 'artist' => $artist, 'folder' => $artist === null ? $dir : null]);
    foreach ($numbers as $n) {
        Track::create(['user_id' => $user->id, 'album_id' => $album->id, 'path' => "{$dir}/{$title}-{$n}.mp3", 'title' => "T{$n}", 'artist' => $trackArtist, 'track_number' => $n, 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1]);
    }

    return $album;
}

describe('merging', function () {
    it('moves the tracks, carries over what the emptied album had and confirms the result', function () {
        $keep = albumWith($this->alice, 'Just For Love', null, 'Q', [2, 3, 5]);
        $other = albumWith($this->alice, 'Just For Love', 'Quicksilver Messenger Service', 'Q', [4]);
        $other->update(['year' => 1970, 'label' => 'Capitol']);
        SharedItem::create(['user_id' => $this->alice->id, 'album_id' => $other->id]);

        $this->actingAs($this->alice)->postJson(route('library.albums.merge', $other), ['into' => $keep->id])
            ->assertOk()
            ->assertJsonPath('album.id', $keep->id)
            ->assertJsonPath('album.track_count', 4)
            ->assertJsonPath('album.artist', 'Quicksilver Messenger Service')
            ->assertJsonPath('album.year', 1970)
            ->assertJsonPath('album.label', 'Capitol');

        expect(Album::find($other->id))->toBeNull()
            ->and(Track::where('album_id', $keep->id)->where('identified', true)->count())->toBe(4)
            ->and(SharedItem::where('album_id', $keep->id)->exists())->toBeTrue();
    });

    it('answers 404 for an album that is not the caller\'s and 422 for an album into itself', function () {
        $mine = albumWith($this->alice, 'Mine', null, 'M', [1]);
        $theirs = albumWith($this->bob, 'Theirs', null, 'T', [1]);

        $this->actingAs($this->alice)->postJson(route('library.albums.merge', $theirs), ['into' => $mine->id])->assertNotFound();
        $this->actingAs($this->alice)->postJson(route('library.albums.merge', $mine), ['into' => $theirs->id])->assertNotFound();
        $this->actingAs($this->alice)->postJson(route('library.albums.merge', $mine), ['into' => $mine->id])->assertUnprocessable();
        $this->actingAs($this->alice)->postJson(route('library.albums.merge', $mine), [])->assertUnprocessable();
    });

    it('is also an API call with a write token', function () {
        $keep = albumWith($this->alice, 'Just For Love', null, 'Q', [2, 3]);
        $other = albumWith($this->alice, 'Just For Love', 'QMS', 'Q', [4]);

        $headers = ['Authorization' => 'Bearer '.$this->alice->createToken('t')->plainTextToken];
        $this->postJson("/api/v1/albums/{$other->id}/merge", ['into' => $keep->id], $headers)->assertOk()->assertJsonPath('album.track_count', 3);
    });
});

describe('the album doctor', function () {
    it('finds a split by its folder and its numbers, and --fix joins it', function () {
        albumWith($this->alice, 'Just For Love', null, 'Q', [2, 3, 5, 6]);
        albumWith($this->alice, 'Just For Love', 'Quicksilver Messenger Service', 'Q', [4]);

        $suspects = app(AlbumDoctor::class)->suspects($this->alice);
        expect($suspects)->toHaveCount(1)
            ->and($suspects[0]['confidence'])->toBe('high')
            ->and($suspects[0]['merge']->tracks)->toHaveCount(1)
            ->and(implode(' ', $suspects[0]['reasons']))->toContain('same folder');

        $this->artisan('magnetite:albums:doctor', ['username' => 'alice'])->expectsOutputToContain('Nothing was changed')->assertSuccessful();
        expect(Album::where('user_id', $this->alice->id)->count())->toBe(2);

        $this->artisan('magnetite:albums:doctor', ['username' => 'alice', '--fix' => true])->expectsOutputToContain('merged')->assertSuccessful();
        $album = Album::where('user_id', $this->alice->id)->sole();
        expect($album->tracks)->toHaveCount(5)->and($album->artist)->toBe('Quicksilver Messenger Service');
    });

    it('leaves alone two different albums that share a title', function () {
        albumWith($this->alice, 'Greatest Hits', 'Artist A', 'A', [1, 2], 'Artist A');
        albumWith($this->alice, 'Greatest Hits', 'Artist B', 'B', [1, 2], 'Artist B');
        albumWith($this->alice, 'Greatest Hits', 'Artist C', 'A', [3], 'Artist C'); // another artist in the same folder

        expect(app(AlbumDoctor::class)->suspects($this->alice))->toBe([]);
    });

    it('only shows, and merges with --all, albums that might be two discs or editions', function () {
        albumWith($this->alice, 'Live', null, 'Disc1', [1, 2, 3]);
        albumWith($this->alice, 'Live', null, 'Disc2', [1, 2]); // same artist (from the tracks), same numbers, other folder

        $suspects = app(AlbumDoctor::class)->suspects($this->alice);
        expect($suspects)->toHaveCount(1)->and($suspects[0]['confidence'])->toBe('medium');

        $this->artisan('magnetite:albums:doctor', ['username' => 'alice', '--fix' => true])->assertSuccessful();
        expect(Album::where('user_id', $this->alice->id)->count())->toBe(2);

        $this->artisan('magnetite:albums:doctor', ['username' => 'alice', '--fix' => true, '--all' => true])->assertSuccessful();
        expect(Album::where('user_id', $this->alice->id)->count())->toBe(1);
    });

    it('says so when there is nothing to do, and for an unknown user', function () {
        $this->artisan('magnetite:albums:doctor', ['username' => 'alice'])->expectsOutputToContain('no split album')->assertSuccessful();
        $this->artisan('magnetite:albums:doctor', ['username' => 'nobody'])->assertFailed();
    });
});
