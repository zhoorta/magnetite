<?php

use App\Models\Playlist;
use App\Models\SharedItem;
use App\Models\Track;
use App\Models\User;
use App\Services\LibraryScanner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->alice = makeLibrary('alice');
    $this->alice->update(['password' => 'secret-pass']);
    $this->bob = User::factory()->create(['username' => 'bob']);
    File::copyDirectory(base_path('tests/Fixtures/music'), $this->music.'/users/bob');

    foreach ([$this->alice, $this->bob] as $user) {
        app(LibraryScanner::class)->scan($user);
    }

    $this->aliceTrack = Track::where('user_id', $this->alice->id)->where('title', 'First Song')->sole();
    $this->bobTrack = Track::where('user_id', $this->bob->id)->where('title', 'First Song')->sole();
    $this->bobAlbum = $this->bobTrack->album;
});

afterEach(fn () => File::deleteDirectory($this->music));

function bearer(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

describe('token', function () {
    it('trades a username and password for a token', function () {
        $this->postJson('/api/v1/token', ['username' => ' Alice ', 'password' => 'secret-pass', 'device_name' => 'Pixel'])
            ->assertCreated()
            ->assertJsonPath('user.username', 'alice')
            ->assertJsonStructure(['token']);

        expect($this->alice->tokens()->where('name', 'Pixel')->count())->toBe(1);
    });

    it('refuses a wrong password and throttles after five tries', function () {
        RateLimiter::clear('alice|127.0.0.1');

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/token', ['username' => 'alice', 'password' => 'nope', 'device_name' => 'x'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/token', ['username' => 'alice', 'password' => 'secret-pass', 'device_name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
    });

    it('works until it is revoked', function () {
        $token = $this->alice->createToken('phone')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('username', 'alice');
        $this->deleteJson('/api/v1/token', [], $headers)->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
    });

    it('answers guests with 401', function () {
        $this->getJson('/api/v1/albums')->assertUnauthorized();
        $this->getJson('/api/v1/me')->assertUnauthorized();
    });
});

describe('library', function () {
    it('lists only my own albums, without paths', function () {
        $response = $this->getJson('/api/v1/albums', bearer($this->alice))->assertOk();

        expect(collect($response->json('data'))->pluck('id')->all())
            ->not->toContain($this->bobAlbum->id)
            ->and(json_encode($response->json('data')))->not->toContain('path');
    });

    it('shows my album with stream urls, and hides another user\'s unshared album', function () {
        $album = $this->aliceTrack->album;

        $this->getJson("/api/v1/albums/{$album->id}", bearer($this->alice))
            ->assertOk()
            ->assertJsonPath('data.tracks.0.stream_url', fn ($url) => str_contains($url, '/stream/'));

        $this->getJson("/api/v1/albums/{$this->bobAlbum->id}", bearer($this->alice))->assertNotFound();
    });

    it('opens a shared album to others', function () {
        SharedItem::create(['user_id' => $this->bob->id, 'album_id' => $this->bobAlbum->id]);

        $this->getJson("/api/v1/albums/{$this->bobAlbum->id}", bearer($this->alice))->assertOk();
        $this->getJson('/api/v1/shared', bearer($this->alice))
            ->assertOk()
            ->assertJsonPath('albums.0.shared_by', 'bob');
    });

    it('searches only what I may use', function () {
        $this->getJson('/api/v1/search?q=First', bearer($this->alice))
            ->assertOk()
            ->assertJsonCount(1, 'tracks')
            ->assertJsonPath('tracks.0.id', $this->aliceTrack->id);

        SharedItem::create(['user_id' => $this->bob->id, 'track_id' => $this->bobTrack->id]);

        $this->getJson('/api/v1/search?q=First', bearer($this->alice))->assertJsonCount(2, 'tracks');
        $this->getJson('/api/v1/search?q=a', bearer($this->alice))->assertUnprocessable();
    });
});

describe('playlists', function () {
    it('lists mine and hides another user\'s private one', function () {
        $mine = Playlist::create(['user_id' => $this->alice->id, 'name' => 'Mine']);
        $theirs = Playlist::create(['user_id' => $this->bob->id, 'name' => 'Theirs']);

        $this->getJson('/api/v1/playlists', bearer($this->alice))->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mine');
        $this->getJson("/api/v1/playlists/{$mine->id}", bearer($this->alice))->assertOk()->assertJsonPath('data.mine', true);
        $this->getJson("/api/v1/playlists/{$theirs->id}", bearer($this->alice))->assertNotFound();
    });

    it('gives no stream url for tracks of a shared playlist the viewer cannot play', function () {
        $playlist = Playlist::create(['user_id' => $this->bob->id, 'name' => 'Shared']);
        $playlist->tracks()->attach($this->bobTrack->id, ['position' => 1]);
        SharedItem::create(['user_id' => $this->bob->id, 'playlist_id' => $playlist->id]);

        $response = $this->getJson("/api/v1/playlists/{$playlist->id}", bearer($this->alice))->assertOk();

        expect($response->json('data.tracks.0.open'))->toBeFalse()
            ->and($response->json('data.tracks.0.stream_url'))->toBeNull();
    });
});

describe('files', function () {
    it('streams and serves covers to a Bearer token, and still hides what is private', function () {
        $this->get(route('stream', $this->aliceTrack), bearer($this->alice))->assertOk();
        $this->get(route('stream', $this->bobTrack), bearer($this->alice))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get(route('stream', $this->aliceTrack))->assertRedirect(route('login'));
    });
});
