# Open source and adoption plan

Draft 2026-11-10. Nothing here is built yet; `PLAN.md` has the checkbox. Revisit after each phase.

## Where we start

- Repo `zhoorta/magnetite` is **private**. AGPL-3.0-or-later in `LICENSE`, `composer.json`, `package.json`, `tui/Cargo.toml` and the README (one licence everywhere since 2026-11-13).
- CI (`.github/workflows/ci.yml`) already tests and **publishes a multi-arch image** (amd64 and arm64) to GHCR (`latest`, short sha, semver on `v*` tags). Private repo, so the image is private too.
- Install today: `docs/docker.md` (one container; the key, the database and the first user are made on the first start) or `docs/bare-metal.md` (nginx, PHP-FPM, systemd; written for one real server). The image is 1.7 GB on disk and about 450 MB to pull (2026-11-17; the layers are in `docs/docker.md`).
- The README is nine lines, no screenshots.
- `PLAN.md` positioning: "pitch the look, not the library"; stay standalone; an OpenSubsonic **client** was dropped (Feishin covers it).
- Clients: web, terminal (Rust), Android, iOS. All need a server. Only the web and the TUI are polished.

## What decides adoption (and what does not)

- **Laravel is not the barrier** if the first run is one command. Self-hosters use Docker; what hurts is anything that needs more than `docker run`: a hand-made `APP_KEY`, a second container, a manual user, a private image.
- **Navidrome's edge** is a single binary, a very good scanner, and the **Subsonic API**: dozens of ready-made apps work with it, so people try the server with the app they already like. Magnetite's API is its own, so a newcomer has no client except ours.
- **Our edge** is not the library. It is the look (themes as shareable JSON, live meters that measure real audio), **file truth** (DR, LUFS, true peak, clipping, lossy-source and duplicate detection, A/B compare) and a terminal client. Audience: people who care about their files. Smaller than "Spotify at home", easier to stand out in.
- **A rewrite in Rust does not by itself bring users.** It removes install friction for the "download one file" crowd only. See phase 6.
- **Adoption does not have to be the goal.** A clean, documented repo is worth having for a freelance profile even with few users. That points at polish and docs before features.

## Phases

Each phase pays off alone; stop whenever it stops being worth it. Effort: S = hours, M = days, L = weeks.

### 0. Decisions (S)
- [x] **One licence for the whole repo (decided 2026-11-13): AGPL-3.0-or-later**, for the server, web, `tui/`, `android/` and `ios/` (protects a hosted service from being resold; `stackcare-hub` is AGPL too). Done: `LICENSE`, `composer.json`, `package.json`, `tui/Cargo.toml`, README. Still to do before publishing: a licence line in the Android and iOS apps' about screens, and check that dependencies are compatible. If outside contributions are wanted: DCO sign-off in `CONTRIBUTING.md` (no CLA).
- [x] **Who is "anyone"?** (written into the README 2026-11-16: people who run their own server) Self-hosters (Docker is enough: phases 1 to 3) or people who want a double-click app (phase 4 and 6). Write the answer at the top of the README.
- [x] **Support promise** (README and `CONTRIBUTING.md`, 2026-11-16): "personal project, best effort" in the README and `CONTRIBUTING.md`, so issues do not become an obligation.
- [ ] **Audit before going public.** The repo is private today, so: scan the git history for tokens, keys, `.env` files, personal paths, hostnames and e-mail addresses (`gitleaks` or `trufflehog` over all commits); decide what to do with the deploy-specific text in `docs/bare-metal.md`, `docs/docker.md`, `docs/tui.md`, `PLAN.md` and `CLAUDE.md` (mentions of the live server and of Ferrite): generalize, or keep as an example; make sure `.env.example` and fixtures contain nothing private; check the fixtures' audio files are free to distribute.
    - Audit run 2026-11-16 over all 258 commits and 6 branches (`git log --all -p`, regular expressions; gitleaks and trufflehog were not installed, so run one of them too before going public). **Found nothing:** no private keys, cloud or GitHub tokens, no `APP_KEY` value, no password or token assignments (the keystore password is read from Gradle properties), no `.env`, `.jks` or `.pem` ever committed, no home paths. `.env.example` and `.env.docker.example` hold placeholders only. The fixtures are one-second synthetic tones (ffmpeg, tags such as "Test Album" by "The Testers"), free to distribute.
    - **Found, and settled the same day:** (1) every commit carried the author e-mail address (a personal address), public once the repo is; (2) eight APKs, about 100 MB, are in the history (`public/downloads/magnetite-0.1.0` to `0.4.0`, no longer in the tree), which makes every clone heavy; (3) deploy-specific text: the live demo hostnames in `docs/bare-metal.md`, `docs/tui.md`, `docs/README.md`, `docs/mockups/dense-library.html`, `config/magnetite.php`, `CLAUDE.md`, `README.md`; the repo name `zhoorta/magnetite` in two docs; the username `jose` as an example in docs. (1) and (2) were fixed by rewriting history (`git filter-branch`: every author and committer address is now the GitHub noreply one, `public/downloads/*.apk` dropped; the tree of the last commit is identical) and a force push; the live server needs a one-time `git fetch origin && git reset --hard origin/main`. (3) The tree now uses `music.example.com` and `files.example.com`; older commits still name the demo hostnames. Gitleaks (Docker image `zricethezav/gitleaks`, `git /repo --log-opts="--all"`) over the new history, 2026-11-16: 278 commits, all branches, no leaks found. Run it again just before the repo is made public.

