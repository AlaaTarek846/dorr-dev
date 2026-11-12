import CoreLocation
import SwiftUI
import UIKit

// androidApp `rides/RidesHost.kt`, `RidesServices.kt` (ServicePicker), `LocationGate.kt`, `RidesUnavailable.kt` and
// `RidesNearbyCars.kt` — DORR Rides for the rider, opened from the services (module `passenger_ride`). One screen
// on a map: set the pickup under the pin, say where to, pick a ride and a way to pay, then follow the captain until
// the end and rate. An active ride is picked up again when the screen opens.

private let riyadh = CLLocationCoordinate2D(latitude: 24.7136, longitude: 46.6753)

@MainActor
struct RidesHost: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var location = RideLocation.shared
    @ObservedObject private var link = RidesLink.shared
    @StateObject private var state = RidesState()
    @StateObject private var camera = DorrCamera(start: riyadh, zoom: 15)
    let onExit: () -> Void
    @State private var resumed = false
    /// O-50: the real position is known (or the rider moved the map) — the starting view is no place.
    @State private var located = false
    @State private var nearby: [String: NearbyCarDto] = [:]

    var body: some View {
        ZStack {
            wa.settingsBackground.ignoresSafeArea()
            ridesMap
            let pinVisible = state.ride == nil && (state.step == .map || state.step == .pickOnMap)
            if pinVisible {
                PickupPin(lifted: camera.isMoving, color: state.step == .map ? AppColors.brandNavy : AppColors.brandOrange,
                          label: L(state.step == .map ? "pk_pin_pickup" : "pk_pin_here"))
            }
            // Back, and "my location".
            VStack {
                HStack(alignment: .top) {
                    RideRoundButton(icon: "arrow.backward", label: L("common_back")) {
                        if state.ride == nil && state.step != .map { state.resetBooking() } else { onExit() }
                    }
                    Spacer()
                    VStack(spacing: 10) {
                        // 🛡️ The safety button, always on top (plan §18.13, R-219).
                        RideRoundButton(icon: "shield.fill") { state.safetyOpen = true }
                        // My rides: current, upcoming and past (R-163..165).
                        if state.ride == nil { RideRoundButton(icon: "list.bullet.rectangle") { state.myRidesOpen = true } }
                        if let me = state.me, state.ride == nil {
                            RideRoundButton(icon: "location.fill") { camera.animate(to: me, zoom: 16) }
                        }
                    }
                }
                .padding(16)
                Spacer()
            }
            VStack {
                Spacer()
                panel
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32), value: panelKey)
            }
            .ignoresSafeArea(edges: .bottom)
            if state.step == .search && state.ride == nil {
                PlaceSearchPage(state: state, camera: camera).transition(.move(edge: .bottom).combined(with: .opacity)).zIndex(2)
            }
            // O-18: the services first — unless a ride is already on (then straight to it).
            if resumed && state.ride == nil && !state.servicePicked {
                ServicePicker(ready: readyServices, onPick: { svc in
                    switch svc {
                    case .bus: state.busOpen = true
                    case .marine: state.marineOpen = true
                    case .findDriver: state.hireOpen = true
                    case .lessons: state.lessonsOpen = true
                    default: withAnimation(.easeInOut(duration: 0.26)) { state.servicePicked = true }
                    }
                }, onBack: onExit, onMyRides: { state.myRidesOpen = true },
                onLoyalty: state.feature("loyalty") ? { state.loyaltyOpen = true } : nil)
                .transition(.opacity.combined(with: .move(edge: .leading)))
                .zIndex(3)
            }
            if state.busOpen {
                BusHost(state: state) { state.busOpen = false }.transition(.move(edge: .trailing).combined(with: .opacity)).zIndex(4)
            }
            if state.marineOpen {
                MarineHost(state: state) { state.marineOpen = false }.transition(.move(edge: .trailing).combined(with: .opacity)).zIndex(4)
            }
            if state.loyaltyOpen {
                LoyaltyScreen { state.loyaltyOpen = false }.transition(.move(edge: .bottom).combined(with: .opacity)).zIndex(4)
            }
            if state.hireOpen {
                HireHost(state: state) { state.hireOpen = false }.transition(.move(edge: .trailing).combined(with: .opacity)).zIndex(4)
            }
            if state.lessonsOpen {
                LessonsHost(state: state) { state.lessonsOpen = false }.transition(.move(edge: .trailing).combined(with: .opacity)).zIndex(4)
            }
            if state.changing != nil && state.ride != nil {
                ChangeTripPage(state: state) { state.changing = nil }.transition(.move(edge: .bottom).combined(with: .opacity)).zIndex(4)
            }
            if state.safetyOpen {
                SafetyCenterPage(state: state) { state.safetyOpen = false }.transition(.move(edge: .trailing)).zIndex(4)
            }
            if state.casesOpen {
                CasesPage(state: state) { state.casesOpen = false }.transition(.move(edge: .trailing)).zIndex(5)
            }
            if state.myRidesOpen {
                MyRidesPage(state: state) { state.myRidesOpen = false }.transition(.move(edge: .trailing)).zIndex(4)
            }
            // O-48: nothing here works without the location — a window that stays until it's allowed and on.
            if !location.ready {
                LocationGate(onLeave: onExit).zIndex(5)
            }
            // O-09: the in-app call with the captain, over everything.
            RideCallOverlay(onAccept: {
                let session = RideCallSession.shared
                guard let ride = session.rideUuid, let call = session.callUuid else { return }
                let other = session.acceptApi
                session.accept {
                    if let other { return try await other(ride, call) }
                    guard let dto = try await RidesApi.acceptCall(ride, call).data else { throw NoCallJoin() }
                    return RideCallJoin(dto)
                }
            })
            .zIndex(6)
        }
        .animation(.easeInOut(duration: 0.25), value: state.step)
        .animation(.easeInOut(duration: 0.25), value: state.servicePicked)
        .animation(.easeInOut(duration: 0.3), value: state.myRidesOpen)
        .animation(.easeInOut(duration: 0.3), value: state.busOpen)
        .animation(.easeInOut(duration: 0.3), value: state.marineOpen)
        .animation(.easeInOut(duration: 0.3), value: state.hireOpen)
        .animation(.easeInOut(duration: 0.3), value: state.lessonsOpen)
        .animation(.easeInOut(duration: 0.3), value: state.loyaltyOpen)
        .animation(.easeInOut(duration: 0.3), value: state.safetyOpen)
        .animation(.easeInOut(duration: 0.3), value: state.casesOpen)
        .animation(.easeInOut(duration: 0.3), value: state.changing)
        // "Are you OK?" — during the ride (from the ride) or after it (the check-in).
        .dorrDialog(isPresented: Binding(get: { (state.ride?.safetyCheck ?? state.pendingCheck) != nil }, set: { _ in })) {
            if let check = state.ride?.safetyCheck ?? state.pendingCheck,
               let rideUuid = state.ride?.safetyCheck != nil ? state.ride?.uuid : state.pendingCheck?.rideUuid {
                SafetyCheckDialog(rideUuid: rideUuid, check: check) {
                    state.pendingCheck = nil
                    state.ride?.safetyCheck = nil
                }
            }
        }
        // Block 06: «دُرر يفهم مقصدك» over the map or the search.
        .sheet(isPresented: $state.smartOpen) {
            SmartDestinationSheet(state: state, camera: camera) { state.smartOpen = false }.dorrThemed().presentationDetents([.large])
        }
        .sheet(isPresented: $state.scheduleOpen) {
            SchedulePickerSheet(state: state) { state.scheduleOpen = false }.dorrThemed().presentationDetents([.large])
        }
        .task {
            // O-53: the admin's map engine (already read from the phone at start) — any change, for next time too.
            await MapEngine.shared.refresh()
            // An active ride first: the app may have been closed in the middle of one.
            if let ride = try? await RidesApi.current() { state.ride = ride }
            resumed = true
            // A check-in still waiting after a ride that ended (R-223).
            state.pendingCheck = try? await RidesApi.safety().data?.pendingCheck
            #if DEBUG
            // -DorrRidesStep map|quotes (with fixtures): straight to the map, or to the prices of a trip.
            if let step = UserDefaults.standard.string(forKey: "DorrRidesStep") {
                state.servicePicked = true
                if step == "bus" || step == "seats" { state.busOpen = true }
                if step == "quotes" {
                    try? await Task.sleep(nanoseconds: 1_500_000_000)
                    state.destination = RidePoint(lat: 24.756, lng: 46.629, address: "الرياض بارك")
                    state.loadQuotes()
                }
            }
            #endif
        }
        // A booked service's notification: open that service (it opens the booking itself).
        .onReceive(ServiceLink.shared.$pending) { p in
            switch p?.kind {
            case "loyalty": state.loyaltyOpen = true; ServiceLink.shared.pending = nil
            case "bus": state.busOpen = true
            case "marine": state.marineOpen = true
            case "hire": state.hireOpen = true
            case "lesson": state.lessonsOpen = true
            default: break
            }
        }
        // DORR AI's chat: "Book with DORR" — the trip it understood fills the booking in: the pickup is where the
        // rider is now, the destination the place understood, and the prices open at once with the route drawn on
        // the map (the confirmation — the destination can still be changed there). When no place was found, the
        // assistant opens with the person's words.
        .task(id: "\(link.prefill != nil)|\(state.features != nil)|\(resumed)") {
            guard let action = link.prefill, resumed, state.features != nil, state.ride == nil else { return }
            link.prefill = nil
            state.servicePicked = true
            state.resetBooking()
            if let place = action.destination {
                state.applyAiBooking(action.understood)
                // The pickup first: where the rider is now (the map may not have placed it yet).
                if state.pickup == nil, location.ready, let here = await location.current() {
                    state.me = here
                    state.pickup = RidePoint(lat: here.latitude, lng: here.longitude, address: await addressOf(here) ?? coordinateText(here))
                }
                state.editing = "destination"
                let address = (place.address ?? "").isEmpty ? (place.name ?? "") : (place.address ?? "")
                state.set(RidePoint(lat: place.lat, lng: place.lng, address: address, name: place.name))
                if state.pickup != nil {
                    state.loadQuotes()
                } else {
                    // No position (location off): the rider sets the pickup, the destination stays filled in.
                    state.editing = "pickup"
                    state.step = .search
                }
            } else if state.feature("ai_booking") {
                state.smartText = action.text
                state.smartOpen = true
            } else {
                state.step = .search
            }
        }
        // A tapped ride notification: that ride — an ended one shows its receipt and the rating (O-41).
        .task(id: link.pending) {
            guard let uuid = link.pending else { return }
            link.pending = nil
            if let ride = try? await RidesApi.ride(uuid).data, state.ride == nil || state.ride?.uuid == uuid || ride.status == "completed" { state.ride = ride }
        }
        // Where am I: the camera goes there, and the service switches for this city are read. O-48, O-50: located
        // again the moment location is allowed and on — and that real place becomes the pickup.
        .task(id: "\(location.ready)|\(state.featuresTick)") {
            let here = location.ready ? await location.current() : nil
            state.me = here
            if let here, state.ride == nil {
                camera.move(to: here, zoom: 16)
                if state.step == .map {
                    state.pickup = RidePoint(lat: here.latitude, lng: here.longitude, address: await addressOf(here) ?? coordinateText(here))
                }
                located = true
            }
            state.features = try? await RidesApi.features(lat: here?.latitude, lng: here?.longitude).data
            await state.loadPlaces()
            await state.loadCircle()
        }
        // The pin: when the map stops, the point under it becomes the pickup (or the point being picked).
        .onChange(of: camera.isMoving) { moving in pinMoved(moving) }
        // O-59: before a ride, the free captains' cars around, moving (like inDrive): one picture on open, then each car's
        // new place arrives on the area's public channel (`rides-area.{cell}`, `area.car`) and the car slides there. A car
        // not heard from for a while fades out by the phone's own clock. Nothing asks the server on a timer.
        .task(id: nearbyCell) {
            guard state.ride == nil, let near = state.me ?? state.pickup?.latLng,
                  let snapshot = try? await RidesApi.nearbyCars(lat: near.latitude, lng: near.longitude).data else { nearby = [:]; return }
            nearby = Dictionary((snapshot.cars ?? []).map { ($0.car, $0) }, uniquingKeysWith: { a, _ in a })
            let seen = NearbySeen()
            nearby.keys.forEach { seen.at[$0] = Date() }
            let channels = (snapshot.cells ?? []).map { "rides-area.\($0)" }
            channels.forEach { ChatRealtime.shared.watchPublic($0, ["area.car"]) }
            defer { channels.forEach { ChatRealtime.shared.unwatchPublic($0) } }
            var slides: [String: Task<Void, Never>] = [:]
            defer { slides.values.forEach { $0.cancel() } }
            let sweep = Task { @MainActor in
                while !Task.isCancelled {
                    try? await Task.sleep(nanoseconds: 15_000_000_000)
                    let cut = Date().addingTimeInterval(-90)
                    for (id, at) in seen.at where at < cut {
                        seen.at[id] = nil
                        nearby[id] = nil
                    }
                }
            }
            defer { sweep.cancel() }
            for await e in ChatRealtime.shared.events.values {
                guard e.name == "area.car", let id = e.str("car") else { continue }
                if e.data["gone"]?.bool == true {
                    slides.removeValue(forKey: id)?.cancel()
                    seen.at[id] = nil
                    nearby[id] = nil
                    continue
                }
                guard let lat = e.data["lat"]?.dbl, let lng = e.data["lng"]?.dbl else { continue }
                let heading = e.data["heading"]?.dbl.map { Int($0) }
                seen.at[id] = Date()
                guard let from = nearby[id] else {
                    nearby[id] = NearbyCarDto(car: id, lat: lat, lng: lng, heading: heading ?? 0)
                    continue
                }
                // Slide from where it's drawn to the new place over the next few seconds (until the next one comes).
                slides.removeValue(forKey: id)?.cancel()
                slides[id] = Task { @MainActor in
                    for step in 1...19 {
                        try? await Task.sleep(nanoseconds: 200_000_000)
                        if Task.isCancelled { return }
                        let f = Double(step) / 19
                        nearby[id] = NearbyCarDto(car: id, lat: from.lat + (lat - from.lat) * f, lng: from.lng + (lng - from.lng) * f, heading: heading ?? from.heading)
                    }
                }
            }
        }
    }

    private var nearbyCell: String {
        guard let near = state.me ?? state.pickup?.latLng else { return "" }
        return "\(Int(floor(near.latitude / 0.05)))_\(Int(floor(near.longitude / 0.05)))|\(state.ride == nil)"
    }

    private var readyServices: Set<RideService> {
        var ready: Set<RideService> = [.instant]
        if state.category("bus") { ready.insert(.bus) }
        if state.category("marine") { ready.insert(.marine) }
        if state.feature("find_driver") { ready.insert(.findDriver) }
        if state.feature("driving_lessons") { ready.insert(.lessons) }
        return ready
    }

    private func pinMoved(_ moving: Bool) {
        let byHand = camera.byGesture
        if moving && state.step == .map && (located || byHand) { state.pickupLoading = true }
        guard !moving, state.ride == nil, located || byHand, state.step == .map || state.step == .pickOnMap else { return }
        let target = camera.target
        Task {
            let name = await addressOf(target) ?? coordinateText(target)
            state.pickupLoading = false
            let point = RidePoint(lat: target.latitude, lng: target.longitude, address: name)
            if state.step == .map { state.pickup = point } else { state.set(point) }
        }
    }

    private var panelKey: String {
        if state.ride != nil { return "ride" }
        if !resumed { return "loading" }
        if let f = state.features, f.service != true { return "off" }
        return state.step.rawValue
    }

    @ViewBuilder
    private var panel: some View {
        switch panelKey {
        case "ride": RideActivePanel(state: state, onExit: onExit)
        case "loading": PanelCard { Text(L("rd_locating")).font(.dorr(15)).foregroundStyle(wa.settingsInk).padding(8) }
        case "off": RidesUnavailablePanel(reason: state.features?.reason, hasLocation: location.allowed, onAskLocation: { location.ask() }) { state.featuresTick += 1 }
        case RideStep.map.rawValue: WhereToPanel(state: state, hasLocation: location.allowed) { location.ask() }
        case RideStep.pickOnMap.rawValue: ConfirmPointPanel(state: state)
        case RideStep.quotes.rawValue: QuotesPanel(state: state)
        default: EmptyView()
        }
    }

    // MARK: The map

    private var ridesMap: some View {
        let ride = state.ride
        let rideStops = (ride?.stops ?? []).map { CLLocationCoordinate2D(latitude: $0.lat, longitude: $0.lng) }
        let stops = ride != nil ? rideStops : state.stops.map(\.latLng)
        let origin = ride?.origin.map { CLLocationCoordinate2D(latitude: $0.lat, longitude: $0.lng) } ?? state.pickup?.latLng
        let destination = ride?.destination.map { CLLocationCoordinate2D(latitude: $0.lat, longitude: $0.lng) } ?? state.destination?.latLng
        let showPoints = ride != nil || state.step == .quotes
        var route = decodePolyline(ride?.route?.polyline ?? state.selected?.route?.polyline)
        if route.isEmpty, showPoints {
            // O-56: no drawn route (e.g. right after a stop is added) — the points joined in order.
            let joined = ([origin] + stops.map { Optional($0) } + [destination]).compactMap { $0 }
            if joined.count >= 2 { route = joined }
        }
        var pins: [MapPin] = []
        if showPoints {
            if let origin { pins.append(MapPin(at: origin, kind: .pickup)) }
            stops.forEach { pins.append(MapPin(at: $0, kind: .stop)) }
            if let destination { pins.append(MapPin(at: destination, kind: .dropoff)) }
        }
        if let loc = ride?.driver?.location {
            pins.append(MapPin(at: CLLocationCoordinate2D(latitude: loc.lat, longitude: loc.lng), kind: .car, rotation: Double(loc.heading ?? 0), id: "my-captain"))
        }
        // O-59: before a ride, the free captains' cars around.
        if ride == nil {
            for (id, car) in nearby.sorted(by: { $0.key < $1.key }) {
                pins.append(MapPin(at: CLLocationCoordinate2D(latitude: car.lat, longitude: car.lng), kind: .car, rotation: Double(car.heading ?? 0), id: "car:\(id)"))
            }
        }
        let fitKey = "\(route.count)|\(ride?.status ?? "")|\(state.selected?.uuid ?? "")"
        return DorrMap(camera: camera, pins: pins, lines: showPoints && route.count > 1 ? [MapLine(points: route, color: MapColors.route, width: 5)] : [],
                       padding: EdgeInsets(top: 0, leading: 0, bottom: 260, trailing: 0))
            .ignoresSafeArea()
            // Keep the whole trip in view once it is planned or running.
            .task(id: fitKey) {
                let points = route.isEmpty ? [origin, destination].compactMap { $0 } : route
                if showPoints { camera.fit(points, padding: 48) }
            }
    }
}

