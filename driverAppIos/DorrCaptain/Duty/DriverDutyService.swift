import CoreLocation
import Foundation
import UIKit
import UserNotifications

/// driverApp `duty/DriverDutyService.kt` — the driver's shift, alive while they are online, even with the app in the
/// background (iOS: the `location` background mode keeps the app running while it receives positions, with the blue
/// location pill — Android's ongoing "online" notification):
///  - the position every 30 s (owner's decision), every 5 s on a ride, sent in batches to `locations/batch` (the server keeps
///    the log and passes it to Wasl); points wait while offline;
///  - the phone's location switch is told to the server when it flips (D-44);
///  - the requests arrive on the captain's real-time channel and by push (DriverLive / DriverPush); a new one rings as a
///    notification with Accept / Decline right on it (`ring`).
@MainActor
final class DriverDutyService: NSObject, ObservableObject {
    static let shared = DriverDutyService()

    static let categoryOffer = "ride_request"
    static let actionAccept = "com.dorr.driver.offer.ACCEPT"
    static let actionDecline = "com.dorr.driver.offer.DECLINE"
    static let extraOffer = "offer_uuid"
    static let idOffer = "ride_request"

    private let manager = CLLocationManager()
    private var pending: [CLLocation] = []
    private var running = false
    private var lastSample: Date?
    private var flushTask: Task<Void, Never>?
    private var gpsReported: Bool?

    private override init() {
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyBest
        manager.activityType = .automotiveNavigation
        manager.pausesLocationUpdatesAutomatically = false
    }

    /// ACTION_START: the shift begins (idempotent).
    func start() {
        Self.channels()
        if !running {
            running = true
            if manager.authorizationStatus == .notDetermined { manager.requestAlwaysAuthorization() }
            track()
            flushTask = Task { await flushLoop() }
        }
        reportGps()
        // Requests reach the shift through real-time and push, never by asking the server on a timer.
        DriverLive.shared.start()
    }

    /// ACTION_STOP.
    func stop() {
        running = false
        flushTask?.cancel()
        flushTask = nil
        manager.stopUpdatingLocation()
        manager.allowsBackgroundLocationUpdates = false
    }

    /// `Duty.onTrip` changed: the sampling follows (5 s on a ride, 30 s idle).
    func tripChanged(_ onTrip: Bool) {
        lastSample = nil
    }

    private func track() {
        let status = manager.authorizationStatus
        guard status == .authorizedAlways || status == .authorizedWhenInUse else { return }
        manager.allowsBackgroundLocationUpdates = true
        manager.showsBackgroundLocationIndicator = true
        manager.startUpdatingLocation()
    }

    /// Android asks the fused provider for one fix every `interval`; iOS delivers continuously, so one point is kept per
    /// interval.
    private func keep(_ locations: [CLLocation]) {
        let interval: TimeInterval = Duty.shared.onTrip ? 5 : 30
        for location in locations {
            if let last = lastSample, location.timestamp.timeIntervalSince(last) < interval / 2 { continue }
            lastSample = location.timestamp
            pending.append(location)
        }
    }

    /// Batches out: every 30 s idle, 10 s on a ride; kept and retried while there's no network.
    private func flushLoop() async {
        while !Task.isCancelled {
            try? await Task.sleep(nanoseconds: (Duty.shared.onTrip ? 10 : 30) * 1_000_000_000)
            if Task.isCancelled { return }
            let points = pending
            if points.isEmpty { continue }
            let body: JsonBody = [
                "batch_uuid": UUID().uuidString.lowercased(),
                "clock_offset_ms": 0,
                "points": points.suffix(500).map { p -> [String: Any] in
                    var point: [String: Any] = [
                        "lat": p.coordinate.latitude,
                        "lng": p.coordinate.longitude,
                        "accuracy_m": Int(p.horizontalAccuracy),
                        "recorded_at": Self.iso(p.timestamp),
                    ]
                    if p.speed >= 0 { point["speed_mps"] = p.speed }
                    if p.course >= 0 { point["heading"] = Int(p.course) % 360 }
                    if #available(iOS 15.0, *) { point["is_mock"] = p.sourceInformation?.isSimulatedBySoftware ?? false } else { point["is_mock"] = false }
                    return point
                },
            ]
            if case .ok = await DriverApi.locations(body: body) {
                let sent = Set(points.map { ObjectIdentifier($0) })
                pending.removeAll { sent.contains(ObjectIdentifier($0)) }
            }
        }
    }

