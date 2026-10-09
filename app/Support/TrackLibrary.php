<?php

namespace App\Support;

use App\Models\User;
use RuntimeException;

/** Where the music lives and which files count as music. Each user has a folder of their own under `users/`. */
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

    /** A username that is safe as a folder name: no separators, and not `.` or `..` or hidden. */
    public static function validUsername(string $username): bool
    {
        return preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._-]*$/u', $username) === 1 && mb_strlen($username) <= 64;
    }

    /** Folder of one user's library, `{music path}/users/{username}`. It may not exist yet. */
    public static function userPath(User $user): string
    {
        if (! self::validUsername((string) $user->username)) {
            throw new RuntimeException('This username cannot be used as a folder name.');
        }

        return rtrim(self::path(), '/').'/users/'.$user->username;
    }

    public static function contentType(string $path): ?string
    {
        return self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
    }

    /** Resolve a stored relative path to a real file inside the owner's folder, or null. */
    public static function resolve(string $relative, ?User $owner): ?string
    {
        if ($owner === null || ! self::validUsername((string) $owner->username)) {
            return null;
        }

        $base = realpath(self::userPath($owner));
        $path = $base ? realpath($base.DIRECTORY_SEPARATOR.$relative) : false;

        if ($path === false || ! is_file($path) || ! str_starts_with($path, $base.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return self::contentType($path) !== null ? $path : null;
    }
}
