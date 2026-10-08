<?php

namespace App\Http\Controllers;

use App\Jobs\ScanLibrary;
use App\Support\ScanStatus;
use Illuminate\Http\JsonResponse;

class ScanController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(ScanStatus::get());
    }

    public function store(): JsonResponse
    {
        ScanLibrary::request();

        return response()->json(ScanStatus::get(), 202);
    }
}
