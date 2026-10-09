<?php

use App\Models\User;

beforeEach(function () {
    $this->alice = User::factory()->create(['username' => 'alice']);
    $this->bob = User::factory()->create(['username' => 'bob']);
});

it('needs a signed-in user', function () {
    $this->putJson(route('settings.meters'), ['ids' => ['vu-wide']])->assertUnauthorized();
});

it('saves the list in order, and an empty list means no meters', function () {
    $this->actingAs($this->alice);

    $this->putJson(route('settings.meters'), ['ids' => ['scope', 'vu-wide']])->assertOk()->assertJson(['meters' => ['scope', 'vu-wide']]);
    expect($this->alice->fresh()->meters)->toBe(['scope', 'vu-wide']);

    $this->putJson(route('settings.meters'), ['ids' => []])->assertOk();
    expect($this->alice->fresh()->meters)->toBe([]);
});

it('keeps each user\'s meters to themselves', function () {
    $this->actingAs($this->alice)->putJson(route('settings.meters'), ['ids' => ['magic-eye']])->assertOk();

    expect($this->bob->fresh()->meters)->toBeNull()->and($this->alice->fresh()->meters)->toBe(['magic-eye']);
});

it('refuses a list that is not a short list of plain ids', function () {
    $this->actingAs($this->alice);

    $this->putJson(route('settings.meters'), [])->assertUnprocessable();
    $this->putJson(route('settings.meters'), ['ids' => 'vu-wide'])->assertUnprocessable();
    $this->putJson(route('settings.meters'), ['ids' => ['a', 'b', 'c', 'd', 'e']])->assertUnprocessable();
    $this->putJson(route('settings.meters'), ['ids' => ['vu-wide', 'vu-wide']])->assertUnprocessable();
    $this->putJson(route('settings.meters'), ['ids' => ['<script>']])->assertUnprocessable();
    $this->putJson(route('settings.meters'), ['ids' => [['vu-wide']]])->assertUnprocessable();

    expect($this->alice->fresh()->meters)->toBeNull();
});

it('hands the saved meters to the page: null until chosen, the list afterwards, nothing for guests', function () {
    $this->get(route('welcome'))->assertOk()->assertSee('"signedIn":false,"meters":null', false);

    $this->actingAs($this->alice)->get(route('themes'))->assertSee('"signedIn":true,"meters":null', false);

    $this->alice->forceFill(['meters' => ['vu-wide', 'scope']])->save();
    $this->get(route('themes'))->assertSee('"signedIn":true,"meters":["vu-wide","scope"]', false);

    $this->alice->forceFill(['meters' => []])->save();
    $this->get(route('themes'))->assertSee('"meters":[]', false);
});

it('no longer ships the bars, radial and receiver meters or their names', function () {
    $this->get(route('welcome'))->assertOk()->assertDontSee('Amber receiver')->assertDontSee('Radial');

    foreach (['bars', 'radial', 'vu-receiver'] as $id) {
        expect(base_path("resources/js/magnetite/visualizers/{$id}.js"))->not->toBeFile();
    }
});
