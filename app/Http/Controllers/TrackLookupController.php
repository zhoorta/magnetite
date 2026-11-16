<?php

namespace App\Http\Controllers;

use App\Models\Track;
use App\Services\TrackLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What AcoustID and MusicBrainz know about one track (see `TrackLookup`). The candidates wait in the cache for the apply
 * call, so the browser never sends titles back to be trusted. Applies title, artist and track number to the library
 * only, never to the file.
 */
class TrackLookupController extends Controller
{
    private const KEEP = 1800;

    private function key(Request $request, Track $track, string $what): string
    {
        return "magnetite.track-lookup.{$what}.{$request->user()->id}.{$track->id}";
    }

    public function progress(Request $request, Track $track): JsonResponse
    {
        abort_unless($request->user()->can('update', $track), 404);

        return response()->json(['text' => Cache::get($this->key($request, $track, 'progress'), '')]);
    }

    public function find(Request $request, Track $track, TrackLookup $lookup): JsonResponse
    {
        abort_unless($request->user()->can('update', $track), 404);

        $progress = $this->key($request, $track, 'progress');
        $lookup->onProgress(fn (string $text) => Cache::put($progress, $text, 120));

        try {
            $candidates = $lookup->find($track);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e instanceof \RuntimeException ? $e->getMessage() : 'AcoustID could not be reached. Try again in a moment.'], 502);
        } finally {
            Cache::forget($progress);
        }

        Cache::put($this->key($request, $track, 'candidates'), $candidates, self::KEEP);

        return response()->json([
            'track' => $track->only(['id', 'title', 'artist', 'track_number']),
            'candidates' => $candidates,
        ]);
    }

    public function apply(Request $request, Track $track): JsonResponse
    {
        abort_unless($request->user()->can('update', $track), 404);
        $data = $request->validate([
            'candidate' => ['required', 'integer', 'min:0'],
            'release' => ['nullable', 'integer', 'min:0'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => ['in:title,artist,number'],
        ]);

        $candidate = Cache::get($this->key($request, $track, 'candidates'))[$data['candidate']] ?? null;
        abort_if($candidate === null, 422, 'That lookup has expired. Look the track up again.');
        $release = isset($data['release']) ? ($candidate['releases'][$data['release']] ?? null) : null;

        $changes = [];
        if (in_array('title', $data['parts'], true)) {
            $changes['title'] = $candidate['title'];
        }
        if (in_array('artist', $data['parts'], true) && $candidate['artist'] !== null) {
            $changes['artist'] = $candidate['artist'];
        }
        if (in_array('number', $data['parts'], true) && ($release['number'] ?? null) !== null) {
            $changes['track_number'] = min(999, max(1, $release['number']));
        }

        if ($changes !== []) {
            $track->update($changes + ['identified' => true]);
        }
        Cache::forget($this->key($request, $track, 'candidates'));

        return response()->json(['done' => array_keys($changes), 'track' => $track->only(['id', 'title', 'artist', 'track_number'])]);
    }
}
