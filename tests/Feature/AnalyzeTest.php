<?php

use App\Jobs\AnalyzeTracks;
use App\Jobs\FindCovers;
use App\Jobs\ScanLibrary;
use App\Models\Track;
use App\Models\User;
use App\Services\LibraryScanner;
use App\Services\TrackAnalyzer;
use App\Support\AnalysisStatus;
use App\Support\FileTruth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(makeLibrary());
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
        app(LibraryScanner::class)->scan($this->user);

        $this->artisan('magnetite:analyze')->expectsOutputToContain('Needed but not found')->assertFailed();
        expect(Track::whereNotNull('analysis')->count())->toBe(0);
    });
});

describe('with ffmpeg and node', function () {
    beforeEach(fn () => app(TrackAnalyzer::class)->missing() === [] || $this->markTestSkipped('ffmpeg, ffprobe or node not installed'));

    it('measures every track, once, and keeps the files untouched', function () {
        app(LibraryScanner::class)->scan($this->user);
        $before = collect(File::allFiles($this->library))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => md5_file($f->getRealPath())]);

        $this->artisan('magnetite:analyze')->assertSuccessful();

        $track = Track::where('title', 'First Song')->sole();
        expect(Track::whereNull('analysis')->count())->toBe(0)
            ->and($track->analysis['loudness'])->toHaveKeys(['integrated', 'range', 'true_peak_db', 'sample_peak_db'])
            ->and($track->analysis['source']['codec'])->toBe('mp3')
            ->and($track->pcm_sha1)->toMatch('/^[0-9a-f]{40}$/')
            ->and($track->analysis_version)->toBe(TrackAnalyzer::VERSION)
            ->and(collect(File::allFiles($this->library))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => md5_file($f->getRealPath())]))->toEqual($before);

        // Nothing left to do the second time.
        $this->artisan('magnetite:analyze')->expectsOutputToContain('analysed already')->assertSuccessful();
    });

    it('forgets the measurements of a file that changed', function () {
        app(LibraryScanner::class)->scan($this->user);
        $this->artisan('magnetite:analyze')->assertSuccessful();

        $path = $this->library.'/Compilation/01.mp3';
        file_put_contents($path, 'x', FILE_APPEND); // a new size, so the next scan reads the file again
        app(LibraryScanner::class)->scan($this->user);

        $changed = Track::where('path', 'Compilation/01.mp3')->sole();
        expect($changed->analysis)->toBeNull()->and($changed->dr)->toBeNull()
            ->and(Track::where('path', 'Compilation/02.mp3')->sole()->analysis)->not->toBeNull();
    });

    it('scores a plain sine as DR0 and finds nothing wrong with it', function () {
        render($this->library, 'sine.wav', ['-f', 'lavfi', '-i', 'sine=frequency=997:duration=12', '-ac', '2', '-c:a', 'pcm_s24le']);
        app(LibraryScanner::class)->scan($this->user);
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
        render($this->library, 'disguise.mp3', ['-f', 'lavfi', '-i', 'anoisesrc=d=20:c=white:a=0.3:r=44100', '-ac', '2', '-c:a', 'libmp3lame', '-b:a', '128k']);
        render($this->library, 'disguise.flac', ['-i', $this->library.'/disguise.mp3', '-c:a', 'flac']);
        render($this->library, 'disguise copy.flac', ['-i', $this->library.'/disguise.mp3', '-c:a', 'flac']);
        app(LibraryScanner::class)->scan($this->user);
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

it('hands the scores to the library page, and says which albums still need measuring', function () {
    app(LibraryScanner::class)->scan($this->user);

    $this->get(route('library'))->assertOk()->assertSee('Analyse');
    $before = $this->getJson(route('library'))->assertOk()->json('albums');
    expect(collect($before)->pluck('needs_analysis')->unique()->all())->toBe([true])->and(collect($before)->pluck('dr')->filter()->all())->toBe([]);

    $track = Track::where('title', 'First Song')->sole();
    $track->forceFill(['dr' => 9, 'analysis' => ['sample_rate' => 44100, 'source' => ['codec' => 'mp3'], 'loudness' => ['integrated' => -12.3], 'spectrum' => ['db' => []]]])->save();

    $album = collect($this->getJson(route('library'))->json('albums'))->firstWhere('id', $track->album_id);
    expect($album['dr'])->toBe(9)->and($album['dr_class'])->not->toBeNull()
        ->and(collect($album['tracks'])->firstWhere('id', $track->id)['truth']['dr'])->toBe(9);
});

describe('the Analyse entries', function () {
    it('queues one job for the signed-in user, and reports progress', function () {
        Queue::fake();
        config(['queue.default' => 'database']); // the sync driver never treats a waiting job as one to coalesce with
        app(LibraryScanner::class)->scan($this->user);

        $this->postJson(route('analysis.store'))->assertStatus(202)->assertJson(['state' => 'queued']);
        $this->postJson(route('analysis.store'))->assertStatus(202);

        Queue::assertPushed(AnalyzeTracks::class, 1);
        $this->getJson(route('analysis.show'))->assertOk()->assertJson(['state' => 'queued']);
    });

    it('is flagged only while some file of the album has no current analysis', function () {
        app(LibraryScanner::class)->scan($this->user);
        $needs = fn () => collect($this->getJson(route('library'))->json('albums'))->pluck('needs_analysis')->all();
        expect($needs())->each->toBeTrue();

        Track::query()->update(['analysis_version' => TrackAnalyzer::VERSION]);
        expect($needs())->each->toBeFalse();

        Track::query()->whereNotNull('album_id')->first()->update(['analysis_version' => TrackAnalyzer::VERSION - 1]);
        expect($needs())->toContain(true);
    });

    it('queues a run limited to one album or one track of the signed-in user', function () {
        Queue::fake();
        config(['queue.default' => 'database']);
        app(LibraryScanner::class)->scan($this->user);
        $track = Track::whereNotNull('album_id')->first();

        $this->postJson(route('analysis.store'), ['album_id' => $track->album_id])->assertStatus(202);
        Queue::assertPushed(AnalyzeTracks::class, fn ($job) => $job->albumId === $track->album_id && $job->trackId === null);
    });

    it('answers 404 for the album or track of somebody else, and queues a request behind a run that is going', function () {
        Queue::fake();
        config(['queue.default' => 'database']);
        app(LibraryScanner::class)->scan($this->user);
        $other = User::factory()->create(['username' => 'bob']);
        $theirs = Track::query()->first()->replicate();
        $theirs->forceFill(['user_id' => $other->id, 'path' => 'x.mp3'])->save();

        $this->postJson(route('analysis.store'), ['track_id' => $theirs->id])->assertNotFound();
        Queue::assertNothingPushed();

        $mine = Track::where('user_id', $this->user->id)->first();
        $this->postJson(route('analysis.store'), ['track_id' => $mine->id])->assertStatus(202);
        // The same album again adds nothing; another album waits its turn and shows as queued.
        $this->postJson(route('analysis.store'), ['track_id' => $mine->id])->assertStatus(202);
        Queue::assertPushed(AnalyzeTracks::class, 1);
    });

    it('marks every requested album as queued, and one job per request waits for the worker', function () {
        Queue::fake();
        config(['queue.default' => 'database']);
        app(LibraryScanner::class)->scan($this->user);
        $albums = Track::where('user_id', $this->user->id)->whereNotNull('album_id')->pluck('album_id')->unique()->values();
        $albums->count() > 1 || $this->markTestSkipped('the fixtures have one album');

        foreach ($albums as $id) {
            $this->postJson(route('analysis.store'), ['album_id' => $id])->assertStatus(202);
        }

        Queue::assertPushed(AnalyzeTracks::class, $albums->count());
        $status = AnalysisStatus::get($this->user->id);
        expect($status['state'])->toBe('queued');
        foreach ($albums as $id) {
            expect($status['albums'][$id]['state'])->toBe('queued');
        }
    });

    it('measures only the track it was asked for', function () {
        app(TrackAnalyzer::class)->missing() === [] || $this->markTestSkipped('ffmpeg, ffprobe or node not installed');
        app(LibraryScanner::class)->scan($this->user);
        $track = Track::where('title', 'First Song')->sole();

        AnalyzeTracks::dispatchSync($this->user->id, null, $track->id);

        expect(Track::whereNotNull('analysis')->pluck('id')->all())->toBe([$track->id]);
    });

    it('refuses to run inside the request with the sync queue', function () {
        Queue::fake();
        config(['queue.default' => 'sync']);

        $this->postJson(route('analysis.store'))->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'queue worker'));
        Queue::assertNothingPushed();
    })->skip(fn () => app(TrackAnalyzer::class)->missing() !== [], 'ffmpeg, ffprobe or node not installed');

    it('says what the server lacks instead of queueing', function () {
        Queue::fake();
        config(['queue.default' => 'database', 'magnetite.ffmpeg' => '/nonexistent/ffmpeg', 'magnetite.node' => '/nonexistent/node']);
        app()->forgetInstance(TrackAnalyzer::class);

        $this->postJson(route('analysis.store'))->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'ffmpeg'));
        Queue::assertNothingPushed();
    });

    it('needs a signed-in user', function () {
        auth()->logout();

        $this->postJson(route('analysis.store'))->assertUnauthorized();
    });

    it('measures only this user\'s tracks in the job, and counts a broken file without stopping', function () {
        app(TrackAnalyzer::class)->missing() === [] || $this->markTestSkipped('ffmpeg, ffprobe or node not installed');
        app(LibraryScanner::class)->scan($this->user);
        $other = User::factory()->create(['username' => 'bob']);
        $theirs = Track::query()->first()->replicate();
        $theirs->forceFill(['user_id' => $other->id, 'path' => 'x.mp3'])->save();
        Track::where('title', 'First Song')->update(['path' => 'gone.mp3']);

        AnalyzeTracks::dispatchSync($this->user->id);

        expect(Track::where('user_id', $this->user->id)->whereNull('analysis')->pluck('title')->all())->toBe(['First Song'])
            ->and(Track::find($theirs->id)->analysis)->toBeNull()
            ->and(AnalysisStatus::get($this->user->id))->toMatchArray(['state' => 'done', 'failed' => 1]);
    });
});

it('can be released back to the queue when another run holds the lock', function (string $job) {
    // WithoutOverlapping calls release() on the job; without InteractsWithQueue that was a fatal error.
    expect(method_exists($job, 'release'))->toBeTrue();
})->with([AnalyzeTracks::class, ScanLibrary::class, FindCovers::class]);

it('keeps retrying while another run holds the lock', function (string $job) {
    // A released job counts as an attempt; without retryUntil() the second one failed with "attempted too many times".
    expect(method_exists($job, 'retryUntil'))->toBeTrue();
})->with([AnalyzeTracks::class, ScanLibrary::class, FindCovers::class]);
