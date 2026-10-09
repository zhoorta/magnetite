<?php

use App\Models\Album;
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
    app(LibraryScanner::class)->scan($this->alice);
    $this->album = Album::where('title', 'Test Album')->sole();
    $this->track = $this->album->tracks()->first();
});

afterEach(fn () => File::deleteDirectory($this->music));

describe('an album', function () {
    it('takes a new title, artist, year and label, and keeps the tracks safe from a rescan', function () {
        $this->actingAs($this->alice)->patchJson(route('library.albums.update', $this->album), [
            'title' => '  Better Title ', 'artist' => 'The Fixers', 'year' => 1999, 'label' => 'Warp Records',
        ])->assertOk()->assertJsonPath('album.label', 'Warp Records');

        $album = $this->album->fresh();
        expect($album->title)->toBe('Better Title')->and($album->artist)->toBe('The Fixers')->and($album->year)->toBe(1999)->and($album->label)->toBe('Warp Records')
            ->and($album->tracks()->where('identified', false)->count())->toBe(0);

        // A changed file is read again, but a confirmed track keeps its album, so no second album appears.
        Track::query()->update(['mtime' => 1]);
        app(LibraryScanner::class)->scan($this->alice);
        expect(Album::where('user_id', $this->alice->id)->where('title', 'Test Album')->exists())->toBeFalse()
            ->and($this->album->fresh()->title)->toBe('Better Title');
    });

    it('turns an empty artist, year or label into nothing', function () {
        $this->album->update(['label' => 'Old', 'year' => 2000]);

        $this->actingAs($this->alice)->patchJson(route('library.albums.update', $this->album), ['title' => 'T', 'artist' => '', 'year' => null, 'label' => ' '])->assertOk();

        $album = $this->album->fresh();
        expect($album->artist)->toBeNull()->and($album->year)->toBeNull()->and($album->label)->toBeNull();
    });

    it('needs a title, a sane year, and not the title and artist of another album of yours', function () {
        $other = Album::create(['user_id' => $this->alice->id, 'title' => 'Other', 'artist' => 'Someone']);
        $this->actingAs($this->alice);

        $this->patchJson(route('library.albums.update', $this->album), ['title' => ''])->assertStatus(422);
        $this->patchJson(route('library.albums.update', $this->album), ['title' => 'x', 'year' => 12])->assertStatus(422);
        $this->patchJson(route('library.albums.update', $this->album), ['title' => 'Other', 'artist' => 'Someone'])->assertStatus(422);
        expect($this->album->fresh()->title)->toBe('Test Album');
    });

    it('is the owner\'s alone', function () {
        $this->actingAs($this->bob)->patchJson(route('library.albums.update', $this->album), ['title' => 'Mine now'])->assertNotFound();
        expect($this->album->fresh()->title)->toBe('Test Album');
    });

    it('shows the label on the library and shared pages', function () {
        $this->album->update(['label' => 'Warp Records']);
        SharedItem::create(['user_id' => $this->alice->id, 'album_id' => $this->album->id]);

        $this->actingAs($this->alice)->get(route('library'))->assertSee('Warp Records');
        $this->get(route('shared'))->assertSee('Warp Records');
    });
});

describe('the release date', function () {
    it('takes a full or partial date and keeps the year in step', function () {
        $this->actingAs($this->alice);

        $this->patchJson(route('library.albums.update', $this->album), ['title' => 'T', 'release_date' => '2001-03-12'])->assertOk();
        expect($this->album->fresh()->release_date)->toBe('2001-03-12')->and($this->album->fresh()->year)->toBe(2001)->and($this->album->fresh()->released)->toBe('2001-03-12');

        $this->patchJson(route('library.albums.update', $this->album), ['title' => 'T', 'release_date' => '1999'])->assertOk();
        expect($this->album->fresh()->year)->toBe(1999);

        $this->patchJson(route('library.albums.update', $this->album), ['title' => 'T', 'release_date' => ''])->assertOk();
        expect($this->album->fresh()->release_date)->toBeNull()->and($this->album->fresh()->year)->toBeNull();
    });

    it('refuses a date that is not one', function () {
        $this->actingAs($this->alice);

        foreach (['2001-13-01', '2001-02-32', '12-03-2001', 'March 2001', '20010312'] as $bad) {
            $this->patchJson(route('library.albums.update', $this->album), ['title' => 'T', 'release_date' => $bad])->assertStatus(422);
        }
    });

    it('lets a year alone replace a date that says something else', function () {
        $this->album->update(['release_date' => '2001-03-12', 'year' => 2001]);

        $this->actingAs($this->alice)->patchJson(route('library.albums.update', $this->album), ['title' => 'T', 'year' => 2005])->assertOk();

        expect($this->album->fresh()->release_date)->toBeNull()->and($this->album->fresh()->year)->toBe(2005);
    });
});

describe('a track', function () {
    it('takes a new title, artist and number, and keeps them through a rescan', function () {
        $this->actingAs($this->alice)->patchJson(route('library.tracks.update', $this->track), ['title' => 'Fixed', 'artist' => 'Me', 'track_number' => 7])->assertOk();

        Track::query()->update(['mtime' => 1]);
        app(LibraryScanner::class)->scan($this->alice);

        $track = $this->track->fresh();
        expect($track->title)->toBe('Fixed')->and($track->artist)->toBe('Me')->and($track->track_number)->toBe(7)->and($track->identified)->toBeTrue();
    });

    it('needs a title and is the owner\'s alone', function () {
        $this->actingAs($this->alice)->patchJson(route('library.tracks.update', $this->track), ['title' => ''])->assertStatus(422);
        $this->actingAs($this->bob)->patchJson(route('library.tracks.update', $this->track), ['title' => 'x'])->assertNotFound();
    });

    it('needs a signed-in user', function () {
        $this->patchJson(route('library.tracks.update', $this->track), ['title' => 'x'])->assertUnauthorized();
        $this->patchJson(route('library.albums.update', $this->album), ['title' => 'x'])->assertUnauthorized();
    });
});

it('reads the record label from the tags when a file has one', function () {
    $file = $this->library.'/'.$this->track->path;
    $writer = new getid3_writetags;
    $writer->filename = $file;
    $writer->tagformats = ['id3v2.3'];
    $writer->overwrite_tags = true;
    $writer->tag_encoding = 'UTF-8';
    $writer->tag_data = ['title' => ['Labelled'], 'album' => ['Labelled Album'], 'artist' => ['A'], 'publisher' => ['Warp Records']];
    expect($writer->WriteTags())->toBeTrue();

    app(LibraryScanner::class)->scan($this->alice);

    expect(Album::where('title', 'Labelled Album')->sole()->label)->toBe('Warp Records');
});
