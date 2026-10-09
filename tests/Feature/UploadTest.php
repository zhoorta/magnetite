<?php

use App\Jobs\ScanLibrary;
use App\Models\Track;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    makeLibrary(fixtures: false);
    $this->work = sys_get_temp_dir().'/magnetite-zips-'.uniqid();
    mkdir($this->work);
    $this->actingAs($this->user);
    Cache::flush();
});

afterEach(function () {
    File::deleteDirectory($this->music);
    File::deleteDirectory($this->work);
});

/** Build a zip from [name => contents] and return it as an upload. Extra attributes: [name => unix mode]. */
function zipUpload(array $files, string $name = 'Test Album.zip', array $modes = []): UploadedFile
{
    $path = test()->work.'/'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);

    foreach ($files as $entry => $contents) {
        $zip->addFromString($entry, $contents);
    }

    foreach ($modes as $entry => $mode) {
        $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, $mode << 16);
    }

    $zip->close();

    return new UploadedFile($path, $name, 'application/zip', null, true);
}

function song(): string
{
    return file_get_contents(base_path('tests/Fixtures/music/Tagged/Album/01-first.mp3'));
}

function fixtureExists(): bool
{
    return is_file(base_path('tests/Fixtures/music/Tagged/Album/01-first.mp3'));
}

function upload(UploadedFile ...$archives)
{
    return test()->postJson(route('upload.store'), ['archives' => $archives]);
}

it('has a fixture to build zips from', fn () => expect(fixtureExists())->toBeTrue());

it('shows the upload page', function () {
    $this->get(route('upload'))->assertOk()->assertSee('Choose zips');
});

it('unpacks a zip into the library and scans it', function () {
    $response = upload(zipUpload([
        'Some Band - Some Album/01 - Intro.mp3' => song(),
        'Some Band - Some Album/02 - Outro.mp3' => song(),
    ]))->assertOk();

    $response->assertJsonPath('archives.0.ok', true)
        ->assertJsonPath('archives.0.added', 2)
        ->assertJsonPath('archives.0.folder', 'Some Band - Some Album')
        ->assertJsonPath('scan.state', 'done')
        ->assertJsonPath('scan.counts.added', 2);

    expect($this->library.'/Some Band - Some Album/01 - Intro.mp3')->toBeFile()
        ->and(Track::count())->toBe(2)
        ->and(glob($this->library.'/*/*.part'))->toBe([]);
});

it('puts loose files in a folder named after the zip', function () {
    upload(zipUpload(['one.mp3' => song(), 'two.mp3' => song()], 'My: Mixtape?.zip'))
        ->assertOk()->assertJsonPath('archives.0.folder', 'My_ Mixtape');

    expect($this->library.'/My_ Mixtape/one.mp3')->toBeFile();
});

it('keeps only audio and cover images and drops hidden files, resource forks and everything else', function () {
    upload(zipUpload([
        'Album/track.mp3' => song(),
        'Album/cover.jpg' => 'not really a jpeg',
        'Album/notes.txt' => 'text',
        'Album/run.php' => '<?php echo 1;',
        'Album/.hidden.mp3' => song(),
        '__MACOSX/Album/._track.mp3' => 'fork',
    ]))->assertOk()->assertJsonPath('archives.0.added', 2)->assertJsonPath('archives.0.skipped', 4);

    expect(glob($this->library.'/Album/*'))->toHaveCount(2)
        ->and($this->library.'/__MACOSX')->not->toBeDirectory();
});

it('never overwrites a file that is already in the library', function () {
    mkdir($this->library.'/Album');
    file_put_contents($this->library.'/Album/track.mp3', 'original');

    upload(zipUpload(['Album/track.mp3' => song(), 'Album/other.mp3' => song()]))
        ->assertOk()->assertJsonPath('archives.0.added', 1)->assertJsonPath('archives.0.existing', 1);

    expect(file_get_contents($this->library.'/Album/track.mp3'))->toBe('original');
});

