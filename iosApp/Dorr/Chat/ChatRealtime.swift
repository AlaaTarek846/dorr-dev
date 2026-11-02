import Combine
import Foundation
import PusherSwift

// androidApp `chat/ChatRealtime.kt` — the app's single real-time connection. It subscribes to the account's private
// channel (`private-Modules.User.Models.User.{id}`) and fans events out through `events` to whatever screen is open;
// a ride, a freelance room or a public channel (live scores, a bus trip) joins while its screen wants it. Connection
// details come from the server (`chat/realtime-config`), so moving from Pusher to Soketi / Reverb needs no app update.
// While the app is in the foreground it keeps «online» alive with a heartbeat.

/// One real-time event (`chat.message.sent`, `chat.typing`…) with its JSON payload (keys as the server sent them).
struct ChatEvent {
    let name: String
    let data: JSONAny

    func str(_ key: String) -> String? { data[key]?.str }
}

@MainActor
final class ChatRealtime: NSObject, ObservableObject {
    static let shared = ChatRealtime()

    static let events = [
        "chat.message.sent", "chat.message.updated", "chat.message.deleted",
        "chat.receipt", "chat.reaction", "chat.typing", "chat.presence",
        "chat.conversation.updated", "chat.pins.updated",
        "chat.call.ringing", "chat.call.accepted", "chat.call.declined", "chat.call.left", "chat.call.ended",
        "chat.voice.updated", "chat.call.live", "chat.call.recording",
        "chat.story.posted", "chat.story.deleted", "chat.story.viewed", "chat.story.reaction",
        "chat.poll.updated", "chat.view_once.opened", "chat.location.moved",
        "chat.group.join_requests", "chat.group.join_decided",
        "chat.scheduled.changed", "chat.reminder.due",
        "sports.alert", "sports.prize",
        "support.ticket.created", "support.message", "support.ticket.updated",
        // Watch & Earn: one of my ads changed (status, views, spend) — its pages reload once.
        "watch_earn.ad.changed",
    ]

    /// A ride's private channel while its screen is open: status, the car's place, its chat.
    static let rideEvents = ["ride.changed", "ride.message", "ride.driver_location"]

    /// One Watch & Earn ad while its comments are open (`private-watch-earn.ad.{uuid}`): comments and reaction counts.
    static let watchEarnAdEvents = ["watch_earn.comment", "watch_earn.comment_deleted", "watch_earn.reactions"]

    /// Every event, as it arrives (screens filter by name).
    let events = PassthroughSubject<ChatEvent, Never>()
    @Published private(set) var connected = false
    /// The app is on screen (not just alive in the background) — in-app vs notification ringing.
    private(set) var inForeground = false

    private var pusher: Pusher?
    private var userId: Int?
    private var starting = false
    private var heartbeat: Task<Void, Never>?
    private var watchedRides = Set<String>()
    private var watchedAds = Set<String>()
    private var publicChannels: [String: [String]] = [:]

    /// Connect (idempotent) for the signed-in user.
    func start() {
        guard let token = AuthSession.shared.token, !token.isEmpty, let id = AuthSession.shared.user?.id else { return }
        if (pusher != nil || starting) && userId == id { return }
        stop()
        userId = id
        CrashReports.setUser(id)
        starting = true
        Task {
            // A failed first try (no network yet) used to leave the session with no live chat and no push: try
            // again a few times, waiting longer each time.
            var config: RealtimeConfigDto?
            var wait: UInt64 = 2_000_000_000
            for _ in 1...6 {
                config = try? await ChatApi.realtimeConfig().data
                if config != nil || userId != id { break }
                try? await Task.sleep(nanoseconds: wait)
                wait = min(wait * 2, 30_000_000_000)
            }
            guard userId == id else { return }
            starting = false
            // The same answer carries the push app id: start notifications and register this phone.
            ChatPush.shared.ensure(config?.onesignalAppId)
            guard let config, config.enabled == true, let key = config.key, !key.isEmpty else { return }
            connect(key: key, config: config, token: token, userId: id)
        }
    }

    private func connect(key: String, config: RealtimeConfigDto, token: String, userId id: Int) {
        let auth = ChatChannelAuth(url: ApiClient.origin + (config.authPath ?? "/broadcasting/auth"), token: token)
        let host: PusherHost = config.host.map { .host($0) } ?? .cluster(config.cluster ?? "mt1")
        let options = PusherClientOptions(authMethod: .authRequestBuilder(authRequestBuilder: auth), host: host, port: config.port, useTLS: config.useTls ?? true)
        let client = Pusher(key: key, options: options)
        client.connection.delegate = self
        pusher = client
        client.connect()
        let userChannel = "private-Modules.User.Models.User.\(id)"
        let channel = client.subscribe(userChannel)
        bind(channel, Self.events)
        // A freelance notice (`freelance.dispute.decided`, `freelance.delivery.submitted`…) means a screen on show may be stale:
        // one reload, no timer. (Chat messages have their own channel and events.)
        client.bind { (event: PusherEvent) in
            guard event.channelName == userChannel, event.eventName.hasPrefix("freelance."),
                  event.eventName != "freelance.message", event.eventName != "freelance.read" else { return }
            Task { @MainActor in NetworkMonitor.shared.noticeArrived() }
        }
        publicChannels.forEach { subscribePublic(client, $0.key, $0.value) }
        watchedRides.forEach { subscribeRide(client, $0) }
        watchedAds.forEach { subscribeAd(client, $0) }
    }

