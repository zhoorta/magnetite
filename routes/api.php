<?php

use App\Http\Controllers\AlbumTitlesController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\Api\AlbumController;
use App\Http\Controllers\Api\AlbumCoverController;
use App\Http\Controllers\Api\AlbumTrackController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PlaylistController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SharedController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\TrackController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\CoverSearchController;
use App\Http\Controllers\FolderController as FolderWrites;
use App\Http\Controllers\LibraryEditController;
use App\Http\Controllers\LibraryRemoveController;
use App\Http\Controllers\MeterSettingController;
use App\Http\Controllers\PlaylistController as PlaylistWrites;
use App\Http\Controllers\ScanController;
use Illuminate\Support\Facades\Route;

// Token API for the Android app (docs/android.md). Visibility is decided by the same scopes and policies as the web.
Route::prefix('v1')->group(function () {
    Route::post('/token', [TokenController::class, 'store'])->name('api.token.store');

    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
        Route::delete('/token', [TokenController::class, 'destroy'])->name('api.token.destroy');
        Route::get('/me', MeController::class)->name('api.me');
        Route::put('/settings/meters', [MeterSettingController::class, 'update'])->name('api.settings.meters');

        Route::get('/albums', [AlbumController::class, 'index'])->name('api.albums');
        Route::get('/albums/{album}', [AlbumController::class, 'show'])->whereNumber('album')->name('api.albums.show');
        Route::get('/shared', SharedController::class)->name('api.shared');
        Route::get('/playlists', [PlaylistController::class, 'index'])->name('api.playlists');
        Route::get('/playlists/{playlist}', [PlaylistController::class, 'show'])->whereNumber('playlist')->name('api.playlists.show');
        Route::get('/search', SearchController::class)->name('api.search');
        Route::get('/tracks/{track}', [TrackController::class, 'show'])->whereNumber('track')->name('api.tracks.show');
        Route::get('/folders', [FolderController::class, 'index'])->name('api.folders');
        Route::get('/analysis', [AnalysisController::class, 'show'])->name('api.analysis.show');
        Route::get('/scan', [ScanController::class, 'show'])->name('api.scan.show');
        Route::get('/covers/find', [CoverSearchController::class, 'show'])->name('api.covers.find.show');

        // Changing the library needs a token that may write. The work and the rules are the web controllers', so both agree.
        Route::middleware('abilities:library:write')->group(function () {
            Route::put('/playlists/{playlist}/order', [PlaylistWrites::class, 'reorder'])->whereNumber('playlist')->name('api.playlists.order');
            Route::post('/playlists', [PlaylistWrites::class, 'store'])->name('api.playlists.store');
            Route::patch('/playlists/{playlist}', [PlaylistWrites::class, 'update'])->whereNumber('playlist')->name('api.playlists.update');
            Route::post('/playlists/{playlist}/tracks', [PlaylistWrites::class, 'addTrack'])->whereNumber('playlist')->name('api.playlists.tracks.store');
            Route::delete('/playlists/{playlist}/tracks/{track}', [PlaylistWrites::class, 'removeTrack'])->whereNumber(['playlist', 'track'])->name('api.playlists.tracks.destroy');
            Route::post('/playlists/{playlist}/copy', [PlaylistWrites::class, 'copy'])->whereNumber('playlist')->name('api.playlists.copy');
            Route::post('/analysis', [AnalysisController::class, 'store'])->name('api.analysis.store');
            Route::post('/scan', [ScanController::class, 'store'])->name('api.scan.store');
            Route::post('/folders', [FolderWrites::class, 'store'])->name('api.folders.store');
            Route::patch('/folders/{folder}', [FolderWrites::class, 'update'])->whereNumber('folder')->name('api.folders.update');
            Route::put('/albums/{album}/folder', [FolderWrites::class, 'moveAlbum'])->whereNumber('album')->name('api.albums.folder');
            Route::patch('/albums/{album}', [LibraryEditController::class, 'album'])->whereNumber('album')->name('api.albums.update');
            Route::patch('/tracks/{track}', [LibraryEditController::class, 'track'])->whereNumber('track')->name('api.tracks.update');
            Route::post('/albums/{album}/titles/find', [AlbumTitlesController::class, 'find'])->whereNumber('album')->name('api.albums.titles.find');
            Route::post('/albums/{album}/titles', [AlbumTitlesController::class, 'apply'])->whereNumber('album')->name('api.albums.titles.apply');
            Route::post('/covers/find', [CoverSearchController::class, 'store'])->name('api.covers.find');
            Route::post('/albums/{album}/cover/find', [CoverSearchController::class, 'album'])->whereNumber('album')->name('api.albums.cover.find');
            Route::put('/albums/{album}/cover', [AlbumCoverController::class, 'update'])->whereNumber('album')->name('api.albums.cover');
            Route::post('/uploads', [UploadController::class, 'store'])->middleware('throttle:60,1')->name('api.uploads');
            Route::post('/albums/{album}/tracks', [AlbumTrackController::class, 'store'])->whereNumber('album')->name('api.albums.tracks.store');
            Route::delete('/albums/{album}/tracks/{track}', [AlbumTrackController::class, 'destroy'])->whereNumber(['album', 'track'])->name('api.albums.tracks.destroy');
        });

        // Deleting for real is a separate ability.
        Route::middleware('abilities:library:delete')->group(function () {
            Route::delete('/folders/{folder}', [FolderWrites::class, 'destroy'])->whereNumber('folder')->name('api.folders.destroy');
            Route::delete('/playlists/{playlist}', [PlaylistWrites::class, 'destroy'])->whereNumber('playlist')->name('api.playlists.destroy');
            Route::delete('/albums/{album}', [LibraryRemoveController::class, 'album'])->whereNumber('album')->name('api.albums.destroy');
            Route::delete('/tracks/{track}', [LibraryRemoveController::class, 'track'])->whereNumber('track')->name('api.tracks.destroy');
        });
    });
});
