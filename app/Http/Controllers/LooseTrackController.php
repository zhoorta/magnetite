<?php

namespace App\Http\Controllers;

use App\Models\Track;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The owner's tracks that belong to no album (files without an album tag), in groups by the folder they sit in, for a client
 * that wants to name albums for them (`AlbumGroupController`). `since` (the upload's server time) keeps the ones that arrived
 * from then on. Only the folder's own name is given, never a path. Mounted on the web and on the API.
 */
class LooseTrackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $since = $request->integer('since');
        $tracks = Track::looseOf($request->user())
            ->when($since > 0, fn ($q) => $q->where('created_at', '>=', Carbon::createFromTimestamp($since)))
            ->orderBy('path')->get();

        $groups = $tracks->groupBy(fn (Track $t) => dirname($t->path))->map(fn ($rows, $dir) => [
            'folder' => in_array($dir, ['.', ''], true) ? '' : basename($dir),
            'tracks' => $rows->map(fn (Track $t) => ['id' => $t->id, 'title' => $t->title, 'artist' => $t->artist, 'number' => $t->track_number])->values()->all(),
        ])->values()->all();

        return response()->json(['groups' => $groups]);
    }
}
