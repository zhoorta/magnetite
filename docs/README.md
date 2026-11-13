# Magnetite docs

Start with `PLAN.md` (short status) at the repo root. `history.md` is the long dated log of what was built and checked.

## Using and running it

| Doc | What |
|---|---|
| `guide/` | The guide for people who install and use it, shown on the site at `/docs` (pages listed in `App\Support\Guide`) |
| `docker.md` | One image, two containers (web and queue worker), SQLite in a volume |
| `bare-metal.md` | nginx, PHP-FPM, MySQL and systemd, as magnetite.stackcare.pt runs; deploy an update; the APK is copied by hand |
| `../CREDITS.md` | Credits and licences of the demo music on the start page |
| `auth-and-upload.md` | Sign-in, who can do what, zip upload rules |
| `multi-user.md` | Own libraries, the shared area, `magnetite:claim` |
| `ferrite-storage.md` | Keeping a library in Ferrite instead of on the server's disk |

## The web app

| Doc | What |
|---|---|
| `dense-library.md` | The keyboard-first library: keys, command line, edit table, search, truth view (mockup in `mockups/`) |
| `meters-and-file-truth.md` | The meters and how they are measured |
| `file-truth.md` | `magnetite:analyze`, findings and thresholds |
| `editing.md`, `identify-albums.md`, `covers.md` | Fixing details, naming untagged albums, finding covers |
| `posters.md` | Meters drawn like posters and book covers, with the track's name printed on them (mockup in `mockups/`) |

## Clients

| Doc | What |
|---|---|
| `android.md` | Phone, tablet and TV app, the `/api/v1` it uses |
| `ios.md` | SwiftUI client |
| `tui.md` | Terminal client in Rust |

## Looking ahead

| Doc | What |
|---|---|
| `open-source.md` | Plan to make it easy for other people to install and try, including the Subsonic API |
