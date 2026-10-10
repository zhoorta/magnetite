import SwiftUI

struct TransportIcons {
    static func play(_ playing: Bool) -> String { playing ? "pause.fill" : "play.fill" }
}

struct MiniPlayer: View {
    var open: () -> Void
    @EnvironmentObject var player: PlayerController
    @EnvironmentObject var meters: MeterStore

    var body: some View {
        VStack(spacing: 0) {
            if !meters.ids.isEmpty {
                // All chosen meters stack here; a tap chooses meters, a swipe up opens Now Playing.
                MeterPanel {
                    VStack(spacing: 6) { ForEach(meters.ids, id: \.self) { MeterView(id: $0, compact: true) } }
                }
                .padding(.horizontal, 12).padding(.top, 6)
                .onTapGesture { meters.picking = true }
                .gesture(DragGesture(minimumDistance: 20).onEnded { if $0.translation.height < -40 { open() } })
            }
            HStack(spacing: 12) {
                Button(action: open) {
                    HStack(spacing: 12) {
                        CoverView(url: player.current?.coverUrl).frame(width: 40, height: 40)
                        RowText(title: player.current?.title ?? "", subtitle: player.current?.artist)
                        Spacer(minLength: 0)
                    }.contentShape(Rectangle())
                }.buttonStyle(.plain)
                Button { player.previous() } label: { Image(systemName: "backward.fill") }
                Button { player.toggle() } label: { Image(systemName: TransportIcons.play(player.isPlaying)).frame(width: 28) }
                Button { player.next() } label: { Image(systemName: "forward.fill") }.disabled(player.index + 1 >= player.queue.count)
            }
            .foregroundStyle(Terminal.accent.opacity(0.75)).font(.system(size: 17))
            .padding(.horizontal, 12).padding(.vertical, 8)
            ProgressView(value: player.duration > 0 ? min(player.position / player.duration, 1) : 0)
                .progressViewStyle(.linear).tint(Terminal.accent).scaleEffect(x: 1, y: 0.5)
        }
        .background(Terminal.surface)
        .overlay(alignment: .top) { Rectangle().fill(Terminal.line).frame(height: 1) }
    }
}

struct NowPlayingView: View {
    @EnvironmentObject var player: PlayerController
    @Environment(\.dismiss) private var dismiss
    @State private var dragging: Double?
    @State private var showQueue = false
    @EnvironmentObject var meters: MeterStore

    var body: some View {
        VStack(spacing: 20) {
            Capsule().fill(Terminal.line).frame(width: 40, height: 4).padding(.top, 8)
            if meters.ids.isEmpty {
                Spacer(minLength: 0)
                CoverView(url: player.current?.coverUrl)
                    .aspectRatio(1, contentMode: .fit).frame(maxWidth: 420)
                    .overlay(Rectangle().stroke(Terminal.line))
            } else {
                // With meters chosen this page is the meters, big; not past what a meter can use.
                GeometryReader { geo in
                    let gap: CGFloat = 8
                    VStack(spacing: gap) {
                        ForEach(meters.ids, id: \.self) { id in
                            let wanted = (meterCatalog.first { $0.id == id }?.height ?? 150) * 2.2
                            let h = min((geo.size.height - gap * CGFloat(meters.ids.count - 1)) / CGFloat(meters.ids.count), wanted)
                            MeterPanel { MeterView(id: id, fill: true) }.frame(height: h)
                        }
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .onTapGesture { meters.picking = true }
                }
            }
            VStack(spacing: 4) {
                Text(player.current?.title ?? "").font(.mono(20, weight: .bold)).foregroundStyle(Terminal.accent).multilineTextAlignment(.center)
                Text([player.current?.artist, player.current?.album].compactMap { $0 }.joined(separator: " · ")).font(.mono(13)).foregroundStyle(Terminal.muted).multilineTextAlignment(.center)
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
                Button { showQueue = true } label: { Label("Queue", systemImage: "list.bullet") }
                Button { meters.picking = true } label: { Label("Meters", systemImage: "waveform") }
            }
            .buttonStyle(.plain).font(.mono(13)).foregroundStyle(Terminal.muted)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 24).padding(.bottom, 16)
        .terminalScreen()
        .sheet(isPresented: $showQueue) { QueueView().presentationBackground(Terminal.bg) }
    }
}
