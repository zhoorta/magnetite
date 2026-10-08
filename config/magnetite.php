<?php

return [
    // Folder scanned for audio files (recursively). Read-only: files are never copied or modified.
    'music_path' => env('MAGNETITE_MUSIC_PATH', storage_path('app/music')),

    // ffmpeg binary used to serve ALAC as FLAC. Leave empty to look it up on PATH; without ffmpeg,
    // ALAC files are served as they are (Safari plays them, Chrome and Firefox do not).
    'ffmpeg' => env('MAGNETITE_FFMPEG'),

    // `php artisan magnetite:analyze` measures loudness, dynamic range, clipping and the spectrum of every file
    // with ffmpeg (decoding), ffprobe (stream info) and Node (the maths, in bin/measure.mjs). Leave empty to find
    // them on PATH; ffprobe is also looked for next to ffmpeg.
    'ffprobe' => env('MAGNETITE_FFPROBE'),
    'node' => env('MAGNETITE_NODE'),
];
