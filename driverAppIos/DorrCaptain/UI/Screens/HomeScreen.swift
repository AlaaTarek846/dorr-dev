import Combine
import CoreLocation
import SwiftUI
import UIKit
import UserNotifications

// driverApp `ui/HomeScreen.kt`.

/// Until the phone says where it is: the launch city (as in the DORR app).
private let riyadh = CLLocationCoordinate2D(latitude: 24.7136, longitude: 46.6753)

/// Where the home sheet rests (Material's `SheetValue`): `Hidden`, `PartiallyExpanded` (the peek) or `Expanded`.
private enum HomeSheetValue { case hidden, partiallyExpanded, expanded }

/// The captain's home: the map with their position, today's net on it, and a sheet over it with the big online switch
/// (breathing while online) and — when they can't work yet — exactly why, with the way to fix it (sign-up, Wasl).
/// Dragging the sheet up shows the rest of the day (breaks, driving time, the airport queue).
/// Everything else (preferences, scheduled rides, cases, safety…) lives in the Account tab.
@MainActor
struct HomeScreen: View {
    @Environment(\.pal) private var pal
    @Environment(\.layoutDirection) private var direction
    @ObservedObject private var duty = Duty.shared
    @ObservedObject private var location = DriverLocation.shared
    @StateObject private var camera = DorrCamera(start: riyadh, zoom: 12)

    let onOpenSignup: () -> Void
    let onOpenRide: () -> Void
    let onOpenEarnings: () -> Void
    let onOpenAirport: () -> Void
    let onOpenNotifications: () -> Void

    @State private var driver: DriverDto?
    @State private var presence: PresenceDto?
    @State private var earnings: EarningsDto?
    @State private var airport: AirportQueueDto?
    @State private var driving: DrivingHoursDto?
    @State private var error: String?
    @State private var busy = false
    @State private var reload = 0
    @State private var states: ServiceStatusDto?
    @State private var servicesOpen = false
    @State private var checkOpen = false
    @State private var heat: [HeatCellDto] = []

    // The sheet (Material's BottomSheetScaffold): where it rests, the finger's drag, and how tall its content is.
    @State private var sheetValue = HomeSheetValue.partiallyExpanded
    @State private var dragY: CGFloat = 0
    @State private var sheetContentHeight: CGFloat = 0

    init(onOpenSignup: @escaping () -> Void, onOpenRide: @escaping () -> Void, onOpenEarnings: @escaping () -> Void,
         onOpenAirport: @escaping () -> Void, onOpenNotifications: @escaping () -> Void) {
        self.onOpenSignup = onOpenSignup
        self.onOpenRide = onOpenRide
        self.onOpenEarnings = onOpenEarnings
        self.onOpenAirport = onOpenAirport
        self.onOpenNotifications = onOpenNotifications
    }

    // MARK: - What the screen shows

    private var permissionsOk: Bool { location.allowed }
    private var locationOn: Bool { location.switchedOn }
    private var online: Bool { ["online", "on_trip", "paused"].contains(presence?.status ?? "") }
    private var breakEnd: Date? { instantOf(presence?.pausedUntil) }
    private var onBreak: Bool { presence?.status == "paused" && (breakEnd == nil || breakEnd! > Date()) }
    private var problem: String? { presence?.reason }
    private var blocked: Bool {
        presence != nil && !(presence?.canGoOnline ?? false) && problem != nil && problem != "driving_limit"
    }
    /// The sheet shows the switch (and why not, when they can't work); the rest is a drag away.
    private var peek: CGFloat { blocked ? 430 : 312 }
    private var sheetHidden: Bool { sheetValue == .hidden }
    private var mapBottom: CGFloat { sheetHidden ? 0 : peek }

