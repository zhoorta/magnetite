<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Services\AlbumIdentifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Details for an album the owner has already named: look the release up (MusicBrainz, by the album's title and artist,
 * checked against the files' count and lengths) for track titles, release date and record label, show what would change,
 * and apply what the owner ticks.
 * The candidates wait in the cache for the apply call, so the browser never sends titles back to be trusted.
 */
class AlbumTitlesController extends Controller
{
    private const KEEP = 1800;

    private function key(Request $request, Album $album): string
    {
        return "magnetite.titles.{$request->user()->id}.{$album->id}";
    }

    private function progressKey(Request $request, Album $album): string
    {
        return "magnetite.titles.progress.{$request->user()->id}.{$album->id}";
    }

    /** What the running search is doing, for the dialog to show while it waits for `find`. */
    public function progress(Request $request, Album $album): JsonResponse
    {
        abort_unless($request->user()->can('update', $album), 404);

        return response()->json(['text' => Cache::get($this->progressKey($request, $album), '')]);
    }

    public function find(Request $request, Album $album, AlbumIdentifier $identifier): JsonResponse
    {
        abort_unless($request->user()->can('update', $album), 404);

        $progress = $this->progressKey($request, $album);
        $identifier->onProgress(fn (string $text) => Cache::put($progress, $text, 120));

        try {
            $candidates = $identifier->titlesFor($album);
        } catch (Throwable $e) {
            return response()->json(['message' => 'MusicBrainz could not be reached. Try again in a moment.'], 502);
        }

        Cache::forget($progress);
        Cache::put($this->key($request, $album), $candidates, self::KEEP);

        return response()->json([
            'album' => ['release_date' => $album->released, 'label' => $album->label],
            'candidates' => $candidates,
            'report' => $identifier->report(),
        ]);
    }

    public function apply(Request $request, Album $album, AlbumIdentifier $identifier): JsonResponse
    {
        abort_unless($request->user()->can('update', $album), 404);
        $data = $request->validate([
            'candidate' => ['required', 'integer', 'min:0'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => ['in:titles,date,label'],
        ]);
        $index = $data['candidate'];

        $candidate = Cache::get($this->key($request, $album))[$index] ?? null;
        abort_if($candidate === null, 422, 'That search has expired. Look the titles up again.');

        $done = $identifier->applyDetails($album, $candidate, $data['parts']);
        Cache::forget($this->key($request, $album));

        return response()->json(['done' => $done]);
    }
}
