import Foundation

/// The signed-in user's playlists, shared by every "Add to playlist" menu and the Playlists tab.
@MainActor
final class PlaylistStore: ObservableObject {
    @Published private(set) var items: [PlaylistSummary] = []
    @Published var message: String?
    /// Set by "New playlist…" menus; the root view shows the name prompt and calls [create].
    @Published var pending: Pending?
    struct Pending: Identifiable { let id = UUID(); var track: Track?; var album: Album? }
    private let api: Api

    init(api: Api) { self.api = api }

    func refresh() async {
        if let list = try? await api.playlists() { items = list.filter { $0.sharedBy == nil } }
    }

    func add(track: Track, to playlist: PlaylistSummary) { run("Added to \(playlist.name)") { try await self.api.addToPlaylist(playlist.id, trackId: track.id) } }
    func add(album: Album, to playlist: PlaylistSummary) { run("Added to \(playlist.name)") { try await self.api.addToPlaylist(playlist.id, albumId: album.id) } }
    func create(name: String, track: Track? = nil, album: Album? = nil) { run("Created \(name)") { try await self.api.createPlaylist(name: name, trackId: track?.id, albumId: album?.id) } }

    private func run(_ done: String, _ work: @escaping () async throws -> Void) {
        Task {
            do { try await work(); message = done } catch { message = error.localizedDescription }
            await refresh()
            try? await Task.sleep(for: .seconds(2.5))
            message = nil
        }
    }
}