### 1. Try it in five minutes (M)
Goal: from nothing to playing a track with three commands, on amd64 and arm64.
- [ ] **Public image.** Make the repo (or just the package) public so `ghcr.io/…/magnetite` can be pulled without login; tag releases `vX.Y.Z` (CI already does the rest).
- [x] **No manual `APP_KEY`** (done 2026-11-13; checked on a built image: created once, unchanged after a restart, the old session stays valid). On first start the entrypoint creates one and keeps it in `/data` (never regenerate if it exists; document that losing it makes stored Ferrite tokens unreadable, as `docs/docker.md` already says).
- [x] **One container** (done 2026-11-13): the queue worker runs beside the web server from the entrypoint, in a loop that starts it again if it stops (no s6-overlay). `MAGNETITE_WORKER=0` plus a second service gives the old two-container setup. A scan started from the web ran through it.
- [x] **First-run user** (done 2026-11-13; `magnetite:user --generate --if-missing`, tested with a generated and a chosen password, and not duplicated after a restart). If there are no users, create one from `MAGNETITE_USER` and `MAGNETITE_PASSWORD` (or print a one-time sign-in link in the logs). Since 2026-11-16 there is also `/setup` (`SetupController`): with no users at all, the first visitor creates the administrator; 404 afterwards; optional `MAGNETITE_SETUP_TOKEN`; the start-up log says where to go. The env route stays for those who expose the server before visiting it.
- [x] **The command, in the README** (2026-11-16; it uses `ghcr.io/zhoorta/magnetite`, which works once the package is public):

        docker run -d -p 8080:8080 -v magnetite-data:/data -v /path/to/music:/music \
          -e MAGNETITE_USER=me -e MAGNETITE_PASSWORD=change-me ghcr.io/…/magnetite

- [x] **Healthcheck**, sane defaults for `APP_URL` behind a proxy, and a clear message when `/music` is not writable (the uid problem in `docs/docker.md`).
- [x] **Test it like a stranger** (2026-11-16: `bin/smoke-docker.sh`, run by the `smoke` job in CI; checked locally on a built arm64 image: plain `docker run`, healthy, `/up`, `/setup` closed, a private route refused, sign-in, restart, sign-in again): a script that starts the image on an empty machine (CI job, `docker run` plus a request to `/up` and a sign-in), so the quick start cannot rot.
- [ ] Smaller image, later (planned 2026-11-17, not urgent: the pull is about 450 MB). In order of gain: drop Node (122 MB) once a Rust measure binary replaces `bin/measure.mjs` (phase 4); a slimmer ffmpeg (the Debian package pulls many codec libraries; check that ALAC conversion and the analysis, which also uses ffprobe, still work); Alpine is not worth it (musl with FrankenPHP and ffmpeg). The Dockerfile has no waste otherwise (multi-stage, `.dockerignore` leaves out the clients, docs and tests).

### 2. First impressions (M)
- [~] **README that sells it** (2026-11-16: who it is for, `docker run`, an honest comparison with Navidrome, Koel and Feishin are in; still wanted: a TUI screenshot and a GIF of the meters): one screenshot or GIF each of the web player with a theme, the meters, the file-truth table in the TUI; what it is in three lines; the `docker run`; a short honest "compared to Navidrome / Koel / Feishin" (what we do, what we do not).
- [x] (2026-11-16, all written; turn on GitHub private vulnerability reporting for `SECURITY.md`) `CONTRIBUTING.md` (how to run the tests, `pint`, `pest`, `npm run test:js`, `cargo test`), `SECURITY.md` (how to report; streaming is the attack surface, `docs/multi-user.md` explains the 404 policy), `CODE_OF_CONDUCT.md`, issue and PR templates.
- [~] **Releases:** `CHANGELOG.md` started (2026-11-16, Unreleased); still to do: the first semver tag and a GitHub Release with notes.
- [x] **Docs index** (exists: `docs/README.md`, and the guide at `/docs`) (`docs/README.md`) so the many files are findable: install, multi-user, editing, file truth, clients, API.
- [ ] **A public demo** with Creative Commons music (optional; costs hosting and moderation, so only if it is wanted).
- [ ] The name: `docs/history.md` has the check (usable with caveats); re-check before going public.

