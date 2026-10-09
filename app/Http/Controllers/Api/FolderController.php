<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    /** The owner's folders, flat, with `parent_id` for the client to build the tree and the albums directly inside each. Writes are the web controller's. */
    public function index(Request $request): JsonResponse
    {
        $albums = Album::where('user_id', $request->user()->id)->whereNotNull('folder_id')->selectRaw('folder_id, count(*) as n')->groupBy('folder_id')->pluck('n', 'folder_id');

        return response()->json(['data' => Folder::where('user_id', $request->user()->id)->orderBy('name')->get()
            ->map(fn (Folder $f) => ['id' => $f->id, 'parent_id' => $f->parent_id, 'name' => $f->name, 'album_count' => (int) ($albums[$f->id] ?? 0)])->values()]);
    }
}
