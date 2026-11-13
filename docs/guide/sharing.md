# Sharing and playlists

Each account has its own library. Nothing is visible to other people until it is shared.

## Sharing an album or a track

`S` on an album or a track shares it with everyone who has an account on the server; `S` again stops sharing. A shared album or track has a people icon next to its title.

- Sharing an album shares the album as it is now and as it changes: tracks added later are shared too.
- Other people can play what is shared. They cannot edit or delete it.
- The file analysis of a shared track is not shown to others.

## The Shared source

`w` switches the left pane to **Shared**: everything that is shared on the server, grouped by the person who shares it.

| Key | What it does |
|---|---|
| `Enter` | Play |
| `y` | Copy the selected album or track to the user's own library (the files are copied) |
| `S` | Stop sharing, on something the user shared |

## Playlists

A playlist is a list of tracks from the user's library and from what is shared. `w` switches the left pane to **Playlists**.

| Key | What it does |
|---|---|
| `N` | New playlist |
| `r` | Rename |
| `d` | Delete the playlist; in the tracks pane, remove the track from it |
| `J` `K` | Move the selected track down or up (or drag it) |
| `S` | Share the playlist, or stop sharing it |
| `y` | On someone else's playlist: copy it to the user's own playlists |

To add to a playlist from the library, select an album or a track and use the **+** button in the toolbar. It lists the playlists and has **New playlist…**.

## Sharing a playlist

A playlist is private until it is shared. Sharing a playlist does not share its tracks:

- A track that is not shared shows greyed out to other people, and they cannot play it.
- The playlist's owner sees how many tracks are not shared, with a button **Share those tracks too** (or the command `sharetracks`).
- A shared playlist follows its owner's changes. A copy made with `y` does not, and holds only the tracks the copier can play.
- When someone stops sharing a track that is in another person's playlist, the track is hidden there, and returns if it is shared again.

## Accounts

Accounts are made on the server's command line:

    php artisan magnetite:user ana
    php artisan magnetite:user ana --reset

The first asks for a password, the second changes it. Usernames are lowercase letters, digits, `.`, `_` and `-`. Each user's files are in their own folder, `users/{username}`, inside the music folder.

Next: [Clients](clients.md).
