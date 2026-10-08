<?php

use App\Http\Controllers\CoverController;
use App\Http\Controllers\StreamController;
use App\Models\Album;
use App\Models\Track;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $albums = Album::with('tracks')->orderBy('artist')->orderBy('year')->orderBy('title')->get()
        ->each(fn (Album $a) => $a->tracks->each->setRelation('album', $a));

    $loose = Track::whereNull('album_id')->orderBy('title')->get();

    return view('pages.library', ['albums' => $albums, 'loose' => $loose]);
})->name('library');

Route::get('/themes', fn () => view('pages.themes'))->name('themes');
Route::get('/about', fn () => view('pages.about'))->name('about');
Route::get('/stream/{track}', StreamController::class)->whereNumber('track')->name('stream');
Route::get('/cover/{album}', CoverController::class)->whereNumber('album')->name('cover');