// MARK: - The services (O-18)

/// What the rides service offers. `ready` = it opens now; the others say «coming soon».
enum RideService: CaseIterable {
    case instant, bus, marine, parcels, food, findDriver, lessons

    var icon: String {
        switch self {
        case .instant: return "car.fill"
        case .bus: return "bus.fill"
        case .marine: return "ferry.fill"
        case .parcels: return "shippingbox.fill"
        case .food: return "fork.knife"
        case .findDriver: return "person.crop.circle.badge.magnifyingglass"
        case .lessons: return "graduationcap.fill"
        }
    }

    var title: String {
        switch self {
        case .instant: return L("svc_instant")
        case .bus: return L("svc_bus")
        case .marine: return L("svc_marine")
        case .parcels: return L("svc_parcels")
        case .food: return L("svc_food")
        case .findDriver: return L("svc_find_driver")
        case .lessons: return L("svc_lessons")
        }
    }

    var hint: String {
        switch self {
        case .instant: return L("svc_instant_hint")
        case .bus: return L("svc_bus_hint")
        case .marine: return L("svc_marine_hint")
        case .parcels: return L("svc_parcels_hint")
        case .food: return L("svc_food_hint")
        case .findDriver: return L("svc_find_driver_hint")
        case .lessons: return L("svc_lessons_hint")
        }
    }

