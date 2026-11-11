<?php

namespace App\Http\Controllers;

use App\Models\Scene;
use App\Models\User;
use App\Services\SceneStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Pictures turned into meters ("scenes"): private to the owner, settings stored as only what they changed. */
class SceneController extends Controller
{
    public const MAX_PER_USER = 30;

    /** Numeric settings and their allowed range; the player clamps again, this keeps junk out of the database. */
    public const NUMBERS = [
        'focus' => [0, 1], 'backdrop' => [0, 1], 'bloom' => [0, 1], 'zoom' => [0, 0.2], 'drift' => [0, 1], 'attack' => [0, 2], 'release' => [0, 2],
        'kickGain' => [0, 3], 'bandGain' => [0, 3], 'glowGain' => [0, 4], 'dim' => [0, 1], 'saturation' => [0, 1],
        'hueShift' => [0, 360], 'hueFollow' => [0, 360], 'hueSpin' => [0, 180], 'hueKick' => [0, 180], 'brightPulse' => [0, 2], 'invertKick' => [0, 1], 'wash' => [0, 1],
        'kickThreshold' => [0, 20], 'kickCooldown' => [0, 2], 'vignette' => [0, 1],
    ];

    /** The person's scenes as the player and the scenes page use them. */
    public function list(User $user): array
    {
        return $user->scenes()->orderBy('id')->get()->map(fn (Scene $s) => $this->present($s))->all();
    }

    public function store(Request $request, SceneStore $store): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'max:12288'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);
        abort_if($request->user()->scenes()->count() >= self::MAX_PER_USER, 422, 'Too many scenes.');

        $file = $store->store($request->file('image')->get());
        abort_if($file === null, 422, 'That is not a picture I can use (JPEG, PNG, WebP or GIF).');

        $name = trim((string) $request->input('name')) ?: pathinfo($request->file('image')->getClientOriginalName(), PATHINFO_FILENAME);
        $scene = $request->user()->scenes()->create(['name' => mb_substr($name ?: 'Scene', 0, 80), 'file' => $file]);

        return response()->json($this->present($scene), 201);
    }

    public function image(Request $request, Scene $scene, SceneStore $store): BinaryFileResponse
    {
        abort_unless($scene->user_id === $request->user()->id, 404);
        $path = $store->path($scene->file);
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    public function update(Request $request, Scene $scene): JsonResponse
    {
        abort_unless($scene->user_id === $request->user()->id, 404);

        $rules = [
            'name' => ['sometimes', 'string', 'min:1', 'max:80'],
            'hotspots' => ['sometimes', 'array', 'max:12'],
            'hotspots.*' => ['array:x,y,w,h,role'],
            'hotspots.*.x' => ['required', 'numeric', 'between:0,1'],
            'hotspots.*.y' => ['required', 'numeric', 'between:0,1'],
            'hotspots.*.w' => ['required', 'numeric', 'between:0.02,1'],
            'hotspots.*.h' => ['required', 'numeric', 'between:0.02,1'],
            'hotspots.*.role' => ['required', Rule::in(['kick', 'band'])],
            'settings' => ['sometimes', 'array'],
            'settings.fit' => ['sometimes', Rule::in(['contain', 'cover'])],
            'settings.glowColor' => ['sometimes', 'nullable', 'string', 'regex:/^(picture|accent|#[0-9a-fA-F]{6})$/'],
            'settings.tint' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
        foreach (self::NUMBERS as $key => [$min, $max]) {
            $rules["settings.{$key}"] = ['sometimes', 'numeric', "between:{$min},{$max}"];
        }
        $data = $request->validate($rules);

        if ($request->has('settings')) {
            // Only known keys are kept; an empty array means "all auto".
            $data['settings'] = array_intersect_key($data['settings'] ?? [], array_flip(['fit', 'glowColor', 'tint', ...array_keys(self::NUMBERS)]));
        }
        if ($request->has('hotspots')) {
            $data['hotspots'] = array_map(fn ($h) => array_map(fn ($v) => is_numeric($v) ? round((float) $v, 4) : $v, $h), array_values($data['hotspots'] ?? []));
        }
        $scene->update($data);

        return response()->json($this->present($scene));
    }

    public function destroy(Request $request, Scene $scene, SceneStore $store): JsonResponse
    {
        abort_unless($scene->user_id === $request->user()->id, 404);
        $scene->delete();
        $store->forget($scene->file);

        return response()->json(['ok' => true]);
    }

    private function present(Scene $scene): array
    {
        return [
            'id' => $scene->id,
            'name' => $scene->name,
            'url' => route('scenes.image', $scene),
            'settings' => (object) ($scene->settings ?? []),
            'hotspots' => $scene->hotspots ?? [],
        ];
    }
}
