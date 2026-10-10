import SwiftUI

struct TransportIcons {
    static func play(_ playing: Bool) -> String { playing ? "pause.fill" : "play.fill" }
}

/// Same structure as the web player: meters (or the cover card) on top, then meters and full-screen buttons,
/// the seek row, and Prev / Play / Next centred.
struct MiniPlayer: View {
    var open: () -> Void
    var openAlbum: (Album) -> Void = { _ in }
    @EnvironmentObject var player: PlayerController
    @EnvironmentObject var meters: MeterStore
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var dragging: Double?
    /// Swiping down folds the meters away; swiping up brings them back (and, with nothing folded, opens Now Playing).
    @AppStorage("miniMetersCollapsed") private var collapsed = false

    private var current: Track? { player.current }

    private func gotoAlbum() {
        if let t = current, let id = t.albumId {
            openAlbum(Album(id: id, title: t.album ?? t.title, artist: t.artist, coverUrl: t.coverUrl))
        } else { open() }
    }

    private func bordered<V: View>(_ v: V, width: CGFloat? = nil) -> some View {
        v.foregroundStyle(Terminal.accent).padding(.horizontal, sizeClass == .regular ? 10 : 7).frame(width: width, height: 32)
            .overlay(RoundedRectangle(cornerRadius: 6).stroke(Terminal.line))
    }

