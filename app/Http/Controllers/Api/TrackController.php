<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use Illuminate\Http\Request;

class TrackController extends Controller
{
    /** One track with its details; the owner also gets the measurements. Not visible to the viewer: 404. */
    public function show(Request $request, Track $track): TrackResource
    {
        abort_unless($request->user()->can('view', $track), 404);

        return new TrackResource($track->load('album'));
    }
}
