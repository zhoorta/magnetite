<?php

use App\Jobs\ScanLibrary;
use App\Models\User;
use App\Services\LibraryUploader;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const FU = 'https://ferrite.test';

/**
 * A stand-in for Ferrite's upload API that keeps what it was sent. $state: access, existing (path => sha256 already
 * in Ferrite), files (path => bytes received), fail_on (path whose upload is refused with a quota error), busy (answer
 * the first chunk with 409), chunk_size.
 */
function fakeFerriteUploads(ArrayObject $state): void
{
    $state['files'] ??= [];
    $state['uploads'] = [];
    $state['posts'] = 0;

    Http::swap(new Factory);
    Http::fake([
        FU.'/api/v1/root' => fn () => Http::response(['name' => 'Music', 'access' => $state['access'] ?? 'read+write', 'quota' => ['used' => 0, 'limit' => null]]),
        FU.'/api/v1/uploads' => function (HttpRequest $request) use ($state) {
            $state['posts']++;
            $path = $request['path'];

            if (isset($state['existing'][$path])) {
                return Http::response(['error' => 'exists', 'id' => 9, 'sha256' => $state['existing'][$path]], 409);
            }

            if (($state['fail_on'] ?? null) === $path) {
                return Http::response(['message' => 'Not enough storage space left.', 'errors' => ['size' => ['Not enough storage space left.']]], 422);
            }

            $id = 'u'.count($state['uploads']);
            $state['uploads'] = [...$state['uploads'], $id => ['path' => $path, 'size' => $request['size'], 'buf' => '', 'sha' => $request['fingerprint']]];

            return Http::response(['id' => $id, 'offset' => 0, 'size' => $request['size'], 'chunk_size' => $state['chunk_size'] ?? 4, 'status' => 'receiving'], 201);
        },
        FU.'/api/v1/uploads/*' => function (HttpRequest $request) use ($state) {
            preg_match('#uploads/(u\d+)#', $request->url(), $m);
            $id = $m[1];
            $upload = $state['uploads'][$id];

            if ($request->method() === 'GET') {
                return Http::response(['id' => $id, 'status' => isset($state['files'][$upload['path']]) ? 'done' : 'receiving', 'offset' => strlen($upload['buf'])]);
            }

            if (($state['busy'] ?? false) && ! ($state['busy_sent'] ?? false)) {
                $state['busy_sent'] = true;

                return Http::response(['id' => $id, 'offset' => 0, 'status' => 'receiving'], 409);
            }

            $offset = (int) $request->header('Upload-Offset')[0];
            if ($offset !== strlen($upload['buf'])) {
                return Http::response(['id' => $id, 'offset' => strlen($upload['buf']), 'status' => 'receiving'], 409);
            }

            $upload['buf'] .= $request->body();
            $state['uploads'] = [...$state['uploads'], $id => $upload];

            if (strlen($upload['buf']) < $upload['size']) {
                return Http::response(['id' => $id, 'offset' => strlen($upload['buf']), 'status' => 'receiving']);
            }

            $state['files'] = [...$state['files'], $upload['path'] => $upload['buf']];

            return Http::response(['id' => $id, 'offset' => $upload['size'], 'status' => 'done', 'node' => ['id' => 1, 'sha256' => hash('sha256', $upload['buf'])]], 202);
        },
    ]);
}

function ferriteZip(array $files, string $name = 'Album.zip'): string
{
    $path = test()->work.'/'.$name;
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);

    foreach ($files as $entry => $contents) {
        $zip->addFromString($entry, $contents);
    }

    $zip->close();

    return $path;
}

beforeEach(function () {
    Storage::fake('local');
    config(['magnetite.music_path' => sys_get_temp_dir().'/magnetite-never-'.uniqid()]);
    $this->work = sys_get_temp_dir().'/magnetite-fzips-'.uniqid();
    mkdir($this->work);
    $this->user = User::factory()->create(['username' => 'remote', 'store' => ['driver' => 'ferrite', 'url' => FU, 'token' => 'tok']]);
    $this->state = new ArrayObject;
});

afterEach(fn () => File::deleteDirectory($this->work));

