<?php

use App\Models\Album;
use App\Models\Folder;
use App\Models\User;

beforeEach(function () {
    $this->alice = User::factory()->create(['username' => 'alice']);
    $this->bob = User::factory()->create(['username' => 'bob']);
    $this->album = Album::create(['user_id' => $this->alice->id, 'title' => 'Kind of Blue', 'artist' => 'Miles Davis']);
});

it('makes folders and subfolders for the signed-in user', function () {
    $jazz = $this->actingAs($this->alice)->postJson(route('library.folders.store'), ['name' => ' Jazz '])->assertCreated()->json('folder');
    $this->postJson(route('library.folders.store'), ['name' => 'Bebop', 'parent_id' => $jazz['id']])->assertCreated();

    expect($jazz['name'])->toBe('Jazz')
        ->and(Folder::where('name', 'Bebop')->sole()->parent_id)->toBe($jazz['id'])
        ->and(Folder::where('name', 'Bebop')->sole()->user_id)->toBe($this->alice->id);
});

it('does not nest folders into another person\'s folder or too deep', function () {
    $theirs = Folder::create(['user_id' => $this->bob->id, 'name' => 'Rock']);
    $this->actingAs($this->alice)->postJson(route('library.folders.store'), ['name' => 'x', 'parent_id' => $theirs->id])->assertUnprocessable();

    $parent = null;
    foreach (range(1, Folder::MAX_DEPTH) as $i) {
        $parent = Folder::create(['user_id' => $this->alice->id, 'name' => "L$i", 'parent_id' => $parent?->id]);
    }
    $this->postJson(route('library.folders.store'), ['name' => 'too deep', 'parent_id' => $parent->id])->assertUnprocessable();
});

it('renames and moves a folder, but never into itself or its own subfolder', function () {
    $a = Folder::create(['user_id' => $this->alice->id, 'name' => 'A']);
    $b = Folder::create(['user_id' => $this->alice->id, 'name' => 'B', 'parent_id' => $a->id]);
    $c = Folder::create(['user_id' => $this->alice->id, 'name' => 'C']);

    $this->actingAs($this->alice)->patchJson(route('library.folders.update', $a), ['name' => 'Alpha'])->assertOk();
    $this->patchJson(route('library.folders.update', $a), ['parent_id' => $b->id])->assertUnprocessable();
    $this->patchJson(route('library.folders.update', $a), ['parent_id' => $a->id])->assertUnprocessable();
    $this->patchJson(route('library.folders.update', $b), ['parent_id' => $c->id])->assertOk();
    $this->patchJson(route('library.folders.update', $b), ['parent_id' => null])->assertOk();

    expect($a->fresh()->name)->toBe('Alpha')->and($b->fresh()->parent_id)->toBeNull();
});

it('puts an album in a folder and back out', function () {
    $jazz = Folder::create(['user_id' => $this->alice->id, 'name' => 'Jazz']);

    $this->actingAs($this->alice)->putJson(route('library.albums.folder', $this->album), ['folder_id' => $jazz->id])->assertOk();
    expect($this->album->fresh()->folder_id)->toBe($jazz->id);

    $this->putJson(route('library.albums.folder', $this->album), ['folder_id' => null])->assertOk();
    expect($this->album->fresh()->folder_id)->toBeNull();
});

it('deleting a folder moves its albums and subfolders up and keeps the music', function () {
    $top = Folder::create(['user_id' => $this->alice->id, 'name' => 'Top']);
    $mid = Folder::create(['user_id' => $this->alice->id, 'name' => 'Mid', 'parent_id' => $top->id]);
    $sub = Folder::create(['user_id' => $this->alice->id, 'name' => 'Sub', 'parent_id' => $mid->id]);
    $this->album->update(['folder_id' => $mid->id]);

    $this->actingAs($this->alice)->deleteJson(route('library.folders.destroy', $mid))->assertOk();

    expect(Folder::find($mid->id))->toBeNull()
        ->and($sub->fresh()->parent_id)->toBe($top->id)
        ->and($this->album->fresh()->folder_id)->toBe($top->id);
});

it('keeps folders private: others get a 404 and cannot file into them', function () {
    $mine = Folder::create(['user_id' => $this->alice->id, 'name' => 'Mine']);
    $theirs = Folder::create(['user_id' => $this->bob->id, 'name' => 'Theirs']);

    $this->actingAs($this->bob)->patchJson(route('library.folders.update', $mine), ['name' => 'x'])->assertNotFound();
    $this->deleteJson(route('library.folders.destroy', $mine))->assertNotFound();
    $this->putJson(route('library.albums.folder', $this->album), ['folder_id' => $theirs->id])->assertNotFound();

    $this->actingAs($this->alice)->putJson(route('library.albums.folder', $this->album), ['folder_id' => $theirs->id])->assertUnprocessable();
    expect($this->album->fresh()->folder_id)->toBeNull();
});

it('shows the folders and their albums in the library, and only the owner\'s', function () {
    $jazz = Folder::create(['user_id' => $this->alice->id, 'name' => 'Jazz']);
    Folder::create(['user_id' => $this->bob->id, 'name' => 'Bobs Secret Folder']);
    $this->album->update(['folder_id' => $jazz->id]);

    $this->actingAs($this->alice)->get(route('library'))->assertOk()->assertSee('Jazz')->assertSee('Kind of Blue')->assertDontSee('Bobs Secret Folder');

    $data = $this->getJson(route('library'))->assertOk()->json();
    expect(collect($data['folders'])->pluck('name')->all())->toBe(['Jazz'])
        ->and(collect($data['albums'])->firstWhere('id', $this->album->id)['folder_id'])->toBe($jazz->id);
});
