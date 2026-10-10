import SwiftUI

/// The play queue: tap to jump, drag to reorder, swipe to remove.
struct QueueView: View {
    @EnvironmentObject var player: PlayerController
    @Environment(\.dismiss) private var dismiss
    @State private var editing = false

    var body: some View {
        NavigationStack {
            List {
                ForEach(Array(player.queue.enumerated()), id: \.offset) { i, t in
                    Button { player.jump(to: i) } label: { TrackRow(track: t, current: i == player.index) }
                        .buttonStyle(.plain)
                        .listRowBackground(i == player.index ? Terminal.surface : Terminal.bg)
                        .listRowSeparatorTint(Terminal.line.opacity(0.5))
                }
                .onMove { player.moveInQueue(from: $0, to: $1) }
                .onDelete { for i in $0.sorted(by: >) { player.removeFromQueue(at: i) } }
            }
            .listStyle(.plain).terminalScreen()
            .environment(\.editMode, .constant(editing ? .active : .inactive))
            .navigationTitle("Queue (\(player.queue.count))").navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) { Button(editing ? "Done" : "Edit") { editing.toggle() } }
                ToolbarItem(placement: .topBarTrailing) { Button("Close") { dismiss() } }
            }
        }
        .onChange(of: player.hasQueue) { has in if !has { dismiss() } }
    }
}
