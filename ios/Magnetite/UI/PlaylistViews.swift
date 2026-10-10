import SwiftUI

struct PlaylistsView: View {
    let api: Api
    @EnvironmentObject var store: PlaylistStore
    @State private var playlists: [PlaylistSummary] = []
    @State private var renaming: PlaylistSummary?
    @State private var renameText = ""
    @State private var deleting: PlaylistSummary?
    @State private var error: String?
    @State private var loaded = false

    var body: some View {
        List {
            ErrorLine(message: error).listRowBackground(Terminal.bg)
            ForEach(playlists) { p in
                NavigationLink(value: p) {
                    HStack {
                        RowText(title: p.name, subtitle: p.sharedBy.map { "from \($0)" })
                        Spacer()
                        Text("\(p.trackCount ?? 0)").font(.mono(12)).foregroundStyle(Terminal.muted)
                    }
                }.listRowBackground(Terminal.bg)
                .contextMenu {
                    if p.sharedBy == nil {
                        Button("Rename", systemImage: "pencil") { renameText = p.name; renaming = p }
                        Button("Delete", systemImage: "trash", role: .destructive) { deleting = p }
                    }
                }
                .swipeActions { if p.sharedBy == nil { Button("Delete", role: .destructive) { deleting = p } } }
            }
            if loaded && playlists.isEmpty { Text("No playlists yet.").font(.mono(13)).foregroundStyle(Terminal.muted).listRowBackground(Terminal.bg) }
        }
        .listStyle(.plain).terminalScreen()
        .navigationTitle("Playlists").navigationBarTitleDisplayMode(.inline)
        .navigationDestination(for: PlaylistSummary.self) { PlaylistView(api: api, summary: $0) }
        .toolbar { ToolbarItem(placement: .topBarTrailing) { Button { store.pending = .init() } label: { Image(systemName: "plus") } } }
        .refreshable { await load() }
        .task { if !loaded { await load() } }
        .onChange(of: store.items) { _ in Task { await load() } }
        .alert("Rename playlist", isPresented: Binding(get: { renaming != nil }, set: { if !$0 { renaming = nil } }), presenting: renaming) { p in
            TextField("Name", text: $renameText)
            Button("Cancel", role: .cancel) {}
            Button("Save") { Task { await run { try await api.renamePlaylist(p.id, name: renameText) } } }
        }
        .confirmationDialog("Delete playlist?", isPresented: Binding(get: { deleting != nil }, set: { if !$0 { deleting = nil } }), presenting: deleting) { p in
            Button("Delete “\(p.name)”", role: .destructive) { Task { await run { try await api.deletePlaylist(p.id) } } }
        } message: { _ in Text("The tracks stay in your library.") }
    }

    private func run(_ work: () async throws -> Void) async {
        do { try await work(); await store.refresh(); await load() } catch { self.error = error.localizedDescription }
    }

    private func load() async {
        do { playlists = try await api.playlists(); error = nil }
        catch let e as URLError where e.code == .cancelled {}
        catch { self.error = error.localizedDescription }
        loaded = true
    }
}

struct PlaylistView: View {
    let api: Api
    let summary: PlaylistSummary
    @State private var tracks: [Track] = []
    @State private var mine = false
    @State private var error: String?
    @State private var loaded = false
    @EnvironmentObject var store: PlaylistStore

    var body: some View {
        List {
            ErrorLine(message: error).listRowBackground(Terminal.bg)
            TrackList(tracks: tracks, source: PlaySource(kind: .playlist, id: summary.id), onMove: mine ? move : nil, onDelete: mine ? remove : nil)
        }
        .listStyle(.plain).terminalScreen()
        .navigationTitle(summary.name).navigationBarTitleDisplayMode(.inline)
        .toolbar {
            if mine && tracks.count > 1 { EditButton() }
            if loaded && !mine {
                Button("Copy to mine") { Task { do { try await api.copyPlaylist(summary.id); await store.refresh(); store.message = "Copied to your playlists" } catch { self.error = error.localizedDescription } } }
            }
        }
        .refreshable { await load() }
        .task { if !loaded { await load() } }
    }

    private func remove(at offsets: IndexSet) {
        let before = tracks
        let ids = offsets.map { tracks[$0].id }
        tracks.remove(atOffsets: offsets)
        Task {
            do { for id in ids { try await api.removeFromPlaylist(summary.id, trackId: id) }; await store.refresh() }
            catch { tracks = before; self.error = error.localizedDescription }
        }
    }

    private func move(from: IndexSet, to: Int) {
        let before = tracks
        tracks.move(fromOffsets: from, toOffset: to)
        let ids = tracks.map(\.id)
        Task {
            do { try await api.reorderPlaylist(summary.id, trackIds: ids) }
            catch { tracks = before; self.error = error.localizedDescription }
        }
    }

    private func load() async {
        do { let p = try await api.playlist(summary.id); tracks = p.tracks ?? []; mine = p.mine ?? false; error = nil }
        catch let e as URLError where e.code == .cancelled {}
        catch { self.error = error.localizedDescription }
        loaded = true
    }
}

struct SharedView: View {
    let api: Api
    @State private var shared = Shared()
    @State private var error: String?
    @State private var loaded = false

    var body: some View {
        List {
            ErrorLine(message: error).listRowBackground(Terminal.bg)
            if !shared.albums.isEmpty {
                Section { ForEach(shared.albums) { a in NavigationLink(value: a) { AlbumRow(album: a) }.listRowBackground(Terminal.bg).contextMenu { AlbumMenu(album: a) } } } header: { header("Albums") }
            }
            if !shared.playlists.isEmpty {
                Section {
                    ForEach(shared.playlists) { p in
                        NavigationLink(value: p) { RowText(title: p.name, subtitle: p.sharedBy.map { "from \($0)" }) }.listRowBackground(Terminal.bg)
                    }
                } header: { header("Playlists") }
            }
            if !shared.tracks.isEmpty {
                Section { TrackList(tracks: shared.tracks) } header: { header("Tracks") }
            }
            if loaded && shared.albums.isEmpty && shared.playlists.isEmpty && shared.tracks.isEmpty {
                Text("Nothing has been shared with you yet.").font(.mono(13)).foregroundStyle(Terminal.muted).listRowBackground(Terminal.bg)
            }
        }
        .listStyle(.plain).terminalScreen()
        .navigationTitle("Shared").navigationBarTitleDisplayMode(.inline)
        .navigationDestination(for: Album.self) { AlbumView(api: api, album: $0) }
        .navigationDestination(for: PlaylistSummary.self) { PlaylistView(api: api, summary: $0) }
        .refreshable { await load() }
        .task { if !loaded { await load() } }
    }

    private func header(_ s: String) -> some View { Text(s.uppercased()).font(.mono(12, weight: .semibold)).foregroundStyle(Terminal.muted) }

    private func load() async {
        do { shared = try await api.shared(); error = nil }
        catch let e as URLError where e.code == .cancelled {}
        catch { self.error = error.localizedDescription }
        loaded = true
    }
}
