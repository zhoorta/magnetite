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
- Uploads and scans stay web-only at first (zip upload and the queue); see "Write API" below for the plan to open them.
- ALAC arrives as FLAC from `TranscodeCache`; Media3 plays FLAC natively.
- HTTPS is required (Android blocks cleartext); the Docker deploy behind a reverse proxy covers it.

### Write API (planned, for Android and a later iOS client)
The web library can now do more than the API shows: folders, scan, details, covers, delete. The mobile clients should get the same, through the same rules, so iOS later needs no new server work. Built 2026-10-09 (server side, `tests/Feature/ApiWriteTest.php`); the Android app does not use it yet. Differences from the plan: the write endpoints for folders, scan, details, titles, covers and delete are the web controllers themselves registered under `/api/v1` (so both agree by construction), `GET /folders` is a flat list with `parent_id` and `album_count` (the client builds the tree), the cover from the phone is `PUT /albums/{id}/cover` with an `image` field (from a client that cannot send multipart PUT: `POST` with `?_method=PUT`), and token abilities are `*` (default) or `library:read` (`read_only=1` on `POST /token`); routes need `library:write` and, for deleting, `library:delete`. Moving files works for both kinds of library through `LibraryStore::move()`: a local folder renames the file, a Ferrite library calls Ferrite's `PATCH /api/v1/files/{id}` with `{folder}` (added 2026-10-09 in Ferrite `docs/api.md`, needs only a write token; a clash is 409 and becomes a 422 here, and an older Ferrite without the call answers 405, reported as "too old").

- Extract, do not duplicate: the web controllers (`FolderController`, `LibraryEditController`, `LibraryRemoveController`, `ScanController`, `CoverSearchController`, `AlbumTitlesController`, `UploadController`) already return JSON and check policies; move the work into actions or services and let `Api\*` controllers call them with API Resources. Same 404 for what is not yours.
- Folders: `GET /api/v1/folders` (tree), `POST /folders`, `PATCH /folders/{id}` (rename, move), `DELETE /folders/{id}`, `PUT /albums/{id}/folder`; `folder_id` added to `AlbumResource`; same 6-level limit.
- Scan: `POST /api/v1/scan` and `GET /api/v1/scan` (queued, status per user, as `ScanStatus`).
- Details: `PATCH /albums/{id}` and `PATCH /tracks/{id}` (title, artist, year, date, label, number), `POST /albums/{id}/titles/find` and `/titles` (MusicBrainz suggestions, apply). `AlbumResource` and `TrackResource` gain the detail fields (label, release date, and for the owner the file-truth findings: DR, LUFS, codec, bit depth).
- Covers: `POST /albums/{id}/cover/find`, `POST /covers/find` and `GET /covers/find` (status); `PUT /albums/{id}/cover` to send an image from the phone (jpeg or png, re-encoded by `CoverStore`; it sets this album's cover only). Reading covers already works (`cover_url`).
- Files, not only zips: `POST /api/v1/uploads` takes one or more audio files (multipart) as well as zips. Single files are audio only, no cover images (covers come in a zip or through the cover endpoint above). Each audio file lands in the root of the user's own folder, not in an album; a zip keeps its current behaviour. All go through `LibraryUploader`, so the rules stay as strict as the web upload (audio only for single files, nothing overwritten, unsafe names refused, quota, queued scan afterwards). Large files: allow resumable or chunked upload if phones on mobile data fail on big FLACs (decide when building).
- Put a file in an album and take it out: `POST /albums/{id}/tracks` with a track id moves that file into the album's folder; `DELETE /albums/{id}/tracks/{track}` moves it out again, to the folder that holds the album's folder, or to the root of the user's folder if the album is at the top. This moves the file (and updates `tracks.path`); it never deletes it. Nothing overwritten: a name clash refuses with 422. Owner only, and a track in a shared album stays shared by its own reference. Deleting for real stays `DELETE /tracks/{id}` and `DELETE /albums/{id}` (through `LibraryRemoveController`'s service: unlink or Ferrite trash).
- Token abilities: reading keeps the current ability; writing and deleting get their own (`library:write`, `library:delete`), so a client can ask for read-only.
- Findings for the owner (built 2026-10-09): `TrackResource.truth` carries the measurements and `flags` including duplicates found across the whole library (the detail text names the other copy's path, the owner's own); `GET /tracks/{id}?spectrum=1` adds `spectrum` and `step_hz`; `AlbumResource.dr` (mean of the tracks, when the tracks are loaded); `GET`/`POST /analysis` (same controller as the web, `album_id` or `track_id` to limit it; the POST needs `library:write`). Not in the API: the Identify suggestions ("Suggest albums").
- Tests per endpoint, as for the read API: owner works, another user gets 404, a shared item cannot be changed by the viewer, a revoked or read-only token is refused, upload rules (traversal, overwrite, wrong type) hold through the API.
- Clients: Android first (folders and details in the library screens, share-sheet upload), iOS later on the same endpoints. The server returns absolute `cover_url` / `stream_url`, so clients need no path logic.

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