    var from: Color {
        switch self {
        case .instant: return Color(hex: 0x001B53)
        case .bus: return Color(hex: 0x3949AB)
        case .marine: return Color(hex: 0x0277BD)
        case .parcels: return Color(hex: 0xEF6C00)
        case .food: return Color(hex: 0xC62828)
        case .findDriver: return Color(hex: 0x00695C)
        case .lessons: return Color(hex: 0x6A1B9A)
        }
    }

    var to: Color {
        switch self {
        case .instant: return Color(hex: 0x2B4C9B)
        case .bus: return Color(hex: 0x7986CB)
        case .marine: return Color(hex: 0x26C6DA)
        case .parcels: return Color(hex: 0xFFB74D)
        case .food: return Color(hex: 0xFF7043)
        case .findDriver: return Color(hex: 0x4DB6AC)
        case .lessons: return Color(hex: 0xBA68C8)
        }
    }
}

/// The first screen of the rides service: every way DORR moves you, as big living cards — a gradient each, the icon
/// floating in its glow, the cards landing one after another. What isn't open yet carries a shimmering «coming soon»
/// and answers with a friendly note instead of a page.
struct ServicePicker: View {
    @Environment(\.wa) private var wa
    let ready: Set<RideService>
    let onPick: (RideService) -> Void
    let onBack: () -> Void
    var onMyRides: (() -> Void)?
    var onLoyalty: (() -> Void)?
    @State private var soon: RideService?
    @State private var shown = false

