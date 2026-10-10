import SwiftUI

@main
struct MagnetiteApp: App {
    @StateObject private var session: Session
    @StateObject private var player: PlayerController
    @StateObject private var store: PlaylistStore
    @StateObject private var meters: MeterStore
    private let api: Api

    init() {
        let session = Session()
        let api = Api(session: session)
        _session = StateObject(wrappedValue: session)
        _player = StateObject(wrappedValue: PlayerController(api: api))
        _store = StateObject(wrappedValue: PlaylistStore(api: api))
        _meters = StateObject(wrappedValue: MeterStore(api: api))
        self.api = api
        CoverLoader.shared.api = api
        setupAppearance()
    }

    var body: some Scene {
        WindowGroup {
            RootView(api: api)
                .environmentObject(session)
                .environmentObject(player)
                .environmentObject(store)
                .environmentObject(meters)
                .preferredColorScheme(.dark)
        }
    }
}
