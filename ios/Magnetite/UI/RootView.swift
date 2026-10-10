import SwiftUI

enum AppSection: Int, CaseIterable, Identifiable {
    case library, shared, playlists, search
    var id: Int { rawValue }
    var title: String { ["Library", "Shared", "Playlists", "Search"][rawValue] }
    var icon: String { ["square.stack", "person.2", "music.note.list", "magnifyingglass"][rawValue] }
}

struct RootView: View {
    let api: Api
    @EnvironmentObject var session: Session
    @EnvironmentObject var player: PlayerController
    @EnvironmentObject var store: PlaylistStore
    @EnvironmentObject var meters: MeterStore
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var nowPlaying = false
    @State private var albumShown: Album?
    @State private var tab = Int(ProcessInfo.processInfo.environment["MAGNETITE_TAB"] ?? "0") ?? 0
    @State private var sidebar: AppSection? = .library

    var body: some View {
        Group {
            if session.signedIn {
                signedIn
                    .fullScreenCover(isPresented: $nowPlaying) {
                        NowPlayingView(api: api).playlistPrompts().sheet(isPresented: Binding(get: { meters.picking && nowPlaying }, set: { meters.picking = $0 })) { MeterPicker() } }
                    .playlistPrompts()
                    .sheet(item: $albumShown) { album in AlbumSheet(api: api, album: album) { albumShown = nil } }
                    .sheet(isPresented: Binding(get: { meters.picking && !nowPlaying }, set: { meters.picking = $0 })) { MeterPicker() }
                    .onAppear { player.restore() }
                    .task { await store.refresh(); await meters.load() }
                    #if DEBUG
                    .task {
                        // For checking on the simulator: MAGNETITE_AUTOPLAY=1 plays the first track of the first album.
                        guard ProcessInfo.processInfo.environment["MAGNETITE_AUTOPLAY"] != nil,
                              let page = try? await api.albums(page: 1),
                              let first = page.data.first(where: { $0.title == (ProcessInfo.processInfo.environment["MAGNETITE_ALBUM"] ?? $0.title) }),
                              let album = try? await api.album(first.id), let tracks = album.tracks else { return }
                        player.play(tracks, start: 0, from: PlaySource(kind: .album, id: album.id))
                        if ProcessInfo.processInfo.environment["MAGNETITE_AUTOPLAY"] == "full" { nowPlaying = true }
                    }
                    #endif
            } else {
                LoginView(api: api)
            }
        }
        .terminalScreen()
        .onAppear {
            api.onUnauthorized = {
                // The server refused the token: end the session, keep server and name for the sign-in form.
                player.signOut()
                session.clear()
            }
        }
    }

    @ViewBuilder private var signedIn: some View {
        if sizeClass == .regular {
            // iPad: sidebar and a detail stack; the mini player sits under both, outside the navigation containers
            // (inside the detail column it vanished when an album was pushed).
            VStack(spacing: 0) {
            NavigationSplitView {
                List(AppSection.allCases, selection: $sidebar) { s in
                    Label(s.title, systemImage: s.icon).tag(s)
                        .listRowBackground(sidebar == s ? Terminal.surface : Terminal.bg)
                        .foregroundStyle(sidebar == s ? Terminal.accent : Terminal.text)
                }
                .listStyle(.sidebar).terminalScreen()
                .navigationTitle("Magnetite")
            } detail: {
                NavigationStack { content(sidebar ?? .library) }.id(sidebar)
            }
            .tint(Terminal.accent)
            if player.hasQueue { MiniPlayer(open: { nowPlaying = true }, openAlbum: { albumShown = $0 }) }
            }
        } else {
            TabView(selection: $tab) {
                ForEach(AppSection.allCases) { s in
                    VStack(spacing: 0) {
                        NavigationStack { content(s) }
                        if player.hasQueue { MiniPlayer(open: { nowPlaying = true }, openAlbum: { albumShown = $0 }) }
                    }
                        .tabItem { Label(s.title, systemImage: s.icon) }.tag(s.rawValue)
                }
            }
        }
    }

    @ViewBuilder private func content(_ s: AppSection) -> some View {
        switch s {
        case .library: LibraryView(api: api)
        case .shared: SharedView(api: api)
        case .playlists: PlaylistsView(api: api)
        case .search: SearchView(api: api)
        }
    }
}
