import CoreLocation
import Foundation

// androidApp `chat/LiveLocationService.kt` — the live locations this phone is sharing (message id → ends at), kept so a
// restart resumes them, and my position sent to each — every 20 s or 25 m (a fix kept still is re-sent each minute) —
// until the last one ends. Android runs a foreground service with an ongoing notification; here location updates
// continue in the background with the system's blue «using location» indicator.

@MainActor
final class LiveLocationSharing: NSObject, ObservableObject, CLLocationManagerDelegate {
    static let shared = LiveLocationSharing()
    private let key = "chat_live_location"
    private let manager = CLLocationManager()
    private var last: CLLocation?
    private var lastSentAt: Date?
    private var ticker: Task<Void, Never>?

    override private init() {
        super.init()
        manager.delegate = self
        manager.distanceFilter = 25
        manager.desiredAccuracy = kCLLocationAccuracyBest
    }

    /// Still running (ended ones are dropped as they're read).
    var active: [String: Date] {
        let all = (UserDefaults.standard.dictionary(forKey: key) as? [String: Double]) ?? [:]
        let now = Date().timeIntervalSince1970
        let live = all.filter { $0.value > now }
        if live.count != all.count { UserDefaults.standard.set(live, forKey: key) }
        return live.mapValues { Date(timeIntervalSince1970: $0) }
    }

    func isSharing(_ messageId: String) -> Bool { active[messageId] != nil }

    func start(_ messageId: String, until iso: String) {
        guard let until = parseRideTime(iso) else { return }
        var all = (UserDefaults.standard.dictionary(forKey: key) as? [String: Double]) ?? [:]
        all[messageId] = until.timeIntervalSince1970
        UserDefaults.standard.set(all, forKey: key)
        ensureRunning()
    }

    func stop(_ messageId: String) {
        var all = (UserDefaults.standard.dictionary(forKey: key) as? [String: Double]) ?? [:]
        all[messageId] = nil
        UserDefaults.standard.set(all, forKey: key)
        if active.isEmpty { halt() }
    }

    func stopAll() {
        UserDefaults.standard.removeObject(forKey: key)
        halt()
    }

    /// After an app restart: ask the server which of mine are still live and pick them up again.
    func resume() async {
        guard AuthSession.shared.token != nil, let mine = try? await ChatApi.myLiveLocations().data else { return }
        UserDefaults.standard.removeObject(forKey: key)
        for m in mine { start(m.messageId, until: m.liveUntil) }
    }

    private func ensureRunning() {
        let status = manager.authorizationStatus
        guard status == .authorizedWhenInUse || status == .authorizedAlways else { return }
        manager.allowsBackgroundLocationUpdates = Bundle.main.object(forInfoDictionaryKey: "UIBackgroundModes").map { "\($0)".contains("location") } ?? false
        manager.showsBackgroundLocationIndicator = true
        manager.pausesLocationUpdatesAutomatically = false
        manager.startUpdatingLocation()
        ticker?.cancel()
        ticker = Task {
            while !Task.isCancelled {
                if active.isEmpty { halt(); return }
                // Standing still still counts as «here»: resend the last fix now and then.
                if let last, Date().timeIntervalSince(lastSentAt ?? .distantPast) > 60 { send(last) }
                try? await Task.sleep(nanoseconds: 20_000_000_000)
            }
        }
    }

    private func halt() {
        ticker?.cancel()
        ticker = nil
        manager.stopUpdatingLocation()
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        guard let loc = locations.last else { return }
        Task { @MainActor in
            // Keep the better of two fixes that arrive close together.
            if let previous = last, loc.timestamp.timeIntervalSince(previous.timestamp) < 5, loc.horizontalAccuracy > previous.horizontalAccuracy { return }
            last = loc
            if Date().timeIntervalSince(lastSentAt ?? .distantPast) >= 10 { send(loc) }
        }
    }

    private func send(_ loc: CLLocation) {
        lastSentAt = Date()
        for id in active.keys {
            Task {
                do {
                    _ = try await ChatApi.moveLive(id, latitude: loc.coordinate.latitude, longitude: loc.coordinate.longitude, accuracy: loc.horizontalAccuracy)
                } catch {
                    // Stopped or ended on the server (another device, or its time is up): drop it here too.
                    if [403, 404, 422].contains(error.apiFailure.httpStatus ?? 0) { stop(id) }
                }
            }
        }
    }
}
