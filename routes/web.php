<?php

use App\Http\Controllers\CoverController;
use App\Http\Controllers\CoverSearchController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\MeterSettingController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('pages.welcome'))->name('welcome');
Route::get('/themes', fn () => view('pages.themes'))->name('themes');
Route::get('/about', fn () => view('pages.about'))->name('about');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// The library, the files and the upload are only for signed-in users.
Route::middleware('auth')->group(function () {
    Route::get('/library', [LibraryController::class, 'library'])->name('library');
    Route::get('/shared', [LibraryController::class, 'shared'])->name('shared');

    Route::post('/share/{kind}/{id}', [ShareController::class, 'store'])->whereIn('kind', ['albums', 'tracks', 'playlists'])->whereNumber('id')->name('share.store');
    Route::delete('/share/{kind}/{id}', [ShareController::class, 'destroy'])->whereIn('kind', ['albums', 'tracks', 'playlists'])->whereNumber('id')->name('share.destroy');

    Route::put('/settings/meters', [MeterSettingController::class, 'update'])->name('settings.meters');

    Route::get('/playlists', [PlaylistController::class, 'index'])->name('playlists');
    Route::post('/playlists', [PlaylistController::class, 'store'])->name('playlists.store');
    Route::get('/playlists/{playlist}', [PlaylistController::class, 'show'])->whereNumber('playlist')->name('playlists.show');
    Route::patch('/playlists/{playlist}', [PlaylistController::class, 'update'])->whereNumber('playlist')->name('playlists.update');
    Route::delete('/playlists/{playlist}', [PlaylistController::class, 'destroy'])->whereNumber('playlist')->name('playlists.destroy');
    Route::post('/playlists/{playlist}/tracks', [PlaylistController::class, 'addTrack'])->whereNumber('playlist')->name('playlists.tracks.store');
    Route::delete('/playlists/{playlist}/tracks/{track}', [PlaylistController::class, 'removeTrack'])->whereNumber(['playlist', 'track'])->name('playlists.tracks.destroy');
    Route::post('/playlists/{playlist}/share-tracks', [PlaylistController::class, 'shareTracks'])->whereNumber('playlist')->name('playlists.share-tracks');
    Route::post('/playlists/{playlist}/copy', [PlaylistController::class, 'copy'])->whereNumber('playlist')->name('playlists.copy');
    Route::put('/playlists/{playlist}/order', [PlaylistController::class, 'reorder'])->whereNumber('playlist')->name('playlists.order');

    Route::get('/stream/{track}', StreamController::class)->whereNumber('track')->name('stream');
    Route::get('/cover/{album}', CoverController::class)->whereNumber('album')->name('cover');

    Route::get('/covers/find', [CoverSearchController::class, 'show'])->name('covers.find.show');
    Route::post('/covers/find', [CoverSearchController::class, 'store'])->name('covers.find');
    Route::post('/cover/{album}/find', [CoverSearchController::class, 'album'])->whereNumber('album')->name('cover.find');

    Route::get('/upload', [UploadController::class, 'create'])->name('upload');
    Route::post('/upload', [UploadController::class, 'store'])->name('upload.store');

    Route::get('/scan', [ScanController::class, 'show'])->name('scan.show');
    Route::post('/scan', [ScanController::class, 'store'])->name('scan.store');
});
