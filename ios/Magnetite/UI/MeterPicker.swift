import SwiftUI

/// Choose up to four meters. Tapping adds or removes one; a chosen meter shows its position and an up arrow.
/// The whole list is saved at every change (and shared with the web) like there.
struct MeterPicker: View {
    @EnvironmentObject var meters: MeterStore
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List {
                Text("Up to \(maxMeters). Saved to your account, so the web player shows the same.")
                    .font(.mono(12)).foregroundStyle(Terminal.muted).listRowBackground(Terminal.bg)
                ForEach(meterCatalog, id: \.id) { m in
                    let at = meters.ids.firstIndex(of: m.id)
                    let on = at != nil
                    HStack(spacing: 12) {
                        Text(at.map { "\($0 + 1)" } ?? "·").frame(width: 20).foregroundStyle(on ? Terminal.accent : Terminal.muted)
                        Text(m.label).foregroundStyle(on || meters.ids.count < maxMeters ? Terminal.text : Terminal.muted)
                        Spacer()
                        if let at, at > 0 {
                            Button { var l = meters.ids; l.insert(l.remove(at: at), at: at - 1); meters.set(l) } label: { Image(systemName: "arrow.up").padding(8) }
                                .buttonStyle(.plain).foregroundStyle(Terminal.accent)
                        }
                    }
                    .contentShape(Rectangle())
                    .onTapGesture {
                        if on { meters.set(meters.ids.filter { $0 != m.id }) } else if meters.ids.count < maxMeters { meters.set(meters.ids + [m.id]) }
                    }
                    .listRowBackground(Terminal.bg)
                }
            }
            .listStyle(.plain).terminalScreen()
            .navigationTitle("Meters").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .topBarTrailing) { Button("Done") { dismiss() } } }
        }
        .presentationDetents([.medium, .large]).presentationBackground(Terminal.bg)
    }
}
