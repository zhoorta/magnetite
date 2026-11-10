<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Models\Album;
use App\Services\AlbumGrouper;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AlbumController extends Controller
{
    /** The signed-in user's own albums, like /library. Shared music from others is on /shared. */
    public function index(Request $request): AnonymousResourceCollection
    {
        // `since` (unix time, as the upload answers it): only albums that got tracks from then on, for a client that wants to
        // look at what an upload brought in.
        $since = $request->integer('since');

        return AlbumResource::collection(
            Album::when($since > 0, fn ($q) => $q->whereHas('tracks', fn ($t) => $t->where('created_at', '>=', Carbon::createFromTimestamp($since))))
                ->withCount(['tracks', 'tracks as artist_count' => fn ($q) => $q->select(DB::raw('count(distinct artist)'))])
                ->withMin('tracks as first_artist', 'artist')->where('user_id', $request->user()->id)
                ->inLibraryOrder()->paginate(50)
        );
    }

    /**
     * Name an album for tracks that have none (files without an album tag: `GET /tracks/loose` lists them). The tracks
     * must be the signed-in user's and still without an album. Answers with the album.
     */
    public function store(Request $request, AlbumGrouper $grouper): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'artist' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'between:1000,2100'],
            'track_ids' => ['required', 'array', 'min:1', 'max:500'],
            'track_ids.*' => ['integer'],
        ]);
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        try {
            $album = $grouper->group($request->user(), $data['track_ids'], trim($data['title']), $clean($data['artist'] ?? null), $data['year'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new AlbumResource($album->loadCount('tracks')))->response()->setStatusCode(201);
    }

    /** An album with its tracks. Same rule as the cover: yours, or in the shared area; anything else is a 404. */
    public function show(Request $request, Album $album): AlbumResource
    {
        abort_unless($request->user()->can('view', $album), 404);

        // Tracks of someone else's album are limited to what the viewer may play (an album can hold a mix).
        $album->load(['tracks' => fn ($q) => $q->visibleTo($request->user())->with('album')]);

        return new AlbumResource($album);
    }
}
