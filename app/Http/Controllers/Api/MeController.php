<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $me = $request->user();

        return response()->json(['id' => $me->id, 'username' => $me->username, 'meters' => $me->meters ?? ['vu-wide'], 'ui' => array_merge(['info' => 2, 'tint' => false], $me->ui ?? [])]);
    }
}
