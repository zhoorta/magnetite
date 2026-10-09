<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlbumResource;
use App\Models\Album;
use App\Services\CoverStore;
use Illuminate\Http\Request;

class AlbumCoverController extends Controller
{
    /** A picture from the phone becomes this album's cover (re-encoded as a small JPEG; it replaces a downloaded or an earlier one). */
    public function update(Request $request, Album $album, CoverStore $covers): AlbumResource
    {
        abort_unless($request->user()->can('update', $album), 404);

        $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:15360']]);

        $name = $covers->store(file_get_contents($request->file('image')->getRealPath()));
        abort_if($name === null, 422, 'That image could not be read.');

        $album->update(['cover' => $name, 'cover_source' => null]);

        return new AlbumResource($album->loadCount('tracks'));
    }
}