    var body: some View {
        GeometryReader { geo in
            ZStack(alignment: .bottom) {
                mapLayer
                sheet(maxHeight: geo.size.height)
            }
        }
        .background(pal.bg.ignoresSafeArea())
        .background(
            // O-49: no working without the location — a window that stays until it's allowed and on.
            LocationGate(onReady: {
                if ["online", "on_trip"].contains(presence?.status ?? "") { Duty.shared.start() }
            })
        )
        .background(
            Color.clear.sheet(isPresented: $servicesOpen) {
                ServicesSheet(onClose: { servicesOpen = false }).sheetEnvironment(pal, direction)
            }
        )
        .background(
            Color.clear.sheet(isPresented: $checkOpen) {
                DispatchCheckSheet(onClose: { checkOpen = false }).sheetEnvironment(pal, direction)
            }
        )
        .task(id: "\(reload)|\(duty.rideChanged)") { await load() }
        // The presence follows the server through real-time: any change for me (a paused / timed-out shift, a ride) reloads it.
        .onReceive(DriverLive.shared.events) { e in Task { await onLiveEvent(e) } }
        // A break ends by the clock (D-41): one reload when it's due — no asking the server meanwhile.
        .task(id: presence?.pausedUntil) {
            guard let end = instantOf(presence?.pausedUntil) else { return }
            let wait = end.timeIntervalSinceNow
            if wait > 0 {
                try? await Task.sleep(nanoseconds: UInt64((wait + 1.5) * 1_000_000_000))
                if Task.isCancelled { return }
                reload += 1
            }
        }
        // The map opens on the captain once the position is known (and again after the permission is given).
        // O-49: and again the moment the location is allowed and switched on — no closing and reopening the app.
        .task(id: "\(permissionsOk)|\(locationOn)") {
            guard permissionsOk, locationOn else { return }
            if let (lat, lng) = await lastPosition() { camera.move(to: CLLocationCoordinate2D(latitude: lat, longitude: lng), zoom: 15) }
        }
    }

    // MARK: - Loading

    private func load() async {
        if let d = await DriverApi.me().value { driver = d }
        if let s = await DriverApi.serviceStatus().value { states = s }
        if let p = await DriverApi.presence().value { presence = p }
        if let e = await DriverApi.earnings().value { earnings = e }
        if let a = await DriverApi.airportQueue().value { airport = a }
        if let h = await DriverApi.drivingHours().value { driving = h }
        if Task.isCancelled { return }
        // A ride running (accepted from the notification, or the app was closed): straight to it.
        if case let .ok(current, _) = await DriverApi.currentRide(), current.isObject, current.has("uuid") {
            Duty.shared.setOnTrip(true)
            onOpenRide()
        } else {
            Duty.shared.setOnTrip(false)
        }
        // Online on the server but the shift isn't running on this phone (rebooted…): start it again.
        if ["online", "on_trip"].contains(presence?.status ?? ""), permissionsOk { Duty.shared.start() }
    }

    private func onLiveEvent(_ e: LiveEvent) async {
        // O-66: activated (or anything about my account changed) from the admin — me and my presence again.
        if e.name == "driver.status" || e.name == "driver.vehicle" || e.name == "driver.document" {
            if let d = await DriverApi.me().value { driver = d }
        }
        if e.name == DriverLive.driverChanged || e.name.hasPrefix("ride.") || e.name.hasPrefix("driver.") {
            if let p = await DriverApi.presence().value { presence = p }
            if let s = await DriverApi.serviceStatus().value { states = s }
            // My place in the airport queue moves with the line.
            if let a = await DriverApi.airportQueue().value { airport = a }
            // The driving-time limit moves with the shift (a reminder, or being taken offline).
            if let h = await DriverApi.drivingHours().value { driving = h }
        }
    }

    // MARK: - Actions

