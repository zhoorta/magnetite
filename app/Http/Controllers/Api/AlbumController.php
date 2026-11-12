<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Models\Album;
use App\Models\Track;
use App\Support\FileTruth;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AlbumController extends Controller
{
    /** The signed-in user's own albums, like /library. Shared music from others is on /shared. */
    public function index(Request $request): AnonymousResourceCollection
    {
        // `since` (unix time, as the upload answers it): only albums that got tracks from then on, for a client that wants to
        // look at what an upload brought in.
        $since = $request->integer('since');

        $page = Album::when($since > 0, fn ($q) => $q->whereHas('tracks', fn ($t) => $t->where('created_at', '>=', Carbon::createFromTimestamp($since))))
            ->withCount(['tracks', 'tracks as artist_count' => fn ($q) => $q->select(DB::raw('count(distinct artist)'))])
            ->withMin('tracks as first_artist', 'artist')->withAvg('tracks as dr_avg', 'dr')->where('user_id', $request->user()->id)
            ->inLibraryOrder()->paginate(50);

        // How many tracks of each album carry a problem or a warning (not notes): the list shows a mark, and the tracks stay out of it.
        $user = $request->user();
        $copies = FileTruth::copies(Track::where('user_id', $user->id)->get(['id', 'title', 'artist', 'path', 'duration', 'pcm_sha1']));
        $counts = Track::whereIn('album_id', $page->pluck('id'))->whereNotNull('analysis')->get()->groupBy('album_id')->map(
            fn ($tracks) => $tracks->filter(fn (Track $t) => collect(FileTruth::flags($t, $copies[$t->id] ?? []))->contains(fn ($f) => $f['level'] !== 'note'))->count()
        );
        $page->each(fn (Album $a) => $a->setAttribute('problems', $counts[$a->id] ?? 0));

        return AlbumResource::collection($page);
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
