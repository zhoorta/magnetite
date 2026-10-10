import SwiftUI

/// "Add to playlist" with the user's playlists and a "New playlist…" entry (the name prompt lives in the root view).
struct AddToPlaylistMenu: View {
    var track: Track?
    var album: Album?
    @EnvironmentObject var store: PlaylistStore

    var body: some View {
        Menu {
            ForEach(store.items) { p in
                Button(p.name) {
                    if let track { store.add(track: track, to: p) } else if let album { store.add(album: album, to: p) }
                }
            }
            Button("New playlist…", systemImage: "plus") { store.pending = .init(track: track, album: album) }
        } label: { Label("Add to playlist", systemImage: "text.badge.plus") }
    }
}

struct TrackMenu: View {
    let track: Track

    var body: some View {
        if track.playable {
            AddToPlaylistMenu(track: track)
        }
    }
}

struct AlbumMenu: View {
    let album: Album

    var body: some View {
        AddToPlaylistMenu(album: album)
    }
}

/// The root view's name prompt for a new playlist and the short confirmation line.
struct PlaylistPrompts: ViewModifier {
    @EnvironmentObject var store: PlaylistStore
    @State private var name = ""

    func body(content: Content) -> some View {
        content
            .alert("New playlist", isPresented: Binding(get: { store.pending != nil }, set: { if !$0 { store.pending = nil } }), presenting: store.pending) { p in
                TextField("Name", text: $name)
                Button("Cancel", role: .cancel) {}
                Button("Create") {
                    let n = name.trimmingCharacters(in: .whitespaces)
                    if !n.isEmpty { store.create(name: n, track: p.track, album: p.album) }
                    name = ""
                }
            }
            .overlay(alignment: .top) {
                if let m = store.message {
                    Text(m).font(.mono(13)).padding(.horizontal, 14).padding(.vertical, 8)
                        .background(Terminal.surface).overlay(Rectangle().stroke(Terminal.accent)).foregroundStyle(Terminal.accent)
                        .padding(.top, 8).transition(.opacity)
                }
            }
            .animation(.easeInOut(duration: 0.2), value: store.message)
    }
}

extension View {
    func playlistPrompts() -> some View { modifier(PlaylistPrompts()) }
}
