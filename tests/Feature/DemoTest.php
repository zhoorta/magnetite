<?php

use App\Models\User;
use App\Support\DemoTracks;

beforeEach(function () {
    User::factory()->create(); // an instance that is set up: with no user at all, visitors go to /setup
    $this->dir = sys_get_temp_dir().'/magnetite-demo-'.uniqid();
    mkdir($this->dir);
    config(['magnetite.demo_path' => $this->dir]);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    rmdir($this->dir);
});

it('lists only the demo tracks whose file is on the server', function () {
    expect(DemoTracks::available())->toBe([]);

    file_put_contents($this->dir.'/achilles.flac', 'fLaC');

    expect(array_keys(DemoTracks::available()))->toBe(['achilles']);
});

it('streams a demo track to a guest with the strict headers and Range', function () {
    file_put_contents($this->dir.'/achilles.flac', str_repeat('a', 1000));

    $this->get(route('demo', 'achilles'))
        ->assertOk()
        ->assertHeader('Content-Type', 'audio/flac')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'inline');

    $this->withHeaders(['Range' => 'bytes=0-9'])->get(route('demo', 'achilles'))->assertStatus(206);
});

it('answers 404 for an id that is not in the list, a missing file or a path', function () {
    file_put_contents($this->dir.'/secret.flac', 'x');
    file_put_contents($this->dir.'/achilles.flac', 'x');

    $this->get('/demo/secret')->assertNotFound();
    $this->get('/demo/xenon-phoenix')->assertNotFound();
    $this->get('/demo/..%2Fachilles')->assertNotFound();
    $this->get('/demo/achilles.flac')->assertNotFound();
});

it('shows the demo panel with the credits only when there is a file to play', function () {
    $this->get(route('welcome'))->assertOk()->assertDontSee('Demo tracks');

    file_put_contents($this->dir.'/achilles.flac', 'fLaC');

    $this->get(route('welcome'))
        ->assertOk()
        ->assertSee('Demo tracks')
        ->assertSee('Achilles')
        ->assertSee('CC0 1.0')
        ->assertSee('https://opengameart.org/content/achilles', false)
        ->assertDontSee('Xenon Phoenix');
});

it('keeps every track of the list credited and licensed', function () {
    $list = json_decode(file_get_contents(resource_path('demo/tracks.json')), true)['tracks'];

    expect($list)->not->toBeEmpty();
    foreach ($list as $t) {
        expect($t['licence'])->toBe('CC0 1.0')
            ->and($t['licence_url'])->toStartWith('https://creativecommons.org/')
            ->and($t['source_url'])->toStartWith('https://')
            ->and($t['file'])->toBe($t['id'].'.flac')
            ->and(file_get_contents(base_path('CREDITS.md')))->toContain($t['source_url']);
    }
});

it('sends a visitor to the sign-in page when there is no demo music', function () {
    $this->get(route('library'))->assertRedirect(route('login'));
});

it('shows a visitor the demo tracks in the library page, read only', function () {
    file_put_contents($this->dir.'/goldberg-aria.flac', 'fLaC');
    file_put_contents($this->dir.'/goldberg-variatio-16.flac', 'fLaC');
    file_put_contents($this->dir.'/achilles.flac', 'fLaC');

    $this->get(route('library'))->assertOk()->assertSee('Demo library')->assertSee('Sign in');

    $data = $this->getJson(route('library'))->assertOk()->json();

    expect($data['demo'])->toBeTrue()
        ->and($data['folders'])->toBe([])
        ->and($data['albums'])->toHaveCount(1)
        ->and($data['albums'][0]['id'])->toBe(-1)
        ->and($data['albums'][0]['title'])->toBe('The Open Goldberg Variations')
        ->and($data['albums'][0]['tracks'])->toHaveCount(2)
        ->and($data['loose'])->toHaveCount(1); // Achilles has no album

    $track = $data['albums'][0]['tracks'][0];
    expect($track['id'])->toBe('goldberg-aria')
        ->and($track['album_id'])->toBe(-1)
        ->and($track['demo'])->toBeTrue()
        ->and($track['url'])->toBe(route('demo', 'goldberg-aria'))
        ->and($track['truth']['analyzed'])->toBeTrue()
        ->and($track['truth']['dr'])->toBe(12)
        ->and($track['truth']['spectrum'])->not->toBeEmpty()
        ->and($track['truth']['spectrum_points'])->toBe(count($track['truth']['spectrum']));
});

it('keeps everything else of the library for signed-in people', function () {
    file_put_contents($this->dir.'/achilles.flac', 'fLaC');

    $this->get(route('shared'))->assertRedirect(route('login'));
    $this->get(route('sources', 'playlists'))->assertRedirect(route('login'));
    $this->get(route('search', ['q' => 'achilles']))->assertRedirect(route('login'));
    $this->get(route('upload'))->assertRedirect(route('login'));
    $this->post(route('library.folders.store'), ['name' => 'Jazz'])->assertRedirect(route('login'));
    $this->put(route('settings.ui'), ['info' => 1])->assertRedirect(route('login'));
});

it('shows a signed-in person their own library, not the demo', function () {
    file_put_contents($this->dir.'/achilles.flac', 'fLaC');

    $data = $this->actingAs(User::factory()->create())->getJson(route('library'))->assertOk()->json();

    expect($data)->not->toHaveKey('demo')
        ->and($data['albums'])->toBe([])
        ->and($data['loose'])->toBe([]);
});
