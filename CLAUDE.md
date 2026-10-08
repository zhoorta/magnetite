# Magnetite

Theme-first music player for the web: persistent player, live audio visualizers, themes as shareable JSON bundles. Personal/fun project, same maker as Ferrite.

- Read `PLAN.md` first: scope, positioning, build order and status. Keep its checkboxes updated as work lands; put detail in `docs/`, not in the plan.
- Stack: Laravel 13, Livewire 4, Alpine (bundled with Livewire), Tailwind 4, Vite, Pest 5 (needs PHPUnit 13), getID3. Flux UI not installed yet.
- Livewire is imported through Vite (`livewire.esm` + `@livewireScriptConfig` in the layout) so `Alpine.store('player')` can be registered in `resources/js/magnetite/boot.js`.
- The player lives in an `@persist('player')` block in `resources/views/components/layouts/app.blade.php`; the `<audio>` element and the Web Audio graph must never be recreated on navigation.
- `wire:navigate` wipes inline CSS variables on `<html>`; the theme is re-applied on `livewire:navigated`. Keep that in mind for anything else set on `<html>`.
- Streaming is the security surface: files are served only through `/stream/{track}` with a type allowlist, `nosniff` and `Content-Disposition: inline`; never expose paths.
- Run `vendor/bin/pest` and `vendor/bin/pint --test` before committing. First commit made 2026-10-08, no remote yet; no Claude trailer in commits (same as Ferrite).
- The library is read from `MAGNETITE_MUSIC_PATH` (never written to). `php artisan magnetite:scan` fills `albums`/`tracks` via `App\Services\LibraryScanner`; covers go to `storage/app/private/covers/{sha1}.jpg`. Tests use committed fixtures in `tests/Fixtures/music` and `Storage::fake('local')`.
- Rebuild assets (`npm run build`) after editing Blade views that add Tailwind classes, or the served CSS is stale.
- ALAC is served as FLAC through `App\Services\TranscodeCache` (ffmpeg, `MAGNETITE_FFMPEG` or PATH). The source is only ever an already-validated absolute path passed as a process argument array, never through a shell.
