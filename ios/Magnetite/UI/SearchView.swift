import SwiftUI

struct SearchView: View {
    let api: Api
    @State private var query = ""
    @State private var result = Api.SearchResult()
    @State private var error: String?
    @State private var searched = false

    var body: some View {
        List {
            ErrorLine(message: error).listRowBackground(Terminal.bg)
            if !result.albums.isEmpty {
                Section { ForEach(result.albums) { a in NavigationLink(value: a) { AlbumRow(album: a) }.listRowBackground(Terminal.bg).contextMenu { AlbumMenu(album: a) } } } header: { header("Albums") }
            }
            if !result.tracks.isEmpty {
                Section { TrackList(tracks: result.tracks) } header: { header("Tracks") }
            }
            if searched && result.albums.isEmpty && result.tracks.isEmpty && error == nil {
                Text("No matches.").font(.mono(13)).foregroundStyle(Terminal.muted).listRowBackground(Terminal.bg)
            }
        }
        .listStyle(.plain).terminalScreen()
        .navigationTitle("Search").navigationBarTitleDisplayMode(.inline)
        .searchable(text: $query, placement: .navigationBarDrawer(displayMode: .always), prompt: "Title or artist")
        .navigationDestination(for: Album.self) { AlbumView(api: api, album: $0) }
        .task(id: query) {
            let q = query.trimmingCharacters(in: .whitespaces)
            guard q.count >= 2 else { result = .init(); searched = false; error = nil; return }
            try? await Task.sleep(for: .milliseconds(300))
            if Task.isCancelled { return }
            do { result = try await api.search(q); error = nil; searched = true }
            catch is CancellationError {}
            catch let e as URLError where e.code == .cancelled {}
            catch { self.error = error.localizedDescription }
        }
    }

    private func header(_ s: String) -> some View { Text(s.uppercased()).font(.mono(12, weight: .semibold)).foregroundStyle(Terminal.muted) }
}
