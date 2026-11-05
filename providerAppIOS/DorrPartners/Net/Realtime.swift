import Combine
import Foundation
import OneSignalFramework
import PusherSwift

// providerApp `net/Realtime.kt` — how things reach the provider's phone without the app ever asking on a timer
// (docs/moving_tasks.md rule):
//  - Live: one connection to the real-time server, subscribed to the provider's own private channel. Every notice the
//    server raises arrives on it; `changed` fires once per notice and the screens that care reload once.
//  - Push: OneSignal wakes the phone when the app is closed; this phone's push id is sent to the server on every open
//    (an id can move between accounts) and removed on sign-out.
// Both start when the provider signs in and stop when the session ends. The connection settings and the push app id
// come from `GET realtime-config`, asked once per sign-in.

/// One live event with its payload (`moving.driver_location` → lat, lng, heading, at).
struct LiveEvent {
    let name: String
    let data: JSONAny?
}

@MainActor
final class Realtime: NSObject, ObservableObject {
    static let shared = Realtime()

    /// A notice just arrived: the account, the unread count or the list may have changed.
    let changed = PassthroughSubject<Void, Never>()
    @Published private(set) var connected = false
    /// The number of unread notices (the dot on the bell).
    @Published private(set) var unread = 0
    /// The type of a notification the provider tapped in the tray, until the navigation has consumed it.
    @Published private(set) var opened: String?
    /// The screen a tapped notification leads to (see `NoticeRoute`); nil: the notifications page.
    @Published private(set) var openedRoute: Route?

    private var pusher: Pusher?
    private var oneSignalReady = false
    private var registeredId: String?
    private var registering: String?
    private var starting = false
    /// The move channels held open and how many pages listen to each, so the last one to close unsubscribes.
    private var moveChannels: [String: (channel: PusherChannel, watchers: Int)] = [:]

    func consumeOpened() {
        opened = nil
        openedRoute = nil
    }

    /// Connects (idempotent) for the signed-in provider.
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
        // A notice is broadcast under its own name (`provider.establishment.reviewed`, `moving.offer.accepted`…): any event
        // on this private channel
        // means "something new", so one global listener is enough.
        client.bind { [weak self] (event: PusherEvent) in
            guard event.channelName == name, !event.eventName.hasPrefix("pusher:") else { return }
            Task { @MainActor in
                guard let self else { return }
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

    // MARK: - one move

    /// The live events of one move while its page is open (C-04 / C-07): `moving.changed` (reload it once) and
    /// `moving.driver_location` (the truck's last position). Channel `private-moving.{uuid}`, authorised by the server for the
    /// establishment's people who follow its jobs and its driver — a request not yet ours is simply refused, and the page still
    /// reloads on the person's own channel. Nothing is asked on a timer. Read it again when `connected` changes
    /// (`.task(id: …connected…) { for await event in Realtime.shared.watchMove(uuid) { … } }`); ending the task ends the watch.
    func watchMove(_ uuid: String) -> AsyncStream<LiveEvent> {
        AsyncStream { continuation in
            guard let client = pusher, !uuid.isEmpty else { return }
            let name = "private-moving.\(uuid)"
            let channel = moveChannels[name]?.channel ?? client.subscribe(name)
            moveChannels[name] = (channel, (moveChannels[name]?.watchers ?? 0) + 1)
            var bound: [(event: String, id: String)] = []
            for event in ["moving.changed", "moving.driver_location"] {
                let id = channel.bind(eventName: event) { (received: PusherEvent) in
                    let data = received.data.flatMap { try? JSONDecoder().decode(JSONAny.self, from: Data($0.utf8)) }
                    continuation.yield(LiveEvent(name: received.eventName, data: data))
                }
                bound.append((event, id))
            }
            let callbacks = bound
            continuation.onTermination = { @Sendable [weak self] _ in
                Task { @MainActor in
                    guard let self, let held = self.moveChannels[name] else { return }
                    callbacks.forEach { held.channel.unbind(eventName: $0.event, callbackId: $0.id) }
                    if held.watchers <= 1 {
                        self.moveChannels[name] = nil
                        client.unsubscribe(name)
                    } else {
                        self.moveChannels[name] = (held.channel, held.watchers - 1)
                    }
                }
            }
        }
    }

    // MARK: - stop

    /// The session ended: close the live connection and let go of this phone's push id.
    func stop() {
        pusher?.disconnect()
        pusher = nil
        moveChannels.removeAll()
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
        let type = s("type") ?? "provider"
        let route = NoticeRoute.resolve(s("event"), field: s)
        Task { @MainActor in
            self.openedRoute = route
            self.opened = type
        }
    }
}

/// Signs a private channel with the provider's token (Laravel's `/broadcasting/auth`).
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