it('sends the audio and covers of a zip to Ferrite in chunks, under a folder named after the zip', function () {
    fakeFerriteUploads($this->state);
    $zip = ferriteZip(['01.mp3' => 'AAAAAAAAAA', '02.flac' => 'BBBBBB', 'cover.jpg' => 'JPEG', 'notes.txt' => 'no', '.hidden.mp3' => 'x']);

    $result = app(LibraryUploader::class)->extract($this->user, $zip, 'Test Album.zip');

    expect($result)->toMatchArray(['added' => 3, 'existing' => 0, 'conflicts' => 0, 'skipped' => 2, 'folder' => 'Test Album'])
        ->and($this->state['files'])->toBe([
            'Test Album/01.mp3' => 'AAAAAAAAAA',
            'Test Album/02.flac' => 'BBBBBB',
            'Test Album/cover.jpg' => 'JPEG',
        ]);

    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer tok') && ! str_contains($r->url(), 'evil'));
    expect(is_dir(config('magnetite.music_path')))->toBeFalse(); // the local music folder is not touched
});

it('keeps a single top folder of the zip as it is', function () {
    fakeFerriteUploads($this->state);

    app(LibraryUploader::class)->extract($this->user, ferriteZip(['Artist - Album/01.mp3' => 'AAAA']), 'x.zip');

    expect(array_keys($this->state['files']))->toBe(['Artist - Album/01.mp3']);
});

it('refuses a read-only token before unpacking or sending anything', function () {
    $this->state['access'] = 'read';
    fakeFerriteUploads($this->state);

    expect(fn () => app(LibraryUploader::class)->extract($this->user, ferriteZip(['01.mp3' => 'AAAA']), 'x.zip'))
        ->toThrow(RuntimeException::class, 'read-only');
    expect($this->state['posts'])->toBe(0);
});

it('counts files that are already there, and leaves a different file with the same name alone', function () {
    $this->state['existing'] = ['Album/01.mp3' => hash('sha256', 'AAAA'), 'Album/02.mp3' => hash('sha256', 'something else')];
    fakeFerriteUploads($this->state);

    $result = app(LibraryUploader::class)->extract($this->user, ferriteZip(['Album/01.mp3' => 'AAAA', 'Album/02.mp3' => 'BBBB', 'Album/03.mp3' => 'CCCC']), 'x.zip');

    expect($result)->toMatchArray(['added' => 1, 'existing' => 1, 'conflicts' => 1])
        ->and(array_keys($this->state['files']))->toBe(['Album/03.mp3']);
});

it('says how many files arrived when Ferrite stops half way, and a second try finishes', function () {
    $this->state['fail_on'] = 'Album/02.mp3';
    fakeFerriteUploads($this->state);
    $zip = ferriteZip(['Album/01.mp3' => 'AAAA', 'Album/02.mp3' => 'BBBB', 'Album/03.mp3' => 'CCCC']);

    expect(fn () => app(LibraryUploader::class)->extract($this->user, $zip, 'x.zip'))
        ->toThrow(RuntimeException::class, '1 of 3 files had arrived');

    $this->state['existing'] = ['Album/01.mp3' => hash('sha256', 'AAAA')];
    $this->state['fail_on'] = null;
    fakeFerriteUploads($this->state);

    expect(app(LibraryUploader::class)->extract($this->user, $zip, 'x.zip'))->toMatchArray(['added' => 2, 'existing' => 1]);
});

it('carries on from the offset Ferrite reports when a chunk is answered with 409', function () {
    $this->state['busy'] = true;
    fakeFerriteUploads($this->state);

    app(LibraryUploader::class)->extract($this->user, ferriteZip(['Album/01.mp3' => 'AAAABBBBCC']), 'x.zip');

    expect($this->state['files'])->toBe(['Album/01.mp3' => 'AAAABBBBCC']);
});

it('still rejects an unsafe zip before talking to Ferrite for any file', function () {
    fakeFerriteUploads($this->state);

    expect(fn () => app(LibraryUploader::class)->extract($this->user, ferriteZip(['Album/01.mp3' => 'AAAA', '../evil.mp3' => 'x']), 'x.zip'))
        ->toThrow(RuntimeException::class, 'unsafe');
    expect($this->state['posts'])->toBe(0);
});

it('uploads through the page, reports the counts and queues a scan', function () {
    Queue::fake();
    fakeFerriteUploads($this->state);
    $path = ferriteZip(['Album/01.mp3' => 'AAAA']);

    $this->actingAs($this->user)
        ->postJson(route('upload.store'), ['archives' => [new UploadedFile($path, 'Album.zip', 'application/zip', null, true)]])
        ->assertOk()
        ->assertJsonPath('archives.0.ok', true)
        ->assertJsonPath('archives.0.added', 1)
        ->assertJsonPath('archives.0.conflicts', 0);

    expect($this->state['files'])->toBe(['Album/01.mp3' => 'AAAA']);
    Queue::assertPushed(ScanLibrary::class);
});
