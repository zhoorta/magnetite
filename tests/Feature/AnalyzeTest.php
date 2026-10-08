<?php

use App\Models\Track;
use App\Services\LibraryScanner;
use App\Services\TrackAnalyzer;
use App\Support\FileTruth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Storage::fake('local');
    $this->music = sys_get_temp_dir().'/magnetite-analyze-'.uniqid();
    File::copyDirectory(base_path('tests/Fixtures/music'), $this->music);
    config(['magnetite.music_path' => $this->music]);
});

afterEach(fn () => File::deleteDirectory($this->music));

/** Render audio with ffmpeg into the test library; skips the test when ffmpeg cannot. */
function render(string $dir, string $name, array $args): void
{
    $ffmpeg = app(TrackAnalyzer::class)->ffmpeg();
    $p = new Process([$ffmpeg, '-nostdin', '-v', 'error', '-y', ...$args, $dir.'/'.$name]);
    $p->setTimeout(120)->run();
    $p->isSuccessful() || test()->markTestSkipped('ffmpeg could not make the test file: '.$p->getErrorOutput());
}

describe('without the tools', function () {
    it('says what is missing and measures nothing', function () {
        config(['magnetite.ffmpeg' => '/nonexistent/ffmpeg', 'magnetite.node' => '/nonexistent/node']);
        app()->forgetInstance(TrackAnalyzer::class);
        app(LibraryScanner::class)->scan();

        $this->artisan('magnetite:analyze')->expectsOutputToContain('Needed but not found')->assertFailed();
        expect(Track::whereNotNull('analysis')->count())->toBe(0);
    });
});

describe('with ffmpeg and node', function () {
    beforeEach(fn () => app(TrackAnalyzer::class)->missing() === [] || $this->markTestSkipped('ffmpeg, ffprobe or node not installed'));

    it('measures every track, once, and keeps the files untouched', function () {
        app(LibraryScanner::class)->scan();
        $before = collect(File::allFiles($this->music))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => md5_file($f->getRealPath())]);

        $this->artisan('magnetite:analyze')->assertSuccessful();

        $track = Track::where('title', 'First Song')->sole();
        expect(Track::whereNull('analysis')->count())->toBe(0)
            ->and($track->analysis['loudness'])->toHaveKeys(['integrated', 'range', 'true_peak_db', 'sample_peak_db'])
            ->and($track->analysis['source']['codec'])->toBe('mp3')
            ->and($track->pcm_sha1)->toMatch('/^[0-9a-f]{40}$/')
            ->and($track->analysis_version)->toBe(TrackAnalyzer::VERSION)
            ->and(collect(File::allFiles($this->music))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => md5_file($f->getRealPath())]))->toEqual($before);

        // Nothing left to do the second time.
        $this->artisan('magnetite:analyze')->expectsOutputToContain('analysed already')->assertSuccessful();
    });

    it('forgets the measurements of a file that changed', function () {
        app(LibraryScanner::class)->scan();
        $this->artisan('magnetite:analyze')->assertSuccessful();

        $path = $this->music.'/Compilation/01.mp3';
        file_put_contents($path, 'x', FILE_APPEND); // a new size, so the next scan reads the file again
        app(LibraryScanner::class)->scan();

        $changed = Track::where('path', 'Compilation/01.mp3')->sole();
        expect($changed->analysis)->toBeNull()->and($changed->dr)->toBeNull()
            ->and(Track::where('path', 'Compilation/02.mp3')->sole()->analysis)->not->toBeNull();
    });

    it('scores a plain sine as DR0 and finds nothing wrong with it', function () {
        render($this->music, 'sine.wav', ['-f', 'lavfi', '-i', 'sine=frequency=997:duration=12', '-ac', '2', '-c:a', 'pcm_s24le']);
        app(LibraryScanner::class)->scan();
        $this->artisan('magnetite:analyze')->assertSuccessful();

        $t = Track::where('path', 'sine.wav')->sole();
        expect($t->dr)->toBe(0)
            ->and($t->analysis['dr']['value'])->toBeLessThan(0.1)
            ->and($t->analysis['source']['codec'])->toBe('pcm_s24le')
            ->and($t->analysis['dual_mono'])->toBeTrue();
        expect(array_column(FileTruth::flags($t), 'code'))->toBe(['crushed', 'dual_mono']); // DR0 is as compressed as it gets
    });

    it('catches a lossy file stored as FLAC, and the duplicate of it', function () {
        // White noise through a 128 kbit/s MP3 encoder keeps only what is below its low-pass (about 16 kHz).
        render($this->music, 'disguise.mp3', ['-f', 'lavfi', '-i', 'anoisesrc=d=20:c=white:a=0.3:r=44100', '-ac', '2', '-c:a', 'libmp3lame', '-b:a', '128k']);
        render($this->music, 'disguise.flac', ['-i', $this->music.'/disguise.mp3', '-c:a', 'flac']);
        render($this->music, 'disguise copy.flac', ['-i', $this->music.'/disguise.mp3', '-c:a', 'flac']);
        app(LibraryScanner::class)->scan();
        $this->artisan('magnetite:analyze')->assertSuccessful();

        $flac = Track::where('path', 'disguise.flac')->sole();
        $copies = FileTruth::copies(Track::get());
        $codes = array_column(FileTruth::flags($flac, $copies[$flac->id] ?? []), 'code');

        expect($flac->analysis['spectrum']['cutoff_hz'])->toBeBetween(15000, 18500)
            ->and($codes)->toContain('lossy_source')->toContain('duplicate')
            // The MP3 itself is lossy by nature and is not accused of anything.
            ->and(array_column(FileTruth::flags(Track::where('path', 'disguise.mp3')->sole()), 'code'))->not->toContain('lossy_source');
    });
});

it('shows the scores on the library page, or says how to get them', function () {
    app(LibraryScanner::class)->scan();

    $this->get(route('library'))->assertOk()->assertSee('magnetite:analyze');

    $track = Track::where('title', 'First Song')->sole();
    $track->forceFill(['dr' => 9, 'analysis' => ['sample_rate' => 44100, 'source' => ['codec' => 'mp3'], 'loudness' => ['integrated' => -12.3], 'spectrum' => ['db' => []]]])->save();

    $this->get(route('library'))->assertOk()->assertSee('DR9')->assertDontSee('Run <code>php artisan magnetite:analyze', false);
});
