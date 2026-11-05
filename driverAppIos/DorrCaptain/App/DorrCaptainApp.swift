import SwiftUI
import UIKit
import UserNotifications

/// driverApp `DriverApp.kt` + `MainActivity.kt` — the one window. At launch: the captain's look, the admin's map engine (from
/// the phone at once; the server's latest is read once signed in), and push ready before the first screen (a request can
/// wake the app on its own). Coming to the screen (`onStart`): connect (idempotent) and reload once for anything missed.
@main
struct DorrCaptainApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @Environment(\.scenePhase) private var scenePhase
    @StateObject private var prefs = DriverPrefs.shared
    @StateObject private var backend = DriverBackend.shared

    var body: some Scene {
        WindowGroup {
            DriverNavHost()
                .environmentObject(prefs)
                .environmentObject(backend)
        }
        .onChange(of: scenePhase) { phase in
            switch phase {
            case .active:
                DriverLive.shared.inForeground = true
                if DriverBackend.shared.isSignedIn {
                    DriverLive.shared.start()
                    DriverLive.shared.requestSync()
                    DriverLive.shared.refreshUnread()
                    DriverPush.shared.requestPermission()
                }
            case .background:
                DriverLive.shared.inForeground = false
            default:
                break
            }
        }
    }
}

final class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        MainActor.assumeIsolated {
            DriverBackend.shareSignedIn(DriverBackend.shared.isSignedIn)
            // O-53: the admin's map engine (Google or Mapbox), from the phone at once.
            MapEngine.shared.load()
            DriverDutyService.channels()
            DriverPush.shared.attach()
        }
        return true
    }

    /// The ringing request the app made itself (`DriverDutyService.ring`): Accept / Decline pressed on it, or a tap that
    /// opens the app on the request (`EXTRA_OFFER`).
    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse) async {
        let info = response.notification.request.content.userInfo
        guard let offer = info[DriverDutyService.extraOffer] as? String else { return }
        let action = response.actionIdentifier
        await MainActor.run {
            if action == DriverDutyService.actionAccept || action == DriverDutyService.actionDecline {
                Task { await DriverDutyService.handleAction(action, offerUuid: offer) }
            } else {
                Duty.shared.focus(offer)
            }
        }
    }

    /// On screen the in-app card is the request; other local notifications show as banners.
    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification) async -> UNNotificationPresentationOptions {
        if notification.request.content.userInfo[DriverDutyService.extraOffer] != nil { return [] }
        return [.banner, .sound, .list]
    }
}
