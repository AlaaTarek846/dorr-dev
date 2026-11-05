import Combine
import Foundation
import OneSignalFramework
import PusherSwift

// freelanceApp `net/Realtime.kt` — how things reach the freelancer's phone without the app ever asking on a timer (FL-007):
//  - Live: one connection to the real-time server, subscribed to the freelancer's own private channel. Every notice the
//    server raises arrives on it; `changed` fires once per notice and the screens that care reload once.
//  - Push: OneSignal wakes the phone when the app is closed; this phone's push id is sent to the server on every open
//    (an id can move between accounts) and removed on sign-out.
// Both start when the freelancer signs in and stop when the session ends. The connection settings and the push app id
// come from `GET realtime-config`, asked once per sign-in.

/// A support live event (`support.message`, `support.ticket.updated`…) with its payload.
struct LiveEvent {
    let name: String
    let data: JSONAny
}

@MainActor
final class Realtime: NSObject, ObservableObject {
    static let shared = Realtime()

    /// A notice just arrived: the account, the unread count or the list may have changed.
    let changed = PassthroughSubject<Void, Never>()
    /// Support's live events with their payload: an open ticket list or conversation updates itself.
    let events = PassthroughSubject<LiveEvent, Never>()
    @Published private(set) var connected = false
    /// The number of unread notices (the dot on the bell).
    @Published private(set) var unread = 0
    /// The type of a notification the freelancer tapped in the tray, until the navigation has consumed it.
    @Published private(set) var opened: String?
    /// The screen a tapped notification leads to (see `NoticeRoute`); nil: the notifications page.
    @Published private(set) var openedRoute: Route?

    private var pusher: Pusher?
    private var oneSignalReady = false
    private var registeredId: String?
    private var registering: String?
    private var starting = false

    func consumeOpened() {
        opened = nil
        openedRoute = nil
    }

    /// Connects (idempotent) for the signed-in freelancer.
    func start() {
        guard pusher == nil, !starting, Session.shared.token != nil else { return }
        starting = true
        Task {
            let config = await NotificationsApi.realtimeConfig().value
            starting = false
            guard Session.shared.token != nil, pusher == nil else { return }
            if let appId = config?.onesignalAppId, !appId.isEmpty { startPush(appId) }
            guard let config, config.enabled == true, let key = config.key, !key.isEmpty, let channel = config.channel, !channel.isEmpty else {
                print("Realtime: real-time is not configured on the server.")
                await refreshUnread()
                return
            }
            connect(key: key, channel: channel, config: config)
            await refreshUnread()
        }
    }

    private func connect(key: String, channel name: String, config: RealtimeConfigDto) {
        let auth = ChannelAuth(url: Api.origin + (config.authPath ?? "/broadcasting/auth"), token: Session.shared.token ?? "")
        let host: PusherHost = config.host.map { .host($0) } ?? .cluster(config.cluster ?? "mt1")
        let options = PusherClientOptions(authMethod: .authRequestBuilder(authRequestBuilder: auth), host: host, port: config.port, useTLS: config.useTls ?? true)
        let client = Pusher(key: key, options: options)
        client.connection.delegate = self
        pusher = client
        client.connect()
        _ = client.subscribe(name)
        // A notice is broadcast under its own name (`freelance.verification.approved`…): any event on this private channel
        // means "something new", so one global listener is enough.
        client.bind { [weak self] (event: PusherEvent) in
            guard event.channelName == name, !event.eventName.hasPrefix("pusher:") else { return }
            let eventName = event.eventName
            let raw = event.data
            Task { @MainActor in
                guard let self else { return }
                if eventName.hasPrefix("support."), let raw, let data = try? JSONDecoder().decode(JSONAny.self, from: Data(raw.utf8)) {
                    self.events.send(LiveEvent(name: eventName, data: data))
                }
                self.changed.send()
                NetworkMonitor.shared.noticeArrived()
                await self.refreshUnread()
            }
        }
    }

