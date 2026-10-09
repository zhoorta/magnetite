<?php

namespace App\Http\Controllers;

use App\Jobs\ScanLibrary;
use App\Services\LibraryUploader;
use App\Support\ScanStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class UploadController extends Controller
{
    public function create(): View
    {
        return view('pages.upload', ['maxMb' => config('magnetite.upload.max_zip_mb')]);
    }

    public function store(Request $request, LibraryUploader $uploader): JsonResponse
    {
        $request->validate([
            'archives' => ['required', 'array', 'max:20'],
            'archives.*' => ['required', 'file', 'extensions:zip', 'max:'.(config('magnetite.upload.max_zip_mb') * 1024)],
        ], [
            'archives.*.max' => 'A zip is larger than the limit of '.config('magnetite.upload.max_zip_mb').' MB.',
            'archives.*.extensions' => 'Only .zip files can be uploaded.',
            'archives.*.uploaded' => 'The upload did not arrive in full. The server limits (upload_max_filesize, post_max_size) may be lower than the zip.',
        ]);

        set_time_limit(0);

        $results = [];
        $added = 0;

        foreach ($request->file('archives') as $archive) {
            try {
                $result = $uploader->extract($request->user(), $archive->getRealPath(), $archive->getClientOriginalName());
                $added += $result['added'];
                $results[] = ['name' => $archive->getClientOriginalName(), 'ok' => true] + $result;
            } catch (RuntimeException $e) {
                $results[] = ['name' => $archive->getClientOriginalName(), 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        // Only look at the folder again when something new was written, and do it in the queue: it can take minutes.
        if ($added > 0) {
            ScanLibrary::request($request->user());
        }

        return response()->json(['archives' => $results, 'scan' => $added > 0 ? ScanStatus::get($request->user()->id) : null]);
    }
}
