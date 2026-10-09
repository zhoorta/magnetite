<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The owner's folders for ordering albums, and moving albums into them. Database only; no file is touched. */
class FolderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('folders', 'id')->where('user_id', $request->user()->id)],
        ]);

        $parent = $data['parent_id'] ?? null ? Folder::find($data['parent_id']) : null;
        abort_if($parent && $parent->depth() >= Folder::MAX_DEPTH, 422, 'Folders can only go '.Folder::MAX_DEPTH.' levels deep.');

        $folder = Folder::create(['user_id' => $request->user()->id, 'parent_id' => $parent?->id, 'name' => trim($data['name'])]);

        return response()->json(['folder' => $folder->only(['id', 'parent_id', 'name'])], 201);
    }

    /** Rename and/or move (`parent_id`, null for the top level). */
    public function update(Request $request, Folder $folder): JsonResponse
    {
        abort_unless($request->user()->can('update', $folder), 404);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('folders', 'id')->where('user_id', $request->user()->id)],
        ]);

        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            $parent = Folder::findOrFail($data['parent_id']);
            abort_if(in_array($parent->id, $folder->selfAndDescendantIds(), true), 422, 'A folder cannot go inside itself.');
            abort_if($parent->depth() + 1 + $folder->heightBelow() > Folder::MAX_DEPTH, 422, 'Folders can only go '.Folder::MAX_DEPTH.' levels deep.');
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        $folder->update($data);

        return response()->json(['folder' => $folder->only(['id', 'parent_id', 'name'])]);
    }

    /** Deleting a folder never deletes music: its albums and subfolders move up to its parent. */
    public function destroy(Request $request, Folder $folder): JsonResponse
    {
        abort_unless($request->user()->can('delete', $folder), 404);

        Album::where('folder_id', $folder->id)->update(['folder_id' => $folder->parent_id]);
        Folder::where('parent_id', $folder->id)->update(['parent_id' => $folder->parent_id]);
        $folder->delete();

        return response()->json(['deleted' => true]);
    }

    /** Put an album in a folder, or back at the top with `folder_id` null. */
    public function moveAlbum(Request $request, Album $album): JsonResponse
    {
        abort_unless($request->user()->can('update', $album), 404);

        $data = $request->validate([
            'folder_id' => ['present', 'nullable', 'integer', Rule::exists('folders', 'id')->where('user_id', $request->user()->id)],
        ]);

        $album->update(['folder_id' => $data['folder_id']]);

        return response()->json(['album' => $album->only(['id', 'folder_id'])]);
    }
}
