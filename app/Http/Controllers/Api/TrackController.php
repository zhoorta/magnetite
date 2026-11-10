<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackResource;
use App\Models\Track;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TrackController extends Controller
{
    /**
     * The signed-in user's own tracks, page by page (`per_page` up to 500), each with its measurements and findings.
     * `truth=1` keeps only the analysed ones. Built for clients that list the whole library by quality (the terminal
     * client's file-truth table) in a few requests instead of one per album; other people's shared tracks stay on /shared.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min(max($request->integer('per_page', 300), 1), 500);

        return TrackResource::collection(
            Track::with('album')->where('user_id', $request->user()->id)
                ->when($request->boolean('truth'), fn ($q) => $q->whereNotNull('analysis'))
                ->orderBy('id')->paginate($perPage)
        );
    }

    /** One track with its details; the owner also gets the measurements. Not visible to the viewer: 404. */
    public function show(Request $request, Track $track): TrackResource
    {
        abort_unless($request->user()->can('view', $track), 404);

        return new TrackResource($track->load('album'));
    }
}