## Look (2026-10-09)
The app uses the Terminal theme of the web app: `ui/Theme.kt` (`MagnetiteTheme`: black, phosphor green, monospace, square corners, `Logo` composable). The logo images are renders of `public/logo.svg` made with headless Chrome (a vector copy looked coarse): `drawable-nodpi/logo_full.png` (login), `splash_logo.png` (Android 12+ splash, black background, set in `values-v31/themes.xml`; the system masks it to a circle, so the logo is small on purpose) and `ic_launcher_fg.png` (adaptive icon, meters only). The sign-in form is prefilled with the last server and user name (`Session.lastUsername` survives sign-out). Checked on the emulator (login, splash, launcher), not the prefill. Other themes are not selectable in the app yet. The Library screen browses folders (2026-10-09): `GET /folders` plus `folder_id` on albums, folders expand in place as a tree; "New folder" in the top bar, long-press a folder for New subfolder, Rename, Delete. Moving albums between folders is not in the app yet.

Sign-in stays (2026-10-09): the token is stored encrypted and never expires on the server, so the app opens straight into the library. If the server refuses the token (401 on any API, cover or stream request) the session ends: the player is emptied, the token is dropped and the sign-in screen comes back with server and user name filled in (`Api.onUnauthorized`). Wrong passwords at sign-in do not count (no token yet). Tried on the emulator: cold restart stays signed in, revoked token returns to sign-in. Android 17 needs `ACCESS_LOCAL_NETWORK` to reach a private-network server (home, or 10.0.2.2 from the emulator); without it the connection times out after 10 s with no other hint. The app asks for it at start.


## Meters in the app (started 2026-10-09)

Steps 1 and 2 of 4 (step 2: `player/Spectrum.kt` FFT over a mono ring buffer in `MeterTap`; `ui/Panels.kt` has `MeterPanel`, `SpectrumPanel`, `ScopePanel`, shown on Now Playing; the level bars sit in their own dark panel). Step 1: `player/MeterTap.kt` is an ExoPlayer `AudioProcessor` added to the audio sink in `PlaybackService`. It passes audio through unchanged and keeps per-channel peak and RMS of the latest block (16/24/32-bit and float PCM; other formats are passed through unmeasured). The mini player draws two stereo level bars from it (`LevelBars` in `ui/MiniPlayer.kt`, redrawn per frame only while playing). Next: a Kotlin port of `worklet/meter-processor.js` (LUFS, true peak, correlation), then the synced meter list (`users.meters` in `GET /me`). Audio offload must stay off or the processor is bypassed.

## Meters (2026-10-09)

The app has the web's nine meters under the same ids (`vu-wide`, `vu-needle`, `vu-leds`, `magic-eye`, `scope`, `goniometer`, `loudness`, `third-octave`, `spectrogram`), drawn in `ui/Meters.kt` on Compose canvases in the Terminal colours. The list is the user's `users.meters`: read from `GET /me` after sign-in, changed in the Meters picker (Now Playing top bar, or tap the meter panel in the mini player) and saved at once with `PUT /api/v1/settings/meters` (the web controller; up to 4, ids checked for shape only, unknown ids are ignored by both clients). The mini player shows the first meter, Now Playing shows all of them under the controls.

- `player/Loudness.kt` is a port of `worklet/meter-processor.js`, fed per frame from `MeterTap`: K-weighted momentary, short-term and integrated LUFS (gated), true peak (4x, 12 taps per phase instead of 48), correlation, and the last 1024 samples for the goniometer. It resets at every track change (`PlaybackService`).
- Third-octave is 31 bands from 25 Hz (FFT 8192, so the lowest bands are one bin wide); the spectrogram is a 160x64 bitmap that scrolls about 13 s. The goniometer has no afterglow.
- Not done: LRA, K-meter and the digital readout (not on the web either), per-theme looks, a hidden-meter pause.

