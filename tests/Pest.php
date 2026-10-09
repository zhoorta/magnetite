<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * A throwaway music folder with one user whose library folder holds a copy of the fixtures (or is empty), so tests can
 * add, touch and delete files freely. Sets `$this->music`, `$this->library` and `$this->user`; the caller removes `$this->music`.
 */
function makeLibrary(string $username = 'tester', bool $fixtures = true): User
{
    $music = sys_get_temp_dir().'/magnetite-test-'.uniqid();
    $library = "{$music}/users/{$username}";
    mkdir($library, 0755, true);

    if ($fixtures) {
        File::copyDirectory(base_path('tests/Fixtures/music'), $library);
    }

    config(['magnetite.music_path' => $music]);

    $user = User::factory()->create(['username' => $username]);
    test()->music = $music;
    test()->library = $library;
    test()->user = $user;

    return $user;
}

const FERRITE = 'https://ferrite.test';

/** Ferrite's listing for the fixture library, with ids; the content endpoint serves the real fixture bytes. */
function ferriteListing(array $extra = []): array
{
    $rows = [];
    $id = 1;
    foreach (File::allFiles(base_path('tests/Fixtures/music')) as $file) {
        $rows[] = ['id' => $id++, 'path' => $file->getRelativePathname(), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'mtime' => 1700000000, 'mime' => 'audio/mpeg'];
    }

    return array_merge($rows, $extra);
}

function fakeFerrite(?array $rows = null, ?Closure $content = null): void
{
    // A new fake would otherwise sit behind the stubs of an earlier one.
    Http::swap(new Factory);
    $rows ??= ferriteListing();
    $byId = collect($rows)->keyBy('id');

    Http::fake([
        FERRITE.'/api/v1/root' => Http::response(['name' => 'Music', 'id' => 5, 'access' => 'read', 'expires_at' => null, 'quota' => ['used' => 1, 'limit' => 10]]),
        FERRITE.'/api/v1/files?*' => Http::response(['data' => $rows, 'next_cursor' => null]),
        FERRITE.'/api/v1/files' => Http::response(['data' => $rows, 'next_cursor' => null]),
        FERRITE.'/api/v1/files/*/content' => $content ?? function (HttpRequest $request) use ($byId) {
            preg_match('#files/(\d+)/content#', $request->url(), $m);
            $row = $byId[(int) $m[1]];
            $bytes = file_get_contents(base_path('tests/Fixtures/music/'.$row['path']));
            $range = $request->header('Range')[0] ?? null;

            if ($range && preg_match('/bytes=(\d+)-(\d+)/', $range, $r)) {
                return Http::response(substr($bytes, (int) $r[1], $r[2] - $r[1] + 1), 206, ['Content-Range' => "bytes {$r[1]}-{$r[2]}/".strlen($bytes), 'ETag' => '"'.$row['sha256'].'"']);
            }

            return Http::response($bytes, 200, ['ETag' => '"'.$row['sha256'].'"']);
        },
    ]);
}
