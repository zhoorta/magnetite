<?php

namespace App\Services;

use App\Contracts\LibraryStore;
use App\Models\User;
use App\Support\StoredFile;
use App\Support\TrackLibrary;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** A library in the user's own folder under `MAGNETITE_MUSIC_PATH/users/{username}`. */
class LocalStore implements LibraryStore
{
    private const FOLDER_COVERS = ['cover', 'folder', 'front', 'album', 'albumart'];

    public function __construct(private User $user) {}

    public function isAvailable(): bool
    {
        return is_dir(TrackLibrary::userPath($this->user));
    }

    public function unavailableReason(): string
    {
        return "The library folder of '{$this->user->username}' is missing.";
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

    public function delete(string $path): void
    {
        $file = $this->fetch($path);
        if ($file === null) {
            return; // already gone, or not a music file inside the library
        }

        if (! @unlink($file)) {
            throw new RuntimeException("The file {$path} could not be deleted (is the music folder writable?).");
        }

        // Folders the album leaves empty go too, never the library folder itself.
        $base = realpath(TrackLibrary::userPath($this->user));
        for ($dir = dirname($file); $base && $dir !== $base && str_starts_with($dir, $base.DIRECTORY_SEPARATOR); $dir = dirname($dir)) {
            if (! @rmdir($dir)) {
                break; // not empty
            }
        }
    }

    public function move(string $path, string $folder): string
    {
        $from = $this->fetch($path) ?? throw new RuntimeException('The file is missing.');
        $base = realpath(TrackLibrary::userPath($this->user));
        $to = $base === false ? false : realpath($folder === '' ? $base : $base.'/'.$folder);

        if ($to === false || ($to !== $base && ! str_starts_with($to, $base.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('That folder is not in your library.');
        }

        $destination = $to.'/'.basename($from);
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('A file with that name is already in the folder.');
        }

        if (! @rename($from, $destination)) {
            throw new RuntimeException('The file could not be moved (is the music folder writable?).');
        }

        // The folder it left may be empty now; never remove the library folder itself.
        for ($dir = dirname($from); $dir !== $base && str_starts_with($dir, $base.DIRECTORY_SEPARATOR); $dir = dirname($dir)) {
            if (! @rmdir($dir)) {
                break;
            }
        }

        return ltrim(($folder === '' ? '' : $folder.'/').basename($from), '/');
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