## Drag and drop (2026-10-09)

- Library: long-press an album and drag it onto a folder row (or onto another album, to join that album's folder); the target row lights up, the list scrolls when the finger nears an edge, and `PUT /albums/{id}/folder` runs on drop. Folders themselves cannot be dragged yet.
- Playlist: long-press a track and drag it up or down in a playlist of your own; `PUT /api/v1/playlists/{id}/order` (the web controller, needs `library:write`) saves the order on drop and the list reloads if that fails. Shared playlists are read-only.
- `ui/Drag.kt` holds the shared gesture (`dragRow`); the finger position is kept in viewport coordinates so a scrolling list does not shift the dragged row.

## Pull to refresh (2026-10-09)

Library, Shared, Playlists, a playlist and an album reload when pulled down (`ui/Refresh.kt`: `PullRefresh` around the list, `ScrollingMessage` so the empty and loading states can be pulled too). The Library reload reloads folders and albums and keeps the expanded folders; a failed reload keeps what is on screen.

## Android Auto (2026-10-10)

The app is a media app for Android Auto: `PlaybackService` is a `MediaLibraryService` (also answering the older `android.media.browse.MediaBrowserService` action, which Auto uses to browse), and the manifest carries `com.google.android.gms.car.application` pointing at `res/xml/automotive_app_desc.xml` (`<uses name="media"/>`).

- Browse tree (`player/LibraryTree.kt`, from the same API as the app, so a person sees only what they may play): Library (folders, then albums; an album opens to its tracks), Playlists, Shared. Lists are paged for the browser; not signed in answers "authentication required".
- Playing: a browser that sends one track id gets the whole list that track was browsed in, starting there (`LibraryTree.resolve`, used by `onSetMediaItems` / `onAddMediaItems`). Checked on the emulator with a Media3 `MediaBrowser` against the running service (root, folders, albums, playlists, and one id queueing the playlist at the right index); not checked in a car or the Desktop Head Unit.
- The phone app only saves its own queue (`PlayerController.save` skips when the queue was replaced from outside).
- Known limits: cover art in Auto may be missing (the covers need the Bearer token, which Auto cannot send); no search, no voice commands yet; the meters are phone-only.
- To try it: on the phone open Android Auto settings, tap Version about 10 times, then ⋮ → Developer settings → turn on Unknown sources (sideloaded apps are hidden otherwise). Then connect to a car, or run the Desktop Head Unit (`adb forward tcp:5277 tcp:5277`, Android Auto → ⋮ → Start head unit server, then `desktop-head-unit`).

## Now Playing navigation (2026-10-10)

- A swipe down on Now Playing (meters or cover view) returns to the mini player; it only watches the gesture (Initial pass, not consumed), so the slider and scrolling keep working, and a scrolled page closes only at the top.
- Tapping the cover opens the album or playlist the queue came from: `PlayerController.play(tracks, start, from: PlaySource?)` remembers it (saved with the queue in `SavedPlayback.source`); lists with no single source (Shared tracks) fall back to the album of the current track.

## Meters follow the playhead (2026-10-10)

- `MeterTap` keeps ~1.3 s of left/right samples. The sink wrapper in `PlaybackService` (`ForwardingAudioSink`) reports the media position being heard and the media time of each buffer; `MeterTap.playheadFrame()` maps that to a ring index, so peak/RMS (1024-sample window), scope, spectra and goniometer show what is heard now, not what just entered the sink buffer. LUFS and true peak are still measured on entry (they lead slightly).
- FFTs for third-octave and spectrogram run on `Dispatchers.Default` (`MeterCanvas(work = …)`); `Spectrum` has precomputed twiddles and band edges.
- `MainActivity.preferHighRefreshRate()` asks for the fastest display mode at the current resolution (adaptive-refresh phones otherwise drop for touch-free animation).
- Draw costs (2026-10-10): the goniometer draws points (`drawPoints`) instead of a 1024-segment stroked path (it was ~3x slower than the other meters on the emulator); the scope draws one point per ~3 px; the magic eye uses concentric translucent discs instead of radial gradients; the spectrogram has 400 columns and slides by the fractional column; LUFS numbers are formatted without `String.format`.
