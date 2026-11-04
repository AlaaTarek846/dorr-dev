import Foundation
import PusherSwift

/// The Pusher half of `DriverLive` (driverApp `live/DriverLive.kt` `connect` / `subscribeRide`): the captain's private channel,
/// signed by `/broadcasting/auth` with the captain's token, and each watched ride's channel.
@MainActor
enum DriverLivePusher {
    private static var delegate: ConnectionWatcher?

    static func connect(_ live: DriverLive, key: String, channel name: String, config: RealtimeConfigDto) {
        guard let token = DriverBackend.token else { return }
        let auth = ChannelAuth(url: Api.origin + (config.authPath ?? "/broadcasting/auth"), token: token)
        let host: PusherHost = config.host.map { .host($0) } ?? .cluster(config.cluster ?? "mt1")
        let options = PusherClientOptions(authMethod: .authRequestBuilder(authRequestBuilder: auth), host: host, port: config.port, useTLS: config.useTls ?? true)
        let client = Pusher(key: key, options: options)
        let watcher = ConnectionWatcher(live)
        delegate = watcher
        client.connection.delegate = watcher
        live.client = client
        client.connect()
        // `channel.bindGlobal` on Android: every event of the captain's channel and of each watched ride's channel.
        client.bind(eventCallback: { [weak live] (event: PusherEvent) in
            guard let channel = event.channelName, channel == name || channel.hasPrefix("private-rides.") else { return }
            let eventName = event.eventName
            let raw = event.data
            if eventName == "pusher:subscription_succeeded" {
                // Subscribed to the captain's channel: whatever came before is now only a reload away.
                if channel == name { Task { @MainActor in live?.requestSync() } }
                return
            }
            Task { @MainActor in live?.handle(name: eventName, raw: raw) }
        })
        _ = client.subscribe(name)
        live.watchedRides.forEach { subscribeRide(live, $0) }
    }

    static func disconnect(_ live: DriverLive) {
        (live.client as? Pusher)?.disconnect()
        live.client = nil
        delegate = nil
    }

    static func subscribeRide(_ live: DriverLive, _ uuid: String) {
        guard let client = live.client as? Pusher else { return }
        let name = "private-rides.\(uuid)"
        if client.connection.channels.find(name: name) != nil { return }
        _ = client.subscribe(name)
    }

    static func unsubscribe(_ live: DriverLive, _ name: String) {
        (live.client as? Pusher)?.unsubscribe(name)
    }

    private final class ConnectionWatcher: PusherDelegate {
        weak var live: DriverLive?
        private var wasConnected = false

        init(_ live: DriverLive) { self.live = live }

        func changedConnectionState(from old: ConnectionState, to new: ConnectionState) {
            let up = new == .connected
            Task { @MainActor in
                self.live?.connected = up
                if up && self.wasConnected { self.live?.reconnected() }
                if up { self.wasConnected = true }
            }
        }
    }

    /// Signs a private channel with the captain's token (Laravel's `/broadcasting/auth`).
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
}