    /// The phone's location switch, told to the server when it flips (D-44) — a captain with GPS off isn't offered requests.
    private func reportGps() {
        let on = CLLocationManager.locationServicesEnabled() && manager.authorizationStatus != .denied && manager.authorizationStatus != .restricted
        guard on != gpsReported else { return }
        gpsReported = on
        Task { _ = await DriverApi.gps(body: ["enabled": on]) }
    }

    private static let isoFormat: DateFormatter = {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(identifier: "UTC")
        f.dateFormat = "yyyy-MM-dd'T'HH:mm:ss.SSS'Z'"
        return f
    }()

    private static func iso(_ date: Date) -> String { isoFormat.string(from: date) }

    // MARK: - The request notification

    /// The notification actions (Android's notification channels + the Accept / Decline buttons).
    static func channels() {
        let accept = UNNotificationAction(identifier: actionAccept, title: L("offer_accept"), options: [.foreground])
        let decline = UNNotificationAction(identifier: actionDecline, title: L("offer_decline"), options: [.destructive])
        let category = UNNotificationCategory(identifier: categoryOffer, actions: [decline, accept], intentIdentifiers: [], options: [])
        UNUserNotificationCenter.current().setNotificationCategories([category])
    }

    /// The request as a ringing notification, with Accept / Decline on it (time-sensitive, the ringtone sound).
    static func ring(_ offer: OfferDto) {
        channels()
        guard let uuid = offer.uuid else { return }
        let earning = offer.earningMinor.map { money($0, offer.currency?.decimals ?? 2, offer.currency?.symbol) }
        let body = [
            L("offer_pickup_km", String(format: "%.1f", locale: Locale(identifier: DriverPrefs.locale), Double(offer.pickupDistanceM ?? 0) / 1000.0)),
            earning.map { L("offer_earning", $0) },
        ].compactMap { $0 }.joined(separator: " · ")
        let content = UNMutableNotificationContent()
        content.title = L("offer_title")
        content.body = [body, offer.origin?.address, offer.destination?.address.map { "→ \($0)" }].compactMap { $0 }.joined(separator: "\n")
        content.categoryIdentifier = categoryOffer
        content.userInfo = [extraOffer: uuid]
        // Android rings with the phone's ringtone; iOS gives apps no access to it — the default sound until a ringtone file
        // is bundled.
        content.sound = .default
        content.interruptionLevel = .timeSensitive
        content.relevanceScore = 1
        let request = UNNotificationRequest(identifier: idOffer, content: content, trigger: nil)
        UNUserNotificationCenter.current().add(request)
        // Android: setTimeoutAfter(secondsLeft) — the request disappears when its time is up.
        let seconds = max(offer.secondsLeft ?? 0, 5)
        Task {
            try? await Task.sleep(nanoseconds: UInt64(seconds) * 1_000_000_000)
            cancelRing()
        }
    }

    static func cancelRing() {
        UNUserNotificationCenter.current().removeDeliveredNotifications(withIdentifiers: [idOffer])
        UNUserNotificationCenter.current().removePendingNotificationRequests(withIdentifiers: [idOffer])
    }

    static func money(_ minor: Int, _ decimals: Int, _ symbol: String?) -> String {
        var divisor = 1.0
        for _ in 0..<decimals { divisor *= 10 }
        return "\(String(format: "%.\(decimals)f", locale: Locale(identifier: DriverPrefs.locale), Double(minor) / divisor)) \(symbol ?? "")"
            .trimmingCharacters(in: .whitespaces)
    }

    /// driverApp `duty/OfferActionReceiver.kt` — Accept / Decline pressed on the request notification.
    static func handleAction(_ action: String, offerUuid uuid: String) async {
        cancelRing()
        if action == actionAccept {
            let result = await DriverApi.accept(key: UUID().uuidString.lowercased(), uuid: uuid)
            if case .ok = result {
                Duty.shared.clearOffer(uuid)
                Duty.shared.setOnTrip(true)
                // The ride screen, right away (the action opened the app).
                Duty.shared.notifyRideChanged()
            } else {
                AppToast.show(result.error?.message ?? L("error_offline"), long: true)
            }
        } else {
            _ = await DriverApi.decline(uuid: uuid, body: [:])
            Duty.shared.clearOffer(uuid)
        }
    }
}

extension DriverDutyService: CLLocationManagerDelegate {
    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        Task { @MainActor in self.keep(locations) }
    }

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        Task { @MainActor in
            if self.running { self.track() }
            self.reportGps()
        }
    }
}
