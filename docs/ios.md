# iOS app

Status (2026-10-10): native SwiftUI client in `ios/`, same `/api/v1` as the Android app (see `docs/android.md`). iPhone and iPad.

- Library with folders (new, rename, delete, move an album from its menu), album page, Shared, Search (`/search`, 2+ letters), Playlists (create, rename, delete, remove a track by swipe, reorder your own, "Copy to mine" on a shared one), "Add to playlist" in the long-press menu of tracks and albums.
- Playback in the background with lock-screen and Bluetooth controls, mini player, Now Playing, local resume of the queue and position per user (restored paused).
- iPad: a sidebar (`NavigationSplitView`) in the regular size class, tabs on iPhone.
- Meters: all nine of the web's (ids as on the web), up to four, picked in the Meters sheet and saved with `PUT /api/v1/settings/meters` (read from `/me`), so web, Android and iOS share the list. The mini player shows them compact, Now Playing big. `MeterEngine` reads the audio with an `MTAudioProcessingTap` on the player item (it sits right before the output, so no playhead mapping as on Android); `Loudness` is the same port as `Loudness.kt` (LUFS, true peak 4x with a 12-tap filter, correlation, goniometer samples); `Spectrum` uses vDSP. Drawn with SwiftUI `Canvas` in `TimelineView`, stopped two seconds after pause. Checked on the simulators with a stereo test file; LUFS and true peak not compared with a reference, and frame rate not measured on a real device.
- Server: playlist create, rename, add/remove track and copy (`POST/PATCH /playlists`, `POST/DELETE /playlists/{id}/tracks`, `POST /playlists/{id}/copy`) and delete (`DELETE /playlists/{id}`, needs `library:delete`) are the web controller's actions registered under `/api/v1`, with tests in `ApiWriteTest`.

- Build: `cd ios && xcodegen generate`, open `Magnetite.xcodeproj`, run with ⌘R. `xcodegen generate` resets the signing team set in Xcode. iOS 17+, Xcode 16.
- Token in the Keychain (needs a signed build; command-line builds need `CODE_SIGN_IDENTITY=-`), server and user name in UserDefaults. Streams and covers send the Bearer token (`AVURLAssetHTTPHeaderFieldsKey`, `CoverLoader`). A 401 ends the session.
- Plain `http://` is allowed only for local-network addresses (`NSAllowsLocalNetworking`); the real server must be https.
- Debug builds read `MAGNETITE_AUTOLOGIN=server|user|pass`, `MAGNETITE_AUTOPLAY=1|full`, `MAGNETITE_ALBUM=title` and `MAGNETITE_TAB=0..3` to drive the simulator (pass as `SIMCTL_CHILD_…` to `simctl launch`).
- `DEVELOPMENT_TEAM` is in `project.yml`, so `xcodegen generate` keeps the signing team. Command-line builds need `CODE_SIGN_IDENTITY=-` or the Keychain refuses the token.
- Not done: drag and drop, CarPlay, other themes, sharing from the app, upload from the phone. Not tried on a real device yet.
