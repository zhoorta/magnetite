<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in user's interface choices for the library, kept with the account so they follow the person to another device. */
class UiSettingController extends Controller
{
    /**
     * `info`: how much the lists and the player show about the files (0 none, 1 DR, 2 DR and findings).
     * `tint`: meter colours from the playing cover. Only the keys sent change.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'info' => ['sometimes', 'integer', 'between:0,2'],
            'tint' => ['sometimes', 'boolean'],
        ]);

        $ui = array_merge($request->user()->ui ?? [], $data);
        $request->user()->forceFill(['ui' => $ui])->save();

        return response()->json(['ui' => $ui]);
    }
}
