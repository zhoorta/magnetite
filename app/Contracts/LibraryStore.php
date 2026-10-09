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

    /**
     * Every music file in the library. Paths are relative to the library root and are the keys of `tracks.path`.
     *
     * @return iterable<StoredFile>
     */
    public function files(): iterable;

    /** A local file that holds the bytes of a music file, for the tools that need one (getID3, ffmpeg, Node). Null when it is not there. */
    public function fetch(string $path): ?string;

    /** Bytes of the conventional cover image (`cover.jpg`, `folder.png`...) in the same folder as a music file, if any. */
    public function folderCover(string $path): ?string;
}