### 3. The clients, installable (M)
- [ ] **TUI binaries** for macOS (arm64, x86_64), Linux and Windows with `cargo-dist` (CI workflow, install script, checksums), `magnetite --version`, a Homebrew tap. Linux builds need the ALSA development package; document the runtime package.
- [ ] **Android:** a signed APK attached to GitHub Releases (the landing page already links one); keystore handling documented, never committed.
- [ ] **iOS:** build-from-source instructions; TestFlight only if it is wanted (needs a paid developer account).
- [ ] Each client's README says which server version it needs (`GET /api/v1/me` could return an API version).

### 4. Something to try that needs no server (L)
The funnel: a person with only a folder of music gets value in thirty seconds, then stays for the rest.
- [ ] **`magnetite-core`** (a Rust workspace crate shared by the TUI and later tools): file measurements (LUFS, true peak, DR, clipping, spectrum cut-off, already native in the TUI), the file-truth thresholds (today only in PHP `FileTruth`), tag reading (`lofty` or symphonia), search word folding and ranking (today in PHP `LibrarySearch` and the TUI's `search.rs`), API types and client.
- [ ] **`magnetite truth <folder>`**: scan a folder and print the file-truth report (problems first) with no server and no database.
- [ ] **TUI `local` mode:** `magnetite local ~/Music` scans tags into a SQLite index and gives the same lists, search, truth table, A/B compare and meters, playing from disk.
- [ ] **A Rust measure binary** the server calls instead of Node's `bin/measure.mjs`: no Node in the image, faster analysis, one less dependency to install. Needs a test that both give the same numbers on the fixtures (`TrackAnalyzer::VERSION` rules apply).
- [ ] Keep the PHP and Rust thresholds from drifting: one table of cases in `tests/Fixtures` that both sides must pass.

### 5. Work with other people's apps (M)
- [ ] **A Subsonic / OpenSubsonic API on the server** (this is the **server** side; `PLAN.md`'s dropped item was a client). Read-only first: `ping`, `getLicense`, `getMusicFolders`, `getArtists`, `getArtist`, `getAlbum`, `getAlbumList2`, `search3` (reuse `LibrarySearch`), `stream`, `getCoverArt`, `getPlaylists`/`getPlaylist`, `getOpenSubsonicExtensions`. Writes (playlists, star, scrobble) later or as stubs.
- [ ] **Auth:** Subsonic's token-and-salt scheme needs the password in the clear on the server, which we do not keep. Use the OpenSubsonic `apiKey` extension, with the existing API tokens (`library:read` ability), and document the app setup per client.
- [ ] **Permissions:** everything goes through `Track::visibleTo()` and the policies, answered with 404 like the rest; a test per endpoint, as for `/api/v1`.
- [ ] **Try it with real clients** (Symfonium, DSub, play:Sub, Feishin, Substreamer) and list what works in the docs.
- [ ] The other direction, later: the TUI and the apps as Subsonic clients, so they also work against Navidrome (file truth appears only on a Magnetite server). Only if wanted: it is a second backend in each client.

### 6. One file for everyone? (decide after 4 and 5)
Not committed. Options, from smallest:
- **FrankenPHP static binary** with the Laravel app embedded: one executable, SQLite, the web UI keeps working; the analysis binary from phase 4 removes the last runtime dependency except ffmpeg for ALAC.
- **Rust server speaking `/api/v1` and Subsonic**, reading the same SQLite schema; the Pest API tests become a contract suite run against both servers. The Livewire web UI would not come along (the JS player, visualizers and themes can be served as static files). It is the most work and means two codebases for a while.
- **Desktop app** (Tauri around the Rust core) for the "double-click" crowd.
Decide with what phases 1 to 5 showed: did people ask for it, and is the PHP install still the complaint?

## Order

1. Phase 0 (decisions, audit) and 1 (the five-minute install): until these are done the project is not ready to be public.
2. Phase 2 (README, templates, releases), then make the repo public.
3. Phase 3 (client binaries) and 5 (Subsonic) in whichever order the first feedback suggests.
4. Phase 4 alongside, in small steps (`magnetite truth` first).
5. Phase 6 only on evidence.

## Risks

- **Scope creep and maintenance:** four clients and a server is a lot for one person. Say what is supported and what is "works on my machine".
- **Support load:** the "best effort" line, issue templates that ask for version and logs, a `docs/troubleshooting.md` that grows from real questions.
- **Legal:** Magnetite plays files the user owns; it ships no music (check the fixtures) and no downloader (decided out, 2026-11-10). Keep wording generic.
- **Security:** a public server's streaming and upload endpoints are the surface; keep the type allowlist, `nosniff`, the 404 policy and the strict upload rules (`CLAUDE.md`), and say so in `SECURITY.md`.
- **Two implementations of the same rules** (PHP and Rust) drift: the shared fixture cases in phase 4 are the guard.

## Open questions

- Is the goal users, or a clean public repo? (It changes how much phase 2 and 5 are worth.)
- AGPL for everything (decided 2026-11-13).
- Public demo: yes or no?
- Subsonic: which clients matter most (Android: Symfonium and DSub; iOS: play:Sub, Substreamer; desktop: Feishin)?
