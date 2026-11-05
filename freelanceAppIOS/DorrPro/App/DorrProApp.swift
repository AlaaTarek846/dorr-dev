import SwiftUI

/// freelanceApp `FreelanceApp.kt` + `MainActivity.kt` — the one window: the session, the appearance and the network
/// status for every screen. The live connection and the push id follow the session (see RootView).
@main
struct DorrProApp: App {
    @StateObject private var appearance = AppearanceState.shared
    @StateObject private var network = NetworkMonitor.shared

    init() {
        // FL-L16: the consultation video session (app-wide, like the DORR app's calls).
        SessionCallController.shared.attach()
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(appearance)
                .environmentObject(network)
        }
    }
}
