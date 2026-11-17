# Configuration

Settings are environment variables. Without Docker they go in `.env`; with Docker they go in `.env.docker` (copy `.env.docker.example`), which the container reads on start.

## Magnetite's settings

| Variable | What it does | Default |
|---|---|---|
| `MAGNETITE_MUSIC_PATH` | The music folder; each user has `users/{username}` in it | `storage/app/music` (`/music` in Docker) |
| `MAGNETITE_UPLOAD_MAX_MB` | Largest zip that can be uploaded | 2048 |
| `MAGNETITE_UPLOAD_MAX_UNPACKED_MB` | Largest size of one zip once unpacked | 6144 |
| `MAGNETITE_UPLOAD_MAX_FILES` | Most files in one zip | 5000 |
| `MAGNETITE_FFMPEG` | Path to ffmpeg | found on the `PATH` |
| `MAGNETITE_FFPROBE` | Path to ffprobe | next to ffmpeg, or on the `PATH` |
| `MAGNETITE_NODE` | Path to Node | found on the `PATH` |
| `MAGNETITE_COVERS_USER_AGENT` | How the cover and album look-ups identify themselves to MusicBrainz, which asks for a contact: `Magnetite/1.0 (you@example.com)` | `Magnetite/1.0` |
| `MAGNETITE_FPCALC` | Path to Chromaprint's `fpcalc` | found on the `PATH` |
| `MAGNETITE_ACOUSTID_KEY` | AcoustID application key; with `fpcalc`, turns on fingerprints for untagged albums | none |
| `MAGNETITE_CACHE_MAX_GB` | Size of the local cache for a library kept in Ferrite | 10 |

General settings that matter on a server:

| Variable | What it does |
|---|---|
| `APP_URL` | The public address, for example `https://music.example.com` |
| `SESSION_SECURE_COOKIE` | `true` when the site is served over https |
| `SESSION_LIFETIME` | Minutes until an idle session ends (120) |
| `TRUSTED_PROXIES` | Which proxies' forwarded headers are believed (Docker: the private address ranges) |

## The tools Magnetite calls

| Tool | Needed for | Without it |
|---|---|---|
| ffmpeg | Serving ALAC as FLAC; the file analysis | ALAC plays only in browsers that decode it (Safari); no analysis |
| ffprobe, Node | The file analysis | No analysis |
| fpcalc | Fingerprints for untagged albums | That step is skipped |

All of them are in the Docker image.

Fingerprints also need an AcoustID application key. It is free: register an application at [acoustid.org/new-application](https://acoustid.org/new-application) and put the key in `MAGNETITE_ACOUSTID_KEY`. Without the key, or without `fpcalc`, the fingerprint step is skipped.

## What is sent to other services

Magnetite contacts other services only when a look-up is asked for:

- **Find cover**: the artist and the album title, to MusicBrainz, the Cover Art Archive and iTunes.
- **Look up online** and **Suggest albums**: names guessed from the folder and files, track counts and lengths, to MusicBrainz.
- **Fingerprints** (only with an AcoustID key): a fingerprint and the length of a few tracks per folder, to AcoustID.

Audio is never sent.

## A library in Ferrite

A user's library can be kept in Ferrite (a self-hosted file server by the same author) instead of on the Magnetite server's disk. Magnetite then reads and adds files through Ferrite's API with a token that is limited to one folder.

    php artisan magnetite:storage yourname --ferrite=https://files.example.com
    php artisan magnetite:scan yourname --queue

The first command asks for the token and prints the folder, the access and the expiry it found. `--local` goes back to the local folder. The **Storage** page shows the setup, tests the connection and clears the cache.

The token is stored encrypted with the application key, so keep the key (in Docker: the `data` volume).

Next: [Troubleshooting](troubleshooting.md).
