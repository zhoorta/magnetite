<?php

namespace App\Services;

use App\Models\Scene;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps scene pictures as JPEGs on the local (private) disk, named by the hash of the re-encoded bytes. Whatever was
 * uploaded is decoded and drawn again, so only pixels are kept (no metadata, no embedded scripts, no odd formats).
 */
class SceneStore
{
    public const MAX_SIDE = 1600;

    private const MAX_PIXELS = 40_000_000;

    /** @return string|null the file name, or null when the bytes are not a usable picture */
    public function store(string $bytes): ?string
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)
            || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        imagefill($dst, 0, 0, imagecolorallocate($dst, 0, 0, 0)); // transparency becomes black: scenes are drawn on a dark cell
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);

        ob_start();
        imagejpeg($dst, null, 88);
        $jpeg = ob_get_clean();

        $name = sha1($jpeg).'.jpg';
        Storage::disk('local')->put("scenes/{$name}", $jpeg);

        return $name;
    }

    public function path(string $name): ?string
    {
        if (! preg_match('/^[a-f0-9]{40}\.jpg$/', $name)) {
            return null;
        }
        $path = Storage::disk('local')->path("scenes/{$name}");

        return is_file($path) ? $path : null;
    }

    /** Remove the file once no scene uses it (the same picture can be two scenes). */
    public function forget(string $name): void
    {
        if (preg_match('/^[a-f0-9]{40}\.jpg$/', $name) && ! Scene::where('file', $name)->exists()) {
            Storage::disk('local')->delete("scenes/{$name}");
        }
    }
}
