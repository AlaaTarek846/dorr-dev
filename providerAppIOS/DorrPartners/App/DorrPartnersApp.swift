import SwiftUI

/// providerApp `ProviderApp.kt` + `MainActivity.kt` — the one window: the session, the appearance and the network
/// status for every screen. The live connection and the push id follow the session (see RootView).
@main
struct DorrPartnersApp: App {
    @StateObject private var appearance = AppearanceState.shared
    @StateObject private var network = NetworkMonitor.shared

    init() {
        // The admin's map engine (Google, Mapbox or Apple Maps, as the captain app): the phone's saved choice at once, before
        // any screen; the server's latest is read once after sign-in (RootView).
        MainActor.assumeIsolated { MapEngine.shared.load() }
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(appearance)
                .environmentObject(network)
        }
    }
}
