import CoreLocation
import MapKit
import SwiftUI
import UIKit

// androidApp `rides/RidesUtil.kt`, the `RidesState` of `RidesHost.kt` and its shared pieces (PanelCard,
// AccentButton, RoundButton, RidesLink).

/// A place on the trip: a point and its readable name; `name` = its short title in a list (O-57).
struct RidePoint: Equatable {
    var lat: Double
    var lng: Double
    var address: String
    var name: String?

    var latLng: CLLocationCoordinate2D { CLLocationCoordinate2D(latitude: lat, longitude: lng) }

    var json: [String: JSONValue] { ["lat": .double(lat), "lng": .double(lng), "address": .string(String(address.prefix(255)))] }
}

/// Where the booking is: the map (pickup), the place search, picking a point on the map, the prices.
enum RideStep: String { case map, search, pickOnMap, quotes }

/// Everything the rides screens share while one is open.
@MainActor
final class RidesState: ObservableObject {
    @Published var me: CLLocationCoordinate2D?
    @Published var pickup: RidePoint?
    /// O-51: the pickup's address is being worked out (the map moves / the place is looked up).
    @Published var pickupLoading = false
    @Published var destination: RidePoint?
    @Published var stops: [RidePoint] = []
    @Published var openDestination = false
    @Published var features: RideFeaturesDto?
    /// Bumped by "try again" on the unavailable card so the service switches are read again.
    @Published var featuresTick = 0
    @Published var step: RideStep = .map
    /// Which point the search / the map pin is setting: "pickup", "destination" or "stop:<index>".
    @Published var editing = "destination"
    @Published var quotes: [RideQuoteDto] = []
    @Published var selected: RideQuoteDto?
    // O-37: cash by default; the wallet's balance goes first when `useWallet` (the rest is cash).
    @Published var payment = "cash"
    @Published var useWallet = true
    /// O-33: With Me — who may share the car: any / male / female.
    @Published var shareWith = "any"
    /// R-45: the promo code priced into the quotes, and why the last one was refused.
    @Published var couponCode: String?
    @Published var couponError: String?
    /// D-60, D-61: how many ride, and a short note for the captain about the pickup.
    @Published var passengers = 1
    @Published var pickupNote = ""
    /// A quiet ride for this trip — nil = the rider's saved choice.
    @Published var quietRide: Bool?
    /// R-13: booking for someone else — their name and number, and the rider's own consent tick.
    @Published var forOther = false
    @Published var beneficiaryName = ""
    @Published var beneficiaryPhone = ""
    @Published var beneficiaryConsent = false
    /// R-5, R-174: Home, Work, favourites and recent destinations; `savingPlace` = the search is picking Home / Work.
    @Published var places: RidePlacesDto?
    @Published var savingPlace: String?
    /// R-16..R-18: the approved points chosen.
    @Published var pickupPointId: Int?
    @Published var dropoffPointId: Int?
    /// Bidding: the price the rider offers (starts at the suggested price).
    @Published var riderPrice: Int?
    /// Block 06: the category DORR AI understood, chosen once the prices come.
    @Published var preferCategory: String?
    /// Booked ahead (R-12): the chosen pickup time; nil = now.
    @Published var scheduledAt: Date?
    @Published var myRidesOpen = false
    /// The rides service's other ways (O-22..O-26) and DORR points (R-147), each a page of its own over the map.
    @Published var busOpen = false
    @Published var marineOpen = false
    @Published var hireOpen = false
    @Published var lessonsOpen = false
    @Published var loyaltyOpen = false
    @Published var scheduleOpen = false
    /// Block 06: «دُرر يفهم مقصدك» is open; AI-09's suggestion.
    @Published var smartOpen = false
    /// Words from DORR AI's chat for «دُرر يفهم مقصدك» to understand at once (no place was found there).
    @Published var smartText: String?
    @Published var suggestion: RideAiSuggestDto?
    /// The Safety Center page is open (R-219).
    @Published var safetyOpen = false
    /// An "Are you OK?" from outside a ride on screen — e.g. the check-in after arriving (R-223).
    @Published var pendingCheck: SafetyCheckDto?
    /// Changing the trip during the ride: "destination" or "add_stop" while its page is open (R-91..95).
    @Published var changing: String?
    /// O-15: the category picked by itself for more riders (the popup says so), then cleared.
    @Published var categorySwitchedTo: String?
    /// R-16..R-18: the approved points being chosen from ("pickup" | "dropoff"), what is there, what was decided.
    @Published var pointsChooser: String?
    @Published var pointChoices: RidePickupPointsDto?
    var decidedPickup: String?
    var decidedDropoff: String?
    /// R-233..R-237, R-NEW-06: what's added to the trip (priced into the quotes), and why the last change was refused.
    @Published var addons = TripOptions()
    var addonsApplied = TripOptions()
    @Published var addonsError: String?
    /// R-INN-07 DORR Circle: ask my favourite captains first — the ones picked (none picked = all of them).
    @Published var useCircle = false
    @Published var circleDrivers: [FavoriteDriverDto] = []
    @Published var circlePicked: [Int] = []
    /// Complaints and disputes (R-133..R-140): the page is open, and the ride a new one is about (nil = pick).
    @Published var casesOpen = false
    @Published var caseRideUuid: String?
    @Published var caseStartNew = false
    /// O-18: the service picked on the first screen (instant ride → the map); false = the picker shows.
    @Published var servicePicked = false
    /// O-17: by the hour — how many hours, on which category (nil = by the distance).
    @Published var hourlyHours: Int?
    @Published var hourlySlug: String?
    /// The ride on screen was opened from "My rides": closing it goes back to booking, not out.
    @Published var fromHistory = false
    @Published var ride: RideDto?
    @Published var busy = false
    @Published var error: String?

