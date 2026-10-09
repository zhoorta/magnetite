<?php

use App\Models\Album;
use App\Models\Playlist;
use App\Models\SharedItem;
use App\Models\Track;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

const RM = 'https://ferrite.test';

function trackRow(User $user, ?Album $album, string $path, array $extra = []): Track
{
    return Track::create($extra + [
        'user_id' => $user->id, 'album_id' => $album?->id, 'path' => $path, 'title' => pathinfo($path, PATHINFO_FILENAME),
        'mime' => 'audio/flac', 'size' => 1, 'mtime' => 1,
    ]);
}

describe('local library', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->user = makeLibrary('alice', fixtures: false);
        $this->other = User::factory()->create(['username' => 'bob']);
        $this->dir = config('magnetite.music_path').'/users/alice';
        mkdir("{$this->dir}/Band - Record", 0755, true);
        foreach (['01.flac', '02.flac'] as $f) {
            file_put_contents("{$this->dir}/Band - Record/{$f}", 'x');
        }
        file_put_contents("{$this->dir}/Band - Record/cover.jpg", 'img');
        $this->album = Album::create(['user_id' => $this->user->id, 'title' => 'Record', 'artist' => 'Band']);
        $this->t1 = trackRow($this->user, $this->album, 'Band - Record/01.flac');
        $this->t2 = trackRow($this->user, $this->album, 'Band - Record/02.flac');
    });

    afterEach(fn () => File::deleteDirectory(config('magnetite.music_path')));

    it('deletes a track\'s file and row, with its shares and playlist entries, and keeps the album while tracks remain', function () {
        $playlist = Playlist::create(['user_id' => $this->user->id, 'name' => 'Mix']);
        $playlist->tracks()->attach($this->t1->id, ['position' => 1]);
        SharedItem::create(['user_id' => $this->user->id, 'track_id' => $this->t1->id]);

        $this->actingAs($this->user)->deleteJson(route('library.tracks.destroy', $this->t1))->assertOk()->assertJson(['removed' => 1, 'failed' => 0]);

        expect(file_exists("{$this->dir}/Band - Record/01.flac"))->toBeFalse()
            ->and(file_exists("{$this->dir}/Band - Record/02.flac"))->toBeTrue()
            ->and(Track::find($this->t1->id))->toBeNull()
            ->and(SharedItem::count())->toBe(0)
            ->and($playlist->tracks()->count())->toBe(0)
            ->and(Album::find($this->album->id))->not->toBeNull();
    });

    it('deletes an album with all its files, and leaves the cover image in the folder', function () {
        $this->actingAs($this->user)->deleteJson(route('library.albums.destroy', $this->album))->assertOk()->assertJson(['removed' => 2]);

        expect(Album::count())->toBe(0)->and(Track::count())->toBe(0)
            ->and(file_exists("{$this->dir}/Band - Record/cover.jpg"))->toBeTrue()
            ->and(is_dir($this->dir))->toBeTrue();
    });

    it('removes folders the album leaves empty, but never the library folder', function () {
        unlink("{$this->dir}/Band - Record/cover.jpg");

        $this->actingAs($this->user)->deleteJson(route('library.albums.destroy', $this->album))->assertOk();

        expect(is_dir("{$this->dir}/Band - Record"))->toBeFalse()->and(is_dir($this->dir))->toBeTrue();
    });

    it('answers 404 for another user\'s album or track, and deletes nothing', function () {
        $this->actingAs($this->other)->deleteJson(route('library.albums.destroy', $this->album))->assertNotFound();
        $this->actingAs($this->other)->deleteJson(route('library.tracks.destroy', $this->t1))->assertNotFound();

        expect(Track::count())->toBe(2)->and(file_exists("{$this->dir}/Band - Record/01.flac"))->toBeTrue();
    });

    it('needs a signed-in user', function () {
        $this->deleteJson(route('library.albums.destroy', $this->album))->assertUnauthorized();
    });

    it('does not follow a stored path out of the library', function () {
        file_put_contents(dirname(config('magnetite.music_path')).'/magnetite-outside-'.basename(config('magnetite.music_path')).'.flac', 'keep');
        $outside = dirname(config('magnetite.music_path')).'/magnetite-outside-'.basename(config('magnetite.music_path')).'.flac';
        $evil = trackRow($this->user, null, '../../../'.basename($outside));

        $this->actingAs($this->user)->deleteJson(route('library.tracks.destroy', $evil))->assertOk();

        expect(file_exists($outside))->toBeTrue(); // the row went, the file outside did not
        unlink($outside);
    });

    it('keeps the row of a file that could not be deleted', function () {
        chmod("{$this->dir}/Band - Record", 0555);

        $res = $this->actingAs($this->user)->deleteJson(route('library.tracks.destroy', $this->t1));
        chmod("{$this->dir}/Band - Record", 0755);

        $res->assertStatus(502)->assertJson(['removed' => 0, 'failed' => 1]);
        expect(Track::find($this->t1->id))->not->toBeNull();
    })->skip(fn () => function_exists('posix_getuid') && posix_getuid() === 0, 'root can delete anything');
});

