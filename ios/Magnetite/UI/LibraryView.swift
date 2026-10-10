import SwiftUI

struct LibraryView: View {
    let api: Api
    @EnvironmentObject var session: Session
    @State private var folders: [Folder] = []
    @State private var albums: [Album] = []
    @State private var expanded: Set<Int> = []
    @State private var loaded = false
    @State private var error: String?
    @State private var prompt: Prompt?
    @State private var promptText = ""
    @State private var deleting: Folder?

    enum Prompt: Identifiable {
        case create(parent: Folder?), rename(Folder)
        var id: String { switch self { case .create(let p): "c\(p?.id ?? 0)"; case .rename(let f): "r\(f.id)" } }
        var title: String { switch self { case .create(let p): p == nil ? "New folder" : "New subfolder in \(p!.name)"; case .rename: "Rename folder" } }
    }

    var body: some View {
        List {
            ErrorLine(message: error).listRowBackground(Terminal.bg)
            ForEach(rows(parent: nil, depth: 0)) { row in
                switch row.kind {
                case .folder(let f):
                    Button { toggle(f) } label: {
                        HStack(spacing: 10) {
                            Image(systemName: expanded.contains(f.id) ? "chevron.down" : "chevron.right").font(.system(size: 12)).frame(width: 16)
                            Image(systemName: "folder").foregroundStyle(Terminal.accent)
                            RowText(title: f.name, subtitle: nil)
                            Spacer()
                            Text("\(f.albumCount ?? 0)").font(.mono(12)).foregroundStyle(Terminal.muted)
                        }
                        .padding(.leading, CGFloat(row.depth) * 18).contentShape(Rectangle())
                    }
                    .buttonStyle(.plain).listRowBackground(Terminal.bg)
                    .contextMenu {
                        Button("New subfolder", systemImage: "folder.badge.plus") { promptText = ""; prompt = .create(parent: f) }
                        Button("Rename", systemImage: "pencil") { promptText = f.name; prompt = .rename(f) }
                        Button("Delete", systemImage: "trash", role: .destructive) { deleting = f }
                    }
                case .album(let a):
                    NavigationLink(value: a) { AlbumRow(album: a).padding(.leading, CGFloat(row.depth) * 18) }
                        .listRowBackground(Terminal.bg)
                        .contextMenu { moveMenu(a); AlbumMenu(album: a) }
                }
            }
            if loaded && folders.isEmpty && albums.isEmpty {
                Text("No albums yet. Add music on the web, then pull down to refresh.").font(.mono(13)).foregroundStyle(Terminal.muted).listRowBackground(Terminal.bg)
            }
        }
        .listStyle(.plain)
        .terminalScreen()
        .navigationTitle("Library")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                Menu {
                    Text(session.username ?? "")
                    Button("Sign out", role: .destructive) { signOut() }
                } label: { Image(systemName: "person.crop.circle") }
            }
            ToolbarItem(placement: .topBarTrailing) {
                Button { promptText = ""; prompt = .create(parent: nil) } label: { Image(systemName: "folder.badge.plus") }
            }
        }
        .navigationDestination(for: Album.self) { AlbumView(api: api, album: $0) }
        .refreshable { await load() }
        .task { if !loaded { await load() } }
        .alert(prompt?.title ?? "", isPresented: Binding(get: { prompt != nil }, set: { if !$0 { prompt = nil } }), presenting: prompt) { p in
            TextField("Name", text: $promptText)
            Button("Cancel", role: .cancel) {}
            Button("Save") { Task { await submit(p) } }
        }
        .confirmationDialog("Delete folder?", isPresented: Binding(get: { deleting != nil }, set: { if !$0 { deleting = nil } }), presenting: deleting) { f in
            Button("Delete “\(f.name)”", role: .destructive) { Task { await run { try await api.deleteFolder(f.id) } } }
        } message: { _ in Text("Albums and subfolders move up one level. No music is deleted.") }
    }

    // MARK: Tree

    struct Row: Identifiable {
        enum Kind { case folder(Folder), album(Album) }
        let id: String; let kind: Kind; let depth: Int
    }

    private func rows(parent: Int?, depth: Int) -> [Row] {
        var out: [Row] = []
        for f in folders where f.parentId == parent {
            out.append(Row(id: "f\(f.id)", kind: .folder(f), depth: depth))
            if expanded.contains(f.id) { out += rows(parent: f.id, depth: depth + 1) }
        }
        for a in albums where a.folderId == parent { out.append(Row(id: "a\(a.id)", kind: .album(a), depth: depth)) }
        return out
    }

    private func toggle(_ f: Folder) { if !expanded.insert(f.id).inserted { expanded.remove(f.id) } }

    @ViewBuilder private func moveMenu(_ a: Album) -> some View {
        Menu("Move to folder", systemImage: "folder") {
            if a.folderId != nil { Button("Top level") { Task { await run { try await api.moveAlbum(a.id, folderId: nil) } } } }
            ForEach(folders.filter { $0.id != a.folderId }) { f in
                Button(f.name) { Task { await run { try await api.moveAlbum(a.id, folderId: f.id) } } }
            }
        }
    }

    // MARK: Actions

    private func load() async {
        do {
            let f = try await api.folders()
            var all: [Album] = []
            var page = 1
            while true {
                let p = try await api.albums(page: page)
                all += p.data
                if page >= p.meta.lastPage { break }
                page += 1
            }
            folders = f; albums = all; error = nil
        } catch is CancellationError {
        } catch let e as URLError where e.code == .cancelled {
        } catch { self.error = error.localizedDescription }
        loaded = true
    }

    private func run(_ work: () async throws -> Void) async {
        do { try await work(); await load() } catch { self.error = error.localizedDescription }
    }

    private func submit(_ p: Prompt) async {
        let name = promptText.trimmingCharacters(in: .whitespaces)
        guard !name.isEmpty else { return }
        switch p {
        case .create(let parent):
            if let parent { expanded.insert(parent.id) }
            await run { try await api.createFolder(name: name, parentId: parent?.id) }
        case .rename(let f): await run { try await api.renameFolder(f.id, name: name) }
        }
    }

    @EnvironmentObject private var player: PlayerController
    private func signOut() {
        player.signOut()
        Task { await api.signOut() }
    }
}
