# Android app

Status: design only (2026-10-09), nothing built. Decide between the PWA and a native client first.

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
