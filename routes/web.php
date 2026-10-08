<?php

use App\Http\Controllers\CoverController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\UploadController;
use App\Models\Album;
use App\Models\Track;
use App\Support\FileTruth;
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
    Route::get('/library', function () {
        $albums = Album::with('tracks')->orderBy('artist')->orderBy('year')->orderBy('title')->get()
            ->each(fn (Album $a) => $a->tracks->each->setRelation('album', $a));

        $loose = Track::whereNull('album_id')->orderBy('title')->get();

        // Duplicates are found across the whole library, so the page gets them from every track.
        $copies = FileTruth::copies(Track::get(['id', 'title', 'artist', 'path', 'duration', 'pcm_sha1']));

        return view('pages.library', ['albums' => $albums, 'loose' => $loose, 'copies' => $copies]);
    })->name('library');

    Route::get('/stream/{track}', StreamController::class)->whereNumber('track')->name('stream');
    Route::get('/cover/{album}', CoverController::class)->whereNumber('album')->name('cover');

    Route::get('/upload', [UploadController::class, 'create'])->name('upload');
    Route::post('/upload', [UploadController::class, 'store'])->name('upload.store');

    Route::get('/scan', [ScanController::class, 'show'])->name('scan.show');
    Route::post('/scan', [ScanController::class, 'store'])->name('scan.store');
});
