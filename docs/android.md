# Android app

Status (2026-10-09): native client chosen, to live in `android/` in this repo. The read-only token API (`/api/v1`) is built and tested (`tests/Feature/ApiTest.php`); the Kotlin project in `android/` builds (`./gradlew :app:assembleDebug`) with sign-in, library list and album page; run on the emulator 2026-10-09 (sign-in, list, covers with the Bearer token, album page); playback built and checked the same day (below). Android Studio and SDK 37 are installed (AVD `Medium_Phone_API_37.0`; no system JDK, use Studio's bundled one). The PWA is still open in `PLAN.md`.

## Options

| Option | Effort | Keeps visualizers and themes | Background play | Needs Android Studio |
|---|---|---|---|---|
| PWA (manifest, service worker, Media Session) | small | yes | works with Media Session | no |
| TWA (Bubblewrap) over the PWA | small, after the PWA | yes | same as the PWA | JDK and SDK tools only |
| Capacitor shell | medium | yes | needs a foreground-service plugin | yes |
| Native Kotlin (Compose, Media3) | large | no, redrawn natively | solid, Android Auto possible | yes |

Recommended order: PWA first (it also closes "Media Session" in `PLAN.md`), then decide if native is worth it.

## Native client: API plan

There is no JSON API today; the web app is Livewire and session cookies. A native client needs a second, token-guarded surface that reuses the same rules.

### Rules that must not change
- Visibility is decided only by `Track::visibleTo()` / `TrackPolicy` / `PlaylistPolicy`; the API calls them, never a copy of the logic. Hidden things answer 404.
- Playlists carry no stream url for tracks the viewer cannot play (`Playlist::tracksFor($user)`).
- Files are served only through `/stream/{track}` and `/cover/{album}`; the API never returns paths.

### Auth
- Laravel Sanctum personal access tokens (not installed yet). `POST /api/v1/token` with `username`, `password`, `device_name`; same throttle as the web login (5 tries). Returns a token; the app keeps it in the Android Keystore (EncryptedSharedPreferences).
- `DELETE /api/v1/token` revokes the current token. Token abilities: one ability set for now.
- `/stream` and `/cover` accept `auth:sanctum,web` so the same url works for the browser (cookie) and for ExoPlayer (`Authorization: Bearer`, set in the `DataSource` factory). Range requests unchanged.

### Built so far
Sanctum is installed; `routes/api.php` holds `POST/DELETE /api/v1/token`, `GET /me`, `/albums`, `/albums/{id}`, `/shared`, `/playlists`, `/playlists/{id}`, `/search?q=` (throttle 120/min). `/stream/{track}` and `/cover/{album}` moved to `auth:web,sanctum`, so one url serves the browser (cookie) and ExoPlayer (Bearer). Track resources never carry a path; `stream_url` is `null` and `open` is `false` for tracks the viewer cannot play. Not built: playlist editing, meters setting, sharing, pagination of search.

### Endpoints (v1, read first)
- `GET /api/v1/me`: id, username, meters.
- `GET /api/v1/albums?page=`: own albums, with `cover_url`, artist, year, track count.
- `GET /api/v1/albums/{album}`: tracks in order (`id`, title, artist, number, duration, codec, `stream_url`).
- `GET /api/v1/shared`: albums, tracks and playlists shared with the user.
- `GET /api/v1/playlists`, `GET /api/v1/playlists/{id}`: tracks marked `open` or not.
- `GET /api/v1/search?q=`: tracks and albums the user can see.
- Later: playlist create, edit, reorder, add and remove (same as the web controllers), `PUT /api/v1/settings/meters`, share and unshare.
- Responses are Laravel API Resources, paginated; ids are integers.

### Implementation notes
- `routes/api.php` with `auth:sanctum` and a `throttle:api` limit; controllers stay thin and share query code with `LibraryController` and `PlaylistController` (extract, do not duplicate).
- Feature tests per endpoint: owner sees it, another user gets 404, shared reference works, a shared playlist hides private tracks, token revoked stops working.
- Uploads and scans stay web-only at first (zip upload and the queue).
- ALAC arrives as FLAC from `TranscodeCache`; Media3 plays FLAC natively.
- HTTPS is required (Android blocks cleartext); the Docker deploy behind a reverse proxy covers it.

### Client build order
1. Sign in, token storage, server url setting.
2. Album list and album page, cover images (Coil with the auth header).
3. Playback: Media3 `MediaSessionService`, queue, notification and lock-screen controls, Bluetooth keys.
4. Playlists and shared.
5. Resume position (server-side, because the web version keeps it in `localStorage`).
6. Optional: offline downloads, Android Auto, simple native visualizer (`Visualizer` API or own FFT).

### Open questions
- Is the sync of play position between devices wanted (needs a server table)?
- Is it worth maintaining two clients, versus the PWA?
- Theme bundles: parse the palette only, or skip theming in v1?

## Project (`android/`)

- Kotlin, Compose (Material 3), OkHttp + kotlinx.serialization, Coil 3 (covers through the same OkHttp client, so they carry the Bearer token), Media3 declared but unused so far. Package `app.magnetite`, minSdk 26, compileSdk 37.
- Build with Studio's bundled JDK: `JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home" ./gradlew :app:assembleDebug` (needs `android/local.properties` with `sdk.dir`, git-ignored). Gradle 9.8.1 via the wrapper, AGP 9.4.1, Kotlin 2.4.21.
- Token, server url and user name live in `EncryptedSharedPreferences` (`data/Session.kt`; the class is deprecated upstream but still the simplest Keystore-backed store).
- Debug builds allow cleartext http (`src/debug`), so the emulator can reach `php artisan serve` at `http://10.0.2.2:8000`; release is https only.
- Done: sign in (`ui/LoginScreen`), paginated album list, album page with tracks. Playback (step 3): `player/PlaybackService` (`MediaSessionService`, ExoPlayer with `OkHttpDataSource` on the app's client, so streams and notification artwork carry the Bearer token), `player/PlayerController` (a `MediaController` exposed as Compose state), `ui/MiniPlayer`. Tapping a track queues the album's open tracks; tracks with `open=false` are skipped. Checked on the emulator: play, next, keeps playing from the home screen with a media notification, hardware `MEDIA_PAUSE` pauses. Now playing (`ui/NowPlayingScreen`, opened from the mini player): large cover, title, artist, seek bar (position polled every 500 ms while playing; the thumb shows the drag value until release, then `seekTo`), Prev / Play-Pause / Next. Checked on the emulator: position ticks, a tap at 75% seeks (range requests work through `/stream`).  Not done: queue editing, playlist editing. 
- Emulator run: `adb reverse tcp:8000 tcp:8000`, server `http://localhost:8000`. The API returns absolute `cover_url` / `stream_url` built from `APP_URL`, so `APP_URL` must be the address the phone really uses (in production the https domain); with `http://localhost:8000` the reverse above makes them reachable. The app does not rewrite urls.
- Emulator note: with the Mac under load (Gradle daemons, memory pressure) System UI on the AVD ANRs; stop the daemons (`./gradlew --stop`) before running it, and do not send input while it boots.
- Fixed while testing: a load-more effect swallowed `CancellationException` and showed it as an error; catch it and rethrow.
- Playlists and shared (step 4, built 2026-10-09): bottom navigation Library / Shared / Playlists (`MainActivity`, `Tab` plus a `Detail` page that Back closes). `ui/SharedScreen` shows albums, tracks and playlists shared by others ("from {user}"), `ui/PlaylistsScreen` your own, `ui/PlaylistScreen` the tracks in order. `ui/TrackRow` is shared by all lists: tracks with `open=false` are dimmed and cannot be tapped, and `PlayerController.play` queues only open tracks. Checked on the emulator with another user's shared playlist of one shared and two private tracks: only the shared one was playable; own playlist played. Playlists are read-only in the app (the API has no editing yet).
- Resume (built 2026-10-09, local to the device like the web player): `player/PlaybackStore` keeps queue (tracks as JSON), current index and position per user in plain `SharedPreferences` (no token inside); `PlayerController` saves on track change and play/pause, and every 5 s while playing, and restores after a process restart when the service has an empty queue. Restored is paused and not prepared; `toggle()` prepares only when the person presses play. Sign out (`PlayerController.signOut`, called before the session clears the user name) pauses, empties the player and forgets the saved state. Checked on the emulator: force-stop, relaunch, mini player back with the track, Play continues at 22 s; sign out empties the prefs. Sync between devices is not built (it would need a server table, still an open question above).
