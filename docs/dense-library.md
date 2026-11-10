# Dense library: the TUI's speed and looks on the web

Decided 2026-11-10 after a long design talk. Mockup (interactive, example data): `docs/mockups/dense-library.html` (also published as an Artifact; open the file in a browser). Nothing is built yet except the Graphite theme.

**Decision (2026-11-10): this becomes the UI of Magnetite. The old library layout is dropped, with no setting to switch back.** The edit table is approved as in the mockup.

## Status

- **Phase 1 done (2026-11-10, branch `dense-library`, uncommitted):** `users.ui` and `PUT /settings/ui`; `GET /library` answers JSON as well as HTML (`LibraryController` builds one data array: folders, albums with their tracks and DR, loose tracks, shares, suggestions); the library page is the new two-pane view (`resources/views/pages/library.blade.php`, `resources/js/magnetite/dense/`: `rows.js` and `keymap.js` are pure and tested, `component.js` is the Alpine component); the old page and its `album-section`, `track-list` and `folder` partials are gone. Working: tree with folders (open state kept under the old localStorage keys), tracks pane, `j k h l g G Tab Enter x v N r d s A e S Esc`, toolbar, click, double-click, Ctrl-click, drag onto a folder, long-press select mode on touch, the move list, new folder and rename, delete with confirm (album, track, folder), share, add to playlist (toolbar `+`), analyse with progress, edit details (the old simple dialogs, to be replaced by the edit table), the player's `,` and `.` seek. 58 Node tests, 403 Pest tests. Checked in headless Chrome with real key and pointer events (keys, mouse, drag, long-press, a 390 px layout, `wire:navigate` away and back with the same audio element). Not checked: Safari, a real phone, a library of thousands of albums (every row is drawn).
- **Phase 2 done (2026-11-10, same branch, uncommitted):** the info level and the cover choice (`Alpine.store('ui')` in `dense/ui.js`, saved by `PUT /settings/ui`, keys `I` and `P` work on every page); `dense/info.js` (pure, tested) builds the format, DR, loudness and findings lines; the player bar now shows a 64 px cover (real, or a 32 x 32 mosaic from `dense/cover.js`, default on in Terminal and Graphite through a new optional `pixel` key in the theme bundle), title and artist, album and "track n of N", the format line (info 1 and up) and the findings (info 2); the library lists show DR (albums, folders as the mean, tracks) and the `!` marks and finding labels by level, `o` filters to albums and tracks with findings, `s` also sorts by DR (not while info is off), and `l` in the tracks pane opens the measurements and spectrum of the selected file. `n` and `p` are lowercase only now. 69 Node tests, 407 Pest tests; checked in headless Chrome (the lines in the bar, the levels, saved to the account, `P`, the mosaic canvas, the filter, the detail panel, DR sort).
- **Phase 3 done (2026-11-10, same branch, uncommitted):** the terminal client's meter keys in the web player, on every page: `m` / `M` change the active panel's meter (never one that another panel shows; with no meters `m` brings one back), `+` / `-` add and remove a panel (0 to 4, the last one going leaves the cover the room), `[` / `]` choose the active panel (`Tab` too in full screen; the active one has an amber frame when there are several), `c` takes the meter colours from the playing cover, `t` / `T` change the theme; a short note in the bar says what a key did. The panel choice and meters are saved as before (`users.meters`); the tint is `users.ui.tint` (off until chosen, unlike the terminal client, so existing looks do not change). The palette method is a port of the terminal client's (`dense/palette.js`, 8 Node tests), eased in over about a quarter second. Full screen (`f`) already had the meters-or-cover layouts; it now also shows the details lines, and the library ignores keys while the player is full screen. Digits still seek. 77 Node tests, 408 Pest tests; checked in headless Chrome with real keys (all of the above, saved meters, the tint turning the green meters red on the red test cover, full screen with two meters and with none).
- **Phase 4 done (2026-11-10, same branch, uncommitted):** the command line (`:`; `dense/commands.js` reads and completes a line, 6 Node tests; Tab completes names, folder names, themes, sort orders and info levels; commands: analyze, details, edit, cover, merge, group, suggest, move, new, rename, delete, share, unshare, sort, problems, info, pixel, tint, theme, full, help; aliases `analyse`, `mv`, `rm`, `mkdir`); the edit table (`e`, in an album or on a track of it; album title, artist, release date and label, and per track the number, title and artist; Title Case, Clear track artists, Reset; arrows and Enter move between cells, Ctrl+Enter saves, Esc cancels; saved by the new `PATCH /library/albums/{album}/details`, one transaction, 3 Pest tests; `dense/text.js` has the title-casing); "Details…" (`i`), the MusicBrainz lookup as a tick-box diff, ported to the new page; for "Without album tag": suggestions with Use this / Dismiss, `x` marks tracks, `:group` names an album for the marked (or all) of them; `C` / Cover finds a cover (the album shows a small cover in the tracks pane, real or mosaic); `:merge` joins the marked albums into the selected one after a confirmation; the toolbar has Details, Cover and › Command for touch. The edit-details dialog remains only for single tracks without an album. 84 Node tests, 411 Pest tests; checked in headless Chrome (the command line and its completions, ten commands, the table with keys, saving, cancel, a refused clash that changes nothing, the details diff state, grouping, merging). Not checked: the real MusicBrainz and cover lookups (the network), the identify job's suggestions end to end.
- **Phase 5, search and the truth view, done (2026-11-10, uncommitted, after the first commits of this branch):** `GET /search` on the session (`SearchController`, the same `LibrarySearch` and filters as the terminal client's API route: own music and the shared area, the player's track shape, DR only for your own tracks; 5 Pest tests). `/` (or `:search text`, or the Search button) opens a box at the bottom; results come as you type (two letters or more, 250 ms debounce, an older answer is dropped), the albums first, then the tracks; Enter or the down arrow goes to the results, `j k g G` move and the next page is fetched near the end, Enter plays a track (the track hits are the queue) or opens one of your albums in the tree (a shared one goes to `/shared`), `/` edits the text again, Esc closes. The truth view (`:truth`, the Truth button): every file in one flat list (`dense/truth.js`, pure, tested), worst first; `s` cycles findings, DR, loudness, true peak and title, `o` keeps the files with findings, Enter plays from there (the list is the queue), `A` analyses the file, the measurements of the selected file fill the right pane, and the toolbar buttons that act on the tree are disabled. The keymap has a table per mode (`SEARCH`, `TRUTH`). A fix on the way: `$root` and `$refs` belong to the element that runs a handler, so a handler inside a template that has gone (the command line) found nothing after `$nextTick`; `afterUpdate()` takes the root first. 97 Node tests, 416 Pest tests; checked in headless Chrome (typing, results, filters, playing, opening an album from a hit, `:search`, `:truth`, sorting, the filter, playing from the truth list, the disabled buttons). Not checked: the second page of results (needs more than 25 albums or 50 tracks).
- **`/shared` and `/playlists`:** not moved onto the component (decided while building phase 5): they work as before in the old style and would need their own, leaner component (read-only albums by who shares them, copy to library; playlists with drag ordering and sharing), not the library's. Moved to phase 6 as a restyle of the same look and keys; say if they should be dropped from the plan instead.
- **Left from the parity list, for the later phases:** `/shared` and `/playlists` on the same component, search, the truth table, the `?` help does not list the library's keys yet. (Phase 2 did the problems filter, the detail panel, DR sorting and the findings flags.)
- Differences from the mockup: the move list opens on `v` as planned, but `m` is still the player's "meters on/off" until phase 3; the arrows move in the library (they used to seek 5 s: `,` and `.` seek 10 s now, as in the TUI); `Shift+Tab` leaves the library so focus can reach the toolbar.

