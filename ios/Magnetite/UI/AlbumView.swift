import SwiftUI

struct AlbumView: View {
    let api: Api
    let album: Album
    @State private var tracks: [Track] = []
    @State private var error: String?
    @State private var loaded = false
    @EnvironmentObject var player: PlayerController

    var body: some View {
        List {
            HStack(spacing: 14) {
                CoverView(url: album.coverUrl).frame(width: 96, height: 96)
                VStack(alignment: .leading, spacing: 4) {
                    Text(album.title).font(.mono(17, weight: .semibold))
                    Text([album.artist, album.year.map(String.init)].compactMap { $0 }.joined(separator: " · ")).font(.mono(13)).foregroundStyle(Terminal.muted)
                }
            }
            .listRowBackground(Terminal.bg)
            ErrorLine(message: error).listRowBackground(Terminal.bg)
            TrackList(tracks: tracks, source: PlaySource(kind: .album, id: album.id), showNumbers: true)
        }
        .listStyle(.plain)
        .terminalScreen()
        .navigationTitle(album.title)
        .navigationBarTitleDisplayMode(.inline)
        .refreshable { await load() }
        .task { if !loaded { await load() } }
    }

    private func load() async {
        do { tracks = try await api.album(album.id).tracks ?? []; error = nil }
        catch let e as URLError where e.code == .cancelled {}
        catch { self.error = error.localizedDescription }
        loaded = true
    }
}
