<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ScanLibrary;
use App\Services\LibraryUploader;
use App\Support\ScanStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Audio files from the phone, one by one, into the root of the user's own folder. Zips keep using POST /upload. */
class UploadController extends Controller
{
    public function store(Request $request, LibraryUploader $uploader): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:100'],
            'files.*' => ['required', 'file', 'max:'.(config('magnetite.upload.max_zip_mb') * 1024)],
        ], [
            'files.*.uploaded' => 'The upload did not arrive in full. The server limits (upload_max_filesize, post_max_size) may be lower than the file.',
        ]);

        set_time_limit(0);

        try {
            $results = $uploader->addFiles($request->user(), $request->file('files'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $added = collect($results)->where('status', 'added')->count();
        $added > 0 && ScanLibrary::request($request->user());

        return response()->json(['files' => $results, 'scan' => $added > 0 ? ScanStatus::get($request->user()->id) : null], 201);
    }
}