## What it is

A replacement for the body of the existing `/library` page, not a new page and not a fork. Same route, nav, folders, share and playlist features, same `@persist('player')` block and one audio graph. Because the old layout goes, the dense view has to do everything the old one does before it is removed (see Parity below); the same component then also serves `/shared` (read-only, no owner actions) and `/playlists` (a playlist as the left pane's source). Upload, Storage, Themes and the other pages keep their own layouts and take the theme. The dense view has:

- **Two panes**: folders and albums as one tree on the left, the tracks of the selected album on the right, titled borders, one-line rows, a selection bar, a status line of key hints. On phones the panes stack.
- **A toolbar** that does everything the keys do (Play, Select, Move…, Analyze, Identify, Edit, Meters, Full, Info, › Command, ⋯), so mouse and touch never need a key. Mouse: click, double-click plays, Ctrl/⌘-click marks, drag onto a folder moves. Touch: tap, long-press to mark (select mode), buttons grow to 40–44 px.
- **A now-playing dock** with transport (⏮ ⏯ ⏭, seek by clicking the line), the cover (real or pixelated), the meters (1 to 4 panels, or none), and the format, DR and findings lines.
- **Full screen** (`f`): with meters they fill the screen and the cover is small; without meters a big centred cover with the details under it.
- **A command line** (`:`) for the rare things, acting on the marked albums or the selected row.
- **An edit table** (`e`): album title, artist, year and every track title as one grid.
- **An identify diff** (`i`) and an **analyze queue** (`A`) with progress in the status line.
- **Info levels** (`I`, a button): off (no DR, flags, format or findings anywhere), DR, all.
- **Any theme** (`t` / `T`), because the layout reads the theme's colours, font and radius. Graphite (grey, coral, amber) is the new built-in one, added 2026-11-10 to `themes.json`, Android's list and the TUI test.

## Keys (the TUI's, plus the library's)

| Keys | Action |
| --- | --- |
| `j` `k` `h` `l`, arrows | move, close / open folder (`h` on an album goes to its folder) |
| `Tab` | next pane (in full screen: next meter panel) |
| `Enter` | play an album / track, or open / close a folder |
| `x`, `v` | mark an album, move the marked (or the selected) into a folder |
| `N` `r` `d` | new folder, rename, delete (asks) |
| `/` `\` `s` | search, find in the list, sort (name, year, DR) |
| `A` `i` `e` | analyze, identify, edit table |
| `space` `n` `p` `,` `.` | play/pause, next, previous (restarts after 3 s), seek -10 / +10 s |
| `m` `M` `+` `-` | next / previous meter on the active panel, add / remove a panel (0 to 4) |
| `c` `t` `T` `P` `I` `f` | meter colours from the cover, theme, pixelate the cover, info level, full screen |
| `:` `?` `Esc` | command line, help, back |

### Conflicts with today's player shortcuts (`onKey()` in `boot.js`)

- `m` used to toggle all meters off and on. It is "next meter" now, as in the TUI (with no panels it brings one back); removing the last panel is `-`. `toggleMeters()` stays for the button that calls it. (Done in phase 3.)
- `n` / `p` used to also answer to `N` / `P`; lowercase only now, so that `N` is new folder and `P` is pixelate. (Done in phase 2.)
- Digits `0`–`9` today seek to that tenth of the track. They stay. The mockup uses `1`–`4` to choose a meter panel; the build uses `[` and `]` instead (and `Tab` in full screen). **Change the mockup when this is built.**
- The dense keys are active only while focus is inside the dense component; `onKey()` stays the one place for player keys and the dense component hands those on to it, so nothing is handled twice.

## Data and settings

- Per-user UI settings: one JSON column `users.ui` (`info` 0 to 2, default 2; `pixel` bool or null for the theme's default), saved by `PUT /settings/ui` like `users.meters`, handed to the page in `window.magnetite`. Guests get the defaults. There is no `dense` setting: it is the only layout.
- DR in the lists: album DR = the mean of its analysed tracks, folder DR = the mean of the albums below; the flags come from `FileTruth::flags()` and the colour from `FileTruth::drClass()`. Computed on the server in `LibraryController` and sent with the page data, so the thresholds stay in `FileTruth`. Not analysed shows a dash.
- The cover: real by default, pixelated when `pixel` is true or the theme asks (`pixel` in the Terminal and Graphite bundles; new optional key in `themes.json`, ignored by the other clients). The mosaic is drawn on a canvas from the cover the page already loads; the cover file and the cover search do not change.
- Meter colours from the cover (`c`): the web needs a small palette function like the TUI's `cover::palette`; it only feeds the visualizers' `colors`.
- Existing endpoints are reused: `POST/PATCH/DELETE /library/folders`, `PUT /library/albums/{album}/folder`, `PATCH /library/albums/{album}` and `/library/tracks/{track}`, `POST /library/albums/{album}/titles/find` and `/titles`, `/identify`, `/covers/find`, `/library/albums/{album}/merge`, `POST/GET /analysis`. New: `PUT /settings/ui`, and a batch save for the edit table (`PATCH /library/albums/{album}/details`: album fields and the changed tracks in one transaction, so a half-saved table cannot happen).
- Search (`/` and the filters `year:`, `dr:`, `codec:`, `artist:`, `album:`) is `LibrarySearch` behind `/api/v1/search`, which is Bearer-token only. The web needs the same controller on a session route (`GET /search`); the controller already calls `$request->user()`.

## Rules to keep

- Livewire and Alpine rules from `CLAUDE.md`: the dense component is an `x-data` that may use `init()` and `destroy()` (never an action called `destroy`), runs again on every `wire:navigate`, and passes the player a copy of its track array (`setQueue([...tracks], i)`).
- Who may use what is still `Track::visibleTo()` and the policies. The dense view only shows what the page data already allows; owner-only actions (move, edit, delete, identify) are not drawn for shared albums.
- The edit table keeps the mockup's look. To lose nothing from today's "Edit details…" dialogs it also carries what they have: release date (`YYYY-MM-DD`) and label as two more optional album fields in the header row (hidden on phones' narrow layout behind a "more" toggle), and the track number as the editable `#` cell. Per-track artist stays, as it is `tracks.artist`.
- Files are never overwritten and nothing in the dense view touches them: edits are database fields, as in `docs/editing.md`. Deleting still asks, and names the count.
- Fast keys and destructive actions: delete, merge and multi-album move go through the app's own dialog with the count; reversible ones get a toast with `u` to undo (a build decision: keep the previous values in memory for one step).
- `prefers-reduced-motion`: the meters draw one frame and stop on changes, as in the mockup.
- Rebuild assets (`npm run build`) after editing Blade views.

## Parity: what the old layout does that the dense one must do first

Checked against `library.blade.php` and its partials on 2026-11-10. The old views (`album-section`, `track-list`, `folder` and the page's Alpine data) are deleted as each line is done and checked; phase 6 is the final walk through.

- Own library tree with folders, open state remembered, the folder holding the playing album marked; new folder, new subfolder, rename, move folder, delete folder (contents move up); move an album into a folder.
- Per album: play, expand tracks, share / unshare, copy from the shared area, add all to a playlist, edit details (title, artist, release date, label), find details online (the MusicBrainz tick-box dialog), find cover, analyse, delete (confirm).
- Per track: play, share / unshare, add to a playlist, edit (title, artist, number), analyse, delete, the detail panel with the spectrum and the file-truth findings.
- "Only files with problems" filter, DR badges, the problem labels and duplicates / other copies.
- Albums without a tag: "Suggest albums", the track-by-track view, "Use this" / "Dismiss"; loose tracks; group tracks into an album; merge albums.
- Analysis progress, scan status after an upload, the empty state of a new library (a link to Upload).
- Phones and touch: everything above reachable without a keyboard.
- `/shared` and `/playlists` on the same component (share and copy; add, order, remove, rename, delete and share a playlist; drag ordering keeps its SortableJS handle and the up/down buttons).

## Build order

No feature flag and no second layout: the dense view is built straight into `/library`, replacing the old body as the phases land (the old views go away piece by piece, once the dense view does what they did; the parity list is the guide). Work happens on a branch and each phase is merged when it is usable, so the live site never runs a half-built library.

1. **Shell and keyboard.** `users.ui` and `PUT /settings/ui`; the two-pane view in a Blade partial plus `resources/js/magnetite/dense/` (pure modules for the visible-row model, tree navigation, sorting and the keymap, so Node can test them; one Alpine component for the DOM); the tree with folders, `j k h l Tab Enter x v N r d`, the toolbar, mouse (click, double-click, Ctrl-click, drag onto a folder) and touch (tap, long-press select mode); the existing move, rename, delete and new-folder requests. Phones stack the panes.
2. **Dock, DR and info levels.** Now-playing dock with transport and clickable progress, the format and findings lines, `I` levels (the lists lose their DR column and flags at off), DR rollups and flags from the server, `s` sort, the cover and `P`, the problems filter, the detail panel.
3. **Meters on the fly and full screen.** `m M + - [ ] c`, 0 to 4 panels in the dock, the full-screen layouts with and without meters, the cover palette; resolve the shortcut conflicts above in `onKey()`; theme `t` / `T`, Graphite in the picker.
4. **Command line, analyze and edit.** `:` with completion, the analyze queue (existing endpoints, polled), the identify diff over the existing find/apply endpoints, the edit table with the batch endpoint, share, copy, playlists, delete, merge, group and cover search from the toolbar and the command line; the "without album tag" suggestions.
5. **Search and the truth view.** (Done.) `GET /search` on the session, `/` with the TUI's filters, results as a flat list that opens the tree on the pick; a "truth" view of every file. `/shared` and `/playlists` moved to phase 6.
6. **Parity check and clean-up.** Walk the parity list above in headless Chrome and on a phone, delete whatever old Blade partials and Alpine data are left, update `CLAUDE.md` and `PLAN.md`; help overlay, `role="tree"` and `aria-activedescendant`, focus after dialogs, Safari, the undo toast.

## Checks

- Pest feature tests of the old page that check its markup are rewritten for the new one as the matching phase lands (the behaviour tests of the endpoints stay as they are).
- Node tests (`npm run test:js`) for the row model, tree navigation (open, close, parent, marks across folders), sorting by name, year and DR (not analysed last), and the keymap (no key bound twice in a mode, no dense key reaching a text field).
- Pest tests: `PUT /settings/ui` validation and guests, the DR rollups (mean, not analysed, shared albums hide nothing the viewer may not see), the batch save (all or nothing, 404 for albums that are not the user's), `GET /search` scoping (own and shared only).
- Headless Chrome with real key and pointer events (see the memory note on the CDP setup): navigate and play across `wire:navigate` without the audio element being recreated, folder open state, drag onto a folder, long-press, full screen and back, theme switch with `t` after a navigation. Not available there: Safari and a real phone; list them in `PLAN.md` as untested until done.

## Open questions

- `L` loudness matching exists in the TUI but not on the web: out of scope here.
- A "meter set" saved under a name (mastering, casual): parked.
- The Themes, Upload and Storage pages: leave their layouts (decided for now), revisit after phase 6 if they feel foreign next to the new library.
