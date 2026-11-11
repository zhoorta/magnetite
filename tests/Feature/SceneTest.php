<?php

use App\Models\Scene;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->alice = User::factory()->create(['username' => 'alice']);
    $this->bob = User::factory()->create(['username' => 'bob']);
});

function picture(int $w = 2400, int $h = 1200): UploadedFile
{
    return UploadedFile::fake()->image('Neon street.png', $w, $h);
}

it('needs a signed-in user', function () {
    $this->get(route('scenes'))->assertRedirect(route('login'));
    $this->postJson(route('scenes.store'), ['image' => picture()])->assertUnauthorized();
});

it('re-encodes an upload as a downsized JPEG and names the scene after the file', function () {
    $res = $this->actingAs($this->alice)->postJson(route('scenes.store'), ['image' => picture()])->assertCreated()->json();

    expect($res['name'])->toBe('Neon street');
    $scene = Scene::first();
    expect($scene->file)->toMatch('/^[a-f0-9]{40}\.jpg$/');
    Storage::disk('local')->assertExists("scenes/{$scene->file}");
    [$w, $h, $type] = getimagesizefromstring(Storage::disk('local')->get("scenes/{$scene->file}"));
    expect($w)->toBe(1600)->and($h)->toBe(800)->and($type)->toBe(IMAGETYPE_JPEG);
});

it('refuses what is not a picture', function () {
    $this->actingAs($this->alice)->postJson(route('scenes.store'), ['image' => UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;')])->assertStatus(422);
    expect(Scene::count())->toBe(0);
});

it('serves the picture to its owner only', function () {
    $id = $this->actingAs($this->alice)->postJson(route('scenes.store'), ['image' => picture(300, 200)])->json('id');

    $this->actingAs($this->alice)->get(route('scenes.image', $id))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->actingAs($this->bob)->get(route('scenes.image', $id))->assertNotFound();
});

it('hands the player only your own scenes', function () {
    $this->actingAs($this->alice)->postJson(route('scenes.store'), ['image' => picture(300, 200)]);

    expect($this->actingAs($this->alice)->get(route('scenes'))->getContent())->toContain('Neon street');
    expect($this->actingAs($this->bob)->get(route('scenes'))->getContent())->not->toContain('Neon street');
});

it('stores only the settings that are known and in range', function () {
    $id = $this->actingAs($this->alice)->postJson(route('scenes.store'), ['image' => picture(300, 200)])->json('id');

    $this->patchJson(route('scenes.update', $id), ['settings' => ['bloom' => 0.3, 'glowColor' => 'picture', 'fit' => 'cover', 'evil' => 'x']])->assertOk();
    expect(Scene::find($id)->settings)->toEqualCanonicalizing(['bloom' => 0.3, 'glowColor' => 'picture', 'fit' => 'cover']);

    $this->patchJson(route('scenes.update', $id), ['settings' => ['bloom' => 9]])->assertStatus(422);
    $this->patchJson(route('scenes.update', $id), ['settings' => ['glowColor' => 'url(x)']])->assertStatus(422);
    $this->patchJson(route('scenes.update', $id), ['settings' => []])->assertOk();
    expect(Scene::find($id)->settings)->toBe([]);
});

it('does not let another user change or delete a scene', function () {
    $id = $this->actingAs($this->alice)->postJson(route('scenes.store'), ['image' => picture(300, 200)])->json('id');

    $this->actingAs($this->bob)->patchJson(route('scenes.update', $id), ['name' => 'mine'])->assertNotFound();
    $this->actingAs($this->bob)->deleteJson(route('scenes.destroy', $id))->assertNotFound();
    expect(Scene::count())->toBe(1);
});

it('deletes the scene and its file, but keeps a file another scene still uses', function () {
    $this->actingAs($this->alice);
    $a = $this->postJson(route('scenes.store'), ['image' => picture(300, 200)])->json('id');
    $file = Scene::find($a)->file;
    Scene::create(['user_id' => $this->alice->id, 'name' => 'copy', 'file' => $file]);

    $this->deleteJson(route('scenes.destroy', $a))->assertOk();
    Storage::disk('local')->assertExists("scenes/{$file}");

    $this->deleteJson(route('scenes.destroy', Scene::first()->id))->assertOk();
    Storage::disk('local')->assertMissing("scenes/{$file}");
});
