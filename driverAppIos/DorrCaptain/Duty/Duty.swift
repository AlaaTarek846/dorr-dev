import Foundation

/// driverApp `duty/Duty.kt` — what the driver's shift shares between the background work and the screens: the request on
/// offer now, whether a ride is running (the location then goes every 5 s instead of 30 s), and a tick when a ride was
/// accepted from the notification (the app opens on it).
@MainActor
final class Duty: ObservableObject {
    static let shared = Duty()

    @Published private(set) var offer: OfferDto?
    /// O-36: every request open for me now — the captain may hold a few and pick one.
    @Published private(set) var offers: [OfferDto] = []
    /// The request card put aside ("later"): the requests wait behind the inbox icon.
    @Published private(set) var minimized = false
    @Published private(set) var onTrip = false
    @Published private(set) var rideChanged = 0

    /// Offers already shown, so the same one never rings twice.
    var seen: [String] = []
    /// Offers the driver put away (declined, or let the card time out): not shown again.
    var dismissed: [String] = []

    func showOffer(_ offer: OfferDto?) {
        if let uuid = offer?.uuid, dismissed.contains(uuid) { return }
        self.offer = offer
    }

    /// The open requests from the server: the one on screen stays (refreshed), else the first.
    func setOffers(_ list: [OfferDto]) {
        let open = list.filter { !dismissed.contains($0.uuid ?? "") }
        offers = open
        let current = offer?.uuid
        offer = open.first { $0.uuid == current } ?? open.first
        if open.isEmpty { minimized = false }
    }

    /// Show this request (picked from the list).
    func focus(_ uuid: String) {
        if let picked = offers.first(where: { $0.uuid == uuid }) { offer = picked }
        minimized = false
    }

    func minimize(_ on: Bool) {
        minimized = on
    }

    func clearOffer(_ uuid: String? = nil) {
        let current = offer?.uuid
        guard let gone = uuid ?? current else { return }
        if !dismissed.contains(gone) { dismissed.append(gone) }
        if dismissed.count > 50 { dismissed.removeFirst() }
        // The next request in line shows (O-36).
        offers = offers.filter { $0.uuid != gone }
        if current == gone { offer = offers.first }
    }

    /// Remembers a request as rung; false when it already was.
    func markSeen(_ uuid: String) -> Bool {
        guard !seen.contains(uuid) else { return false }
        seen.append(uuid)
        if seen.count > 50 { seen.removeFirst() }
        return true
    }

    func setOnTrip(_ onTrip: Bool) {
        self.onTrip = onTrip
        DriverDutyService.shared.tripChanged(onTrip)
    }

    func notifyRideChanged() {
        rideChanged += 1
    }

    /// Online: the shift starts sending the position (and stays alive in the background).
    func start() {
        DriverDutyService.shared.start()
    }

    func stop() {
        DriverDutyService.shared.stop()
    }
}