    var body: some View {
        let bare = meters.ids.isEmpty || collapsed
        VStack(spacing: 8) {
            // The handle's row is always there, so the space above the cover does not change with the meters.
            Button { withAnimation(.easeInOut(duration: 0.2)) { collapsed = false } } label: {
                Image(systemName: "chevron.compact.up").font(.system(size: 22, weight: .semibold))
                    .foregroundStyle(Terminal.accent.opacity(0.8)).frame(maxWidth: .infinity).frame(height: 14)
                    .opacity(collapsed && !meters.ids.isEmpty ? 1 : 0)
                    .contentShape(Rectangle())
            }
            .buttonStyle(.plain).disabled(!(collapsed && !meters.ids.isEmpty)).accessibilityLabel("Show meters")
            .padding(.top, -2).padding(.bottom, -6)
            if !bare {
                MeterPanel {
                    // Two side by side on the iPad (four make two rows); stacked on the phone.
                    let columns = sizeClass == .regular && meters.ids.count > 1 ? 2 : 1
                    Grid(horizontalSpacing: 10, verticalSpacing: 8) {
                        ForEach(Array(stride(from: 0, to: meters.ids.count, by: columns)), id: \.self) { start in
                            GridRow {
                                ForEach(meters.ids[start..<min(start + columns, meters.ids.count)], id: \.self) {
                                    MeterView(id: $0, compact: true, scale: columns == 2 ? 1.6 : (sizeClass == .regular ? 1.4 : 0.85))
                                }
                            }
                        }
                    }
                }
                .contentShape(Rectangle())
                .onTapGesture { meters.picking = true }
                .onLongPressGesture(minimumDuration: 0.4) { meters.picking = true }
            }

            // Cover, title and the buttons on the right; the cover and the text go to the album.
            HStack(spacing: sizeClass == .regular ? 12 : 8) {
                Button(action: gotoAlbum) {
                    HStack(spacing: 12) {
                        CoverView(url: current?.coverUrl).frame(width: bare ? 56 : 40, height: bare ? 56 : 40)
                        VStack(alignment: .leading, spacing: 1) {
                            Text(current?.title ?? "Nothing playing").font(.mono(bare ? 16 : 14, weight: .bold)).lineLimit(1)
                            if let artist = current?.artist, !artist.isEmpty {
                                Text(artist).font(.mono(12)).foregroundStyle(Terminal.accent).lineLimit(1)
                            }
                            if bare, let album = current?.album, !album.isEmpty {
                                Text(album).font(.mono(12)).foregroundStyle(Terminal.muted).lineLimit(1)
                            }
                        }
                    }.contentShape(Rectangle())
                }.buttonStyle(.plain).layoutPriority(1)
                Button(action: open) { Color.clear.frame(maxWidth: .infinity, minHeight: 32, maxHeight: 32) }.buttonStyle(.plain)
                // Accent while meters are chosen (like the web), filled a little while they are folded.
                Button {
                    // Folded meters unfold, and the picker opens either way.
                    if collapsed, !meters.ids.isEmpty { withAnimation(.easeInOut(duration: 0.2)) { collapsed = false } }
                    meters.picking = true
                } label: {
                    bordered(HStack(spacing: 5) {
                        Image(systemName: "waveform")
                        Text("\(meters.ids.count)").font(.mono(12))
                    })
                    .opacity(meters.ids.isEmpty ? 0.6 : 1)
                    .background(collapsed && !meters.ids.isEmpty ? Terminal.accent.opacity(0.2) : Color.clear, in: RoundedRectangle(cornerRadius: 6))
                }.buttonStyle(.plain).accessibilityLabel("Choose meters")
                Button(action: open) {
                    bordered(Image(systemName: "arrow.up.left.and.arrow.down.right").font(.system(size: 12)))
                }.buttonStyle(.plain).accessibilityLabel("Full screen")
            }

            if let e = player.error { Text(e).font(.mono(11)).foregroundStyle(Terminal.red) }

            // Seek: time, slider, duration.
            HStack(spacing: 10) {
                Text(clock(dragging ?? player.position)).frame(width: 40, alignment: .trailing)
                Slider(value: Binding(get: { dragging ?? player.position }, set: { dragging = $0 }), in: 0...max(player.duration, 1)) { editing in
                    if !editing, let d = dragging { player.seek(to: d); dragging = nil }
                }
                .tint(Terminal.accent)
                Text(clock(player.duration)).frame(width: 40, alignment: .leading)
            }
            .font(.mono(11)).foregroundStyle(Terminal.muted)

            // Like the full-screen controls, smaller.
            HStack(spacing: 34) {
                Button { player.previous() } label: { Image(systemName: "backward.fill").font(.system(size: 20)).frame(width: 44, height: 40).contentShape(Rectangle()) }
                Button { player.toggle() } label: { Image(systemName: TransportIcons.play(player.isPlaying)).font(.system(size: 28)).frame(width: 48, height: 40).contentShape(Rectangle()) }
                Button { player.next() } label: { Image(systemName: "forward.fill").font(.system(size: 20)).frame(width: 44, height: 40).contentShape(Rectangle()) }
                    .disabled(player.index + 1 >= player.queue.count)
            }
            .foregroundStyle(Terminal.accent.opacity(0.75))
            .buttonStyle(.plain)
        }
        .padding(.horizontal, 12).padding(.top, 8).padding(.bottom, 6)
        .frame(maxWidth: 760)
        .frame(maxWidth: .infinity)
        .background(Terminal.surface)
        .overlay(alignment: .top) { Rectangle().fill(Terminal.line).frame(height: 1) }
        // Swipes on the bar (mostly vertical, so the seek slider keeps its drags): down folds the meters away,
        // up unfolds them, or opens Now Playing when nothing is folded.
        .simultaneousGesture(DragGesture(minimumDistance: 20).onEnded {
            let dy = $0.translation.height
            guard abs($0.translation.width) < abs(dy) / 2 else { return }
            withAnimation(.easeInOut(duration: 0.2)) {
                if dy > 40, !meters.ids.isEmpty, !collapsed { collapsed = true }
                else if dy < -40 { if collapsed, !meters.ids.isEmpty { collapsed = false } else { open() } }
            }
        })
    }
}

/// An album page in its own navigation stack, shown as a sheet from the mini player and over Now Playing.
struct AlbumSheet: View {
    let api: Api
    let album: Album
    var done: () -> Void
    var body: some View {
        NavigationStack {
            AlbumView(api: api, album: album)
                .toolbar { ToolbarItem(placement: .topBarTrailing) { Button("Done", action: done) } }
                .navigationDestination(for: Album.self) { AlbumView(api: api, album: $0) }
        }
        .tint(Terminal.accent).presentationBackground(Terminal.bg)
    }
}

struct NowPlayingView: View {
    let api: Api
    @State private var albumShown: Album?
    @EnvironmentObject var player: PlayerController
    @Environment(\.dismiss) private var dismiss
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var dragging: Double?
    @EnvironmentObject var meters: MeterStore

