<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Models\Album;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlbumController extends Controller
{
    /** The signed-in user's own albums, like /library. Shared music from others is on /shared. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return AlbumResource::collection(
            Album::withCount('tracks')->where('user_id', $request->user()->id)
                ->orderBy('artist')->orderBy('year')->orderBy('title')->paginate(50)
        );
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
