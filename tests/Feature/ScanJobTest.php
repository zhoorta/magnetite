<?php

use App\Jobs\ScanLibrary;
use App\Models\Track;
use App\Services\LibraryScanner;
use App\Support\ScanStatus;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Cache::flush();
    config(['queue.default' => 'database']); // the tests run on sync, which never leaves a job waiting
    makeLibrary();
});

afterEach(fn () => File::deleteDirectory($this->music));

it('starts out idle', fn () => expect(ScanStatus::get($this->user->id)['state'])->toBe('idle'));

it('scans in the job and records the counts', function () {
    ScanLibrary::dispatchSync($this->user->id);

    $status = ScanStatus::get($this->user->id);
    expect($status['state'])->toBe('done')
        ->and($status['counts']['added'])->toBe(Track::count())
        ->and($status['processed'])->toBe(Track::count())
        ->and($status['finished_at'])->not->toBeNull();
});

it('reports queued until the worker takes the job, and asks for a single job', function () {
    Queue::fake();

    ScanLibrary::request($this->user);
    ScanLibrary::request($this->user);

    expect(ScanStatus::get($this->user->id)['state'])->toBe('queued');
    Queue::assertPushed(ScanLibrary::class, 1);
});

it('queues another scan behind a running one, and ignores a queued flag that is over an hour old', function () {
    Queue::fake();

    ScanLibrary::request($this->user);
    $this->travel(2)->hours();
    ScanLibrary::request($this->user);

    Queue::assertPushed(ScanLibrary::class, 2);
});

it('lets a forced scan through even when one is waiting', function () {
    Queue::fake();

    ScanLibrary::request($this->user);
    ScanLibrary::request($this->user, force: true);

    Queue::assertPushed(ScanLibrary::class, 2);
});

it('does not run two scans next to each other', function () {
    $middleware = (new ScanLibrary($this->user->id))->middleware();

    expect($middleware)->toHaveCount(1)->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});

it('records a failure with its message', function () {
    $this->mock(LibraryScanner::class)->shouldReceive('scan')->andThrow(new RuntimeException('disk gone'));

    $job = new ScanLibrary($this->user->id);
    try {
        $job->handle(app(LibraryScanner::class));
    } catch (RuntimeException $e) {
        $job->failed($e);
    }

    expect(ScanStatus::get($this->user->id))->toMatchArray(['state' => 'failed', 'error' => 'disk gone']);
});

it('gives the status only to signed-in users and starts a scan on request', function () {
    $this->getJson(route('scan.show'))->assertUnauthorized();
    $this->postJson(route('scan.store'))->assertUnauthorized();

    Queue::fake();
    $this->actingAs($this->user);

    $this->getJson(route('scan.show'))->assertOk()->assertJsonPath('state', 'idle');
    $this->postJson(route('scan.store'))->assertStatus(202)->assertJsonPath('state', 'queued');
    Queue::assertPushed(ScanLibrary::class, 1);
});

it('scans from the command, now or through the queue', function () {
    Queue::fake();
    $this->artisan('magnetite:scan', ['--queue' => true])->assertSuccessful();
    Queue::assertPushed(ScanLibrary::class, 1);

    Queue::fake([]);
    $this->artisan('magnetite:scan')->assertSuccessful();
    expect(Track::count())->toBeGreaterThan(0);
});

it('runs at once with the sync driver, even when a stale queued flag is left over', function () {
    config(['queue.default' => 'sync']);
    ScanStatus::queued($this->user->id);

    ScanLibrary::request($this->user);

    expect(ScanStatus::get($this->user->id)['state'])->toBe('done');
});
