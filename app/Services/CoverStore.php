<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Keeps album covers as small JPEGs on the local (private) disk, named by the hash of the
 * source image so identical covers are stored once. Originals are never written back.
 */
class CoverStore
{
    public const MAX_SIDE = 500;

    private const MAX_PIXELS = 40_000_000; // refuse to decode absurdly large images

    public function store(string $bytes): ?string
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

        $name = sha1($bytes).'.jpg';
        $disk = Storage::disk('local');
        if ($disk->exists("covers/{$name}")) {
            return $name;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // flatten transparency (PNG covers)
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);

        ob_start();
        imagejpeg($dst, null, 82);
        $jpeg = ob_get_clean();

        $disk->put("covers/{$name}", $jpeg);

        return $name;
    }
}