    var body: some View {
        ZStack(alignment: .bottom) {
            wa.settingsBackground.ignoresSafeArea()
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    header
                    Text(L("svc_more")).font(.dorr(18, .black)).foregroundStyle(wa.settingsInk).padding(.horizontal, 20).padding(.top, 20).padding(.bottom, 10)
                    let rest = RideService.allCases.filter { $0 != .instant }
                    LazyVGrid(columns: [GridItem(.flexible(), spacing: 12), GridItem(.flexible(), spacing: 12)], spacing: 12) {
                        ForEach(Array(rest.enumerated()), id: \.offset) { i, svc in
                            ServiceCard(svc: svc, ready: ready.contains(svc), index: i) { tap(svc) }
                        }
                    }
                    .padding(.horizontal, 14)
                    Spacer(minLength: 24)
                }
            }
            .ignoresSafeArea(edges: .top)
            // «Coming soon»: a friendly card rising from the bottom, gone by itself.
            if let s = soon {
                HStack(spacing: 12) {
                    Image(systemName: s.icon).font(.system(size: 28)).foregroundStyle(.white)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: L("svc_soon_title", s.title)).font(.dorr(15, .black)).foregroundStyle(.white)
                        Text(L("svc_soon_body")).font(.dorr(12)).foregroundStyle(.white.opacity(0.9))
                    }
                    Spacer(minLength: 0)
                }
                .padding(16)
                .background(LinearGradient(colors: [s.from, s.to], startPoint: .leading, endPoint: .trailing), in: RoundedRectangle(cornerRadius: 22, style: .continuous))
                .shadow(radius: 18)
                .padding(16)
                .transition(.move(edge: .bottom).combined(with: .opacity))
            }
        }
        .animation(.spring(response: 0.4, dampingFraction: 0.7), value: soon)
        .task(id: soon) {
            guard soon != nil else { return }
            try? await Task.sleep(nanoseconds: 2_400_000_000)
            soon = nil
        }
    }

    private func tap(_ svc: RideService) {
        if ready.contains(svc) { onPick(svc) } else { soon = svc }
    }

    /// The header: a deep gradient with drifting rings and the road's dashes sliding behind the title.
    private var header: some View {
        ZStack(alignment: .topLeading) {
            TimelineView(.animation) { context in
                Canvas { gc, size in
                    let t = context.date.timeIntervalSinceReferenceDate
                    let ring = (t / 5.2).truncatingRemainder(dividingBy: 1)
                    let road = (t / 3.8).truncatingRemainder(dividingBy: 1)
                    let c = CGPoint(x: size.width * 0.85, y: size.height * 0.2)
                    for i in 0..<3 {
                        let p = (ring + Double(i) / 3).truncatingRemainder(dividingBy: 1)
                        let r = size.width * (0.15 + p * 0.6)
                        gc.fill(Path(ellipseIn: CGRect(x: c.x - r, y: c.y - r, width: 2 * r, height: 2 * r)), with: .color(.white.opacity((1 - p) * 0.14)))
                    }
                    let y = size.height - 9
                    var x = -27 + road * 27
                    while x < size.width {
                        gc.fill(Path(roundedRect: CGRect(x: x, y: y, width: 15, height: 2), cornerRadius: 1), with: .color(.white.opacity(0.22)))
                        x += 27
                    }
                }
            }
            .environment(\.layoutDirection, .leftToRight)
            VStack(alignment: .leading, spacing: 0) {
                HStack {
                    glass("arrow.backward", onBack)
                    Spacer()
                    // R-147: my DORR points.
                    if let onLoyalty { glass("crown.fill", onLoyalty) }
                    if let onMyRides { glass("list.bullet.rectangle", onMyRides) }
                }
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("svc_hello")).font(.dorr(14)).foregroundStyle(.white.opacity(0.85))
                    Text(L("svc_title")).font(.dorr(28, .black)).foregroundStyle(.white)
                }
                .padding(.top, 18)
                .offset(y: shown ? 0 : 30).opacity(shown ? 1 : 0)
                .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.52)) { shown = true } }
                // The hero card: the everyday ride, big and first.
                HeroService(svc: .instant, ready: ready.contains(.instant)) { tap(.instant) }.padding(.top, 16)
            }
            .padding(.horizontal, 18)
            .padding(.top, 56)
            .padding(.bottom, 34)
        }
        .background(LinearGradient(colors: [wa.accent, Color(hex: 0x2B4C9B), AppColors.brandOrange.opacity(0.9)], startPoint: .topLeading, endPoint: .bottomTrailing))
        .clipShape(BottomRoundedRectangle(radius: 36))
    }

    private func glass(_ icon: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 17, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 40, height: 40).background(Color.white.opacity(0.18), in: Circle())
        }
        .buttonStyle(WaPressStyle())
    }
}

