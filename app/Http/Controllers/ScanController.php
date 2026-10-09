<?php

namespace App\Http\Controllers;

use App\Jobs\ScanLibrary;
use App\Support\ScanStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(ScanStatus::get($request->user()->id));
    }

    public function store(Request $request): JsonResponse
    {
        ScanLibrary::request($request->user());

        return response()->json(ScanStatus::get($request->user()->id), 202);
    }
}
