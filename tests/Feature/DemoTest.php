<?php

use App\Support\DemoTracks;

beforeEach(function () {
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
    $this->get(route('welcome'))->assertOk()->assertDontSee('Try it with free music');

    file_put_contents($this->dir.'/achilles.flac', 'fLaC');

    $this->get(route('welcome'))
        ->assertOk()
        ->assertSee('Try it with free music')
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