it('refuses a zip with a path that leaves the library, and writes nothing', function () {
    foreach (['../evil.mp3', 'Album/../../evil.mp3', '/etc/evil.mp3', '..\\evil.mp3', 'C:/evil.mp3'] as $bad) {
        upload(zipUpload(['Album/ok.mp3' => song(), $bad => song()]))
            ->assertOk()->assertJsonPath('archives.0.ok', false);
    }

    expect(glob($this->library.'/*'))->toBe([])
        ->and(file_exists($this->music.'/users/evil.mp3'))->toBeFalse()
        ->and(file_exists($this->music.'/evil.mp3'))->toBeFalse()
        ->and(file_exists(dirname($this->music).'/evil.mp3'))->toBeFalse();
});

it('does not extract symlinks', function () {
    upload(zipUpload(['Album/track.mp3' => song(), 'Album/link.mp3' => '/etc/passwd'], modes: ['Album/link.mp3' => 0120777]))
        ->assertOk()->assertJsonPath('archives.0.added', 1)->assertJsonPath('archives.0.skipped', 1);

    expect(file_exists($this->library.'/Album/link.mp3') || is_link($this->library.'/Album/link.mp3'))->toBeFalse();
});

it('does not follow a symlink that is already in the library', function () {
    $outside = sys_get_temp_dir().'/magnetite-outside-'.uniqid();
    mkdir($outside);
    symlink($outside, $this->library.'/Link');

    upload(zipUpload(['Link/track.mp3' => song()]))->assertOk()->assertJsonPath('archives.0.ok', false);

    expect(glob($outside.'/*'))->toBe([]);
    File::deleteDirectory($outside);
});

it('refuses a zip that is too large once unpacked and leaves nothing behind', function () {
    config(['magnetite.upload.max_unpacked_mb' => 0.00001]); // about 10 bytes: the declared size is already too much

    upload(zipUpload(['Album/track.mp3' => song()]))
        ->assertOk()->assertJsonPath('archives.0.ok', false);

    expect(glob($this->library.'/*'))->toBe([]);
});

it('stops copying and removes what it wrote when the real size passes the limit', function () {
    // Two files that fit one by one but not together.
    config(['magnetite.upload.max_unpacked_mb' => (strlen(song()) * 1.5) / 1048576]);

    upload(zipUpload(['Album/a.mp3' => song(), 'Album/b.mp3' => song()]))
        ->assertOk()->assertJsonPath('archives.0.ok', false);

    expect(glob($this->library.'/Album/*'))->toBe([]);
});

it('refuses a zip without audio', function () {
    upload(zipUpload(['Album/cover.jpg' => 'x', 'Album/readme.txt' => 'y']))
        ->assertOk()->assertJsonPath('archives.0.ok', false)->assertJsonPath('scan', null);
});

it('refuses files that are not zips', function () {
    $this->postJson(route('upload.store'), ['archives' => [UploadedFile::fake()->create('song.mp3', 10)]])
        ->assertUnprocessable();

    $notZip = $this->work.'/fake.zip';
    file_put_contents($notZip, 'this is not a zip');

    upload(new UploadedFile($notZip, 'fake.zip', 'application/zip', null, true))
        ->assertOk()->assertJsonPath('archives.0.ok', false);
});

it('reports each zip on its own', function () {
    upload(zipUpload(['A/one.mp3' => song()], 'a.zip'), zipUpload(['readme.txt' => 'x'], 'b.zip'))
        ->assertOk()
        ->assertJsonPath('archives.0.ok', true)
        ->assertJsonPath('archives.1.ok', false);
});

it('says so when the music folder cannot be written', function () {
    config(['magnetite.music_path' => $this->music.'/missing']);

    upload(zipUpload(['A/one.mp3' => song()]))->assertOk()->assertJsonPath('archives.0.ok', false);
});

it('leaves the scan to the queue instead of holding the request', function () {
    Queue::fake();

    upload(zipUpload(['Album/one.mp3' => song()]))
        ->assertOk()->assertJsonPath('scan.state', 'queued');

    Queue::assertPushed(ScanLibrary::class, 1);
    expect(Track::count())->toBe(0);
});

it('queues one scan for several uploads that arrive before the worker starts', function () {
    Queue::fake();

    upload(zipUpload(['A/one.mp3' => song()], 'a.zip'));
    upload(zipUpload(['B/two.mp3' => song()], 'b.zip'));

    Queue::assertPushed(ScanLibrary::class, 1);
});