private struct HeroService: View {
    let svc: RideService
    let ready: Bool
    let action: () -> Void
    @State private var bob = false

    var body: some View {
        Button(action: action) {
            HStack(spacing: 0) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: svc.title).font(.dorr(20, .black)).foregroundStyle(svc.from)
                    Text(verbatim: svc.hint).font(.dorr(12)).foregroundStyle(Color(hex: 0x6B7280))
                    HStack(spacing: 6) {
                        ForEach(["svc_tag_economy", "svc_tag_vip", "svc_tag_family", "svc_tag_moto"], id: \.self) { tag in
                            Text(L(tag)).font(.dorr(10, .bold)).foregroundStyle(svc.from)
                                .padding(.horizontal, 8).padding(.vertical, 3).background(svc.from.opacity(0.08), in: Capsule())
                        }
                    }
                    .padding(.top, 10)
                    if !ready { SoonBadge().padding(.top, 8) }
                }
                Spacer(minLength: 4)
                ZStack(alignment: .bottomTrailing) {
                    Circle().fill(RadialGradient(colors: [svc.to.opacity(0.35), .clear], center: .center, startRadius: 0, endRadius: 40)).frame(width: 80, height: 80)
                    Image(systemName: svc.icon).font(.system(size: 28)).foregroundStyle(.white)
                        .frame(width: 62, height: 62)
                        .background(LinearGradient(colors: [svc.from, svc.to], startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                        .frame(width: 80, height: 80)
                    Image(systemName: "bicycle").font(.system(size: 18)).foregroundStyle(svc.to)
                }
                .frame(width: 86, height: 86)
                .offset(y: bob ? 4 : -4)
                .onAppear { withAnimation(.easeInOut(duration: 1.6).repeatForever()) { bob = true } }
            }
            .padding(16)
            .background(Color.white, in: RoundedRectangle(cornerRadius: 26, style: .continuous))
            .shadow(color: .black.opacity(0.3), radius: 20, y: 6)
        }
        .buttonStyle(WaPressStyle())
    }
}

