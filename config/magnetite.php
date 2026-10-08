<?php

return [
    // Folder scanned for audio files (recursively). Read-only: files are never copied or modified.
    'music_path' => env('MAGNETITE_MUSIC_PATH', storage_path('app/music')),

    // ffmpeg binary used to serve ALAC as FLAC. Leave empty to look it up on PATH; without ffmpeg,
    // ALAC files are served as they are (Safari plays them, Chrome and Firefox do not).
    'ffmpeg' => env('MAGNETITE_FFMPEG'),
];