    private func bind(_ channel: PusherChannel, _ names: [String]) {
        for name in names {
            channel.bind(eventName: name) { [weak self] (event: PusherEvent) in
                guard let raw = event.data, let data = try? JSONDecoder().decode(JSONAny.self, from: Data(raw.utf8)) else { return }
                Task { @MainActor in self?.events.send(ChatEvent(name: name, data: data)) }
            }
        }
    }

    /// Listen to a public channel (e.g. `sports.match.{id}`, `bus-trips.{uuid}`). Safe before the connection is up.
    func watchPublic(_ channel: String, _ names: [String]) {
        if publicChannels.updateValue(names, forKey: channel) == nil, let pusher { subscribePublic(pusher, channel, names) }
    }

    func unwatchPublic(_ channel: String) {
        if publicChannels.removeValue(forKey: channel) != nil { pusher?.unsubscribe(channel) }
    }

    private func subscribePublic(_ client: Pusher, _ channel: String, _ names: [String]) {
        bind(client.subscribe(channel), names)
    }

    /// Listen to one ride (`ride.changed`, `ride.message`, `ride.driver_location`). Safe before the connection is up.
    func watchRide(_ uuid: String) {
        if watchedRides.insert(uuid).inserted, let pusher { subscribeRide(pusher, uuid) }
    }

    func unwatchRide(_ uuid: String) {
        if watchedRides.remove(uuid) != nil { pusher?.unsubscribe("private-rides.\(uuid)") }
    }

    private func subscribeRide(_ client: Pusher, _ uuid: String) {
        bind(client.subscribe("private-rides.\(uuid)"), Self.rideEvents)
    }

    /// Listen to one Watch & Earn ad (its comments sheet is open). Safe before the connection is up.
    func watchWatchEarnAd(_ uuid: String) {
        if watchedAds.insert(uuid).inserted, let pusher { subscribeAd(pusher, uuid) }
    }

    func unwatchWatchEarnAd(_ uuid: String) {
        if watchedAds.remove(uuid) != nil { pusher?.unsubscribe("private-watch-earn.ad.\(uuid)") }
    }

    private func subscribeAd(_ client: Pusher, _ uuid: String) {
        bind(client.subscribe("private-watch-earn.ad.\(uuid)"), Self.watchEarnAdEvents)
    }

    /// A freelance room while its page is open: `freelance.message` / `freelance.read`.
    func watchFreelanceRoom(_ uuid: String) {
        guard let pusher else { return }
        bind(pusher.subscribe("private-freelance-chat.\(uuid)"), ["freelance.message", "freelance.read"])
    }

    func unwatchFreelanceRoom(_ uuid: String) { pusher?.unsubscribe("private-freelance-chat.\(uuid)") }

    func stop() {
        heartbeat?.cancel()
        heartbeat = nil
        pusher?.disconnect()
        pusher = nil
        watchedRides.removeAll()
        watchedAds.removeAll()
        userId = nil
        starting = false
        connected = false
    }

    /// App came to the foreground: «online» now and every minute (the server forgets after 90 s).
    func onForeground() {
        inForeground = true
        if pusher == nil { start() }
        guard AuthSession.shared.token != nil else { return }
        heartbeat?.cancel()
        heartbeat = Task {
            _ = try? await ChatApi.markDelivered()
            while !Task.isCancelled {
                _ = try? await ChatApi.presence(online: true)
                try? await Task.sleep(nanoseconds: 60_000_000_000)
            }
        }
    }

    /// App went to the background: «last seen now».
    func onBackground() {
        inForeground = false
        guard AuthSession.shared.token != nil else { return }
        heartbeat?.cancel()
        heartbeat = nil
        Task { _ = try? await ChatApi.presence(online: false) }
    }

    /// A screen feeds an event it produced itself (e.g. an optimistic message) to the others.
    func emitLocal(_ event: ChatEvent) { events.send(event) }
}

extension ChatRealtime: PusherDelegate {
    nonisolated func changedConnectionState(from old: ConnectionState, to new: ConnectionState) {
        Task { @MainActor in self.connected = new == .connected }
    }

    nonisolated func subscribedToChannel(name: String) {
        // Anything that arrived while we were away is now on this phone: grey double ticks.
        if name.hasPrefix("private-Modules.User") { Task { _ = try? await ChatApi.markDelivered() } }
    }
}

/// Signs a private channel with the app's own token (Laravel's `/broadcasting/auth`).
private final class ChatChannelAuth: AuthRequestBuilderProtocol {
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
