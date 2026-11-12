import Foundation
import OneSignalFramework

/// The OneSignal half of `DriverPush`.
@MainActor
enum DriverPushOneSignal {
    private static let observer = Observer()

    static func initialise(_ appId: String) {
        guard !DriverPush.shared.initialised else { return }
        OneSignal.initialize(appId, withLaunchOptions: nil)
        DriverPush.shared.initialised = true
        OneSignal.User.pushSubscription.addObserver(observer)
        OneSignal.Notifications.addClickListener(observer)
        OneSignal.Notifications.addForegroundLifecycleListener(observer)
    }

    static func requestPermission() {
        OneSignal.Notifications.requestPermission({ _ in }, fallbackToSettings: false)
    }

    static func subscriptionId() -> String? {
        guard DriverPush.shared.initialised, let id = OneSignal.User.pushSubscription.id, !id.isEmpty else { return nil }
        return id
    }

    static func logout() {
        if DriverPush.shared.initialised { OneSignal.logout() }
    }

    private final class Observer: NSObject, OSPushSubscriptionObserver, OSNotificationClickListener, OSNotificationLifecycleListener {
        func onPushSubscriptionDidChange(state: OSPushSubscriptionChangedState) {
            Task { @MainActor in DriverPush.shared.register() }
        }

        func onClick(event: OSNotificationClickEvent) {
            let raw = event.notification.additionalData ?? [:]
            let data = raw.reduce(into: [String: String]()) { $0["\($1.key)"] = "\($1.value)" }
            // Accept / Decline on a ride request push.
            if let action = event.result.actionId, let offer = data["offer_uuid"],
               action == DriverDutyService.actionAccept || action == DriverDutyService.actionDecline {
                Task { @MainActor in await DriverDutyService.handleAction(action, offerUuid: offer) }
                return
            }
            if data["type"] == "ride_offer" {
                Task { @MainActor in DriverLive.shared.requestSync() }
                return
            }
            Task { @MainActor in DriverPush.shared.opened(data) }
        }

        /// The app is on screen: a request's card shows it already (Android: `DriverLive.inForeground` → preventDefault).
        func onWillDisplay(event: OSNotificationWillDisplayEvent) {
            let type = event.notification.additionalData?["type"].map { "\($0)" }
            let nextTrip = event.notification.additionalData?["next_trip"].map { "\($0)" }
            if type == "ride_offer" {
                Task { @MainActor in DriverLive.shared.requestSync() }
                if nextTrip != "1" && nextTrip != "true" { event.preventDefault() }
            }
        }
    }
}
