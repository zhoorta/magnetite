<?php

use App\Http\Controllers\AdminUsersController;
use App\Http\Controllers\AlbumGroupController;
use App\Http\Controllers\AlbumMergeController;
use App\Http\Controllers\AlbumTitlesController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\CopyController;
use App\Http\Controllers\CoverController;
use App\Http\Controllers\CoverSearchController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\IdentifyController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LibraryController;
use App\Http\Controllers\LibraryEditController;
use App\Http\Controllers\LibraryRemoveController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\LooseTrackController;
use App\Http\Controllers\MeterSettingController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\PrefetchController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\TrackLookupController;
use App\Http\Controllers\UiSettingController;
use App\Http\Controllers\UploadController;
use App\Support\DemoTracks;
use App\Support\Guide;
use Illuminate\Support\Facades\Route;

Route::middleware('public-site')->group(function () {
    Route::get('/', fn () => view('pages.welcome', ['demo' => array_values(DemoTracks::available()), 'demoAlbums' => auth()->check() ? [] : DemoTracks::albumIds()]))->name('welcome');
    Route::get('/themes', fn () => view('pages.themes'))->name('themes');

    // The guide (docs/guide): only the pages listed in Guide::PAGES, never a path from the request.
    Route::get('/docs/{page?}', function (?string $page = null) {
        $html = Guide::html($page) ?? abort(404);

        return view('pages.guide', ['page' => $page, 'html' => $html, 'pages' => Guide::PAGES, 'neighbours' => Guide::neighbours($page)]);
    })->where('page', '[a-z-]+')->name('guide');
    Route::get('/demo/{id}', DemoController::class)->where('id', '[a-z0-9-]+')->middleware('throttle:120,1')->name('demo');
});

// First run only: 404 as soon as a user exists.
Route::get('/setup', [SetupController::class, 'create'])->name('setup');
Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:5,1')->name('setup.store');

