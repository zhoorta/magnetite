# Clients

Besides the web app there are three clients. They talk to the same server, show the same library, shared music and playlists, and use the list of meters saved in the account. They have the nine plain meters; the posters are in the web app, and the terminal client draws all but Circuit and Terrain.

All of them need the address of the server. Over the internet the server must use https.

## Android

One app for phones, tablets and Android TV. It needs Android 8 or newer.

- **Getting it.** A server can offer the APK on its start page. It is not on the Play Store, so Android asks to allow installs from the browser, and Play Protect may warn about an unknown developer. To build it instead: `cd android && ./gradlew :app:assembleDebug` (needs the Android SDK and a JDK).
- **Sign in on a phone or tablet.** Type the server's address, the username and the password.
- **Sign in on a TV.** The TV shows a code. On a phone or computer, sign in to the web app, open `/device` on the server and type the code.
- **What it has.** Library with folders, shared music, playlists, search, the meters, background playback with lock-screen controls, and D-pad navigation on a TV.

## iOS

A SwiftUI app for iPhone and iPad (iOS 17 or newer). It is not in the App Store and has to be built with Xcode 16:

    cd ios
    xcodegen generate
    open Magnetite.xcodeproj

It has the library with folders, shared music, search, playlists, the meters, and background playback with lock-screen controls. Plain `http://` works only for addresses on the local network.

## Terminal

A client written in Rust that decodes the audio itself, so its meters are computed from the decoded samples. It needs a Rust toolchain to build.

    cd tui
    cargo run --release -- login https://music.example.com
    cargo run --release

The first command shows a code; approve it at `/device` on the server, signed in to the web app. The second starts the player. The sign-in token is kept in `~/.config/magnetite/tui.json`.

It has the library, search with the same filters as the web app, the file-truth table, the meters, loudness matching and A/B compare. `?` lists the keys of the current view, and most are the same as in the web library (`j` `k`, `Enter`, `Space`, `n` `p`, `m` `M`, `w` `W` for the posters in the terminal client, `+` `-`, `f`).

Next: [Configuration](configuration.md).
