<?php

it('shows the landing page', function () {
    $this->get(route('welcome'))
        ->assertOk()
        ->assertSee('The look is', false)
        ->assertSee('A theme is one file');
});

it('carries a description and share metadata', function () {
    $this->get(route('welcome'))
        ->assertOk()
        ->assertSee('<meta name="description"', false)
        ->assertSee('<meta property="og:image"', false)
        ->assertSee('/screenshots/library.png', false);
});

it('hides the Android download when the file is not on the server', function () {
    config(['magnetite.apk_version' => '9.9.9']);

    $this->get(route('welcome'))->assertOk()->assertDontSee('Download the APK');
});

it('keeps the library screenshots that the page and the README point to', function () {
    expect(public_path('screenshots/library.png'))->toBeFile()
        ->and(public_path('screenshots/truth.png'))->toBeFile()
        ->and(public_path('demo-cover.png'))->toBeFile();
});
