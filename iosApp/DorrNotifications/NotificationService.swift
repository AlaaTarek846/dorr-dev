import OneSignalExtension
import UserNotifications
import WidgetKit

// androidApp `chat/ChatNotificationExtension.kt` — runs for every push the moment it arrives, even when the app is closed.
//
//  - A message sent "without sound": shown as usual, but no sound.
//  - Privacy mode / a circle that shows nothing: one «N new messages» that names nobody (iOS can't drop a push without
//    Apple's filtering entitlement, so "hidden" folds into it too).
//  - Local intelligence (spec 156): its kind, my words, codes and money kept off the screen — all here, on the phone.
//  - Ringing calls come by VoIP push (PushKit / CallKit) instead, so nothing for calls here.
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
        // A sports push (a goal, a kick-off): the home-screen widgets read again — even with the app closed.
        if data["type"] == "sports" { WidgetCenter.shared.reloadAllTimelines() }
        shape(content, data: data)
        // OneSignal's part (pictures, delivery receipts), as its own extension does on Android.
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

    private func shape(_ content: UNMutableNotificationContent, data: [String: String]) {
        guard data["type"] == "chat" else { return }
        if data["hidden"] == "1" || data["private"] == "1" {
            let count = LocalIntelligence.privateCount + 1
            LocalIntelligence.privateCount = count
            content.title = T("app_name")
            content.subtitle = ""
            content.body = String(format: T(count == 1 ? "ch_private_count##one" : "ch_private_count##other"), count)
            content.threadIdentifier = "dorr.private"
            content.badge = nil
            return
        }
        if data["silent"] == "1" {
            content.sound = nil
            content.interruptionLevel = .passive
            return
        }
        guard LocalIntelligence.enabled else { return }
        let body = content.body
        guard !body.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return }
        let kind = LocalIntelligence.kindOf(body)
        let keyword = LocalIntelligence.matchesKeyword(body)
        let label = [
            keyword.map { "⭐ " + String(format: T("lp_kind_keyword"), $0) },
            kind.map { "\($0.emoji) " + T($0.label) },
        ].compactMap { $0 }.joined(separator: " · ")
        if !label.isEmpty { content.subtitle = label }
        if keyword != nil { content.interruptionLevel = .timeSensitive }
        if let kind, kind.sensitive, LocalIntelligence.hideSensitive {
            // The label instead of the text, locked or not.
            content.body = "\(kind.emoji) " + String(format: T("lp_hidden_body"), T(kind.label))
        }
    }
}

/// The app's strings in the app's language (the extension carries its own copy of `Localizable.strings`).
private func T(_ key: String) -> String {
    let code = LocalIntelligence.language == "en" ? "en" : "ar"
    let bundle = Bundle.main.path(forResource: code, ofType: "lproj").flatMap(Bundle.init(path:)) ?? .main
    return bundle.localizedString(forKey: key, value: key, table: nil)
}
