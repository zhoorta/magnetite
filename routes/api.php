<?php

use App\Http\Controllers\Api\AlbumController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PlaylistController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SharedController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Support\Facades\Route;

// Token API for the Android app (docs/android.md). Visibility is decided by the same scopes and policies as the web.
Route::prefix('v1')->group(function () {
    Route::post('/token', [TokenController::class, 'store'])->name('api.token.store');

    Route::middleware(['auth:sanctum', 'throttle:120,1'])->group(function () {
        Route::delete('/token', [TokenController::class, 'destroy'])->name('api.token.destroy');
        Route::get('/me', MeController::class)->name('api.me');

        Route::get('/albums', [AlbumController::class, 'index'])->name('api.albums');
        Route::get('/albums/{album}', [AlbumController::class, 'show'])->whereNumber('album')->name('api.albums.show');
        Route::get('/shared', SharedController::class)->name('api.shared');
        Route::get('/playlists', [PlaylistController::class, 'index'])->name('api.playlists');
        Route::get('/playlists/{playlist}', [PlaylistController::class, 'show'])->whereNumber('playlist')->name('api.playlists.show');
        Route::get('/search', SearchController::class)->name('api.search');
    });
});
