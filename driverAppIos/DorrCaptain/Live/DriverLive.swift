import Combine
import Foundation

/// One real-time event: `driver.changed`, `ride.offer`, `ride.message`, `wallet.…` — with its JSON payload.
struct LiveEvent {
    let name: String
    let data: JSONAny
}

/// driverApp `live/DriverLive.kt` — the captain app's real-time connection; nothing asks the server on a timer. It
/// subscribes to the captain's own private channel and, while a ride runs, to that ride's channel, and reacts:
///  - `driver.changed` (a request came, was taken or withdrawn, a bid was answered, the ride moved) → one reload of what the
///    app shows (`requestSync`);
///  - any other event is a notification (wallet, support, Wasl…): the unread counter follows.
/// Connection details come from the server (`driver/v1/realtime-config`), so moving from Pusher to our own Reverb / Soketi
/// needs no app update. After a lost connection, one reload catches up on what was missed. The Pusher client itself lives in
/// `DriverLivePusher.swift`.
@MainActor
final class DriverLive: ObservableObject {
    static let shared = DriverLive()
    static let driverChanged = "driver.changed"

    /// Everything that arrived, for the screens that care (the trip, the chat, the inbox, the wallet).
    let events = PassthroughSubject<LiveEvent, Never>()
    @Published var connected = false
    /// Unread notifications (the bell).
    @Published private(set) var unread = 0

    /// The app is on screen: a request shows as the in-app card, not as a ringing notification too.
    var inForeground = false

    private(set) var started = false
    var watchedRides = Set<String>()
    private var syncPending = false
    private var syncRunning = false
    /// The Pusher connection (`DriverLivePusher`), kept untyped so this file builds without the SDK.
    var client: AnyObject?

    /// Connect (idempotent) for the signed-in captain.
    func start() {
        guard DriverBackend.shared.isSignedIn else { return }
        if started { return }
        started = true
        Task {
            var wait: UInt64 = 2_000_000_000
            var config: RealtimeConfigDto?
            for attempt in 1...8 {
                config = await DriverApi.realtimeConfig().value
                if config != nil || !started { break }
                print("DriverLive: real-time config failed (try \(attempt))")
                try? await Task.sleep(nanoseconds: wait)
                wait = min(wait * 2, 30_000_000_000)
            }
            guard started else { return }
            guard let cfg = config else {
                started = false
                return
            }
            // The same answer carries the push app id: start notifications and register this phone.
            DriverPush.shared.ensure(cfg.onesignalAppId)
            guard cfg.enabled == true, let key = cfg.key, !key.isEmpty, let channel = cfg.channel, !channel.isEmpty else {
                print("DriverLive: real-time is not configured on the server.")
                return
            }
            DriverLivePusher.connect(self, key: key, channel: channel, config: cfg)
        }
        refreshUnread()
        requestSync()
    }

    func stop() {
        started = false
        DriverLivePusher.disconnect(self)
        watchedRides.removeAll()
        connected = false
        unread = 0
    }

    /// A screen needs this ride's events (its messages) while it is open.
    func watchRide(_ uuid: String) {
        guard watchedRides.insert(uuid).inserted else { return }
        DriverLivePusher.subscribeRide(self, uuid)
    }

    func unwatchRide(_ uuid: String) {
        if watchedRides.remove(uuid) != nil { DriverLivePusher.unsubscribe(self, "private-rides.\(uuid)") }
    }

    /// Reload the requests and the ride once (events arriving together make one reload).
    func requestSync() {
        syncPending = true
        guard !syncRunning else { return }
        syncRunning = true
        Task {
            while syncPending {
                syncPending = false
                await sync()
                try? await Task.sleep(nanoseconds: 300_000_000)
            }
            syncRunning = false
        }
    }

    func refreshUnread() {
        Task { if let count = await DriverApi.unreadCount().value?.count { unread = count } }
    }

    func setUnread(_ count: Int) {
        unread = count
    }

    /// Reconciles with the server once: a new request rings (and shows on the card), a withdrawn one goes, and a ride that
    /// became mine (an offer taken, a booking at its time) opens.
    func sync() async {
        guard DriverBackend.shared.isSignedIn else { return }
        // O-70: on a trip too — the server only offers a busy captain a With Me rider joining his car.
        await syncOffers()
        await syncRide()
    }

    /// The offers part alone — what a push that woke the app needs.
    func syncOffers() async {
        guard let list = await DriverApi.offers().value else { return }
        let duty = Duty.shared
        let open = list.filter { !duty.dismissed.contains($0.uuid ?? "") }
        if open.isEmpty {
            let hadBid = duty.offer?.bid?.myBid?.status == "pending"
            if duty.offer != nil { DriverDutyService.cancelRing() }
            duty.setOffers([])
            // My offer was taken by the rider: the ride is mine.
            if hadBid { await syncRide() }
            return
        }
        // O-36: several requests may be open at once; each new one rings once.
        for offer in open {
            guard let uuid = offer.uuid, duty.markSeen(uuid) else { continue }
            // On screen the card is the UI; closed or in the background it rings.
            if !inForeground { DriverDutyService.ring(offer) }
        }
        // Fresh each time: a bidding request's price, my offer's state and its timer move.
        duty.setOffers(open)
    }

    func syncRide() async {
        guard let current = await DriverApi.currentRide().value else { return }
        let has = current["uuid"] != nil
        let duty = Duty.shared
        if has && !duty.onTrip {
            duty.setOnTrip(true)
            duty.notifyRideChanged()
        } else if !has && duty.onTrip {
            duty.setOnTrip(false)
        }
    }

    /// One event from the captain's channel or a ride's (called by the Pusher client).
    func handle(name: String, raw: String?) {
        // Pusher's own bookkeeping.
        if name.hasPrefix("pusher:") || name.hasPrefix("pusher_internal:") { return }
        let data = raw.flatMap { try? JSONDecoder().decode(JSONAny.self, from: Data($0.utf8)) } ?? .object([:])
        events.send(LiveEvent(name: name, data: data))
        if name == Self.driverChanged || name == "ride.changed" || name == "ride.offer" {
            requestSync()
        } else if name != "ride.message" && name != "ride.driver_location" {
            // A notification row arrived (wallet, support, Wasl…): the bell follows.
            refreshUnread()
        }
        // A request came in a notification too: sync (the push that rings it may be a few seconds away).
        if name == "ride.offer" { requestSync() }
    }

    /// The connection came back after a gap (the tunnel, the lift…): one reload for anything missed.
    func reconnected() {
        requestSync()
        refreshUnread()
    }
}
