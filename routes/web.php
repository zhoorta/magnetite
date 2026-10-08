<?php

use App\Http\Controllers\CoverController;
use App\Http\Controllers\StreamController;
use App\Models\Album;
use App\Models\Track;
use App\Support\FileTruth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $albums = Album::with('tracks')->orderBy('artist')->orderBy('year')->orderBy('title')->get()
        ->each(fn (Album $a) => $a->tracks->each->setRelation('album', $a));

    $loose = Track::whereNull('album_id')->orderBy('title')->get();

    // Duplicates are found across the whole library, so the page gets them from every track.
    $copies = FileTruth::copies(Track::get(['id', 'title', 'artist', 'path', 'duration', 'pcm_sha1']));

    return view('pages.library', ['albums' => $albums, 'loose' => $loose, 'copies' => $copies]);
})->name('library');

Route::get('/themes', fn () => view('pages.themes'))->name('themes');
Route::get('/about', fn () => view('pages.about'))->name('about');
Route::get('/stream/{track}', StreamController::class)->whereNumber('track')->name('stream');
Route::get('/cover/{album}', CoverController::class)->whereNumber('album')->name('cover');
