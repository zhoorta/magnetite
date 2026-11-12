<?php

use App\Models\Album;
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
        $this->getJson('/api/v1/tracks')->assertUnauthorized();
    });
});

describe('library', function () {
    it('lists only my own albums, without paths', function () {
        $response = $this->getJson('/api/v1/albums', bearer($this->alice))->assertOk();

        expect(collect($response->json('data'))->pluck('id')->all())
            ->not->toContain($this->bobAlbum->id)
            ->and(json_encode($response->json('data')))->not->toContain('path');
    });

    it('gives every album a display artist: the tagged one, else its tracks\' single artist, else Various artists', function () {
        $album = $this->aliceTrack->album;
        $album->update(['artist' => null]);
        Track::where('album_id', $album->id)->update(['artist' => 'Solo Act']);

        $shown = fn () => collect($this->getJson('/api/v1/albums', bearer($this->alice))->assertOk()->json('data'))->firstWhere('id', $album->id);

        expect($shown()['artist'])->toBeNull()
            ->and($shown()['display_artist'])->toBe('Solo Act');

        Track::where('album_id', $album->id)->orderBy('id')->first()->update(['artist' => 'Someone Else']);
        expect($shown()['display_artist'])->toBe('Various artists');

        $album->update(['artist' => 'Tagged Band']);
        expect($shown()['display_artist'])->toBe('Tagged Band');

        $album->update(['artist' => null]);
        Track::where('album_id', $album->id)->update(['artist' => 'Solo Act']);
        $this->getJson("/api/v1/albums/{$album->id}", bearer($this->alice))->assertJsonPath('data.display_artist', 'Solo Act');
    });

    it('lists all my tracks with their truth in pages, only the analysed ones with truth=1, never another user\'s', function () {
        $this->aliceTrack->update(['analysis' => ['sample_rate' => 44100, 'loudness' => ['integrated' => -12.5, 'true_peak_db' => 0.4]], 'dr' => 6]);

        $all = $this->getJson('/api/v1/tracks', bearer($this->alice))->assertOk();
        $mine = Track::where('user_id', $this->alice->id)->count();
        expect($all->json('meta.total'))->toBe($mine)
            ->and(collect($all->json('data'))->pluck('id')->all())->not->toContain($this->bobTrack->id)
            ->and(json_encode($all->json('data')))->not->toContain('"path"');

        $analysed = $this->getJson('/api/v1/tracks?truth=1', bearer($this->alice))->assertOk();
        expect($analysed->json('data'))->toHaveCount(1)
            ->and($analysed->json('data.0.id'))->toBe($this->aliceTrack->id)
            ->and($analysed->json('data.0.truth.analyzed'))->toBeTrue()
            ->and($analysed->json('data.0.truth.lufs'))->toBe(-12.5)
            ->and($analysed->json('data.0.album_id'))->toBe($this->aliceTrack->album_id)
            ->and($analysed->json('data.0.truth'))->not->toHaveKey('spectrum');

        $this->getJson('/api/v1/tracks?per_page=1&page=2', bearer($this->alice))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1);
    });

    it('sorts albums by the artist that is shown, also when it comes from the tracks and not from an album-artist tag', function () {
        $album = $this->aliceTrack->album;
        $album->update(['artist' => null, 'title' => 'Happy Trails']);
        Track::where('album_id', $album->id)->update(['artist' => 'Quicksilver Messenger Service']);
        $first = Album::create(['user_id' => $this->alice->id, 'title' => 'Zed', 'artist' => 'Black Sabbath']);
        $last = Album::create(['user_id' => $this->alice->id, 'title' => 'Aaa', 'artist' => 'Talking Heads']);

        $order = collect($this->getJson('/api/v1/albums', bearer($this->alice))->assertOk()->json('data'))->pluck('id')
            ->filter(fn ($id) => in_array($id, [$first->id, $album->id, $last->id]))->values()->all();

        // Black Sabbath, Quicksilver (artist only on the tracks), Talking Heads: not "no artist" first
        expect($order)->toBe([$first->id, $album->id, $last->id]);
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

describe('meters', function () {
    it('saves the meter list through the API and returns it from /me', function () {
        $this->putJson('/api/v1/settings/meters', ['ids' => ['vu-needle', 'scope']], bearer($this->alice))
            ->assertOk()->assertJson(['meters' => ['vu-needle', 'scope']]);
        $this->getJson('/api/v1/me', bearer($this->alice))->assertJson(['meters' => ['vu-needle', 'scope']]);
        expect($this->bob->fresh()->meters)->toBeNull();
    });

    it('saves the interface level through the API and returns it from /me', function () {
        $this->getJson('/api/v1/me', bearer($this->alice))->assertJson(['ui' => ['info' => 2]]);
        $this->putJson('/api/v1/settings/ui', ['info' => 1], bearer($this->alice))->assertOk();
        $this->getJson('/api/v1/me', bearer($this->alice))->assertJson(['ui' => ['info' => 1]]);
    });

    it('refuses more than four and duplicates', function () {
        $this->putJson('/api/v1/settings/meters', ['ids' => ['a', 'b', 'c', 'd', 'e']], bearer($this->alice))->assertUnprocessable();
        $this->putJson('/api/v1/settings/meters', ['ids' => ['scope', 'scope']], bearer($this->alice))->assertUnprocessable();
    });

    it('answers guests with 401', function () {
        $this->putJson('/api/v1/settings/meters', ['ids' => []])->assertUnauthorized();
    });
});

describe('device code', function () {
    it('signs a TV in once a person has approved its code', function () {
        $start = $this->postJson('/api/v1/device-code', ['device_name' => 'Living room TV'])
            ->assertCreated()
            ->assertJsonPath('verification_url', route('device'))
            ->assertJsonStructure(['device_code', 'user_code', 'expires_in', 'interval']);

        $this->postJson('/api/v1/device-token', ['device_code' => $start->json('device_code')])
            ->assertStatus(400)->assertJsonPath('error', 'authorization_pending');

        $this->actingAs($this->alice)->post('/device', ['user_code' => strtolower(str_replace('-', ' ', $start->json('user_code')))])
            ->assertOk()->assertSee('signing in as alice');

        $this->postJson('/api/v1/device-token', ['device_code' => $start->json('device_code')])
            ->assertCreated()->assertJsonPath('user.username', 'alice')->assertJsonStructure(['token']);
        expect($this->alice->tokens()->where('name', 'Living room TV')->count())->toBe(1);

        // The token is handed out once.
        $this->postJson('/api/v1/device-token', ['device_code' => $start->json('device_code')])
            ->assertStatus(400)->assertJsonPath('error', 'expired_token');
    });

    it('refuses an unknown code and an unknown device code', function () {
        $this->actingAs($this->alice)->post('/device', ['user_code' => 'ABCD-EFGH'])->assertSessionHasErrors('user_code');
        $this->postJson('/api/v1/device-token', ['device_code' => 'nope'])->assertStatus(400)->assertJsonPath('error', 'expired_token');
    });

    it('lets only signed-in people approve, and a code only once', function () {
        $start = $this->postJson('/api/v1/device-code', ['device_name' => 'TV'])->assertCreated();

        $this->post('/device', ['user_code' => $start->json('user_code')])->assertRedirect('/login');
        $this->get('/device')->assertRedirect('/login');

        $this->actingAs($this->alice)->post('/device', ['user_code' => $start->json('user_code')])->assertOk();
        $this->actingAs($this->bob)->post('/device', ['user_code' => $start->json('user_code')])->assertSessionHasErrors('user_code');
    });
});
