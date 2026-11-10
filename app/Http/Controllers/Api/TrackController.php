<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrackController extends Controller
{
    /**
     * The signed-in user's own tracks, page by page (`per_page` up to 500), each with its measurements and findings.
     * `truth=1` keeps only the analysed ones. Built for clients that list the whole library by quality (the terminal
     * client's file-truth table) in a few requests instead of one per album; other people's shared tracks stay on /shared.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 300), 1), 500);

        return TrackResource::collection(
            Track::with('album')->where('user_id', $request->user()->id)
                ->when($request->boolean('truth'), fn ($q) => $q->whereNotNull('analysis'))
                ->orderBy('id')->paginate($perPage)
        );
    }

    /**
     * The owner's tracks that belong to no album (files without an album tag), in groups by the folder they sit in, for a
     * client that wants to name albums for them. `since` (the upload's server time) keeps the ones that arrived from then on.
     * Only the folder's own name is given, never a path.
     */
    public function loose(Request $request): JsonResponse
    {
        $since = $request->integer('since');
        $tracks = Track::where('user_id', $request->user()->id)->whereNull('album_id')
            ->when($since > 0, fn ($q) => $q->where('created_at', '>=', Carbon::createFromTimestamp($since)))
            ->orderBy('path')->get();

        $groups = $tracks->groupBy(fn (Track $t) => dirname($t->path))->map(fn ($rows, $dir) => [
            'folder' => in_array($dir, ['.', ''], true) ? '' : basename($dir),
            'tracks' => $rows->map(fn (Track $t) => ['id' => $t->id, 'title' => $t->title, 'artist' => $t->artist, 'number' => $t->track_number])->values()->all(),
        ])->values()->all();

        return response()->json(['groups' => $groups]);
    }

    /** One track with its details; the owner also gets the measurements. Not visible to the viewer: 404. */
    public function show(Request $request, Track $track): TrackResource
    {
        abort_unless($request->user()->can('view', $track), 404);

        return new TrackResource($track->load('album'));
    }
}
