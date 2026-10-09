<?php

use App\Models\Track;
use App\Services\LibraryScanner;
use App\Services\TranscodeCache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(makeLibrary());
    app(LibraryScanner::class)->scan($this->user);
});

afterEach(fn () => File::deleteDirectory($this->music));

function cachedFlacs(): array
{
    return Storage::disk('local')->files('transcodes');
}

describe('with ffmpeg', function () {
    beforeEach(fn () => app(TranscodeCache::class)->available() || $this->markTestSkipped('ffmpeg not installed'));

    it('serves ALAC as FLAC and keeps the original untouched', function () {
        $alac = Track::where('codec', 'alac')->sole();

        $response = $this->get(route('stream', $alac))
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/flac')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        expect(file_get_contents($response->baseResponse->getFile()->getPathname(), length: 4))->toBe('fLaC')
            ->and(cachedFlacs())->toHaveCount(1)
            ->and(File::exists(base_path('tests/Fixtures/music/'.$alac->path)))->toBeTrue()
            ->and(File::glob(base_path('tests/Fixtures/music/**/*.flac')))->toBeEmpty();
    });

    it('converts once and answers range requests from the cache', function () {
        $alac = Track::where('codec', 'alac')->sole();

        $this->get(route('stream', $alac))->assertOk();
        $file = cachedFlacs()[0];
        $stamp = Storage::disk('local')->lastModified($file);
        $size = Storage::disk('local')->size($file);

        $this->get(route('stream', $alac), ['Range' => 'bytes=0-99'])
            ->assertStatus(206)
            ->assertHeader('Content-Type', 'audio/flac')
            ->assertHeader('Content-Range', "bytes 0-99/{$size}");

        expect(cachedFlacs())->toBe([$file])
            ->and(Storage::disk('local')->lastModified($file))->toBe($stamp);
    });

    it('does not touch non-ALAC tracks', function () {
        $mp3 = Track::where('title', 'First Song')->sole();

        $this->get(route('stream', $mp3))->assertOk()->assertHeader('Content-Type', 'audio/mpeg');

        expect(cachedFlacs())->toBeEmpty();
    });

    it('marks ALAC as playable because it is converted', function () {
        expect(Track::where('codec', 'alac')->sole()->playable)->toBeTrue();
    });

    it('prunes cached files that no ALAC track maps to', function () {
        $this->get(route('stream', Track::where('codec', 'alac')->sole()))->assertOk();
        Storage::disk('local')->put('transcodes/stale.flac', 'old');

        expect(app(TranscodeCache::class)->prune())->toBe(1)
            ->and(cachedFlacs())->toHaveCount(1);
    });

    it('answers 503 when the conversion fails', function () {
        config(['magnetite.ffmpeg' => PHP_BINARY]); // any executable that exits non-zero without producing output
        app()->forgetInstance(TranscodeCache::class);

        $this->get(route('stream', Track::where('codec', 'alac')->sole()))->assertStatus(503);

        expect(cachedFlacs())->toBeEmpty();
    });

    it('never sends a non-audio file to ffmpeg', function () {
        $notAudio = Track::create(['user_id' => $this->user->id,
            'path' => 'Loose/notes.txt', 'title' => 'x', 'codec' => 'alac', 'mime' => 'audio/mp4', 'size' => 1, 'mtime' => 1,
        ]);

        $this->get(route('stream', $notAudio))->assertNotFound();

        expect(cachedFlacs())->toBeEmpty();
    });
});

describe('without ffmpeg', function () {
    it('serves the original ALAC file and flags it as unplayable in Chrome and Firefox', function () {
        app()->instance(TranscodeCache::class, new class extends TranscodeCache
        {
            public function available(): bool
            {
                return false;
            }
        });

        $alac = Track::where('codec', 'alac')->sole();

        $this->get(route('stream', $alac))->assertOk()->assertHeader('Content-Type', 'audio/mp4');

        expect($alac->playable)->toBeFalse()
            ->and(cachedFlacs())->toBeEmpty();
    });
});