/// One service: its gradient, its icon floating in a glow; lands with a spring.
private struct ServiceCard: View {
    let svc: RideService
    let ready: Bool
    let index: Int
    let action: () -> Void
    @State private var landed = false
    @State private var bob = false

    var body: some View {
        Button(action: action) {
            ZStack(alignment: .topTrailing) {
                Circle().fill(Color.white.opacity(0.1)).frame(width: 120, height: 120).offset(x: 40, y: -40)
                VStack(alignment: .leading, spacing: 0) {
                    Image(systemName: svc.icon).font(.system(size: 26)).foregroundStyle(.white)
                        .frame(width: 54, height: 54).background(Color.white.opacity(0.2), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                        .offset(y: bob ? 3 : -3)
                    Spacer(minLength: 0)
                    Text(verbatim: svc.title).font(.dorr(16, .black)).foregroundStyle(.white)
                    Text(verbatim: svc.hint).font(.dorr(11)).foregroundStyle(.white.opacity(0.85)).lineLimit(2)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .leading)
                if !ready { SoonBadge() }
            }
            .padding(14)
            .aspectRatio(0.92, contentMode: .fit)
            .background(LinearGradient(colors: [svc.from, svc.to], startPoint: .topLeading, endPoint: .bottomTrailing))
            .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
            .shadow(color: svc.from.opacity(0.35), radius: 12, y: 4)
        }
        .buttonStyle(WaPressStyle())
        .opacity(landed ? 1 : 0)
        .scaleEffect(landed ? 1 : 0.85)
        .offset(y: landed ? 0 : 60)
        .onAppear {
            withAnimation(.spring(response: 0.6, dampingFraction: 0.62).delay(0.09 * Double(index) + 0.12)) { landed = true }
            withAnimation(.easeInOut(duration: 1.5 + Double(index) * 0.13).repeatForever()) { bob = true }
        }
    }
}

/// «Coming soon», with a light sweeping across it.
private struct SoonBadge: View {
    @State private var sweep = false

