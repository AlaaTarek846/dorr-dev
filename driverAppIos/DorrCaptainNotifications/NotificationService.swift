import OneSignalExtension
import UserNotifications

// driverApp `live/DriverPushExtension.kt` — runs for every push the moment it arrives, even when the app is closed.
//
//  - A ride request (`type = ride_offer`): it rings — the request notification with Accept / Decline on it, time-sensitive
//    (Android's full-screen ring; iOS has no full-screen notification for an app that isn't a phone app).
//  - Block 06: a next trip reaches a captain who is driving — a plain notification, never the ring.
//  - Everything else (wallet, support, Wasl…) pops down over the screen as it came.
//  - Nobody signed in on this phone: nothing to ring (iOS can't drop a push without Apple's filtering entitlement, so it
//    shows quietly instead).
final class NotificationService: UNNotificationServiceExtension {
    private var contentHandler: ((UNNotificationContent) -> Void)?
    private var receivedRequest: UNNotificationRequest?
    private var bestAttempt: UNMutableNotificationContent?

    override func didReceive(_ request: UNNotificationRequest, withContentHandler contentHandler: @escaping (UNNotificationContent) -> Void) {
        self.contentHandler = contentHandler
        receivedRequest = request
        guard let content = request.content.mutableCopy() as? UNMutableNotificationContent else { return contentHandler(request.content) }
        bestAttempt = content
        let data = Self.data(request.content.userInfo)
        let signedIn = UserDefaults(suiteName: "group.com.dorr.driver")?.bool(forKey: "signed_in") ?? true
        if !signedIn {
            content.sound = nil
            content.interruptionLevel = .passive
        } else if data["type"] == "ride_offer" && data["next_trip"] != "1" && data["next_trip"] != "true" {
            content.categoryIdentifier = "ride_request"
            content.interruptionLevel = .timeSensitive
            content.relevanceScore = 1
            content.sound = .default
        } else {
            content.interruptionLevel = .active
        }
        OneSignalExtension.didReceiveNotificationExtensionRequest(request, with: content, withContentHandler: contentHandler)
    }

    override func serviceExtensionTimeWillExpire() {
        if let request = receivedRequest, let content = bestAttempt, let handler = contentHandler {
            OneSignalExtension.serviceExtensionTimeWillExpireRequest(request, with: content)
            handler(content)
        }
    }

    /// OneSignal puts the push's data under `custom.a`.
    private static func data(_ info: [AnyHashable: Any]) -> [String: String] {
        let custom = info["custom"] as? [String: Any]
        let a = custom?["a"] as? [String: Any] ?? [:]
        return a.reduce(into: [:]) { out, pair in out[pair.key] = "\(pair.value)" }
    }
}