Route::middleware('guest')->group(function () {
    Route::get('/reset/{token}', [PasswordController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->name('reset.show');
    Route::post('/reset/{token}', [PasswordController::class, 'reset'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('reset.accept');
    Route::get('/invite/{token}', [InvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->name('invite.show');
    Route::post('/invite/{token}', [InvitationController::class, 'accept'])->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:10,1')->name('invite.accept');
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// Files and covers answer the browser (session cookie) and the Android app (Bearer token) alike.
Route::middleware('auth:web,sanctum')->group(function () {
    Route::get('/stream/{track}', StreamController::class)->whereNumber('track')->name('stream');
    Route::get('/cover/{album}', CoverController::class)->whereNumber('album')->name('cover');
});

// A visitor who is not signed in sees the demo tracks here, read only (or the sign-in page when there are none).
Route::get('/library', [LibraryController::class, 'library'])->middleware('public-site')->name('library');

// The library's data, the files and the upload are only for signed-in users.
Route::middleware('auth')->group(function () {
    Route::get('/shared', [LibraryController::class, 'shared'])->name('shared');
    Route::get('/sources/{name}', [LibraryController::class, 'source'])->whereIn('name', ['shared', 'playlists'])->name('sources');
    Route::get('/library/tracks/{track}/spectrum', [LibraryController::class, 'spectrum'])->whereNumber('track')->name('library.tracks.spectrum');
    Route::get('/search', SearchController::class)->middleware('throttle:120,1')->name('search');

    Route::post('/share/{kind}/{id}', [ShareController::class, 'store'])->whereIn('kind', ['albums', 'tracks', 'playlists'])->whereNumber('id')->name('share.store');
    Route::delete('/share/{kind}/{id}', [ShareController::class, 'destroy'])->whereIn('kind', ['albums', 'tracks', 'playlists'])->whereNumber('id')->name('share.destroy');
    Route::post('/copy/{kind}/{id}', [CopyController::class, 'store'])->whereIn('kind', ['albums', 'tracks'])->whereNumber('id')->name('copy.store');

    Route::get('/settings/password', [PasswordController::class, 'edit'])->name('password');
    Route::put('/settings/password', [PasswordController::class, 'update'])->middleware('throttle:5,1')->name('password.update');
    Route::put('/settings/meters', [MeterSettingController::class, 'update'])->name('settings.meters');
    Route::put('/settings/ui', [UiSettingController::class, 'update'])->name('settings.ui');

    Route::get('/device', [DeviceController::class, 'create'])->name('device');
    Route::post('/device', [DeviceController::class, 'store'])->middleware('throttle:10,1')->name('device.store');

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

    Route::post('/prefetch/{track}', PrefetchController::class)->whereNumber('track')->middleware('throttle:60,1')->name('prefetch');

    Route::get('/covers/find', [CoverSearchController::class, 'show'])->name('covers.find.show');
    Route::post('/covers/find', [CoverSearchController::class, 'store'])->name('covers.find');
    Route::post('/cover/{album}/find', [CoverSearchController::class, 'album'])->whereNumber('album')->name('cover.find');

    Route::patch('/library/albums/{album}', [LibraryEditController::class, 'album'])->whereNumber('album')->name('library.albums.update');
    Route::patch('/library/albums/{album}/details', [LibraryEditController::class, 'details'])->whereNumber('album')->name('library.albums.details');
    Route::get('/library/tracks/{track}/lookup/progress', [TrackLookupController::class, 'progress'])->whereNumber('track')->name('library.tracks.lookup.progress');
    Route::post('/library/tracks/{track}/lookup', [TrackLookupController::class, 'find'])->whereNumber('track')->name('library.tracks.lookup');
    Route::post('/library/tracks/{track}/lookup/apply', [TrackLookupController::class, 'apply'])->whereNumber('track')->name('library.tracks.lookup.apply');
    Route::patch('/library/tracks/{track}', [LibraryEditController::class, 'track'])->whereNumber('track')->name('library.tracks.update');
    Route::post('/library/folders', [FolderController::class, 'store'])->name('library.folders.store');
    Route::patch('/library/folders/{folder}', [FolderController::class, 'update'])->whereNumber('folder')->name('library.folders.update');
    Route::delete('/library/folders/{folder}', [FolderController::class, 'destroy'])->whereNumber('folder')->name('library.folders.destroy');
    Route::put('/library/albums/{album}/folder', [FolderController::class, 'moveAlbum'])->whereNumber('album')->name('library.albums.folder');
    Route::get('/library/albums/{album}/titles/progress', [AlbumTitlesController::class, 'progress'])->whereNumber('album')->name('library.albums.titles.progress');
    Route::post('/library/albums/{album}/titles/find', [AlbumTitlesController::class, 'find'])->whereNumber('album')->name('library.albums.titles.find');
    Route::post('/library/albums/{album}/titles', [AlbumTitlesController::class, 'apply'])->whereNumber('album')->name('library.albums.titles.apply');
    Route::get('/library/tracks/loose', [LooseTrackController::class, 'index'])->name('library.tracks.loose');
    Route::post('/library/albums', [AlbumGroupController::class, 'store'])->name('library.albums.store');
    Route::post('/library/albums/{album}/merge', [AlbumMergeController::class, 'store'])->whereNumber('album')->name('library.albums.merge');
    Route::delete('/library/albums/{album}', [LibraryRemoveController::class, 'album'])->whereNumber('album')->name('library.albums.destroy');
    Route::delete('/library/tracks/{track}', [LibraryRemoveController::class, 'track'])->whereNumber('track')->name('library.tracks.destroy');

    Route::get('/identify', [IdentifyController::class, 'show'])->name('identify.show');
    Route::post('/identify', [IdentifyController::class, 'store'])->name('identify.store');
    Route::post('/identify/{suggestion}/accept', [IdentifyController::class, 'accept'])->whereNumber('suggestion')->name('identify.accept');
    Route::delete('/identify/{suggestion}', [IdentifyController::class, 'dismiss'])->whereNumber('suggestion')->name('identify.dismiss');

    Route::get('/admin/users', [AdminUsersController::class, 'index'])->middleware('admin')->name('admin.users');
    Route::post('/admin/users/{user}/reset', [PasswordController::class, 'link'])->whereNumber('user')->middleware('admin')->name('admin.users.reset');
    Route::post('/admin/invitations', [InvitationController::class, 'store'])->middleware('admin')->name('admin.invitations.store');
    Route::delete('/admin/invitations/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation')->middleware('admin')->name('admin.invitations.destroy');
    Route::patch('/admin/users/{user}/role', [AdminUsersController::class, 'role'])->whereNumber('user')->middleware('admin')->name('admin.users.role');
    Route::delete('/admin/users/{user}', [AdminUsersController::class, 'destroy'])->whereNumber('user')->middleware('admin')->name('admin.users.destroy');
    Route::patch('/admin/users/{user}/quota', [AdminUsersController::class, 'quota'])->whereNumber('user')->middleware('admin')->name('admin.users.quota');
    Route::get('/storage', [StorageController::class, 'show'])->name('storage');
    Route::post('/storage/test', [StorageController::class, 'test'])->name('storage.test');
    Route::delete('/storage/cache', [StorageController::class, 'clearCache'])->name('storage.cache.clear');

    Route::get('/upload', [UploadController::class, 'create'])->name('upload');
    Route::post('/upload', [UploadController::class, 'store'])->name('upload.store');

    Route::get('/analysis', [AnalysisController::class, 'show'])->name('analysis.show');
    Route::post('/analysis', [AnalysisController::class, 'store'])->name('analysis.store');

    Route::get('/scan', [ScanController::class, 'show'])->name('scan.show');
    Route::post('/scan', [ScanController::class, 'store'])->name('scan.store');
});
