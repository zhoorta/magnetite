# The library

The library page has two panes: folders and albums on the left, the tracks of the selected album on the right. The player is below them, and the bottom line shows the keys that apply to the selection.

Everything can be done with the keyboard, and every action also has a toolbar button, an entry in the **Actions** menu or a right-click menu. On a touch screen a tap on a track plays it, and a long press on an album starts marking.

## Moving and playing

| Key | What it does |
|---|---|
| `j` `k` or the arrows | Move down and up |
| `h` `l` | Close or open a folder |
| `g` `G` | Top and bottom |
| `Tab` | The other pane |
| `Enter` | Play the album or the track; on a folder, open or close it |
| `Backspace` | Back: from an album to its folder, from a folder to the one above |
| `z` | All albums as one list, without folders; again to go back |
| `s` | Order: by artist and year, by title, by year, by dynamic range |
| `?` | The full list of keys |

The player's keys work on every page: `Space` plays or pauses, `n` and `p` go to the next and previous track, the arrow keys seek 5 seconds (30 with `Shift`), and `0` to `9` jump to 0 % to 90 % of the track.

## Sources

The left pane shows one of three sources. `w` and `W` step through them, or use the three buttons at the left of the toolbar.

- **Library**: the music of the signed-in user.
- **Shared**: what everyone on the server has shared.
- **Playlists**: the user's own playlists and the shared ones.

[Sharing and playlists](sharing.md) covers the last two.

## Folders

Folders are a way to sort albums in the library. They do not move files.

| Key | What it does |
|---|---|
| `N` | New folder (inside the selected one) |
| `r` | Rename the folder |
| `x` | Mark an album; several can be marked |
| `v` | Move the marked albums, or the selected one, to a folder |
| `u` | Undo the last move |
| `d` | Delete; a deleted folder's albums move up one level |

Albums can also be dragged onto a folder. Folders can be nested up to 6 levels.

## Editing

`e` on an album opens the edit table: the album's title, artist, release date and record label, and one row per track with its number, title and artist.

- `↑` `↓` and `Enter` move between cells, `Tab` goes to the next field.
- `Ctrl+Enter` saves, `Esc` cancels. The table is saved as a whole or not at all.
- The buttons **Title Case**, **Clear track artists** and **Reset** change the whole table.

Edits change the library only, not the files or their tags, and a later scan keeps them.

`i` on an album looks it up on MusicBrainz by title and artist, and offers the release date, the record label and the track titles. Each can be ticked separately; a track title is only replaced when the track's length matches. Only the album title and artist are sent.

When the server has `fpcalc` and an AcoustID key, the album look-up also tries audio fingerprints if the title search finds nothing that fits, and the dialog lists what each step did (and shows its progress while it runs). `i` with a track selected in the tracks list looks up that one track by its fingerprint: AcoustID names the recording (title, artist) and the releases it is on, with the track number on each. Title, artist and track number can be ticked separately; only the library changes, never the file. Only a fingerprint and the length of that track are sent.

`C` looks for a cover for an album that has none (MusicBrainz and the Cover Art Archive, then iTunes). Only the artist and the album title are sent.

`:merge` joins the marked albums into the selected one, for an album that was split in two by its tags.

## Tracks without an album tag

Files with no album tag are listed under "Without album tag". Two ways to turn them into albums:

- `i` on that row suggests albums per folder, from the folder and file names and from MusicBrainz (and from an audio fingerprint when the server has an AcoustID key). Each suggestion shows the proposed title for every file; **Use this** applies it, **Dismiss** removes it. Nothing changes before that.
- Mark tracks with `x`, then `:group` names an album for them by hand.

## Deleting

`d` on an album or a track deletes it after a confirmation. This removes the files from the library folder. When the library is kept in Ferrite, the files are moved to Ferrite's trash.

## Search

`/` opens the search. It looks in the user's own music and in what is shared. Two letters or more are needed; `Enter` moves to the results, where `Enter` plays a track or opens an album.

| Filter | Meaning |
|---|---|
| `year:1983` `year:1960..1969` `year:>=1990` | The album's year |
| `dr:<8` `dr:>=12` `dr:8..10` | The track's dynamic range (own tracks only) |
| `codec:flac` `codec:flac,alac` | The file format |
| `artist:bowie` `artist:"david bowie"` | Words in the artist |
| `album:heroes` | Words in the album title |

Filters and plain words can be combined: `live year:1970..1979 codec:flac`.

## The command line

`:` opens a command line at the bottom. `Tab` completes, `Enter` runs, `Esc` closes. A command can be shortened as long as it is not ambiguous.

| Command | What it does |
|---|---|
| `analyze` | Measure the selected albums |
| `edit`, `details`, `cover` | The edit table, the online look-up, find a cover |
| `move NAME`, `new NAME`, `rename NAME`, `delete` | Folders and the selection |
| `merge`, `group`, `suggest` | Join albums, name an album, suggest albums |
| `share`, `unshare`, `copy`, `sharetracks` | Sharing |
| `library`, `shared`, `playlists` | Switch source |
| `sort`, `flat`, `tree` | Order, flat list, back to the folders |
| `search TEXT`, `truth`, `problems` | Search, the Truth view, the Findings view |
| `info 0`, `info 1`, `info 2` | How much is shown about the files |
| `theme NAME`, `tint`, `hints` | Theme, meter colours from the cover, the key hints |
| `shuffle`, `repeat`, `full`, `help` | Player |
| `upload` | The upload panel |

## How much is shown about the files

`I` (capital i) steps through three levels: nothing, the dynamic range only, or the dynamic range with the findings. The setting is kept per account.

Next: [Meters and posters](meters.md).
