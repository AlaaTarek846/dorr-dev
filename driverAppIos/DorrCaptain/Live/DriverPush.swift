import Foundation

/// driverApp `live/DriverPush.kt` — phone push for the captain app (OneSignal): what reaches a captain whose app is closed.
///  - The app id comes from the server (`driver/v1/realtime-config`, the captain's own OneSignal app when the owner set one)
///    and is cached for cold starts, so it can change without an app release.
///  - This phone's subscription id is registered with the server whenever it appears or changes.
///  - A ride request push rings with Accept / Decline (the notification extension sets that up; see DorrCaptainNotifications).
/// The OneSignal calls themselves live in `DriverPushOneSignal.swift`.
@MainActor
final class DriverPush: ObservableObject {
    static let shared = DriverPush()

    private static let appIdKey = "driver_push.app_id"
    var initialised = false
    private var registeredId: String?
    private var permissionAsked = false

    /// The last push tapped: its event and data — the navigation opens the screen it's about, then clears it.
    @Published var tapped: (event: String, data: [String: String])?

    func attach() {
        if let cached = UserDefaults.standard.string(forKey: Self.appIdKey) { DriverPushOneSignal.initialise(cached) }
    }

    /// Called once the server told us the app id.
    func ensure(_ appId: String?) {
        guard let appId, !appId.isEmpty else { return }
        if UserDefaults.standard.string(forKey: Self.appIdKey) != appId { UserDefaults.standard.set(appId, forKey: Self.appIdKey) }
        DriverPushOneSignal.initialise(appId)
        register()
    }

    /// Ask for the notification permission — once per run.
    func requestPermission() {
        guard initialised, !permissionAsked else { return }
        permissionAsked = true
        DriverPushOneSignal.requestPermission()
    }

    /// Signing out: this phone stops getting this captain's pushes.
    func signedOut() async {
        if let id = DriverPushOneSignal.subscriptionId() {
            _ = await DriverApi.unregisterDevice(body: ["player_id": id])
        }
        registeredId = nil
        DriverPushOneSignal.logout()
    }

    /// Tell the server this phone's subscription id (only when it changed).
    func register() {
        guard initialised, DriverBackend.shared.isSignedIn, let id = DriverPushOneSignal.subscriptionId(), id != registeredId else { return }
        Task {
            if case .ok = await DriverApi.registerDevice(body: ["player_id": id, "platform": "ios"]) {
                registeredId = id
            } else {
                print("DriverPush: device registration failed")
            }
        }
    }

    /// A tap on an ordinary push: the app opens on what it's about.
    func opened(_ data: [String: String]) {
        DriverLive.shared.requestSync()
        tapped = (data["event"] ?? "", data)
    }
}
