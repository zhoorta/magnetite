<?php

namespace App\Contracts;

use App\Support\StoredFile;

/**
 * Where one user's music files live. Everything that reads the library (scan, stream, analysis) goes through it and
 * never builds a path itself, so a library can sit on the local disk or, later, in a remote store.
 */
interface LibraryStore
{
    /** Whether the library can be read now (a local folder that is missing, a remote store that is down: no). */
    public function isAvailable(): bool;

    /** Why the library cannot be read, in words for whoever runs the scan. */
    public function unavailableReason(): string;

    /**
     * Every music file in the library. Paths are relative to the library root and are the keys of `tracks.path`.
     *
     * @return iterable<StoredFile>
     */
    public function files(): iterable;

    /** A local file that holds the bytes of a music file, for the tools that need one (getID3, ffmpeg, Node). Null when it is not there. */
    public function fetch(string $path): ?string;

    /**
     * Remove one music file from the library: deleted from disk for a local folder, moved to the trash of the remote
     * store (where the owner can restore it) for Ferrite. A file that is already gone counts as removed. Throws
     * RuntimeException, with a message for the owner, when the store does not allow it or cannot be reached.
     */
    public function delete(string $path): void;

    /**
     * Move one music file to another folder of the library (`''` for the top), keeping its name, and return its new
     * path. Nothing is replaced and nothing is deleted. Throws RuntimeException, with a message for the owner, when a
     * file of that name is already there, the folder is not in the library, or the store does not allow it.
     */
    public function move(string $path, string $folder): string;

    /** Bytes of the conventional cover image (`cover.jpg`, `folder.png`...) in the same folder as a music file, if any. */
    public function folderCover(string $path): ?string;
}