    /// Reads the unread count (one request) — also after the screen marked something as read.
    func refreshUnread() async {
        if let count = await NotificationsApi.unreadCount().value?.count { unread = count }
    }

    func setUnread(_ count: Int) { unread = count }

    // MARK: - push

    private func startPush(_ appId: String) {
        if oneSignalReady {
            registerDevice()
            return
        }
        OneSignal.initialize(appId, withLaunchOptions: nil)
        oneSignalReady = true
        OneSignal.User.pushSubscription.addObserver(self)
        OneSignal.Notifications.addClickListener(self)
        registerDevice()
    }

    /// Whether this phone receives push (the Notifications switch in the settings).
    func pushOptedIn() -> Bool { oneSignalReady ? OneSignal.User.pushSubscription.optedIn : true }

    /// Turns this phone's push on or off without signing out.
    func setPushOptedIn(_ on: Bool) {
        guard oneSignalReady else { return }
        if on { OneSignal.User.pushSubscription.optIn() } else { OneSignal.User.pushSubscription.optOut() }
    }

    /// Asks for the notification permission — call once, after the first screen.
    func requestPushPermission() {
        guard oneSignalReady else { return }
        OneSignal.Notifications.requestPermission({ _ in }, fallbackToSettings: false)
    }

    /// This phone's push id, sent with the logout so the server stops pushing to it.
    func pushId() -> String? {
        guard oneSignalReady, let id = OneSignal.User.pushSubscription.id, !id.isEmpty else { return nil }
        return id
    }

    private func registerDevice() {
        guard let id = pushId(), Session.shared.token != nil else { return }
        // Once per push id (the SDK reports the same subscription several times while it starts).
        guard id != registeredId, id != registering else { return }
        registering = id
        Task {
            if case .ok = await NotificationsApi.registerDevice(id) { registeredId = id } else { print("Realtime: device registration failed") }
            registering = nil
        }
    }

    // MARK: - stop

    /// The session ended: close the live connection and let go of this phone's push id.
    func stop() {
        pusher?.disconnect()
        pusher = nil
        connected = false
        unread = 0
        registeredId = nil
        if oneSignalReady { OneSignal.logout() }
    }
}

extension Realtime: PusherDelegate {
    nonisolated func changedConnectionState(from old: ConnectionState, to new: ConnectionState) {
        Task { @MainActor in
            let now = new == .connected
            // Back after a drop: one catch-up request (nothing is polled while connected).
            if now && !self.connected && old == .reconnecting { self.changed.send() }
            self.connected = now
        }
    }
}

extension Realtime: OSPushSubscriptionObserver, OSNotificationClickListener {
    nonisolated func onPushSubscriptionDidChange(state: OSPushSubscriptionChangedState) {
        Task { @MainActor in self.registerDevice() }
    }

    nonisolated func onClick(event: OSNotificationClickEvent) {
        let data = event.notification.additionalData ?? [:]
        func s(_ k: String) -> String? {
            guard let v = data[k] else { return nil }
            let text = "\(v)"
            return text.isEmpty || text == "null" || text == "<null>" ? nil : text
        }
        let type = s("type") ?? "freelance"
        let route = NoticeRoute.resolve(s("event"), field: s)
        Task { @MainActor in
            self.openedRoute = route
            self.opened = type
        }
    }
}

/// Signs a private channel with the freelancer's token (Laravel's `/broadcasting/auth`).
private final class ChannelAuth: AuthRequestBuilderProtocol {
    let url: String
    let token: String

    init(url: String, token: String) {
        self.url = url
        self.token = token
    }

    func requestFor(socketID: String, channelName: String) -> URLRequest? {
        guard let u = URL(string: url) else { return nil }
        var request = URLRequest(url: u)
        request.httpMethod = "POST"
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("1", forHTTPHeaderField: "ngrok-skip-browser-warning")
        request.setValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")
        let body = "socket_id=\(socketID)&channel_name=\(channelName.addingPercentEncoding(withAllowedCharacters: .alphanumerics) ?? channelName)"
        request.httpBody = Data(body.utf8)
        return request
    }
}
