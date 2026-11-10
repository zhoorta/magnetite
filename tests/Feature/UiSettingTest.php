<?php

use App\Models\User;

beforeEach(function () {
    $this->alice = User::factory()->create(['username' => 'alice']);
    $this->bob = User::factory()->create(['username' => 'bob']);
});

it('needs a signed-in user', function () {
    $this->putJson(route('settings.ui'), ['info' => 1])->assertUnauthorized();
});

it('saves the info level and the cover choice, and only the keys that are sent change', function () {
    $this->actingAs($this->alice);

    $this->putJson(route('settings.ui'), ['info' => 1, 'pixel' => true])->assertOk()->assertJson(['ui' => ['info' => 1, 'pixel' => true]]);
    $this->putJson(route('settings.ui'), ['info' => 0])->assertOk();
    expect($this->alice->fresh()->ui)->toBe(['info' => 0, 'pixel' => true]);

    // null means "follow the theme"
    $this->putJson(route('settings.ui'), ['pixel' => null])->assertOk();
    expect($this->alice->fresh()->ui)->toBe(['info' => 0, 'pixel' => null]);
});

it('refuses levels and values that make no sense', function () {
    $this->actingAs($this->alice);

    $this->putJson(route('settings.ui'), ['info' => 3])->assertUnprocessable();
    $this->putJson(route('settings.ui'), ['info' => -1])->assertUnprocessable();
    $this->putJson(route('settings.ui'), ['info' => 'lots'])->assertUnprocessable();
    $this->putJson(route('settings.ui'), ['pixel' => 'maybe'])->assertUnprocessable();
    $this->putJson(route('settings.ui'), ['tint' => 'sometimes'])->assertUnprocessable();

    expect($this->alice->fresh()->ui)->toBeNull();
});

it('saves the meter tint from the cover', function () {
    $this->actingAs($this->alice)->putJson(route('settings.ui'), ['tint' => true])->assertOk()->assertJson(['ui' => ['tint' => true]]);
    $this->putJson(route('settings.ui'), ['info' => 1])->assertOk();

    expect($this->alice->fresh()->ui)->toBe(['tint' => true, 'info' => 1]);
});

it('keeps each user\'s choices to themselves and hands them to the page', function () {
    $this->actingAs($this->alice)->putJson(route('settings.ui'), ['info' => 1])->assertOk();

    expect($this->bob->fresh()->ui)->toBeNull();
    $this->get(route('themes'))->assertSee('"ui":{"info":1}', false);
    $this->actingAs($this->bob)->get(route('themes'))->assertSee('"ui":null', false);
});
