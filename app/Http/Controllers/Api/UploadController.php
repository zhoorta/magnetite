<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ScanLibrary;
use App\Services\LibraryUploader;
use App\Support\ScanStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Audio files, one by one, into the root of the user's own folder (`files`), and zips unpacked like the web upload does
 * (`archives`). Both go through LibraryUploader, so the rules are the same: audio and covers only, nothing overwritten.
 */
class UploadController extends Controller
{
    public function store(Request $request, LibraryUploader $uploader): JsonResponse
    {
        $maxKb = config('magnetite.upload.max_zip_mb') * 1024;
        $request->validate([
            'files' => ['required_without:archives', 'array', 'max:100'],
            'files.*' => ['required', 'file', 'max:'.$maxKb],
            'archives' => ['required_without:files', 'array', 'max:20'],
            'archives.*' => ['required', 'file', 'extensions:zip', 'max:'.$maxKb],
        ], [
            'files.*.uploaded' => 'The upload did not arrive in full. The server limits (upload_max_filesize, post_max_size) may be lower than the file.',
            'archives.*.uploaded' => 'The upload did not arrive in full. The server limits (upload_max_filesize, post_max_size) may be lower than the zip.',
            'archives.*.extensions' => 'Only .zip files can be uploaded as archives.',
            'archives.*.max' => 'A zip is larger than the limit of '.config('magnetite.upload.max_zip_mb').' MB.',
        ]);

        set_time_limit(0);

        $started = now()->getTimestamp();
        $results = [];
        $added = 0;

        try {
            $results = $uploader->addFiles($request->user(), $request->file('files', []));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $added += collect($results)->where('status', 'added')->count();

        $zips = $uploader->extractMany($request->user(), $request->file('archives', []));
        $archives = $zips['archives'];
        $added += $zips['added'];

        $added > 0 && ScanLibrary::request($request->user());

        // `since` is the server's clock from before anything was written: GET /albums?since= lists the albums this upload brought tracks to.
        return response()->json([
            'files' => $results, 'archives' => $archives, 'since' => $started,
            'scan' => $added > 0 ? ScanStatus::get($request->user()->id) : null,
        ], 201);
    }
}
