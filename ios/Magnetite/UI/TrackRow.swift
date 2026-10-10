import SwiftUI

/// Tracks the viewer may not play are dimmed and cannot be tapped.
struct TrackRow: View {
    let track: Track
    var showNumber = false
    var current = false

    var body: some View {
        HStack(spacing: 12) {
            if showNumber {
                Text(track.number.map(String.init) ?? "").font(.mono(12)).foregroundStyle(Terminal.muted).frame(width: 26, alignment: .trailing)
            } else {
                CoverView(url: track.coverUrl).frame(width: 44, height: 44)
            }
            RowText(title: track.title, subtitle: [track.artist, track.sharedBy.map { "from \($0)" }].compactMap { $0 }.joined(separator: " · "), dim: !track.playable, bold: current)
            Spacer(minLength: 0)
            if current { Image(systemName: "speaker.wave.2.fill").foregroundStyle(Terminal.accent) }
            else if let d = track.duration { Text(clock(d)).font(.mono(12)).foregroundStyle(Terminal.muted) }
        }
        .contentShape(Rectangle())
    }
}

/// A list of tracks that starts the queue at the tapped one.
struct TrackList: View {
    let tracks: [Track]
    var source: PlaySource?
    var showNumbers = false
    var onMove: ((IndexSet, Int) -> Void)?
    var onDelete: ((IndexSet) -> Void)?
    @EnvironmentObject var player: PlayerController

    var body: some View {
        ForEach(Array(tracks.enumerated()), id: \.offset) { i, t in
            Button { player.play(tracks, start: i, from: source) } label: {
                TrackRow(track: t, showNumber: showNumbers, current: player.current?.id == t.id && player.hasQueue)
            }
            .buttonStyle(.plain).disabled(!t.playable)
            .contextMenu { TrackMenu(track: t) }
            .listRowBackground(Terminal.bg).listRowSeparatorTint(Terminal.line.opacity(0.5))
        }
        .onMove(perform: onMove)
        .onDelete(perform: onDelete)
    }
}

struct AlbumRow: View {
    let album: Album
    var body: some View {
        HStack(spacing: 12) {
            CoverView(url: album.coverUrl).frame(width: 52, height: 52)
            RowText(title: album.title, subtitle: [album.artist, album.year.map(String.init), album.sharedBy.map { "from \($0)" }].compactMap { $0 }.joined(separator: " · "))
            Spacer(minLength: 0)
            if let n = album.trackCount { Text("\(n)").font(.mono(12)).foregroundStyle(Terminal.muted) }
        }
    }
}

struct ErrorLine: View {
    let message: String?
    var body: some View {
        if let message { Text(message).font(.mono(13)).foregroundStyle(Terminal.red) }
    }
}
