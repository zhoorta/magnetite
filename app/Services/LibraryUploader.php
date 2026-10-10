<?php

namespace App\Services;

use App\Models\User;
use App\Support\TrackLibrary;
use FilesystemIterator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Unpacks an uploaded zip into the user's own folder. This is the one place Magnetite writes to the library, so it is strict:
 * only audio and cover images are extracted, paths that try to leave the folder abort the whole archive, symlinks and
 * hidden files are dropped, nothing that already exists is overwritten, the unpacked size is counted while copying
 * (the sizes a zip declares can lie) and a failed archive leaves nothing behind.
 */
class LibraryUploader
{
    private const COVER_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    private const CHUNK = 1024 * 1024;

    /** @return array{folder: string, added: int, existing: int, conflicts: int, skipped: int, bytes: int} */
    public function extract(User $user, string $zipPath, string $originalName): array
    {
        $store = $user->store();
        $remote = $store instanceof FerriteStore;

        if ($remote) {
            $this->assertWritable($store);
        }

        $base = $remote ? '' : $this->libraryRoot($user);
        $maxFiles = (int) config('magnetite.upload.max_files');
        $maxBytes = (int) (config('magnetite.upload.max_unpacked_mb') * 1024 * 1024);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('This is not a readable zip file.');
        }

