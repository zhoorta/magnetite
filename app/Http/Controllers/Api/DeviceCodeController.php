<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\DeviceGrants;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceCodeController extends Controller
{
    /** A device without a keyboard asks for a code to show; the person approves it at `verification_url`. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['device_name' => ['required', 'string', 'max:100']]);
        $grant = DeviceGrants::start($data['device_name']);

        return response()->json([
            'device_code' => $grant['device_code'],
            'user_code' => $grant['user_code'],
            'verification_url' => route('device'),
            'expires_in' => DeviceGrants::TTL,
            'interval' => DeviceGrants::INTERVAL,
        ], 201);
    }

    /** The device asks until the person has approved: 400 `authorization_pending` meanwhile, 400 `expired_token` when it is gone, else the token. */
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate(['device_code' => ['required', 'string', 'max:100']]);
        $grant = DeviceGrants::poll($data['device_code']);

        if (! $grant) {
            return response()->json(['error' => 'expired_token'], 400);
        }

        $user = $grant['user_id'] ? User::find($grant['user_id']) : null;

        if (! $user) {
            return response()->json(['error' => 'authorization_pending'], 400);
        }

        return response()->json([
            'token' => $user->createToken($grant['device_name'], ['*'])->plainTextToken,
            'user' => ['id' => $user->id, 'username' => $user->username, 'meters' => $user->meters ?? ['vu-wide']],
        ], 201);
    }
}
