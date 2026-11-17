# Magnetite

Magnetite is a self-hosted music player with loudness meters and file analysis. It plays the music on the server it is installed on, measures every file, and shows the result in the library.

It is meant for people who run a server (a small VPS, a NAS, a home machine) and keep a music folder on it. One Docker command installs it. It is a personal project, shared in case it is useful: issues and pull requests are read on a best-effort basis (see `CONTRIBUTING.md`). It can keep libraries in [Ferrite](https://github.com/zhoorta/ferrite), a self-hosted file server by the same author.

![The library with the Terminal theme: folders and albums, tracks with their dynamic range, and the player with two meters](public/screenshots/library.png)

## Meters and posters

Up to four meters show at once, next to the player or in full screen. There are twelve plain ones: wide meters, VU needles, LED meters, magic eyes, a scope, a goniometer, a loudness meter (LUFS and true peak as nixie digits), a K-meter, crest factor, loudness range, a third-octave analyser and a spectrogram. Loudness, true peak, phase correlation and the goniometer are computed sample by sample in an AudioWorklet, following ITU-R BS.1770, and the file analysis uses the same code, so the live reading and the stored numbers agree.

Posters are meters drawn like printed posters: flat shapes in a few colours, with the artist, title and album of the playing track on them, moving with the music. There are fifteen. They use the theme's colours or their own.

## File analysis

`magnetite:analyze` decodes each file once and measures dynamic range, integrated loudness, loudness range, true peak, clipped peaks, bit depth in use, the average spectrum and an audio hash. From these it reports findings: probable lossy sources, probable upsampling, padded bit depth, clipping, heavy compression, peaks over 0 dBTP, DC offset, decode errors and duplicates, even when the tags differ.

The truth view lists every analysed file in one table, worst first, with the findings of the selected file explained underneath. The analysis runs as its own step after a scan, on the server, and never changes the files. The thresholds and their limits are in `docs/guide/file-analysis.md`.

![The truth view: every file in one list, worst first](public/screenshots/truth.png)

## Library

- Two panes, a command line, an edit table and search with filters. Every key also has a button for touch.
- Untagged albums get suggested names from the folder and file names, MusicBrainz and, with an AcoustID key, audio fingerprints. Only fingerprints are sent, never audio.
- Each person has their own library and can share albums, tracks and playlists, play only. Zips of audio and covers can be uploaded.
- The player keeps playing across page changes. The queue and position are restored, paused, after a reload.
- Themes are JSON files: six colours, a corner radius and fonts. A studio edits a copy with a live preview; there are 16 bundled open fonts.

## Clients

One server, four clients. They share the library, the shared music, the playlists and the list of meters saved in the account.

| Client | What it is |
|---|---|
| Web | The full app: library, player, theme studio, all meters and posters |
| Terminal (`tui/`) | Rust. Decodes the audio itself and computes the meters from the samples. Truth table, search, loudness matching, A/B compare |
| Android (`android/`) | Phone, tablet and Android TV. Twelve meters, posters, lock-screen controls. APK, not on the Play Store |
| iOS (`ios/`) | SwiftUI, iPhone and iPad. Nine of the twelve meters, background playback. Built with Xcode |

## Compared to others

- **Navidrome** is the mature choice: a small Go server, very large libraries, the Subsonic API and many third-party clients. Magnetite has no Subsonic API yet (planned) and fewer clients; it adds per-file measurements (`magnetite:analyze`), the keyboard-first library and themes as files.
- **Koel** is a Laravel web player with a polished interface. Magnetite is closer to a workbench: meters, file truth and its own terminal, Android and iOS clients, less to a streaming-service look.
- **Feishin** is a desktop and web client for servers such as Navidrome and Jellyfin, not a server. Magnetite brings its own library, scanner and users.

What Magnetite does not do: no downloader or music store, no podcasts or radio, no Subsonic clients yet, and it has been run in production only by its author.

## Run it

Needs PHP 8.3 or newer with Composer, and Node. ffmpeg is needed for ALAC and for the file analysis.

    composer setup                                # dependencies, .env, the SQLite file, migrations, assets
    php artisan magnetite:user yourname           # asks for a password
    mkdir -p storage/app/music/users/yourname     # put the music here, or set MAGNETITE_MUSIC_PATH in .env
    php artisan magnetite:scan yourname           # reads the files
    php artisan magnetite:analyze                 # optional: dynamic range, loudness and findings for every file
    composer dev                                  # web server, queue worker and Vite

Open the address the server prints (`http://127.0.0.1:8000`, or the next free port) and sign in. A queue worker has to be running for scans started from the web; `composer dev` starts one. In a library with no music yet, the Upload page takes zips and has a "Scan now" button for files copied in by hand.

With Docker, one command and nothing to configure (the image is built for amd64 and arm64):

    docker run -d --name magnetite -p 8080:8080 -v magnetite-data:/data -v /path/to/music:/music \
      -e MAGNETITE_USER=me -e MAGNETITE_PASSWORD=change-me ghcr.io/zhoorta/magnetite

Open `http://localhost:8080` and sign in. Leave the two `MAGNETITE_` settings out and the first visitor creates the administrator in the browser. The music folder must be writable by uid 33 for uploads (the start-up log warns when it is not).

Or build it yourself with Compose:

    MAGNETITE_USER=yourname docker compose up -d --build
    docker compose logs app | grep Password       # the first user's password, printed once

Then open `http://localhost:8080`. `docs/docker.md` has the music folder, a chosen password, a reverse proxy and updating.

To deploy: `docs/docker.md` (one image, web server and worker in one container) or `docs/bare-metal.md` (nginx, PHP-FPM, systemd, as the demo server runs).

## Develop

    vendor/bin/pest            # PHP tests
    npm run test:js            # the meters, the library's row model and keys
    vendor/bin/pint --test     # style

`PLAN.md` has the status and what is open, `docs/README.md` indexes the topic docs, and `CLAUDE.md` holds the conventions that matter when changing the code (the persistent player, the visualizer contract, the streaming rules).

## License

Magnetite is free software, licensed under the [GNU Affero General Public License v3.0 or later](LICENSE) (AGPL-3.0-or-later). A modified version run as a network service must offer its source to the users of that service. The whole repository is under that one licence: the server, the web app, the terminal client (`tui/`), the Android app (`android/`) and the iOS app (`ios/`).
