<?php

use Illuminate\Support\Facades\File;

beforeEach(fn () => makeLibrary());

afterEach(fn () => File::deleteDirectory($this->music));

it('lists only music files, with paths relative to the user\'s folder', function () {
    File::put("{$this->library}/Loose/notes.txt", 'not music');

    $paths = collect($this->user->store()->files())->map->path;

    expect($paths)->toContain('Loose/07_no_tags.mp3')->not->toContain('Loose/notes.txt')->and($paths->count())->toBe(5);
});

it('gives a local file for a music file, and nothing for a path outside the library', function () {
    $store = $this->user->store();

    expect($store->fetch('Loose/07_no_tags.mp3'))->toBe(realpath("{$this->library}/Loose/07_no_tags.mp3"))
        ->and($store->fetch('../../../etc/passwd'))->toBeNull()
        ->and($store->fetch('Loose/missing.mp3'))->toBeNull();
});

it('says it is unavailable when the folder is missing, and lists nothing', function () {
    File::deleteDirectory($this->library);

    expect($this->user->store()->isAvailable())->toBeFalse()
        ->and(iterator_to_array($this->user->store()->files()))->toBe([]);
});

it('finds a conventional cover image next to a track', function () {
    ob_start();
    imagejpeg(imagecreatetruecolor(4, 4));
    File::put("{$this->library}/Loose/Cover.JPG", ob_get_clean());

    expect($this->user->store()->folderCover('Loose/07_no_tags.mp3'))->toStartWith("\xFF\xD8")
        ->and($this->user->store()->folderCover('Tagged/Album/01-first.mp3'))->toBeNull();
});
