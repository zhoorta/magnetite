import Foundation

/// The user's chosen meters (up to four), read from `/me` and saved at once, so the web and the phone show the same list.
@MainActor
final class MeterStore: ObservableObject {
    @Published private(set) var ids: [String] = ["vu-wide"]
    /// Set by any meter panel that is tapped; the root view shows the picker.
    @Published var picking = false
    private let api: Api

    init(api: Api) { self.api = api }

    func load() async {
        if let list = try? await api.meters() { ids = list }
    }

    func set(_ list: [String]) {
        ids = list
        Task { try? await api.saveMeters(list) }
    }
}
