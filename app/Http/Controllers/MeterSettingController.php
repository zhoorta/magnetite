<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Which meters the signed-in user has in the player, so the same ones show after a reload or on another device. */
class MeterSettingController extends Controller
{
    public const MAX = 4;

    /**
     * The list of meter ids, in panel order; an empty list means "no meters". The ids are only checked for shape: the
     * player ignores any it does not know, so this does not need to know the meters.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['present', 'array', 'max:'.self::MAX],
            'ids.*' => ['string', 'max:40', 'regex:/^[a-z0-9-]+$/', 'distinct'],
        ]);

        $request->user()->forceFill(['meters' => array_values($data['ids'])])->save();

        return response()->json(['meters' => $request->user()->meters]);
    }
}
