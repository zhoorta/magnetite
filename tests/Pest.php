<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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
