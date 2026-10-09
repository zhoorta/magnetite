<?php

use App\Models\User;
use App\Services\FerriteStore;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->ferrite = User::factory()->create(['username' => 'remote', 'store' => ['driver' => 'ferrite', 'url' => 'https://ferrite.test', 'token' => 'secret-token']]);
});

it('needs a signed-in user', function () {
    $this->get(route('storage'))->assertRedirect(route('login'));
    $this->postJson(route('storage.test'))->assertUnauthorized();
    $this->deleteJson(route('storage.cache.clear'))->assertUnauthorized();
});

it('shows a local library with its folder and no cache section', function () {
    $user = makeLibrary(fixtures: false);
    File::deleteDirectory($this->music);

    $this->actingAs($user)->get(route('storage'))
        ->assertOk()
        ->assertSee('Local folder')
        ->assertSee($user->libraryPath())
        ->assertDontSee('Clear cache');
});

it('shows a Ferrite library with its address, never the token', function () {
    $this->actingAs($this->ferrite)->get(route('storage'))
        ->assertOk()
        ->assertSee('Ferrite')
        ->assertSee('https://ferrite.test')
        ->assertSee('Clear cache')
        ->assertDontSee('secret-token');
});

it('tests the connection and reports the folder, access, expiry and space', function () {
    Http::swap(new Factory);
    Http::fake(['ferrite.test/api/v1/root' => Http::response(['name' => 'Music', 'access' => 'read', 'expires_at' => now()->addDays(5)->toIso8601String(), 'quota' => ['used' => 5, 'limit' => 100]])]);

    $this->actingAs($this->ferrite)->postJson(route('storage.test'))
        ->assertOk()
        ->assertJson(['ok' => true, 'folder' => 'Music', 'access' => 'read', 'expires_soon' => true, 'quota' => ['used' => 5, 'limit' => 100]])
        ->assertJsonMissing(['token' => 'secret-token']);
});

it('says in plain words why the connection fails', function (int $status, string $message) {
    Http::swap(new Factory);
    Http::fake(['ferrite.test/*' => Http::response('', $status)]);

    $this->actingAs($this->ferrite)->postJson(route('storage.test'))
        ->assertOk()->assertJson(['ok' => false, 'error' => $message]);
})->with([
    [401, 'The Ferrite token was revoked or has expired.'],
    [404, 'The Ferrite folder was deleted or the token cannot see it.'],
    [500, 'Ferrite answered 500.'],
]);

it('reports a local library as readable or missing', function () {
    $user = makeLibrary(fixtures: false);

    $this->actingAs($user)->postJson(route('storage.test'))->assertJson(['ok' => true]);

    File::deleteDirectory($this->library);
    $this->actingAs($user)->postJson(route('storage.test'))->assertJson(['ok' => false])->assertJsonPath('error', fn ($e) => str_contains($e, 'missing'));
    File::deleteDirectory($this->music);
});

it('clears the cache but not a download in progress', function () {
    $dir = Storage::disk('local')->path('ferrite-cache');
    File::ensureDirectoryExists($dir);
    File::put("$dir/".str_repeat('a', 64).'.mp3', 'one');
    File::put("$dir/".str_repeat('b', 64).'.mp3', 'two!');
    File::put("$dir/".str_repeat('c', 64).'.mp3.1234.part', 'partial');

    expect(FerriteStore::cacheStats())->toBe(['files' => 2, 'bytes' => 7]);

    $this->actingAs($this->ferrite)->deleteJson(route('storage.cache.clear'))->assertOk()->assertJson(['files' => 2, 'bytes' => 7]);

    expect(FerriteStore::cacheStats())->toBe(['files' => 0, 'bytes' => 0])
        ->and(glob("$dir/*"))->toHaveCount(1);
});

it('does not offer cache clearing to a local library', function () {
    $user = makeLibrary(fixtures: false);

    $this->actingAs($user)->deleteJson(route('storage.cache.clear'))->assertNotFound();
    File::deleteDirectory($this->music);
});
