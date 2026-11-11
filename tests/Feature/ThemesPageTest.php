<?php

use App\Models\User;

it('shows the themes page to a guest, with the preview, import and the quick switcher in the top bar', function () {
    $this->get(route('themes'))
        ->assertOk()
        ->assertSee('x-data="themeStudio"', false)
        ->assertSee('Import a theme')
        ->assertSee('Download JSON')
        ->assertSee('aria-label="Theme"', false);
});

it('has the quick theme switcher on the library page too', function () {
    $this->actingAs(User::factory()->create())->get(route('library'))->assertOk()->assertSee('Preview, edit, import…');
});

it('has the upload panel as a dialog in the library and the same panel on the upload page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('library'))
        ->assertOk()
        ->assertSee('data-upload-panel', false)
        ->assertSee('"uploads":{"url"', false);

    $this->actingAs($user)->get(route('upload'))->assertOk()->assertSee('Choose zips or drop them here');
});
