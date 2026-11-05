import SwiftUI

/// providerApp `ProviderApp.kt` + `MainActivity.kt` — the one window: the session, the appearance and the network
/// status for every screen. The live connection and the push id follow the session (see RootView).
@main
struct DorrPartnersApp: App {
    @StateObject private var appearance = AppearanceState.shared
    @StateObject private var network = NetworkMonitor.shared

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(appearance)
                .environmentObject(network)
        }
    }
}
