<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeTracks;
use App\Models\Album;
use App\Models\Playlist;
use App\Models\SharedItem;
use App\Models\Track;
use App\Support\FileTruth;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LibraryController extends Controller
{
    /** The signed-in user's own albums and tracks, and nobody else's; shared music from others is on /shared. */
    public function library(Request $request): View
    {
        $me = $request->user();

        $albums = Album::with('tracks')->where('user_id', $me->id)->orderBy('artist')->orderBy('year')->orderBy('title')->get()
            ->each(fn (Album $a) => $a->tracks->each->setRelation('album', $a));

        $loose = Track::where('user_id', $me->id)->whereNull('album_id')->orderBy('title')->get();

        // Duplicates are found across the user's own library only, so a hash never reveals a file someone keeps privately.
        $copies = FileTruth::copies(Track::where('user_id', $me->id)->get(['id', 'title', 'artist', 'path', 'duration', 'pcm_sha1']));

        $shared = [
            'albums' => SharedItem::where('user_id', $me->id)->whereNotNull('album_id')->pluck('album_id')->all(),
            'tracks' => SharedItem::where('user_id', $me->id)->whereNotNull('track_id')->pluck('track_id')->all(),
        ];

        return view('pages.library', ['albums' => $albums, 'loose' => $loose, 'copies' => $copies, 'shared' => $shared, 'toAnalyse' => AnalyzeTracks::missing($me)->count()]);
    }

    /** Everything in the shared area, from all users (yours included), with the name of who shares it. No findings here. */
    public function shared(Request $request): View
    {
        $albums = Album::with(['user', 'tracks'])->whereHas('share')->orderBy('artist')->orderBy('year')->orderBy('title')->get()
            ->each(fn (Album $a) => $a->tracks->each->setRelation('album', $a));

        $singles = Track::with(['user', 'album'])->whereHas('share')
            ->whereDoesntHave('album', fn ($q) => $q->whereHas('share'))
            ->orderBy('artist')->orderBy('title')->get();

        $playlists = Playlist::with('user')->withCount('tracks')->whereHas('share')->orderBy('name')->get();

        return view('pages.shared', ['albums' => $albums, 'singles' => $singles, 'playlists' => $playlists, 'me' => $request->user()]);
    }
}