        try {
            if ($zip->numFiles > $maxFiles * 4) {
                throw new RuntimeException('The zip has too many entries.');
            }

            [$entries, $skipped] = $this->plan($zip);

            if (count($entries) > $maxFiles) {
                throw new RuntimeException("The zip has more than {$maxFiles} music files.");
            }

            if (! collect($entries)->contains(fn ($e) => TrackLibrary::contentType($e['path']) !== null)) {
                throw new RuntimeException('There is no audio in this zip (mp3, m4a, flac, ogg, opus or wav).');
            }

            $unpacked = array_sum(array_column($entries, 'size'));

            if ($unpacked > $maxBytes) {
                throw new RuntimeException('The zip is too large once unpacked.');
            }

            // Ferrite keeps its own quota; the folder check is only for a local library.
            $remote || $this->checkQuota($user, $base, $unpacked);

            $prefix = $this->needsFolder($entries) ? $this->folderName($originalName).'/' : '';

            $result = $remote
                ? $this->writeToFerrite($zip, $store, $entries, $prefix, $maxBytes)
                : $this->write($zip, $base, $entries, $prefix, $maxBytes) + ['conflicts' => 0];

            return $result + ['skipped' => $skipped];
        } finally {
            $zip->close();
        }
    }

    /**
     * Single audio files (the mobile apps), put in the root of the user's own folder. Audio only: no cover images, and
     * nothing that already exists is replaced. The name is the client's, cleaned to one plain file name.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{name: string, ok: bool, status?: string, error?: string}> status: added, existing or conflict
     */
    public function addFiles(User $user, array $files): array
    {
        $store = $user->store();
        $remote = $store instanceof FerriteStore;
        $remote && $this->assertWritable($store);
        $base = $remote ? '' : $this->libraryRoot($user);

        $results = [];
        foreach ($files as $file) {
            $client = $file->getClientOriginalName();
            $name = $this->plainName($client);

            if ($name === null || TrackLibrary::contentType($name) === null) {
                $results[] = ['name' => $client, 'ok' => false, 'error' => 'Only audio files can be added (mp3, m4a, flac, ogg, opus or wav).'];

                continue;
            }

            try {
                $remote || $this->checkQuota($user, $base, (int) $file->getSize());

                if ($remote) {
                    $status = $store->put($file->getRealPath(), $name);
                } elseif (file_exists($base.'/'.$name) || is_link($base.'/'.$name)) {
                    $status = 'existing';
                } else {
                    $partial = $base.'/'.$name.'.part';
                    if (! copy($file->getRealPath(), $partial) || ! chmod($partial, 0644) || ! rename($partial, $base.'/'.$name)) {
                        @unlink($partial);

                        throw new RuntimeException('Could not save '.$name.'.');
                    }
                    $status = 'added';
                }

                $results[] = ['name' => $name, 'ok' => true, 'status' => $status];
            } catch (RuntimeException|FerriteException $e) {
                $results[] = ['name' => $name, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Music files that already exist on this server (another user's shared tracks), copied into the user's own library
     * under the given relative paths. Same rules as every other write: audio only, nothing replaced, the quota
     * counted, paths cleaned to plain segments inside the user's folder.
     *
     * @param  list<array{source?: string, cover?: string, path: string}>  $items  `source` is an absolute file, or `cover` the bytes of a folder cover image; `path` is where it goes
     * @return array{added: int, existing: int, failed: int}
     */
    public function copyIn(User $user, array $items): array
    {
        $store = $user->store();
        $remote = $store instanceof FerriteStore;
        $remote && $this->assertWritable($store);
        $base = $remote ? '' : $this->libraryRoot($user);

        $counts = ['added' => 0, 'existing' => 0, 'failed' => 0];

        foreach ($items as $item) {
            $clean = array_map(fn ($segment) => $this->plainName($segment), explode('/', $item['path']));
            $temporary = null;

            try {
                if (isset($item['cover'])) {
                    // A cover is judged by its bytes, not its name: a real jpeg or png of a sane size, stored as cover.jpg or cover.png.
                    $extension = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'][@getimagesizefromstring($item['cover'])[2] ?? 0] ?? null;
                    if ($extension === null || strlen($item['cover']) > 15_000_000) {
                        continue;
                    }

                    $clean[array_key_last($clean)] = 'cover.'.$extension;
                    $source = $temporary = (string) tempnam(sys_get_temp_dir(), 'magnetite-cover-');
                    file_put_contents($source, $item['cover']);
                } else {
                    $source = $item['source'];
                    if (TrackLibrary::contentType((string) end($clean)) === null) {
                        $counts['failed']++;

                        continue;
                    }
                }

                if (in_array(null, $clean, true) || ! is_file($source)) {
                    $counts['failed']++;

                    continue;
                }

                // Cover images are not counted: they ride along with their music.
                $status = $remote ? $store->put($source, implode('/', $clean)) : $this->placeLocal($user, $base, $source, implode('/', $clean));
                isset($item['cover']) || $counts[$status === 'added' ? 'added' : 'existing']++;
            } finally {
                $temporary !== null && @unlink($temporary);
            }
        }

        return $counts;
    }

    /** Copy a file to a relative path inside the user's folder without replacing anything. Returns `added` or `existing`. */
    private function placeLocal(User $user, string $base, string $source, string $path): string
    {
        $destination = $base.'/'.$path;
        if (file_exists($destination) || is_link($destination)) {
            return 'existing';
        }

        $this->checkQuota($user, $base, (int) filesize($source));

        $directory = dirname($destination);
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create a folder in your library.');
        }

        $real = realpath($directory);
        if ($real === false || ($real !== $base && ! str_starts_with($real, $base.'/'))) {
            throw new RuntimeException('That would write outside your library.');
        }

        $partial = $destination.'.part';
        if (! copy($source, $partial) || ! chmod($partial, 0644) || ! rename($partial, $destination)) {
            @unlink($partial);

            throw new RuntimeException('Could not copy '.basename($path).'.');
        }

        return 'added';
    }

    /** One plain file name from whatever the client sent: no folders, no control characters, not hidden. */
    private function plainName(string $client): ?string
    {
        $name = basename(str_replace('\\', '/', $client));

        if (! mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $name) || str_starts_with($name, '.') || strlen($name) > 255) {
            return null;
        }

        return $name === '' ? null : $name;
    }

    /** Uploading needs a token that may add files, and says so before anything is unpacked. */
    private function assertWritable(FerriteStore $store): void
    {
        try {
            $writable = $store->canWrite();
        } catch (FerriteException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        if (! $writable) {
            throw new RuntimeException('The Ferrite token of this library is read-only, so music cannot be uploaded here.');
        }
    }

    /**
     * Send each entry to Ferrite, one at a time through a temporary file (the size a zip declares cannot be trusted, so
     * it is counted while copying). Nothing is replaced. Ferrite's token cannot delete, so a failure halfway cannot be
     * rolled back: the message says how many files already arrived, and sending the same zip again finishes the job.
     *
     * @return array{folder: string, added: int, existing: int, conflicts: int, bytes: int}
     */
    private function writeToFerrite(ZipArchive $zip, FerriteStore $store, array $entries, string $prefix, int $maxBytes): array
    {
        $dir = sys_get_temp_dir().'/magnetite-up-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        $counts = ['added' => 0, 'existing' => 0, 'conflict' => 0];
        $total = 0;

        try {
            foreach ($entries as $entry) {
                $local = $dir.'/file';
                $total += $this->copy($zip, $entry, $local, $maxBytes - $total);

                try {
                    $counts[$store->put($local, $prefix.$entry['path'])]++;
                } finally {
                    @unlink($local);
                }
            }
        } catch (FerriteException $e) {
            $done = array_sum($counts);

            throw new RuntimeException($done > 0
                ? "{$e->getMessage()} {$done} of ".count($entries).' files had arrived; send the same zip again to finish.'
                : $e->getMessage(), 0, $e);
        } finally {
            File::deleteDirectory($dir);
        }

        return [
            'folder' => rtrim($prefix, '/') ?: strstr($entries[0]['path'], '/', true),
            'added' => $counts['added'],
            'existing' => $counts['existing'],
            'conflicts' => $counts['conflict'],
            'bytes' => $total,
        ];
    }

    /** The user's folder, created on the first upload. It always sits inside the music folder's `users/`. */
    private function libraryRoot(User $user): string
    {
        $root = realpath(TrackLibrary::path());

        if ($root === false || ! is_dir($root) || ! is_writable($root)) {
            throw new RuntimeException('The music folder does not exist or is not writable.');
        }

        $users = $root.'/users';
        if (! is_dir($users) && ! @mkdir($users, 0755) && ! is_dir($users)) {
            throw new RuntimeException('Could not create the folder for the libraries.');
        }

        $folder = TrackLibrary::userPath($user);
        if (! is_dir($folder) && ! @mkdir($folder, 0755) && ! is_dir($folder)) {
            throw new RuntimeException('Could not create your library folder.');
        }

        $base = realpath($folder);
        if ($base === false || ! str_starts_with($base, $root.'/users/') || ! is_writable($base)) {
            throw new RuntimeException('Your library folder is not usable.');
        }

        return $base;
    }

    /** Refuse an upload that would take the user's folder over their quota (users.quota_mb, none by default). */
    private function checkQuota(User $user, string $base, int $incoming): void
    {
        if ($user->quota_mb === null) {
            return;
        }

        $used = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
            $used += $file->isFile() && ! $file->isLink() ? $file->getSize() : 0;
        }

        if ($used + $incoming > $user->quota_mb * 1024 * 1024) {
            throw new RuntimeException("This would take your library over its limit of {$user->quota_mb} MB.");
        }
    }

    /**
     * Decide what to extract. Returns the wanted entries and how many were left out.
     *
     * @return array{0: list<array{index: int, name: string, path: string, size: int}>, 1: int}
     */
    private function plan(ZipArchive $zip): array
    {
        $entries = [];
        $skipped = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) $stat['name'];

            if (str_ends_with($name, '/')) {
                continue;
            }

            $path = $this->safePath($name);

            if ($path === null || $this->isSymlink($zip, $i) || ! $this->wanted($path)) {
                $skipped++;

                continue;
            }

            $entries[] = ['index' => $i, 'name' => $name, 'path' => $path, 'size' => (int) $stat['size']];
        }

        return [$entries, $skipped];
    }

    /**
     * The cleaned relative path, null for entries to ignore (hidden files, macOS resource forks). A path that tries to
     * leave the folder or is not a plain relative path aborts the whole archive.
     */
    private function safePath(string $name): ?string
    {
        if (! mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'CP437'); // zips from older Windows tools
        }

        $name = str_replace('\\', '/', $name);

        if (preg_match('/[\x00-\x1f\x7f]/', $name) || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name)) {
            throw new RuntimeException('The zip contains an unsafe file name.');
        }

        $segments = array_values(array_filter(explode('/', $name), fn ($s) => $s !== '' && $s !== '.'));

        foreach ($segments as $segment) {
            if ($segment === '..' || strlen($segment) > 255) {
                throw new RuntimeException('The zip contains an unsafe file name.');
            }
        }

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '.') || $segment === '__MACOSX') {
                return null;
            }
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    private function isSymlink(ZipArchive $zip, int $index): bool
    {
        $zip->getExternalAttributesIndex($index, $opsys, $attributes);

        return $opsys === ZipArchive::OPSYS_UNIX && ((($attributes >> 16) & 0170000) === 0120000);
    }

    private function wanted(string $path): bool
    {
        return TrackLibrary::contentType($path) !== null
            || in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::COVER_EXTENSIONS, true);
    }

    /** A zip with loose files, or several top folders, goes into a folder named after the zip so it does not clutter the library. */
    private function needsFolder(array $entries): bool
    {
        $tops = collect($entries)->map(fn ($e) => str_contains($e['path'], '/') ? strstr($e['path'], '/', true) : null);

        return $tops->contains(null) || $tops->unique()->count() > 1;
    }

    private function folderName(string $originalName): string
    {
        $name = trim(preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', pathinfo($originalName, PATHINFO_FILENAME)), ' ._');

        return $name === '' ? 'Upload '.date('Y-m-d H.i') : mb_substr($name, 0, 120);
    }

    /** @return array{folder: string, added: int, existing: int, bytes: int} */
    private function write(ZipArchive $zip, string $base, array $entries, string $prefix, int $maxBytes): array
    {
        $created = [];
        $existing = 0;
        $total = 0;

        try {
            foreach ($entries as $entry) {
                $destination = $base.'/'.$prefix.$entry['path'];
                $directory = dirname($destination);

                if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                    throw new RuntimeException('Could not create a folder in the music folder.');
                }

                // Resolve what is really there: a symlink inside the library must not lead the write elsewhere.
                $real = realpath($directory);
                if ($real === false || ($real !== $base && ! str_starts_with($real, $base.'/'))) {
                    throw new RuntimeException('The zip would write outside the music folder.');
                }

                $destination = $real.'/'.basename($destination);

                if (file_exists($destination) || is_link($destination)) {
                    $existing++;

                    continue;
                }

                $total += $this->copy($zip, $entry, $destination, $maxBytes - $total);
                $created[] = $destination;
            }
        } catch (Throwable $e) {
            foreach ($created as $file) {
                @unlink($file);
            }

            throw $e instanceof RuntimeException ? $e : new RuntimeException('Could not unpack the zip: '.$e->getMessage(), 0, $e);
        }

        return [
            'folder' => rtrim($prefix, '/') ?: strstr($entries[0]['path'], '/', true),
            'added' => count($created),
            'existing' => $existing,
            'bytes' => $total,
        ];
    }

    /** Stream one entry to disk, counting what is really written. Returns the bytes written. */
    private function copy(ZipArchive $zip, array $entry, string $destination, int $allowance): int
    {
        $in = $zip->getStream($entry['name']);
        $partial = $destination.'.part';
        $out = $in ? @fopen($partial, 'xb') : false;

        if (! $in || ! $out) {
            $in && fclose($in);

            throw new RuntimeException('Could not read or write '.$entry['path'].'.');
        }

        $written = 0;

        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);

                if ($chunk === false) {
                    throw new RuntimeException('Could not read '.$entry['path'].' from the zip.');
                }

                $written += strlen($chunk);

                if ($written > $allowance) {
                    throw new RuntimeException('The zip is too large once unpacked.');
                }

                fwrite($out, $chunk);
            }
        } catch (Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($partial);

            throw $e;
        }

        fclose($in);
        fclose($out);
        chmod($partial, 0644);

        if (! rename($partial, $destination)) {
            @unlink($partial);

            throw new RuntimeException('Could not save '.$entry['path'].'.');
        }

        return $written;
    }
}
