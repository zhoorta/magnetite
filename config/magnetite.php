<?php

return [
    // Where the start page's demo tracks are (FLAC files named in resources/demo/tracks.json). Not in git:
    // `bin/prepare-demo.sh` makes them and they are copied to the server.
    'demo_path' => env('MAGNETITE_DEMO_PATH', storage_path('app/demo')),

    // Version of the Android app offered on the start page: the file public/downloads/magnetite-{version}.apk
    // (not in git, copied to the server by hand). The page hides the download when the file is missing.
    'apk_version' => env('MAGNETITE_APK_VERSION', '0.4.7'),

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

    // Libraries kept in Ferrite (`php artisan magnetite:storage`): files fetched for analysis, conversion or cover
    // extraction are kept here, least recently used removed first once the folder is over this size.
    'ferrite' => [
        'cache_max_gb' => (float) env('MAGNETITE_CACHE_MAX_GB', 10),
    ],

    // Missing covers can be looked up online (MusicBrainz / Cover Art Archive, then iTunes): only the artist and the
    // album title leave the server. MusicBrainz asks for a contact in the User-Agent, e.g. "Magnetite/1.0 (you@example.com)".
    'covers' => [
        'user_agent' => env('MAGNETITE_COVERS_USER_AGENT', 'Magnetite/1.0'),
    ],

    // Identifying albums by fingerprint (docs/identify-albums.md, level 3): Chromaprint's `fpcalc` (empty: look it up
    // on PATH) and an AcoustID application key (free, https://acoustid.org/new-application). Without both, the
    // fingerprint step is skipped. Only a fingerprint and the length of a few tracks per folder are sent.
    'fpcalc' => env('MAGNETITE_FPCALC'),
    'acoustid' => [
        'key' => env('MAGNETITE_ACOUSTID_KEY'),
    ],
];
