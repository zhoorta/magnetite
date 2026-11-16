<?php

use App\Models\Album;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['username' => 'boss', 'is_admin' => true]);
    $this->ana = User::factory()->create(['username' => 'ana']);
});

it('promotes and demotes other users, never yourself, and only for administrators', function () {
    $this->actingAs($this->ana)->patch(route('admin.users.role', $this->ana), ['is_admin' => 1])->assertNotFound();

    $this->actingAs($this->admin)->patch(route('admin.users.role', $this->ana), ['is_admin' => 1])->assertRedirect();
    expect($this->ana->fresh()->is_admin)->toBeTrue();

    $this->actingAs($this->admin)->patch(route('admin.users.role', $this->ana), ['is_admin' => 0])->assertRedirect();
    expect($this->ana->fresh()->is_admin)->toBeFalse();

    $this->actingAs($this->admin)->patch(route('admin.users.role', $this->admin), ['is_admin' => 0])->assertForbidden();
    expect($this->admin->fresh()->is_admin)->toBeTrue();
});

it('deletes a user with their library, playlists and tokens after the name is typed, and keeps the files', function () {
    $album = Album::create(['title' => 'A', 'artist' => 'B', 'user_id' => $this->ana->id]);
    Playlist::create(['name' => 'Mine', 'user_id' => $this->ana->id]);
    $this->ana->createToken('phone');
    $root = sys_get_temp_dir().'/magnetite-users-'.uniqid();
    config(['magnetite.music_path' => $root]);
    $dir = $this->ana->libraryPath();
    @mkdir($dir, 0755, true);
    file_put_contents($dir.'/keep.mp3', 'x');

    $this->actingAs($this->ana)->delete(route('admin.users.destroy', $this->ana), ['confirm' => 'ana'])->assertNotFound();
    $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->ana), ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
    expect(User::where('username', 'ana')->exists())->toBeTrue();

    $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->ana), ['confirm' => 'ana'])->assertRedirect()->assertSessionHas('status');

    expect(User::where('username', 'ana')->exists())->toBeFalse()
        ->and(Album::whereKey($album->id)->exists())->toBeFalse()
        ->and(Playlist::where('name', 'Mine')->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->count())->toBe(0)
        ->and(file_exists($dir.'/keep.mp3'))->toBeTrue();

    @unlink($dir.'/keep.mp3');
    @rmdir($dir);
    @rmdir(dirname($dir));
    @rmdir($root);
});

it('does not let an administrator delete themselves', function () {
    $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->admin), ['confirm' => 'boss'])->assertForbidden();
    expect(User::count())->toBe(2);
});

it('shows the role and delete controls for others but not for yourself', function () {
    $page = $this->actingAs($this->admin)->get(route('admin.users'));

    $page->assertOk()->assertSee('Make admin')->assertSee('Delete…');
    expect(substr_count($page->getContent(), 'Delete…'))->toBe(1);
});
