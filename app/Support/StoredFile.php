<?php

namespace App\Support;

/** One music file as a store lists it. */
final class StoredFile
{
    public function __construct(
        public readonly string $path, // relative to the library root
        public readonly int $size,
        public readonly int $mtime,
    ) {}
}
