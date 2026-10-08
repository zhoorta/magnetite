<?php

namespace App\Support;

/** Where the music lives and which files count as music. The folder is only ever read. */
class TrackLibrary
{
    public const TYPES = [
        'mp3' => 'audio/mpeg',
        'm4a' => 'audio/mp4',
        'flac' => 'audio/flac',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'wav' => 'audio/wav',
    ];

    public static function path(): string
    {
        return (string) config('magnetite.music_path');
    }

    public static function contentType(string $path): ?string
    {
        return self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    /** Resolve a stored relative path to a real file inside the music folder, or null. */
    public static function resolve(string $relative): ?string
    {
        $base = realpath(self::path());
        $path = $base ? realpath($base.DIRECTORY_SEPARATOR.$relative) : false;

        if ($path === false || ! is_file($path) || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return self::contentType($path) !== null ? $path : null;
    }
}