describe('Ferrite library', function () {
    beforeEach(function () {
        Storage::fake('local');
        Http::swap(new Factory);
        $this->user = User::factory()->create(['username' => 'remote', 'store' => ['driver' => 'ferrite', 'url' => RM, 'token' => 'tok']]);
        $this->album = Album::create(['user_id' => $this->user->id, 'title' => 'Record', 'artist' => 'Band']);
        $this->t1 = trackRow($this->user, $this->album, 'Band/01.flac', ['store_key' => '11', 'store_hash' => str_repeat('a', 64)]);
        $this->t2 = trackRow($this->user, $this->album, 'Band/02.flac', ['store_key' => '12', 'store_hash' => str_repeat('b', 64)]);
        $this->deleted = new ArrayObject;
    });

    function fakeFerriteTrash(ArrayObject $deleted, string $access = 'read+write+trash', int $status = 200): void
    {
        Http::fake([
            RM.'/api/v1/root' => Http::response(['name' => 'Music', 'access' => $access, 'quota' => ['used' => 0, 'limit' => null]]),
            RM.'/api/v1/files/*' => function (HttpRequest $r) use ($deleted, $status) {
                $deleted[] = $r->method().' '.basename($r->url());

                return Http::response(['trashed' => true], $status);
            },
        ]);
    }

    it('moves the files of an album to Ferrite\'s trash and removes the rows', function () {
        fakeFerriteTrash($this->deleted);

        $this->actingAs($this->user)->deleteJson(route('library.albums.destroy', $this->album))->assertOk()->assertJson(['removed' => 2]);

        expect($this->deleted->getArrayCopy())->toBe(['DELETE 11', 'DELETE 12'])
            ->and(Track::count())->toBe(0)->and(Album::count())->toBe(0);
        Http::assertSent(fn (HttpRequest $r) => $r->method() !== 'DELETE' || $r->hasHeader('Authorization', 'Bearer tok'));
    });

    it('refuses before touching anything when the token cannot trash', function () {
        fakeFerriteTrash($this->deleted, 'read+write');

        $this->actingAs($this->user)->deleteJson(route('library.albums.destroy', $this->album))
            ->assertStatus(502)->assertJson(['removed' => 0, 'failed' => 2])->assertJsonPath('error', fn ($e) => str_contains($e, 'trash'));

        expect($this->deleted->getArrayCopy())->toBe([])->and(Track::count())->toBe(2);
    });

    it('treats a file that is already gone from Ferrite as removed', function () {
        fakeFerriteTrash($this->deleted, status: 404);

        $this->actingAs($this->user)->deleteJson(route('library.tracks.destroy', $this->t1))->assertOk()->assertJson(['removed' => 1]);
        expect(Track::find($this->t1->id))->toBeNull();
    });

    it('stops at the first error and keeps what was not removed', function () {
        fakeFerriteTrash($this->deleted, status: 500);

        $this->actingAs($this->user)->deleteJson(route('library.albums.destroy', $this->album))->assertStatus(502);

        expect(Track::count())->toBe(2)->and($this->deleted)->toHaveCount(1);
    });

    it('still lets a read and write token upload', function () {
        fakeFerriteTrash($this->deleted, 'read+write+trash');

        expect($this->user->store()->canWrite())->toBeTrue();
    });
});

it('renders the library page with its Alpine component intact', function () {
    Storage::fake('local');
    $user = makeLibrary('dora', fixtures: false);
    $album = Album::create(['user_id' => $user->id, 'title' => 'Record', 'artist' => 'Band']);
    trackRow($user, $album, 'a.flac');

    $html = $this->actingAs($user)->get(route('library'))->assertOk()->getContent();

    // Blade directives are not compiled inside the attributes of a component tag: an `@js(...)` there reaches the browser as text.
    expect(html_entity_decode($html, ENT_QUOTES))->not->toContain('@js(')->toContain("remove('albums', {$album->id}, ");

    // The component is an attribute in double quotes: its script must not contain one, or the page prints code.
    preg_match('/<div x-data="(\{.*?)">\s*<div class="mb-6/s', $html, $m);
    expect($m[1] ?? '')->toContain('async remove(kind')->not->toContain('"');
    File::deleteDirectory(config('magnetite.music_path'));
});