    /// The snake decoder camel-cases map keys too: "find_driver" is read as "findDriver".
    func feature(_ name: String) -> Bool { (features?.features?[name] ?? features?.features?[camel(name)]) == true }

    func category(_ name: String) -> Bool { (features?.categories?[name] ?? features?.categories?[camel(name)]) == true }

    func beneficiaryReady() -> Bool {
        !forOther || (beneficiaryName.trimmingCharacters(in: .whitespaces).count >= 2 && beneficiaryPhone.filter(\.isNumber).count >= 8 && beneficiaryConsent)
    }

    func set(_ point: RidePoint) {
        if editing == "pickup" {
            pickup = point
        } else if editing == "destination" {
            destination = point
            openDestination = false
        } else if editing.hasPrefix("stop:"), let i = Int(editing.dropFirst(5)) {
            if i < stops.count { stops[i] = point } else { stops.append(point) }
        }
    }

    func resetBooking() {
        hourlyHours = nil
        hourlySlug = nil
        destination = nil
        stops = []
        openDestination = false
        quotes = []
        selected = nil
        step = .map
        error = nil
        scheduledAt = nil
        fromHistory = false
        couponCode = nil
        couponError = nil
        passengers = 1
        pickupNote = ""
        quietRide = nil
        forOther = false
        beneficiaryName = ""
        beneficiaryPhone = ""
        beneficiaryConsent = false
        savingPlace = nil
        pickupPointId = nil
        dropoffPointId = nil
        pointsChooser = nil
        pointChoices = nil
        decidedPickup = nil
        decidedDropoff = nil
        preferCategory = nil
        suggestion = nil
        addons = TripOptions()
        addonsApplied = TripOptions()
        addonsError = nil
        useCircle = false
        circlePicked = []
    }
}

/// A ride to open when the rides screen shows — set by a tapped ride notification (O-41).
@MainActor
final class RidesLink: ObservableObject {
    static let shared = RidesLink()
    @Published var pending: String?
    /// DORR AI's chat: "Book with DORR" — the trip it understood, to fill the booking in when rides opens.
    @Published var prefill: AiServiceActionDto?
    /// Set with `prefill`: the app opens the rides screen (over DORR AI, which is there again on back).
    @Published var openRequested = false

    func book(_ action: AiServiceActionDto) {
        prefill = action
        openRequested = true
    }

    func show(_ uuid: String) { pending = uuid }
}

/// "25.50 ر.س" from halalas and the ride's currency.
func rideMoney(_ minor: Int?, _ currency: RideCurrencyDto?) -> String {
    guard let minor else { return "—" }
    let places = currency?.decimals ?? 2
    let value = Double(minor) / pow(10, Double(places))
    let f = NumberFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.minimumFractionDigits = places
    f.maximumFractionDigits = places
    f.usesGroupingSeparator = false
    let text = f.string(from: NSNumber(value: value)) ?? String(format: "%.\(places)f", value)
    return "\(text) \(currency?.symbol ?? "")".trimmingCharacters(in: .whitespaces)
}

