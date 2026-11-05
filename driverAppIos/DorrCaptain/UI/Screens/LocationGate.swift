import CoreLocation
import SwiftUI
import UIKit

// driverApp `ui/LocationGate.kt`.

/*
 * The location gate (docs/rides_tasks.md O-49, as O-48 in the DORR app): requests can't reach a captain whose place isn't known, so a
 * window that can't be dismissed asks first for the permission, then for the phone's location to be on.
 * It checks again every time the app comes back (from the settings) and the moment the location switch flips — no restart needed.
 *
 * iOS: the permission is asked as "while using" first, then upgraded to "always" (Android's fine location + the shift's
 * background location). iOS can't switch location services on from an app: «Turn on» opens the Settings app.
 */

/// The phone's location permission and switch, followed live (Android's permission check + `PROVIDERS_CHANGED_ACTION`).
@MainActor
final class DriverLocation: NSObject, ObservableObject, CLLocationManagerDelegate {
    static let shared = DriverLocation()

    private let manager = CLLocationManager()
    @Published private(set) var status: CLAuthorizationStatus
    /// Location services on for the whole phone.
    @Published private(set) var switchedOn = true
    /// The permission was asked from this screen (Android's `askedOnce`).
    @Published private(set) var askedOnce = false
    private var waiting: [CheckedContinuation<Void, Never>] = []
    private static let alwaysAskedKey = "dorr_driver_location_always_asked"

    override init() {
        status = manager.authorizationStatus
        super.init()
        manager.delegate = self
        refresh()
    }

    /// ACCESS_FINE_LOCATION granted (while using or always).
    var allowed: Bool { status == .authorizedWhenInUse || status == .authorizedAlways }
    /// Asked and refused: iOS never asks again — only the Settings app can change it (Android's "don't ask again").
    var deniedForever: Bool { status == .denied || status == .restricted }
    /// Allowed and switched on.
    var ready: Bool { allowed && switchedOn }

    /// Reads both again (back from the settings).
    func refresh() {
        status = manager.authorizationStatus
        // `locationServicesEnabled()` may block: read off the main thread.
        Task.detached {
            let on = CLLocationManager.locationServicesEnabled()
            await MainActor.run { DriverLocation.shared.switchedOn = on }
        }
    }

    /// The system's permission prompt ("while using"); then "always" (`locationManagerDidChangeAuthorization`). Returns once
    /// the captain answered.
    func ask() async {
        askedOnce = true
        guard status == .notDetermined else {
            askAlwaysOnce()
            return
        }
        await withCheckedContinuation { cont in
            waiting.append(cont)
            if waiting.count == 1 { manager.requestWhenInUseAuthorization() }
        }
    }

    /// "Always", asked once after "while using" was given — the shift sends the position in the background too.
    private func askAlwaysOnce() {
        guard status == .authorizedWhenInUse, !UserDefaults.standard.bool(forKey: Self.alwaysAskedKey) else { return }
        UserDefaults.standard.set(true, forKey: Self.alwaysAskedKey)
        manager.requestAlwaysAuthorization()
    }

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        let s = manager.authorizationStatus
        Task { @MainActor in
            self.status = s
            // Also called when location services are switched on / off for the whole phone.
            self.refresh()
            if s != .notDetermined {
                let all = self.waiting
                self.waiting = []
                all.forEach { $0.resume() }
                if self.askedOnce { self.askAlwaysOnce() }
            }
        }
    }
}

/// Location is allowed and switched on.
@MainActor
func driverLocationReady() -> Bool { DriverLocation.shared.ready }

/// The window that stays until the location is allowed and on. Put it anywhere in the screen: it shows itself (over
/// everything, not dismissible) while the location isn't ready, and calls `onReady` the moment it is.
@MainActor
struct LocationGate: View {
    @Environment(\.scenePhase) private var phase
    @ObservedObject private var location = DriverLocation.shared
    let onReady: () -> Void
    let onLeave: (() -> Void)?

    init(onReady: @escaping () -> Void, onLeave: (() -> Void)? = nil) {
        self.onReady = onReady
        self.onLeave = onLeave
    }

    var body: some View {
        Color.clear
            .frame(width: 0, height: 0)
            .dorrDialog(isPresented: Binding(get: { !location.ready }, set: { _ in }), dismissible: false) {
                LocationGateCard(onLeave: onLeave)
            }
            // Back from the settings (or any other app): check again.
            .onChange(of: phase) { p in if p == .active { location.refresh() } }
            .onChange(of: location.ready) { ready in if ready { onReady() } }
    }
}

private struct LocationGateCard: View {
    @ObservedObject private var location = DriverLocation.shared
    let onLeave: (() -> Void)?
    @State private var bob = false

    var body: some View {
        let allowed = location.allowed
        // Android: asked once, refused, and no rationale any more → only the settings can change it.
        let denyForever = location.deniedForever
        VStack(spacing: 0) {
            DorrIcon("location.fill", size: 52, tint: .white)
                .offset(y: bob ? -10 : 0)
                .frame(width: 96, height: 96)
                .background(LinearGradient(colors: [Color(hex: 0x001B53), Color(hex: 0x2B4C9B)], startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                .onAppear { withAnimation(.linear(duration: 0.9).repeatForever(autoreverses: true)) { bob = true } }
            VSpace(18)
            Text(L(!allowed ? "lg_title_permission" : "lg_title_off"))
                .font(.dorr(20, .black)).foregroundStyle(Color(hex: 0x111928)).multilineTextAlignment(.center)
            VSpace(8)
            Text(L(!allowed ? "lg_body_permission" : "lg_body_off"))
                .font(.dorr(14)).foregroundStyle(Color(hex: 0x6B7280)).multilineTextAlignment(.center)
            VSpace(22)
            Button {
                if !allowed && !denyForever {
                    Task { await location.ask() }
                } else if let url = URL(string: UIApplication.openSettingsURLString) {
                    // The app's own settings (refused for good), or the location switch: iOS has no in-app «turn on».
                    UIApplication.shared.open(url)
                }
            } label: {
                Text(L(!allowed ? (denyForever ? "lg_open_settings" : "lg_allow") : "lg_turn_on"))
                    .font(.dorr(16, .bold)).foregroundStyle(.white).multilineTextAlignment(.center)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 15)
                    .background(Color(hex: 0x001B53), in: RoundedRectangle(cornerRadius: 16))
            }
            .buttonStyle(.plain)
            if let onLeave {
                Button(action: onLeave) {
                    Text(L("lg_leave")).font(.dorr(14)).foregroundStyle(Color(hex: 0x6B7280)).multilineTextAlignment(.center)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 10)
                        .contentShape(RoundedRectangle(cornerRadius: 16))
                }
                .buttonStyle(.plain)
                .padding(.top, 10)
            }
        }
        .padding(24)
        .frame(maxWidth: .infinity)
        .background(Color.white, in: RoundedRectangle(cornerRadius: 28))
        .padding(24)
    }
}
