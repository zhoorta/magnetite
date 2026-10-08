<?php

return [
    // Folder scanned for audio files (recursively). Magnetite only writes to it when a signed-in user uploads a zip
    // (see `upload` below); existing files are never overwritten or modified.
    'music_path' => env('MAGNETITE_MUSIC_PATH', storage_path('app/music')),

    // ffmpeg binary used to serve ALAC as FLAC. Leave empty to look it up on PATH; without ffmpeg,
    // ALAC files are served as they are (Safari plays them, Chrome and Firefox do not).
    'ffmpeg' => env('MAGNETITE_FFMPEG'),

    // `php artisan magnetite:analyze` measures loudness, dynamic range, clipping and the spectrum of every file
    // with ffmpeg (decoding), ffprobe (stream info) and Node (the maths, in bin/measure.mjs). Leave empty to find
    // them on PATH; ffprobe is also looked for next to ffmpeg.
    'ffprobe' => env('MAGNETITE_FFPROBE'),
    'node' => env('MAGNETITE_NODE'),

    // Zip uploads: the archive is unpacked into the music folder (audio and cover images only, nothing overwritten),
    // then the library is scanned. PHP's own `upload_max_filesize` and `post_max_size` (and the web server's body
    // limit) must be at least as large as `max_zip_mb`.
    'upload' => [
        'max_zip_mb' => (int) env('MAGNETITE_UPLOAD_MAX_MB', 2048),
        'max_unpacked_mb' => (int) env('MAGNETITE_UPLOAD_MAX_UNPACKED_MB', 6144),
        'max_files' => (int) env('MAGNETITE_UPLOAD_MAX_FILES', 5000),
    ],
];
