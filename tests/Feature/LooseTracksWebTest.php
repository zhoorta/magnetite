<?php

use App\Models\Album;
use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

// The same controllers answer on the web (session) and on the API (token): the work is done once, in the services.

beforeEach(function () {
    Storage::fake('local');
    $this->alice = makeLibrary('alice', fixtures: false);
    $this->bob = User::factory()->create(['username' => 'bob']);
    $this->loose = collect(['02 - Beta.mp3', '01 - Alpha.mp3'])->map(fn ($n) => Track::create(['user_id' => $this->alice->id, 'album_id' => null, 'path' => 'Web Folder/'.$n, 'title' => pathinfo($n, PATHINFO_FILENAME), 'mime' => 'audio/mpeg', 'size' => 1, 'mtime' => 1]));
});

afterEach(fn () => File::deleteDirectory($this->music));

it('lists the loose tracks of the signed-in user on the web', function () {
    $r = $this->actingAs($this->alice)->getJson('/library/tracks/loose')->assertOk();

    expect(collect($r->json('groups'))->firstWhere('folder', 'Web Folder')['tracks'])->toHaveCount(2);
});

it('names an album for them on the web, numbered from the file names', function () {
    $id = $this->actingAs($this->alice)->postJson('/library/albums', ['title' => 'Web Album', 'artist' => 'Band', 'track_ids' => $this->loose->pluck('id')->all()])
        ->assertCreated()->json('data.id');

    expect(Album::find($id)->tracks->pluck('title')->all())->toBe(['Alpha', 'Beta']);
});

it('leaves somebody else\'s loose tracks alone on the web', function () {
    $this->actingAs($this->bob)->postJson('/library/albums', ['title' => 'Stolen', 'track_ids' => $this->loose->pluck('id')->all()])->assertUnprocessable();

    expect($this->loose->first()->fresh()->album_id)->toBeNull();
    $this->actingAs($this->bob)->getJson('/library/tracks/loose')->assertOk()->assertJsonCount(0, 'groups');
});

it('needs a signed-in user', function () {
    $this->getJson('/library/tracks/loose')->assertUnauthorized();
});