    var body: some View {
        Text(L("svc_soon")).font(.dorr(10, .black)).foregroundStyle(Color(hex: 0x5D4037))
            .padding(.horizontal, 9).padding(.vertical, 3)
            .background(
                LinearGradient(colors: [Color(hex: 0xFFC107), Color(hex: 0xFFF59D), Color(hex: 0xFFC107)],
                               startPoint: UnitPoint(x: sweep ? 1 : -1, y: 0.5), endPoint: UnitPoint(x: sweep ? 2 : 0, y: 0.5)),
                in: Capsule())
            .onAppear { withAnimation(.linear(duration: 1.8).repeatForever(autoreverses: false)) { sweep = true } }
    }
}

// MARK: - The location gate (O-48)

/// Rides can't work without knowing where the rider is: a window that can't be dismissed asks first for the
/// permission, then for location services to be on (iOS sends the rider to Settings for that). It checks again every
/// time the app comes back to the front — no restart needed.
struct LocationGate: View {
    @Environment(\.scenePhase) private var phase
    @Environment(\.openURL) private var openURL
    @ObservedObject private var location = RideLocation.shared
    let onLeave: (() -> Void)?
    @State private var bob = false

    var body: some View {
        let allowed = location.allowed
        ZStack {
            Color.black.opacity(0.45).ignoresSafeArea()
            VStack(spacing: 0) {
                Image(systemName: "location.fill").font(.system(size: 44)).foregroundStyle(.white).offset(y: bob ? -10 : 0)
                    .frame(width: 96, height: 96)
                    .background(LinearGradient(colors: [Color(hex: 0x001B53), Color(hex: 0x2B4C9B)], startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                    .onAppear { withAnimation(.easeInOut(duration: 0.9).repeatForever()) { bob = true } }
                Text(L(!allowed ? "lg_title_permission" : "lg_title_off")).font(.dorr(20, .black)).foregroundStyle(Color(hex: 0x111928))
                    .multilineTextAlignment(.center).padding(.top, 18)
                Text(L(!allowed ? "lg_body_permission" : "lg_body_off")).font(.dorr(14)).foregroundStyle(Color(hex: 0x6B7280))
                    .multilineTextAlignment(.center).padding(.top, 8)
                Button {
                    if !allowed && !location.denied {
                        location.ask()
                    } else if let url = URL(string: UIApplication.openSettingsURLString) {
                        openURL(url)
                    }
                } label: {
                    Text(L(!allowed ? (location.denied ? "lg_open_settings" : "lg_allow") : "lg_turn_on")).font(.dorr(16, .bold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity).padding(.vertical, 15)
                        .background(Color(hex: 0x001B53), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(WaPressStyle())
                .padding(.top, 22)
                if let onLeave {
                    Button(action: onLeave) {
                        Text(L("lg_leave")).font(.dorr(14)).foregroundStyle(Color(hex: 0x6B7280)).frame(maxWidth: .infinity).padding(.vertical, 10)
                    }
                    .buttonStyle(.plain)
                    .padding(.top, 10)
                }
            }
            .padding(24)
            .background(Color.white, in: RoundedRectangle(cornerRadius: 28, style: .continuous))
            .padding(24)
        }
        .onChange(of: phase) { p in if p == .active { location.refresh() } }
    }
}

/// "Rides aren't available here" — and why: we can't tell where you are, or nobody has switched rides on here.
struct RidesUnavailablePanel: View {
    @Environment(\.wa) private var wa
    let reason: String?
    let hasLocation: Bool
    let onAskLocation: () -> Void
    let onRetry: () -> Void
    @State private var pulse = false

    var body: some View {
        let noCountry = reason == "no_country"
        PanelCard {
            VStack(spacing: 8) {
                Image(systemName: "location.slash.fill").font(.system(size: 30)).foregroundStyle(wa.accent)
                    .frame(width: 72, height: 72).background(wa.accent.opacity(0.12), in: Circle())
                    .scaleEffect(pulse ? 1.08 : 1)
                    .onAppear { withAnimation(.easeInOut(duration: 1.1).repeatForever()) { pulse = true } }
                Text(L("rd_na_title")).font(.dorr(18, .bold)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                Text(L(noCountry ? "rd_na_no_country" : "rd_na_not_enabled")).font(.dorr(14)).foregroundStyle(wa.settingsInk.opacity(0.65)).multilineTextAlignment(.center)
                if noCountry && !hasLocation {
                    AccentButton(text: L("rd_allow"), action: onAskLocation).padding(.top, 6)
                } else {
                    AccentButton(text: L("rd_retry"), action: onRetry).padding(.top, 6)
                }
            }
            .frame(maxWidth: .infinity)
        }
    }
}

/// When each nearby car was last heard from (shared with the sweep that fades the silent ones).
@MainActor
private final class NearbySeen {
    var at: [String: Date] = [:]
}