/// Google's encoded polyline → points (the route drawn on the map).
func decodePolyline(_ encoded: String?) -> [CLLocationCoordinate2D] {
    guard let encoded, !encoded.isEmpty else { return [] }
    let bytes = Array(encoded.utf8)
    var points: [CLLocationCoordinate2D] = []
    var index = 0, lat = 0, lng = 0
    func next() -> Int {
        var result = 0, shift = 0, b = 0
        repeat {
            guard index < bytes.count else { break }
            b = Int(bytes[index]) - 63
            index += 1
            result |= (b & 0x1F) << shift
            shift += 5
        } while b >= 0x20 && index < bytes.count
        return (result & 1) != 0 ? ~(result >> 1) : result >> 1
    }
    while index < bytes.count {
        lat += next()
        lng += next()
        points.append(CLLocationCoordinate2D(latitude: Double(lat) / 1e5, longitude: Double(lng) / 1e5))
    }
    return points
}

/// "12 د" / "1 س 5 د" from seconds.
func minutesText(_ seconds: Int?, _ minuteWord: String, _ hourWord: String) -> String {
    let total = ((seconds ?? 0) + 59) / 60
    return total < 60 ? "\(total) \(minuteWord)" : "\(total / 60) \(hourWord) \(total % 60) \(minuteWord)"
}

/// "24.71360, 46.67530" — a point without a name.
func coordinateText(_ c: CLLocationCoordinate2D) -> String { String(format: "%.5f, %.5f", c.latitude, c.longitude) }

// MARK: - Location

/// The phone's location: permission, on / off, and one fix at a time (`currentLocation` on Android).
@MainActor
final class RideLocation: NSObject, ObservableObject, CLLocationManagerDelegate {
    static let shared = RideLocation()
    private let manager = CLLocationManager()
    @Published private(set) var status: CLAuthorizationStatus
    private var waiting: [CheckedContinuation<CLLocationCoordinate2D?, Never>] = []

    override init() {
        status = manager.authorizationStatus
        super.init()
        manager.delegate = self
        manager.desiredAccuracy = kCLLocationAccuracyBest
    }

    var allowed: Bool { status == .authorizedWhenInUse || status == .authorizedAlways }
    var denied: Bool { status == .denied || status == .restricted }
    /// Location services switched on for the whole phone.
    var switchedOn: Bool { CLLocationManager.locationServicesEnabled() }
    /// O-48: allowed *and* switched on.
    var ready: Bool { allowed && switchedOn }

    func ask() { manager.requestWhenInUseAuthorization() }

    func refresh() { status = manager.authorizationStatus }

    /// The position now (nil without the permission or a fix).
    func current() async -> CLLocationCoordinate2D? {
        guard allowed else { return nil }
        return await withCheckedContinuation { cont in
            waiting.append(cont)
            if waiting.count == 1 { manager.requestLocation() }
        }
    }

    private func finish(_ value: CLLocationCoordinate2D?) {
        let all = waiting
        waiting = []
        all.forEach { $0.resume(returning: value) }
    }

    nonisolated func locationManagerDidChangeAuthorization(_ manager: CLLocationManager) {
        let s = manager.authorizationStatus
        Task { @MainActor in self.status = s }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didUpdateLocations locations: [CLLocation]) {
        let c = locations.last?.coordinate
        Task { @MainActor in self.finish(c) }
    }

    nonisolated func locationManager(_ manager: CLLocationManager, didFailWithError error: Error) {
        Task { @MainActor in self.finish(nil) }
    }
}

/// The street / district under a point, in the app's language.
func addressOf(_ at: CLLocationCoordinate2D) async -> String? {
    let placemarks = try? await CLGeocoder().reverseGeocodeLocation(CLLocation(latitude: at.latitude, longitude: at.longitude),
                                                                    preferredLocale: Locale(identifier: AppLocale.current))
    guard let p = placemarks?.first else { return nil }
    let parts = [p.thoroughfare ?? p.name, p.subLocality ?? p.locality].compactMap { $0 }
    var unique: [String] = []
    parts.forEach { if !unique.contains($0) { unique.append($0) } }
    let text = unique.joined(separator: "، ")
    return text.isEmpty ? p.name : text
}

/// Places matching what the rider typed, nearest to them first (O-57): the server's search (the admin's map
/// provider), from two letters; the phone's own search only when the server has nothing.
func searchPlaces(_ query: String, near: CLLocationCoordinate2D?) async -> [RidePoint] {
    let q = query.trimmingCharacters(in: .whitespaces)
    guard q.count >= 2 else { return [] }
    let server = ((try? await RidesApi.placeSuggest(q: q, lat: near?.latitude, lng: near?.longitude).data) ?? []).compactMap { hit -> RidePoint? in
        guard let lat = hit.lat, let lng = hit.lng else { return nil }
        let name = (hit.name ?? "").isEmpty ? nil : hit.name
        return RidePoint(lat: lat, lng: lng, address: (hit.address ?? "").isEmpty ? (hit.name ?? "") : hit.address!, name: name)
    }
    if !server.isEmpty || q.count < 3 { return server }
    let request = MKLocalSearch.Request()
    request.naturalLanguageQuery = q
    if let near { request.region = MKCoordinateRegion(center: near, latitudinalMeters: 110_000, longitudinalMeters: 110_000) }
    let found = (try? await MKLocalSearch(request: request).start())?.mapItems ?? []
    return found.prefix(8).map { item in
        let c = item.placemark.coordinate
        return RidePoint(lat: c.latitude, lng: c.longitude, address: item.placemark.title ?? item.name ?? q, name: item.name)
    }
}

