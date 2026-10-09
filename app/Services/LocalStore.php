<?php

namespace App\Services;

use App\Contracts\LibraryStore;
use App\Models\User;
use App\Support\StoredFile;
use App\Support\TrackLibrary;
use Illuminate\Support\Facades\File;

/** A library in the user's own folder under `MAGNETITE_MUSIC_PATH/users/{username}`. */
class LocalStore implements LibraryStore
{
    private const FOLDER_COVERS = ['cover', 'folder', 'front', 'album', 'albumart'];

    public function __construct(private User $user) {}

    public function isAvailable(): bool
    {
        return is_dir(TrackLibrary::userPath($this->user));
    }

    public function files(): iterable
    {
        if (! $this->isAvailable()) {
            return;
        }

        foreach (File::allFiles(TrackLibrary::userPath($this->user)) as $file) {
            if (TrackLibrary::contentType($file->getFilename()) !== null) {
                yield new StoredFile($file->getRelativePathname(), $file->getSize(), $file->getMTime());
            }
        }
    }

    public function fetch(string $path): ?string
    {
        return TrackLibrary::resolve($path, $this->user);
    }

    public function folderCover(string $path): ?string
    {
        $file = $this->fetch($path);
        if ($file === null) {
            return null;
        }

        foreach (File::files(dirname($file)) as $candidate) {
            if (in_array(strtolower($candidate->getExtension()), ['jpg', 'jpeg', 'png'], true)
                && in_array(strtolower($candidate->getFilenameWithoutExtension()), self::FOLDER_COVERS, true)
                && $candidate->getSize() < 15_000_000) {
                return File::get($candidate->getRealPath());
            }
        }

        return null;
    }
}
