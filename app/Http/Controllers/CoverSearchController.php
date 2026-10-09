<?php

namespace App\Http\Controllers;

use App\Jobs\FindCovers;
use App\Models\Album;
use App\Services\CoverFinder;
use App\Support\CoverStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Online cover lookup for the signed-in user's own albums. */
class CoverSearchController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(CoverStatus::get($request->user()->id));
    }

    /** Every album of the user that has no cover, in the background. */
    public function store(Request $request): JsonResponse
    {
        FindCovers::request($request->user(), $request->boolean('retry'));

        return response()->json(CoverStatus::get($request->user()->id), 202);
    }

    /** One album, now: two or three requests, so the answer can wait for them. */
    public function album(Request $request, Album $album, CoverFinder $finder): JsonResponse
    {
        abort_unless($request->user()->can('update', $album), 404);
        abort_if($album->cover !== null, 422, 'This album already has a cover.');

        return response()->json(['source' => $finder->fill($album)]);
    }
}
