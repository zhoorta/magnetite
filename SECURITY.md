# Security

## Reporting a problem

Please do not open a public issue for a security problem. Use GitHub's private reporting: the **Security** tab of this repository, then **Report a vulnerability**. Say which version or commit, what you did, and what happened.

This is a personal project, so there is no service level. Reports are read and answered on a best-effort basis, and a fix is released as soon as it is ready.

## What matters most

A Magnetite server hands out audio files and accepts uploads, so these are the parts to look at:

- **Streaming** (`/stream/{track}`, `/cover`, `/demo/{id}`): files are served only through the track id, with a type allowlist, `X-Content-Type-Options: nosniff` and `Content-Disposition: inline`. A path must never reach the client, and a request must never read outside the library.
- **Who may use a track:** own tracks and tracks in a share only (`Track::visibleTo()`, `TrackPolicy`); everything else answers 404, so a person cannot find out that a track exists. See `docs/multi-user.md`.
- **Upload** (`docs/auth-and-upload.md`): zips with audio and cover images only, nothing overwritten, unsafe paths abort the archive.
- **Theme files:** imported themes are checked (`validateTheme`) because their values end up in CSS variables.
- **Sign-in and tokens:** passwords are hashed, API tokens are Sanctum tokens, tokens for Ferrite stores are encrypted with `APP_KEY`.

## Running a server

- Put it behind a reverse proxy with TLS; set `APP_URL` and `SESSION_SECURE_COOKIE=true`.
- Keep the application key. Losing it makes stored tokens unreadable.
- Narrow `TRUSTED_PROXIES` to your proxy if the container's port is reachable directly.
- `/setup` creates the first administrator when there are no users; it is closed (404) after that. Set `MAGNETITE_SETUP_TOKEN` or create the user from the environment if the server is public before you visit it.

Only the latest release is supported.
