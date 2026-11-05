import AVFoundation
import UIKit
import UserNotifications

// freelanceApp `session/SessionCallService.kt`.
//
// Android keeps the session going outside its screen with a foreground service (a backgrounded app loses its microphone and camera
// otherwise) whose notification is the session itself. On iOS the app's `audio` background mode (Info.plist) keeps the microphone and
// the sound going while LiveKit's audio session is active; the camera is paused by the system in the background and LiveKit resumes it
// on return. What stays of the service here is its notification: while the app is in the background during a session, one silent
// notification says I am in it (who, connecting / in the session) and a tap brings the app back; it goes away on return or at the end.
// (Android's mute / leave buttons in the notification are not on iOS: the controls are on the session screen.)
@MainActor
enum SessionCallService {
    private static let notificationId = "dorr_consultation_session"
    private static var observers: [NSObjectProtocol] = []
    private static var inBackground = false

    /// Called once at launch through `SessionCallController.attach()`.
    static func attach() {
        guard observers.isEmpty else { return }
        let center = NotificationCenter.default
        observers.append(center.addObserver(forName: UIApplication.didEnterBackgroundNotification, object: nil, queue: .main) { _ in
            Task { @MainActor in
                inBackground = true
                update()
            }
        })
        observers.append(center.addObserver(forName: UIApplication.willEnterForegroundNotification, object: nil, queue: .main) { _ in
            Task { @MainActor in
                inBackground = false
                removeNotice()
            }
        })
    }

    /// The session started or changed (connecting → in, mute): the notice follows it while the app is in the background.
    static func update() {
        guard SessionCallController.shared.ongoing() else { return }
        if inBackground { show() }
    }

    /// The session is over: nothing is left behind.
    static func stop() {
        removeNotice()
    }

    private static func show() {
        let controller = SessionCallController.shared
        let content = UNMutableNotificationContent()
        content.title = controller.title.isEmpty ? L("ses_title") : controller.title
        content.body = L(controller.phase == .active ? "ses_ongoing" : "ses_connecting")
        content.threadIdentifier = notificationId
        content.sound = nil
        if #available(iOS 15.0, *) { content.interruptionLevel = .passive }
        let request = UNNotificationRequest(identifier: notificationId, content: content, trigger: nil)
        UNUserNotificationCenter.current().add(request) { _ in }
    }

    private static func removeNotice() {
        let center = UNUserNotificationCenter.current()
        center.removePendingNotificationRequests(withIdentifiers: [notificationId])
        center.removeDeliveredNotifications(withIdentifiers: [notificationId])
    }
}
