<?php

namespace App\Http\Controllers;

use App\Services\FerriteException;
use App\Services\FerriteStore;
use App\Support\ScanStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only view of where the signed-in user's library lives and whether it works. Nothing here changes the setup
 * (that is `php artisan magnetite:storage`) and the token is never sent to the browser.
 */
class StorageController extends Controller
{
    /** A token that ends within this many days is flagged. */
    private const EXPIRY_WARNING_DAYS = 14;

    public function show(Request $request): View
    {
        $user = $request->user();
        $store = $user->store();
        $remote = $store instanceof FerriteStore;

        return view('pages.storage', [
            'remote' => $remote,
            'address' => $remote ? $store->url() : null,
            'folder' => $remote ? null : $user->libraryPath(),
            'tracks' => $user->tracks()->count(),
            'libraryBytes' => (int) $user->tracks()->sum('size'),
            'scan' => ScanStatus::get($user->id),
            'cache' => FerriteStore::cacheStats(),
            'cacheMaxBytes' => (int) (config('magnetite.ferrite.cache_max_gb') * 1024 ** 3),
        ]);
    }

    /** Ask Ferrite what the token reaches; failures come back as plain words, not as an error page. */
    public function test(Request $request): JsonResponse
    {
        $store = $request->user()->store();

        if (! $store instanceof FerriteStore) {
            return response()->json(['ok' => $store->isAvailable(), 'error' => $store->isAvailable() ? null : $store->unavailableReason()]);
        }

        try {
            $root = $store->root();
        } catch (FerriteException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }

        $expires = ($root['expires_at'] ?? null) ? now()->parse($root['expires_at']) : null;

        return response()->json([
            'ok' => true,
            'folder' => $root['name'] ?? null,
            'access' => $root['access'] ?? null,
            'expires_at' => $expires?->toDateString(),
            'expires_soon' => $expires !== null && $expires->lte(now()->addDays(self::EXPIRY_WARNING_DAYS)),
            'quota' => $root['quota'] ?? null,
        ]);
    }

    public function clearCache(Request $request): JsonResponse
    {
        abort_unless($request->user()->store() instanceof FerriteStore, 404);

        return response()->json(FerriteStore::clearCache());
    }
}