    var body: some View {
        VStack(spacing: 20) {
            Capsule().fill(Terminal.line).frame(width: 40, height: 4).padding(.top, 8)
            if meters.ids.isEmpty {
                // The same flexible area the meters use, so the title and the controls stay where they are.
                CoverView(url: player.current?.coverUrl)
                    .aspectRatio(1, contentMode: .fit).frame(maxWidth: 420)
                    .overlay(Rectangle().stroke(Terminal.line))
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            } else {
                // With meters chosen this page is the meters, big; not past what a meter can use.
                GeometryReader { geo in
                    // Same rule as the mini player: two side by side on the iPad (four make two rows).
                    let gap: CGFloat = 8
                    let columns = sizeClass == .regular && meters.ids.count > 1 ? 2 : 1
                    let rows = (meters.ids.count + columns - 1) / columns
                    VStack(spacing: gap) {
                        ForEach(0..<rows, id: \.self) { r in
                            let row = Array(meters.ids[(r * columns)..<min((r + 1) * columns, meters.ids.count)])
                            let wanted = row.map { id in (meterCatalog.first { $0.id == id }?.height ?? 150) * 2.2 }.max() ?? 150
                            let h = min((geo.size.height - gap * CGFloat(rows - 1)) / CGFloat(rows), wanted)
                            HStack(spacing: gap) {
                                ForEach(row, id: \.self) { id in MeterPanel { MeterView(id: id, fill: true) }.frame(height: h) }
                            }
                        }
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .contentShape(Rectangle())
                    .onTapGesture { meters.picking = true }
                    .onLongPressGesture(minimumDuration: 0.4) { meters.picking = true }
                }
            }
            VStack(spacing: 4) {
                // The title and the artist line go to the album.
                Button {
                    if let t = player.current, let id = t.albumId {
                        albumShown = Album(id: id, title: t.album ?? t.title, artist: t.artist, coverUrl: t.coverUrl)
                    }
                } label: {
                    VStack(spacing: 4) {
                        Text(player.current?.title ?? "").font(.mono(20, weight: .bold)).foregroundStyle(Terminal.accent).multilineTextAlignment(.center)
                        Text([player.current?.artist, player.current?.album].compactMap { $0 }.joined(separator: " · ")).font(.mono(13)).foregroundStyle(Terminal.muted).multilineTextAlignment(.center)
                    }.contentShape(Rectangle())
                }.buttonStyle(.plain)
                if let e = player.error { Text(e).font(.mono(12)).foregroundStyle(Terminal.red) }
            }
            VStack(spacing: 2) {
                Slider(value: Binding(get: { dragging ?? player.position }, set: { dragging = $0 }), in: 0...max(player.duration, 1)) { editing in
                    if !editing, let d = dragging { player.seek(to: d); dragging = nil }
                }
                HStack {
                    Text(clock(dragging ?? player.position)); Spacer(); Text(clock(player.duration))
                }.font(.mono(12)).foregroundStyle(Terminal.muted)
            }
            HStack(spacing: 44) {
                Button { player.previous() } label: { Image(systemName: "backward.fill") }
                Button { player.toggle() } label: { Image(systemName: TransportIcons.play(player.isPlaying)).font(.system(size: 38)).frame(width: 50) }
                Button { player.next() } label: { Image(systemName: "forward.fill") }.disabled(player.index + 1 >= player.queue.count)
            }
            .font(.system(size: 26)).foregroundStyle(Terminal.accent.opacity(0.75))
            HStack(spacing: 28) {
                Button { meters.picking = true } label: { Label("Meters", systemImage: "waveform") }
            }
            .buttonStyle(.plain).font(.mono(13)).foregroundStyle(Terminal.muted)
        }
        .padding(.horizontal, 24).padding(.bottom, 16)
        .terminalScreen()
        // A swipe down closes Now Playing back to the mini player (mostly vertical, so the seek slider keeps its drags).
        .simultaneousGesture(DragGesture(minimumDistance: 30).onEnded {
            if $0.translation.height > 100, abs($0.translation.width) < $0.translation.height / 2 { dismiss() }
        })
        // The album opens over Now Playing, which stays where it is.
        .sheet(item: $albumShown) { album in AlbumSheet(api: api, album: album) { albumShown = nil } }
    }
}