    private func pause(_ minutes: Int) {
        busy = true
        error = nil
        Task {
            switch await DriverApi.pause(body: ["minutes": minutes]) {
            case let .ok(p, _): presence = p
            case let .failed(e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    private func goOnline() {
        busy = true
        error = nil
        Task {
            guard let here = await lastPosition() else {
                error = L("home_permissions")
                busy = false
                return
            }
            switch await DriverApi.goOnline(body: ["lat": here.0, "lng": here.1]) {
            case let .ok(p, _):
                presence = p
                Duty.shared.start()
            case let .failed(e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    /// Android's `RequestMultiplePermissions(fine, coarse, notifications)`: everything granted → online at once; else the
    /// system asks, and with the location given the captain goes online.
    private func askPermissionsThenGoOnline() {
        Task {
            let center = UNUserNotificationCenter.current()
            let notifications = await center.notificationSettings().authorizationStatus
            let notificationsOk = notifications == .authorized || notifications == .provisional || notifications == .ephemeral
            if location.allowed && notificationsOk {
                goOnline()
                return
            }
            if !location.allowed { await location.ask() }
            if notifications == .notDetermined { _ = try? await center.requestAuthorization(options: [.alert, .sound, .badge]) }
            if location.allowed { goOnline() }
        }
    }

    private func toggleOnline() {
        if online {
            busy = true
            Task {
                Duty.shared.stop()
                if let p = await DriverApi.goOffline().value { presence = p }
                busy = false
            }
        } else {
            askPermissionsThenGoOnline()
        }
    }

    private func openSettings() {
        if let url = URL(string: UIApplication.openSettingsURLString) { UIApplication.shared.open(url) }
    }

    // MARK: - The map and what floats on it

    private var mapLayer: some View {
        ZStack(alignment: .top) {
            // The map under everything: the captain's car (the map follows the phone itself — no asking the server).
            // O-53: the admin's engine (Google or Mapbox) — the whole app draws with it.
            DorrMap(
                camera: camera,
                zones: heat.map { c in
                    let hot = min(max(c.weight ?? 0, 0.15), 1)
                    return MapZone(center: CLLocationCoordinate2D(latitude: c.lat ?? 0, longitude: c.lng ?? 0), radiusM: 650, color: heatColor(hot))
                },
                // O-60: my place as a small car turning with my heading.
                showMe: permissionsOk && locationOn,
                meAsCar: true,
                padding: EdgeInsets(top: 120, leading: 0, bottom: mapBottom, trailing: 0),
                night: pal.night,
                googleNightStyle: "map_night"
            )
            .ignoresSafeArea()
            // O-58: the hot areas (where requests came from in the last hour) — read on open, when going
            // online and when the captain recentres; nothing asks on a timer.
            .task(id: "\(online)|\(permissionsOk)|\(locationOn)") {
                guard permissionsOk, locationOn else { return }
                guard let here = await lastPosition() else { return }
                if let cells = await DriverApi.demandHeat(lat: here.0, lng: here.1).value { heat = cells }
            }
            .task { await MapEngine.shared.refresh() }

            // Offline, the map steps back a little.
            pal.bg.opacity(online ? 0 : 0.18)
                .ignoresSafeArea()
                .allowsHitTesting(false)
                .animation(.tween(400), value: online)

            header

            // O-45: the sheet is down — a pill (with my status) brings it back.
            if sheetHidden {
                HStack(spacing: 8) {
                    Circle().fill(online ? pal.success : pal.faint).frame(width: 10, height: 10)
                    Text(L(online ? "home_status_online" : "home_status_offline")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                    DorrIcon("chevron.up", size: 24, tint: pal.ink)
                }
                .padding(.horizontal, 18).padding(.vertical, 12)
                .background(Capsule().fill(pal.card))
                .bounceClick { withAnimation(.tween(300)) { sheetValue = .partiallyExpanded } }
                .padding(.bottom, 18)
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottom)
            }
            // Back to where I am.
            RoundIconButton("location.fill", L("home_recenter"), onClick: {
                Task {
                    if let (lat, lng) = await lastPosition() { camera.animate(to: CLLocationCoordinate2D(latitude: lat, longitude: lng), zoom: 16) }
                }
            })
            .padding(.trailing, 16)
            .padding(.bottom, sheetHidden ? 80 : peek + 14)
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomTrailing)
        }
    }

    /// The heat of an area: amber to red as it gets hotter, more opaque too.
    private func heatColor(_ hot: Double) -> Color {
        func mix(_ a: Double, _ b: Double) -> Double { (a + (b - a) * hot) / 255 }
        return Color(.sRGB, red: mix(0xFF, 0xE5), green: mix(0xB3, 0x39), blue: mix(0x00, 0x35), opacity: 0.18 + 0.27 * hot)
    }

    /// Header: who, the rating, the bell — floating on the map; today's net under it, a tap opens the earnings.
    private var header: some View {
        VStack(spacing: 10) {
            HStack(spacing: 10) {
                Text(avatarLetter).font(.dorr(17, .black)).foregroundStyle(pal.accent)
                    .frame(width: 40, height: 40)
                    .background(Circle().fill(pal.well))
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("home_greeting")).font(.dorr(12)).foregroundStyle(pal.mut)
                    Text(driver?.name ?? driver?.phone ?? "…").font(.dorr(17, .black)).foregroundStyle(pal.accent).lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if let rating = earnings?.profile?.rating {
                    HStack(spacing: 0) {
                        DorrIcon("star.fill", size: 16, tint: pal.warning)
                        Text(String(format: "%.1f", locale: Locale(identifier: DriverPrefs.locale), rating))
                            .font(.dorr(13, .bold)).foregroundStyle(pal.ink)
                            .padding(.leading, 3)
                    }
                    .padding(.horizontal, 10).padding(.vertical, 5)
                    .background(Capsule().fill(pal.warning.opacity(0.14)))
                }
                // O-89: my services — what my vehicle can work in here, each with its switch.
                RoundIconButton("square.grid.2x2", L("sv_title"), onClick: { servicesOpen = true })
                NotificationBell(onClick: onOpenNotifications)
            }
            .padding(.horizontal, 14).padding(.vertical, 10)
            .surface(22)
            .staggerIn(0)
            HStack(spacing: 6) {
                Text(L("home_today_title")).font(.dorr(12, .bold)).foregroundStyle(Color.white.opacity(0.8))
                Text(money(earnings?.today?.netMinor ?? 0, earnings?.currency)).font(.dorr(15, .heavy)).foregroundStyle(.white)
            }
            .padding(.horizontal, 18).padding(.vertical, 8)
            .background(Capsule().fill(pal.accent))
            .bounceClick(onClick: onOpenEarnings)
            .staggerIn(1)
        }
        .padding(.horizontal, 12)
        .padding(.top, 10)
    }

    private var avatarLetter: String {
        let first = (driver?.name ?? "").trimmingCharacters(in: .whitespacesAndNewlines).prefix(1).uppercased()
        return first.trimmingCharacters(in: .whitespaces).isEmpty ? "D" : first
    }

    // MARK: - The sheet (Material's BottomSheetScaffold)

    private func sheet(maxHeight: CGFloat) -> some View {
        let handle: CGFloat = 20
        // Expanded: as tall as its content, up to the whole screen.
        let full = max(min(handle + sheetContentHeight, maxHeight), peek)
        let rest: CGFloat = {
            switch sheetValue {
            case .hidden: return 0
            case .partiallyExpanded: return peek
            case .expanded: return full
            }
        }()
        let shown = min(max(rest - dragY, 0), full)
        let drag = DragGesture()
            .onChanged { dragY = $0.translation.height }
            .onEnded { g in
                let projected = min(max(rest - g.predictedEndTranslation.height, 0), full)
                let spots: [(HomeSheetValue, CGFloat)] = [(.hidden, 0), (.partiallyExpanded, peek), (.expanded, full)]
                let nearest = spots.min { abs($0.1 - projected) < abs($1.1 - projected) }?.0 ?? .partiallyExpanded
                withAnimation(.tween(300)) {
                    sheetValue = nearest
                    dragY = 0
                }
            }
        return VStack(spacing: 0) {
            // The drag handle.
            Capsule().fill(pal.line).frame(width: 40, height: 4)
                .padding(.top, 10).padding(.bottom, 6)
                .frame(maxWidth: .infinity)
                .contentShape(Rectangle())
                .gesture(drag)
            ScrollView {
                sheetContent
                    .background(GeometryReader { g in
                        Color.clear
                            .onAppear { sheetContentHeight = g.size.height }
                            .onChange(of: g.size.height) { sheetContentHeight = $0 }
                    })
            }
            .scrollDisabled(sheetValue != .expanded)
        }
        .frame(height: full, alignment: .top)
        .frame(maxWidth: .infinity)
        .background(
            HomeSheetShape(radius: 28).fill(pal.card)
                .shadow(color: .black.opacity(pal.night ? 0 : 0.14), radius: 16, y: -2)
                .ignoresSafeArea(edges: .bottom)
        )
        // Short of expanded the whole sheet drags; expanded, its content scrolls and the handle drags.
        .gesture(sheetValue == .expanded ? nil : drag)
        .offset(y: full - shown)
        .animation(.tween(300), value: peek)
    }

    private var sheetContent: some View {
        VStack(spacing: 14) {
            // Where they stand, in words as well as colour.
            VStack(spacing: 0) {
                HStack(spacing: 8) {
                    Circle().fill(onBreak ? pal.warning : (online ? pal.success : pal.faint)).frame(width: 10, height: 10)
                        .animation(.tween(300), value: online)
                    Text(L(onBreak ? "br_title" : (online ? "home_status_online" : "home_status_offline")))
                        .font(.dorr(18, .heavy)).foregroundStyle(pal.ink)
                }
                Text(online ? L("home_waiting_requests") : L("home_offline_hint"))
                    .font(.dorr(13)).foregroundStyle(pal.mut).multilineTextAlignment(.center)
                    .padding(.top, 2)
            }

            // D-329..D-344: maintenance, the service / a category off here, an emergency review, the safe mode.
            ServiceStateBanners(states)

            // Why not, when they can't work — with the way out.
            if blocked && !["maintenance", "service_off_here", "emergency_review"].contains(problem ?? "") {
                VStack(spacing: 0) {
                    ListRow(icon: "person.text.rectangle", title: L("home_cannot_work"),
                            subtitle: driver?.statusReason ?? problemText(problem), tint: pal.warning, onClick: onOpenSignup)
                    BigButton(L("home_complete_signup"), color: pal.accent, onClick: onOpenSignup)
                        .padding(.horizontal, 14)
                        .padding(.bottom, 14)
                }
                .frame(maxWidth: .infinity)
                .surface()
                .staggerIn(1)
            }

            OnlineButton(online: online, enabled: online || presence?.canGoOnline == true, busy: busy) { toggleOnline() }
            Banner(error)

            // D-44: GPS off — nothing can be offered.
            if online && !locationOn {
                Card {
                    HStack(spacing: 12) {
                        TileIcon("location.slash.fill", tint: pal.danger, box: 44)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("gps_off_title")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                            Text(L("gps_off_body")).font(.dorr(13)).foregroundStyle(pal.mut)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    VSpace(12)
                    // iOS can't open the location switch itself: the Settings app.
                    BigButton(L("gps_turn_on"), color: pal.danger) { openSettings() }
                }
            }

            // Today at a glance — a tap opens the earnings.
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    Text(L("home_today_title")).font(.dorr(13, .bold)).foregroundStyle(pal.mut).frame(maxWidth: .infinity, alignment: .leading)
                    Text(L("home_open_earnings")).font(.dorr(12, .bold)).foregroundStyle(pal.accent)
                }
                VSpace(12)
                let today = earnings?.today
                let onlineS = today?.onlineS ?? 0
                WaWeightedRow {
                    HomeStat(label: L("home_net"), value: money(today?.netMinor ?? 0, earnings?.currency), big: true).layoutWeight(1.5)
                    HomeStat(label: L("home_trips"), value: String(today?.trips ?? 0)).layoutWeight(1)
                    HomeStat(label: L("home_online_time"), value: "\(onlineS / 3600)h \((onlineS % 3600) / 60)m").layoutWeight(1)
                }
            }
            .padding(18)
            .frame(maxWidth: .infinity)
            .surface(24, elevation: 8)
            .bounceClick(onClick: onOpenEarnings)

            // D-42, D-43: the driving-time limit — how much is used, a reminder, and when they can work again.
            if let d = driving, d.enabled == true { DrivingTimeCard(d: d) }
            // D-41: a break — no requests until it ends, then online again by itself.
            if onBreak {
                Card {
                    HStack(spacing: 12) {
                        TileIcon("cup.and.saucer.fill", tint: pal.warning, box: 44)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("br_title")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                            if let end = breakEnd { Text(L("br_until", timeOfDay(end))).font(.dorr(13)).foregroundStyle(pal.mut) }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    VSpace(12)
                    BigButton(L("br_back"), color: pal.success, busy: busy) { goOnline() }
                }
            } else if presence?.status == "online" || presence?.status == "paused" {
                Card {
                    HStack(spacing: 12) {
                        TileIcon("cup.and.saucer.fill", tint: pal.warning, box: 44)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("br_take")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                            Text(L("br_hint")).font(.dorr(12)).foregroundStyle(pal.mut)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    VSpace(12)
                    HStack(spacing: 8) {
                        ForEach([15, 30, 60], id: \.self) { minutes in
                            Chip(L("br_min", minutes), onClick: { if !busy { pause(minutes) } })
                        }
                    }
                }
            }
            // O-47: «why don't requests reach me?» — every condition, checked now.
            VStack(spacing: 0) {
                ListRow(icon: "dot.radiowaves.left.and.right", title: L("dc_entry"), subtitle: L("dc_entry_hint"), tint: pal.accent,
                        onClick: { checkOpen = true })
            }
            .frame(maxWidth: .infinity)
            .surface()
            if online {
                Card {
                    HStack(spacing: 0) {
                        Text(L("home_last_ride")).font(.dorr(16, .semibold)).foregroundStyle(pal.ink)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        DorrToggle(on: presence?.lastRideRequested == true) { on in
                            Task { if let p = await DriverApi.lastRide(body: ["on": on]).value { presence = p } }
                        }
                    }
                }
            }
            // The airport queue, while in it or at its zone (D-276..D-283).
            if airport?.queued == true || airport?.zone != nil {
                VStack(spacing: 0) {
                    ListRow(
                        icon: "airplane", title: L("air_title"),
                        subtitle: airport?.queued == true ? L("air_home_queued", airport?.position ?? 0, airport?.size ?? 0)
                            : L("air_home_at", airport?.zone?.name ?? ""),
                        onClick: onOpenAirport
                    )
                }
                .frame(maxWidth: .infinity)
                .surface()
            }
        }
        .padding(.horizontal, 16)
        .padding(.bottom, 18)
        .frame(maxWidth: .infinity)
    }
}

private extension View {
    /// What a sheet needs from the screen under it (iOS 16 doesn't always pass the custom environment into a sheet).
    func sheetEnvironment(_ pal: Pal, _ direction: LayoutDirection) -> some View {
        environment(\.pal, pal)
            .environment(\.layoutDirection, direction)
            .environment(\.locale, Locale(identifier: DriverPrefs.locale))
    }
}

private struct HomeStat: View {
    @Environment(\.pal) private var pal
    let label: String
    let value: String
    var big = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(label).font(.dorr(12)).foregroundStyle(pal.mut)
            Text(value).font(.dorr(big ? 24 : 18, .heavy)).foregroundStyle(big ? pal.accent : pal.ink).lineLimit(1)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// The big round switch; online it breathes with a soft ring, offline it waits.
private struct OnlineButton: View {
    @Environment(\.pal) private var pal
    let online: Bool
    let enabled: Bool
    let busy: Bool
    let onClick: () -> Void
    @State private var breathe = false

    var body: some View {
        let color = online ? pal.success : pal.accent
        ZStack {
            if enabled && !busy {
                Circle().fill(color).opacity(0.22).frame(width: 170, height: 170)
                    .scaleEffect(online ? (breathe ? 1.22 : 1) : 1.06)
            }
            VStack(spacing: 0) {
                DorrIcon("power", size: 46, tint: .white)
                VSpace(6)
                Text(L(online ? "home_go_offline" : "home_go_online")).font(.dorr(16, .bold)).foregroundStyle(.white)
            }
            .frame(width: 160, height: 160)
            .background(Circle().fill(LinearGradient(colors: [color, color.opacity(0.75)], startPoint: .topLeading, endPoint: .bottomTrailing)))
            .opacity(enabled ? 1 : 0.35)
            .scaleEffect(busy ? 0.94 : 1)
            .animation(.tween(160), value: busy)
            .bounceClick(enabled: enabled && !busy, onClick: onClick)
        }
        .frame(width: 200, height: 200)
        .animation(.tween(400), value: online)
        .onAppear { withAnimation(.tween(1300).repeatForever(autoreverses: true)) { breathe = true } }
    }
}

/// Driving time in the last window against the country's limit: a bar, the words for where they stand, and when work resumes.
private struct DrivingTimeCard: View {
    @Environment(\.pal) private var pal
    let d: DrivingHoursDto

    private func hours(_ s: Int) -> String { L("earn_hours", s / 3600, (s % 3600) / 60) }

    var body: some View {
        let maxS = d.maxS ?? 0
        let usedS = d.usedS ?? 0
        let limit = maxS > 0 ? maxS : (d.warnS ?? 0)
        let level = d.level ?? "ok"
        let color: Color = level == "limit" ? pal.danger : (level == "warn" ? pal.warning : pal.success)
        let used = min(max(CGFloat(usedS) / CGFloat(max(limit, 1)), 0.02), 1)
        Card {
            HStack(spacing: 12) {
                TileIcon("clock.fill", tint: color, box: 44)
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("dh_title", d.windowH ?? 24)).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                    // The state in words as well as colour.
                    Text(stateText(level)).font(.dorr(13, .semibold)).foregroundStyle(color)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            VSpace(12)
            GeometryReader { g in
                ZStack(alignment: .leading) {
                    Capsule().fill(color.opacity(0.15))
                    Capsule().fill(color).frame(width: g.size.width * used)
                }
            }
            .frame(height: 8)
            .animation(.tween(600), value: used)
            Text(maxS > 0 ? L("dh_of", hours(usedS), hours(maxS)) : hours(usedS))
                .font(.dorr(12)).foregroundStyle(pal.mut)
                .padding(.top, 6)
        }
    }

    private func stateText(_ level: String) -> String {
        switch level {
        case "limit":
            if let free = instantOf(d.freeAt) { return L("dh_limit_until", timeOfDay(free)) }
            return L("dh_limit")
        case "warn": return L("dh_warn")
        default: return L("dh_ok")
        }
    }
}

/// The sheet's shape: only the top corners rounded.
private struct HomeSheetShape: Shape {
    let radius: CGFloat

    func path(in rect: CGRect) -> Path {
        Path(UIBezierPath(roundedRect: rect, byRoundingCorners: [.topLeft, .topRight],
                          cornerRadii: CGSize(width: radius, height: radius)).cgPath)
    }
}
