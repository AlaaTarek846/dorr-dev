import Foundation
import WidgetKit
import OneSignalFramework

// androidApp `chat/ChatPush.kt` — phone notifications (OneSignal). The app id comes from the server
// (`chat/realtime-config`) and is kept for cold starts; this phone's subscription id is registered with the server
// whenever it appears or changes; a chat notification is not shown while that very conversation is on screen; a tap
// opens what it is about through `deepLink`.

/// Where a tapped notification wants to go.
enum ChatDeepLink: Equatable {
    case conversation(String)
    /// A ride notification: the rides screen on that ride (ended: the receipt and the rating).
    case ride(String)
    /// A booked service's notification (bus, boat, driver, lessons, points): that booking.
    case service(kind: String, uuid: String?)
    case call(id: String, conversationId: String?)
    case moments
    case tasks
    case calendar
    case discover(String?)
    case sports(String?)
    case support(Int)
    case security
    /// A «click to chat» link opened from outside the app.
    case chatLink(String)
    /// A group / channel invite.
    case join(String)
    /// A call link: who made it, then «Join».
    case callLink(String)
    /// A freelance notification (an offer, the agreement, a dispute…): the Freelance page it belongs to.
    case freelance(String)
}

@MainActor
final class ChatPush: NSObject, ObservableObject {
    static let shared = ChatPush()

    @Published var deepLink: ChatDeepLink?
    /// The conversation currently on screen — its notifications stay silent.
    nonisolated(unsafe) var openConversationId: String?

    private var initialised = false
    private var registeredId: String?
    private var permissionAsked = false

    /// At launch: start with the app id kept from last time.
    func attach() {
        if let id = ChatStore.shared.oneSignalAppId { initialise(id) }
    }

    /// The server told us the app id (with the real-time config).
    func ensure(_ appId: String?) {
        guard let appId, !appId.isEmpty else { return }
        if ChatStore.shared.oneSignalAppId != appId { ChatStore.shared.oneSignalAppId = appId }
        initialise(appId)
        register()
    }

    /// Ask for the notification permission — once per app run.
    func requestPermission() {
        guard initialised, !permissionAsked else { return }
        permissionAsked = true
        OneSignal.Notifications.requestPermission({ _ in }, fallbackToSettings: false)
    }

    func consumeDeepLink() { deepLink = nil }

    /// Open something from outside the chat (the calendar's «open the chat»).
    func open(_ link: ChatDeepLink) { deepLink = link }

    /// This phone's push id — sent with the logout so the server stops pushing to it.
    func currentId() -> String? {
        guard initialised, let id = OneSignal.User.pushSubscription.id, !id.isEmpty else { return nil }
        return id
    }

    func signedOut() {
        registeredId = nil
        if initialised { OneSignal.logout() }
    }

    private func initialise(_ appId: String) {
        guard !initialised else { return }
        OneSignal.initialize(appId, withLaunchOptions: nil)
        initialised = true
        OneSignal.User.pushSubscription.addObserver(self)
        OneSignal.Notifications.addForegroundLifecycleListener(self)
        OneSignal.Notifications.addClickListener(self)
    }

    /// PushKit gave this phone a (new) VoIP token: the server sends ringing calls to it.
    func voipTokenChanged() { register() }

    /// Tell the server this phone's subscription id, with its VoIP token (only when either changed).
    private func register() {
        guard initialised, AuthSession.shared.token != nil, let id = currentId() else { return }
        let voip = CallSystem.shared.voipToken
        let key = "\(id)|\(voip ?? "")"
        guard key != registeredId else { return }
        var body = ["player_id": id, "platform": "ios"]
        if let voip { body["voip_token"] = voip }
        Task {
            do {
                let _: ApiEnvelope<Ignored> = try await ApiClient.post("mobile/v1/notifications/devices", body: body, auth: true)
                registeredId = key
            } catch {}
        }
    }

    /// The notification's data → where a tap goes.
    nonisolated static func link(for data: [AnyHashable: Any]) -> ChatDeepLink? {
        func s(_ k: String) -> String? {
            guard let v = data[k] else { return nil }
            let text = "\(v)"
            return text.isEmpty || text == "null" || text == "<null>" ? nil : text
        }
        let type = s("type"), event = s("event")
        let conversation = s("conversation_uuid")
        if type == "chat_call", let call = s("call_id") { return .call(id: call, conversationId: conversation) }
        if event == "chat.call_link.joined", let token = s("token") { return .callLink(token) }
        if type == "support", let t = s("ticket_id").flatMap(Int.init), t > 0 { return .support(t) }
        if type == "security" { return .security }
        if let conversation { return .conversation(conversation) }
        if type == "ride", let ride = s("ride_uuid") { return .ride(ride) }
        if let event, event.hasPrefix("freelance.") {
            return .freelance(FreelanceLink.pageFor(event: event, project: s("project"), offer: s("offer"), dispute: s("dispute")))
        }
        if let event, let kind = event.split(separator: ".").first.map(String.init), ["bus", "marine", "hire", "lesson", "loyalty"].contains(kind) {
            return .service(kind: kind, uuid: ["booking_uuid", "hire_uuid", "enrollment_uuid"].lazy.compactMap(s).first)
        }
        switch type {
        case "tasks": return .tasks
        case "calendar": return .calendar
        case "sports": return .sports(s("match_id"))
        case "discover": return .discover(s("event_id"))
        case "moments": return .moments
        default: return event == "chat.collab.invited" ? .moments : nil
        }
    }
}

extension ChatPush: OSPushSubscriptionObserver, OSNotificationLifecycleListener, OSNotificationClickListener {
    nonisolated func onPushSubscriptionDidChange(state: OSPushSubscriptionChangedState) {
        Task { @MainActor in self.register() }
    }

    nonisolated func onWillDisplay(event: OSNotificationWillDisplayEvent) {
        let data = event.notification.additionalData ?? [:]
        let conversation = data["conversation_uuid"].map { "\($0)" }
        let type = data["type"].map { "\($0)" }
        // A sports push (a goal, a kick-off): the home-screen widgets read again.
        if type == "sports" { WidgetCenter.shared.reloadAllTimelines() }
        // Already reading that chat: the message is on screen, no banner needed.
        if type == "chat", let conversation, conversation == openConversationId { event.preventDefault() }
        // A call while the app is open: the call screen rings instead of a banner (the real-time event may have been
        // missed, so it's loaded); a missed one closes it.
        if type == "chat_call", let callId = data["call_id"].map({ "\($0)" }) {
            let name = data["event"].map { "\($0)" }
            if name == "chat.call.ringing" {
                event.preventDefault()
                Task { @MainActor in CallController.shared.loadIncoming(callId) }
            } else if name == "chat.call.missed" {
                Task { @MainActor in
                    CallNotifications.cancel(callId)
                    CallController.shared.onRemoteEnded(callId)
                }
            }
        }
    }

    nonisolated func onClick(event: OSNotificationClickEvent) {
        let data = event.notification.additionalData ?? [:]
        let link = ChatPush.link(for: data)
        Task { @MainActor in self.deepLink = link }
    }
}