/// O-57: before anything is typed — the popular places within 50 km (empty when unknown).
func popularPlaces(near: CLLocationCoordinate2D?) async -> [RidePoint] {
    guard let near else { return [] }
    return ((try? await RidesApi.placePopular(lat: near.latitude, lng: near.longitude).data) ?? []).compactMap { hit in
        guard let lat = hit.lat, let lng = hit.lng else { return nil }
        let name = (hit.name ?? "").isEmpty ? nil : hit.name
        return RidePoint(lat: lat, lng: lng, address: (hit.address ?? "").isEmpty ? (hit.name ?? "") : hit.address!, name: name)
    }
}

// MARK: - Shared pieces

extension WaPalette {
    /// `settingsBackground()` — the page base (white by day).
    var settingsBackground: Color { night ? bg : .white }
}

/// The white card at the bottom that every step draws into.
struct PanelCard<Content: View>: View {
    @Environment(\.wa) private var wa
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Capsule().fill(wa.settingsInk.opacity(0.15)).frame(width: 38, height: 4).frame(maxWidth: .infinity).padding(.bottom, 12)
            content()
        }
        .padding(.horizontal, 18)
        .padding(.top, 10)
        .padding(.bottom, 24)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.settingsCard, in: UnevenRoundedRectangleCompat(topRadius: 26))
        .overlay { if wa.night { UnevenRoundedRectangleCompat(topRadius: 26).stroke(wa.line, lineWidth: 1) } }
        .shadow(color: .black.opacity(wa.night ? 0 : 0.14), radius: 18, y: -2)
    }
}

/// The main action of a step: rounded, in the app colour, with its own glow by day and a small press-in.
struct AccentButton: View {
    @Environment(\.wa) private var wa
    let text: String
    var enabled = true
    var busy = false
    var color: Color?
    var icon: String?
    let action: () -> Void

    var body: some View {
        let c = color ?? wa.accent
        Button(action: action) {
            ZStack {
                if busy {
                    ProgressView().tint(.white)
                } else {
                    HStack(spacing: 8) {
                        if let icon { Image(systemName: icon).font(.system(size: 17, weight: .semibold)) }
                        Text(verbatim: text).font(.dorr(16, .bold)).lineLimit(1).minimumScaleFactor(0.8)
                    }
                    .foregroundStyle(.white)
                }
            }
            .frame(maxWidth: .infinity, minHeight: 54)
            .background(enabled ? c : c.opacity(0.4), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: enabled && !busy && !wa.night ? c.opacity(0.35) : .clear, radius: 10, y: 4)
        }
        .buttonStyle(WaPressStyle())
        .disabled(!enabled || busy)
        .animation(.easeInOut(duration: 0.2), value: enabled)
    }
}

/// The round map buttons (back, my location, safety…).
struct RideRoundButton: View {
    @Environment(\.wa) private var wa
    let icon: String
    var label: String?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 17, weight: .semibold)).foregroundStyle(wa.settingsInk)
                .frame(width: 44, height: 44)
                .background(wa.settingsCard, in: Circle())
                .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
                .shadow(color: wa.night ? .clear : .black.opacity(0.14), radius: 6, y: 2)
        }
        .buttonStyle(WaPressStyle())
        .accessibilityLabel(label ?? "")
    }
}

/// Rises and fades in the first time it is shown, one after another by `index` (`staggerIn`).
struct StaggerIn: ViewModifier {
    let index: Int
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown ? 1 : 0)
            .offset(y: shown ? 0 : 22)
            .onAppear {
                withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.38).delay(Double(min(index, 8)) * 0.045)) { shown = true }
            }
    }
}

extension View {
    func staggerIn(_ index: Int = 0) -> some View { modifier(StaggerIn(index: index)) }
}

/// The server's message for a failed call, else the generic one.
func rideError(_ error: Error) -> String { error.serverMessage ?? L("rd_error") }
