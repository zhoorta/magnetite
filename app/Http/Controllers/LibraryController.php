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
use App\Support\FileTruth;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LibraryController extends Controller
{
    /** The signed-in user's own albums and tracks, and nobody else's; shared music from others is on /shared. */
    public function library(Request $request, AlbumIdentifier $identifier): View
    {
        $me = $request->user();

        $albums = Album::with('tracks')->where('user_id', $me->id)->inLibraryOrder()->get()
            ->each(fn (Album $a) => $a->tracks->each->setRelation('album', $a));

        // The owner's folders as a tree: children and albums by parent (0 is the top), a flat list for the pickers, and album counts including subfolders.
        $folders = Folder::where('user_id', $me->id)->orderBy('name')->get();
        $childrenOf = $folders->groupBy(fn (Folder $f) => $f->parent_id ?? 0);
        $albumsIn = $albums->groupBy(fn (Album $a) => $a->folder_id ?? 0);
        $folderList = [];
        $albumCount = [];
        $walk = function (int $parent, int $depth) use (&$walk, $childrenOf, $albumsIn, &$folderList, &$albumCount): int {
            $total = 0;
            foreach ($childrenOf[$parent] ?? [] as $f) {
                $folderList[] = ['id' => $f->id, 'parent_id' => $f->parent_id, 'name' => $f->name, 'depth' => $depth];
                $albumCount[$f->id] = count($albumsIn[$f->id] ?? []) + $walk($f->id, $depth + 1);
                $total += $albumCount[$f->id];
            }

            return $total;
        };
        $walk(0, 1);

        $loose = Track::where('user_id', $me->id)->whereNull('album_id')->orderBy('title')->get();

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

        return view('pages.library', ['childrenOf' => $childrenOf, 'albumsIn' => $albumsIn, 'folderList' => $folderList, 'albumFolder' => $albums->whereNotNull('folder_id')->pluck('folder_id', 'id'), 'albumCount' => $albumCount, 'albums' => $albums, 'loose' => $loose, 'copies' => $copies, 'shared' => $shared, 'suggestions' => $suggestions, 'toIdentify' => $toIdentify, 'trashOnly' => $me->store() instanceof FerriteStore]);
    }

    /** Everything in the shared area, from all users (yours included), with the name of who shares it. No findings here. */
    public function shared(Request $request): View
    {
        $albums = Album::with(['user', 'tracks'])->whereHas('share')->inLibraryOrder()->get()
            ->each(fn (Album $a) => $a->tracks->each->setRelation('album', $a));

        $singles = Track::with(['user', 'album'])->whereHas('share')
            ->whereDoesntHave('album', fn ($q) => $q->whereHas('share'))
            ->orderBy('artist')->orderBy('title')->get();

        $playlists = Playlist::with('user')->withCount('tracks')->whereHas('share')->orderBy('name')->get();

        return view('pages.shared', ['albums' => $albums, 'singles' => $singles, 'playlists' => $playlists, 'me' => $request->user()]);
    }
}
