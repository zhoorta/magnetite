<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\AlbumSuggestion;
use App\Models\Folder;
use App\Models\Playlist;
use App\Models\SharedItem;
use App\Models\Track;
use App\Services\AlbumIdentifier;
use App\Services\FerriteStore;
use App\Services\TrackAnalyzer;
use App\Support\FileTruth;
use App\Support\FolderTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LibraryController extends Controller
{
    /** The signed-in user's own albums and tracks, and nobody else's; shared music from others is on /shared. */
    public function library(Request $request, AlbumIdentifier $identifier): View|JsonResponse
    {
        $me = $request->user();

        $albums = Album::with('tracks')->where('user_id', $me->id)->inLibraryOrder()->get()
            ->each(fn (Album $a) => $a->tracks->each->setRelation('album', $a));

        $tree = FolderTree::of(Folder::where('user_id', $me->id)->orderBy('name')->get(), $albums);

        $loose = Track::looseOf($me)->orderBy('title')->get();

        // Duplicates are found across the user's own library only, so a hash never reveals a file someone keeps privately.
        $copies = FileTruth::copies(Track::where('user_id', $me->id)->get(['id', 'title', 'artist', 'path', 'duration', 'pcm_sha1']));

        // Suggestions for folders whose tracks are still without an album (a rescan may have removed some of them).
        $looseIds = $loose->pluck('id')->flip();
        $suggestions = AlbumSuggestion::where('user_id', $me->id)->whereNull('dismissed_at')->orderBy('folder')->get()
            ->filter(fn (AlbumSuggestion $s) => collect($s->candidates[0]['tracks'] ?? [])->contains(fn ($t) => $looseIds->has($t['id'])))->values();
        $toIdentify = $identifier->pending($me)->count();

        $shared = [
            'albums' => SharedItem::where('user_id', $me->id)->whereNotNull('album_id')->pluck('album_id')->all(),
            'tracks' => SharedItem::where('user_id', $me->id)->whereNotNull('track_id')->pluck('track_id')->all(),
        ];

        $rows = fn ($tracks) => $tracks->map(fn (Track $t) => $t->toLibrary($copies[$t->id] ?? []))->values();

        // Everything the page draws, as plain data: the dense library renders it in the browser, and the same JSON answers a background refresh.
        $data = [
            'folders' => $tree->list,
            'albums' => $albums->map(fn (Album $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'artist' => $a->displayArtist,
                'year' => $a->year,
                'label' => $a->label,
                'dr' => $a->dr,
                'dr_class' => FileTruth::drClass($a->dr),
                'cover' => $a->cover ? route('cover', $a).'?v='.substr((string) $a->cover, 0, 10) : null,
                'cover_source' => $a->cover_source !== null,
                'folder_id' => $a->folder_id,
                'needs_analysis' => $a->tracks->contains(fn (Track $t) => $t->analysis_version === null || $t->analysis_version < TrackAnalyzer::VERSION),
                'edit' => ['title' => $a->title, 'artist' => $a->artist, 'release_date' => $a->released, 'label' => $a->label],
                'tracks' => $rows($a->tracks),
            ])->values(),
            'loose' => $rows($loose),
            'shared' => $shared,
            'suggestions' => $suggestions->map(fn (AlbumSuggestion $s) => ['id' => $s->id, 'folder' => $s->folder, 'candidates' => $s->candidates])->values(),
            'to_identify' => $toIdentify,
            'trash_only' => $me->store() instanceof FerriteStore,
        ];

        return $request->wantsJson() ? response()->json($data) : view('pages.library', ['data' => $data]);
    }

    /** Everything in the shared area, from all users (yours included), with the name of who shares it. No findings here. */
    public function shared(Request $request): View
    {
        $albums = Album::with('tracks')->shared()->get()
            ->each(fn (Album $a) => $a->tracks->each->setRelation('album', $a));

        $singles = Track::sharedSingles()->get();
        $playlists = Playlist::shared()->get();

        return view('pages.shared', ['albums' => $albums, 'singles' => $singles, 'playlists' => $playlists, 'me' => $request->user()]);
    }
}
