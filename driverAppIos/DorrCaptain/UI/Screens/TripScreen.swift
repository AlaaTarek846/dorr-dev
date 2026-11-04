import SwiftUI

// driverApp `ui/TripScreen.kt`.

/// The ride's state for as long as the screen is there (Compose's `remember` + the `LaunchedEffect` collecting the live
/// events, and the `DisposableEffect` keeping the ride's channel): kept out of the view so a sheet or a dialog over the
/// screen (which makes SwiftUI think it disappeared) never stops it.
@MainActor
final class TripModel: ObservableObject {
    @Published var trip: TripDto? { didSet { follow() } }
    let rideUuid: String?
    var onDone: () -> Void = {}
    private var events: Task<Void, Never>?
    private var watched: String?

    init(rideUuid: String?) { self.rideUuid = rideUuid }

    deinit {
        events?.cancel()
        if let w = watched { Task { @MainActor in DriverLive.shared.unwatchRide(w) } }
    }

    /// The trip follows the server through real-time: one reload now, then whenever it changes for me.
    func start() {
        guard events == nil else { return }
        events = Task { [weak self] in
            await self?.refresh()
            for await e in DriverLive.shared.events.values {
                guard let self else { return }
                // O-10: a message counts too — the chat button's badge follows it.
                // O-09: "ride.call" is the rider ringing (the push, when the app is open).
                if e.name == DriverLive.driverChanged || e.name == "ride.changed" || e.name == "ride.message" || e.name == "ride.call" {
                    await self.refresh()
                }
            }
        }
    }

    func refresh() async {
        // O-30: with riders sharing the car, the screen is about one of them (opened from the With Me list).
        if let rideUuid {
            if let t = await DriverApi.ride(uuid: rideUuid).value { trip = t }
            return
        }
        guard case let .ok(data, _) = await DriverApi.currentRide() else { return }
        if data.isObject && data.has("uuid") {
            if let t = data.decoded(TripDto.self) { trip = t }
        } else {
            Duty.shared.setOnTrip(false)
            if trip?.status != "completed" { onDone() }
        }
    }

    /// The ride's own channel for the whole ride (its status, the chat's messages), not only while the chat is open.
    private func follow() {
        let uuid = trip?.uuid
        guard uuid != watched else { return }
        if let old = watched { DriverLive.shared.unwatchRide(old) }
        watched = uuid
        if let uuid { DriverLive.shared.watchRide(uuid) }
    }
}

/// The ride, one big action at a time: to the rider (navigation opens in Google Maps), "I've arrived", the code, start,
/// each stop, end — then the cash (with the change going to the rider's wallet if they want) and the rider's rating.
struct TripScreen: View {
    @Environment(\.pal) private var pal
    @Environment(\.layoutDirection) private var direction
    let onDone: () -> Void
    let rideUuid: String?

    @StateObject private var model: TripModel
    @State private var busy = false
    @State private var error: String?
    // O-76: «Arrived» pressed too far from the pickup — shown as a dialog, not a line at the bottom.
    @State private var notAtPickup: ApiError?
    @State private var pinOpen = false
    @State private var chatOpen = false
    @State private var cancelOpen = false
    @State private var endConfirm = false
    @State private var sosOpen = false
    @State private var reportAfterSos = false
    @State private var reportOpen = false
    @State private var sharing = false
    // Ending: the server refuses it away from the destination (O-39); the captain then confirms the rider is getting off
    // sooner, and the fare is worked out on the distance driven (O-40).
    @State private var earlyEnd: String?

    init(onDone: @escaping () -> Void, rideUuid: String? = nil) {
        self.onDone = onDone
        self.rideUuid = rideUuid
        _model = StateObject(wrappedValue: TripModel(rideUuid: rideUuid))
    }

    var body: some View {
        Group {
            if let t = model.trip {
                trip(t)
            } else {
                ZStack { CircularProgress(color: pal.accent, size: 40, lineWidth: 4) }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .background(pal.bg.ignoresSafeArea())
            }
        }
        .onAppear {
            model.onDone = onDone
            model.start()
        }
        // O-09: the rider's call rings here; a call gone from the ride is over on this phone too.
        .onChange(of: "\(model.trip?.call?.uuid ?? "")|\(model.trip?.call?.status ?? "")") { _ in ringIfCalled() }
    }

    private func ringIfCalled() {
        guard let current = model.trip else { return }
        let session = RideCallSession.shared
        if let call = current.call, call.status == "ringing", call.caller == "user", let ride = current.uuid, let callUuid = call.uuid {
            session.incoming(ride: ride, call: callUuid, peer: current.rider?.name ?? "",
                             end: { r, c in _ = await DriverApi.endCall(uuid: r, call: c) },
                             peerVerified: current.rider?.isVerified == true)
        } else if current.call == nil && session.rideUuid == current.uuid && session.phase != .calling {
            session.endedElsewhere(nil)
        }
    }

    private func act(_ call: @escaping (JsonBody) async -> ApiResult<TripDto>) {
        busy = true
        error = nil
        Task {
            guard let here = await lastPosition() else { error = L("home_permissions"); busy = false; return }
            switch await call(["lat": here.0, "lng": here.1]) {
            case let .ok(t, _): model.trip = t
            case let .failed(e): if e.errorCode == "rides_not_at_pickup" { notAtPickup = e } else { error = e.displayMessage }
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    private func endRide(_ t: TripDto, early: Bool) {
        busy = true
        error = nil
        Task {
            guard let here = await lastPosition() else { error = L("home_permissions"); busy = false; return }
            var body: JsonBody = ["lat": here.0, "lng": here.1]
            if early { body["early"] = true }
            switch await DriverApi.end(key: newIdempotencyKey(), uuid: t.uuid ?? "", body: body) {
            case let .ok(r, _): model.trip = r
            case let .failed(e):
                if e.errorCode == "rides_not_at_destination" { earlyEnd = e.displayMessage } else { error = e.displayMessage }
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    private func callRider(_ t: TripDto) {
        // O-09: the microphone first (Android's RECORD_AUDIO), then the call.
        Task { if await askMicrophone() { startRideCall(t) } }
    }

    // MARK: - the screen

    /// O-34: the map is the whole screen — the route drawn on it — and the ride's details sit in a sheet the captain pulls
    /// down to see the road, or up for everything. Driving here or in Google Maps: his choice.
    @ViewBuilder
    private func trip(_ t: TripDto) -> some View {
        let status = t.status ?? ""
        let stops = t.stops ?? []
        let peek: CGFloat = status == "completed" ? 560 : (status == "in_progress" && stops.contains { $0.status != "done" } ? 250 : 190)
        ZStack {
            TripSheetScaffold(peek: peek) {
                actions(t)
                details(t)
            } content: {
                ZStack(alignment: .top) {
                    TripMap(t: t, bottomPadding: peek)
                    statusBand(t)
                }
            }
            // The rider asks for a new destination / stop: answer within 30 s (wireflow 6ب).
            if t.changeRequest?.status == "pending" {
                ChangeRequestCard(t: t, onAnswered: { Task { await model.refresh() } })
            }
            // O-09: the in-app call with the rider, over the trip.
            RideCallOverlay(onAccept: {
                let session = RideCallSession.shared
                if let ride = session.rideUuid, let call = session.callUuid {
                    session.accept { try callJoin(await DriverApi.acceptCall(uuid: ride, call: call)) }
                }
            })
        }
        .background {
            // The dialogs that present themselves (Android composables showing a Dialog / ModalBottomSheet).
            ZStack {
                if let e = notAtPickup {
                    let data = e.data as? [String: Any]
                    NotAtPickupDialog(
                        distanceM: (data?["distance_m"] as? NSNumber)?.intValue,
                        radiusM: (data?["radius_m"] as? NSNumber)?.intValue,
                        message: e.displayMessage,
                        pickupLat: t.origin?.lat, pickupLng: t.origin?.lng,
                        onClose: { notAtPickup = nil }
                    )
                }
            }
        }
        // "Are you OK?" during the trip — it can't be dismissed.
        .background(
            Color.clear.dorrDialog(isPresented: Binding(get: { t.safetyCheck != nil }, set: { _ in }), dismissible: false) {
                if let check = t.safetyCheck {
                    DriverSafetyCheckDialog(t: t, check: check, onDone: {
                        var updated = t
                        updated.safetyCheck = nil
                        model.trip = updated
                    })
                }
            }
        )
        .sheet(isPresented: $reportOpen) {
            DriverReportSheet(t: t, onClose: { reportOpen = false })
                .environment(\.pal, pal)
                .environment(\.layoutDirection, direction)
        }
        .dorrDialog(isPresented: $pinOpen) {
            PinDialog(onDismiss: { pinOpen = false }) { pin in
                pinOpen = false
                act { b in
                    var body = b
                    body["pin"] = pin
                    return await DriverApi.start(key: newIdempotencyKey(), uuid: t.uuid ?? "", body: body)
                }
            }
        }
        .background(
            Color.clear.dorrDialog(isPresented: $endConfirm) {
                TripAlert(title: L("trip_end_confirm"), text: nil,
                          confirm: L("trip_end"), onConfirm: { endConfirm = false; endRide(t, early: false) },
                          dismiss: L("offer_decline"), onDismiss: { endConfirm = false })
            }
        )
        .background(
            Color.clear.dorrDialog(isPresented: Binding(get: { earlyEnd != nil }, set: { if !$0 { earlyEnd = nil } })) {
                TripAlert(title: L("trip_end_early_title"), text: earlyEnd,
                          confirm: L("trip_end_early_confirm"), confirmColor: pal.danger, confirmBold: true,
                          onConfirm: { earlyEnd = nil; endRide(t, early: true) },
                          dismiss: L("trip_end_early_keep"), onDismiss: { earlyEnd = nil })
            }
        )
        .fullScreenCover(isPresented: $chatOpen) {
            TripChat(t: t, onClose: { chatOpen = false; Task { await model.refresh() } })
                .environment(\.pal, pal)
                .environment(\.layoutDirection, direction)
        }
        .sheet(isPresented: $sosOpen, onDismiss: {
            if reportAfterSos { reportAfterSos = false; reportOpen = true }
        }) {
            SosSheet(t: t, onClose: { sosOpen = false }, onReport: { reportAfterSos = true; sosOpen = false })
                .presentationDetents([.large])
                .environment(\.pal, pal)
                .environment(\.layoutDirection, direction)
        }
        .sheet(isPresented: $cancelOpen) {
            CancelRide(t: t, onClose: { cancelOpen = false }, onReleased: {
                cancelOpen = false
                Duty.shared.setOnTrip(false)
                onDone()
            })
            .presentationDetents([.large])
            .environment(\.pal, pal)
            .environment(\.layoutDirection, direction)
        }
    }

    /// The status band at the top of the ride: rounded at the bottom, like the cards under it.
    private func statusBand(_ t: TripDto) -> some View {
        let (title, band): (String, Color) = {
            switch t.status {
            case "accepted": return (L("trip_to_pickup"), pal.navy)
            case "arrived": return (L("trip_at_pickup"), pal.warning)
            case "in_progress": return (L("trip_on_ride"), pal.success)
            default: return (L("trip_done"), pal.success)
            }
        }()
        return VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: title).font(.dorr(22, .heavy)).foregroundStyle(.white)
                    Text(verbatim: t.number ?? "").font(.dorr(12)).foregroundStyle(.white.opacity(0.75))
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                // Safety, always within reach (D-141, D-142).
                if t.status != "completed" {
                    Button {
                        sharing = true
                        Task { error = await shareTrip(t.uuid ?? ""); sharing = false }
                    } label: {
                        Image(systemName: "square.and.arrow.up").font(.system(size: 18, weight: .semibold)).foregroundStyle(.white)
                            .frame(width: 44, height: 44).background(Color.white.opacity(0.18), in: Circle())
                    }
                    .buttonStyle(.plain)
                    .disabled(sharing)
                    .accessibilityLabel(L("trip_share"))
                    Spacer().frame(width: 10)
                    Button { sosOpen = true } label: {
                        Text(verbatim: "SOS").font(.dorr(15, .black)).foregroundStyle(.white)
                            .frame(width: 64, height: 44)
                            .background(Color(hex: 0xA32D2D), in: RoundedRectangle(cornerRadius: 22))
                            .overlay(RoundedRectangle(cornerRadius: 22).strokeBorder(Color.white.opacity(0.7), lineWidth: 2))
                    }
                    .buttonStyle(.plain)
                }
            }
            if t.status == "arrived" { ArrivalClock(t: t) }
        }
        .padding(20)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            BandShape(radius: 28)
                .fill(LinearGradient(colors: [band, band.opacity(0.8)], startPoint: .top, endPoint: .bottom))
                .shadow(color: .black.opacity(0.25), radius: 10, y: 4)
                .ignoresSafeArea(edges: .top)
        )
    }

    @ViewBuilder
    private func details(_ t: TripDto) -> some View {
        let unread = t.unreadMessages ?? 0
        Card {
            HStack(spacing: 0) {
                Text(verbatim: String((t.rider?.name ?? "").prefix(1))).font(.dorr(20, .bold)).foregroundStyle(pal.orange)
                    .frame(width: 48, height: 48).background(pal.orange.opacity(0.15), in: Circle())
                Spacer().frame(width: 12)
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 0) {
                        Text(verbatim: t.rider?.name ?? "").font(.dorr(17, .bold)).foregroundStyle(pal.ink).lineLimit(1)
                        // The rider's account verification tick (AV-028).
                        RiderTick(verified: t.rider?.isVerified == true)
                    }
                    Text(verbatim: t.rider?.rating.map { L("trip_rider_rating", String(format: "%.1f", locale: Locale(identifier: DriverPrefs.locale), $0)) } ?? L("offer_new_rider"))
                        .font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.6))
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if t.status != "completed" {
                    // O-09: a free call inside the app (LiveKit) — no numbers shown either way.
                    RoundAction(icon: "headphones", label: L("rc_in_app"), bg: pal.navy) { callRider(t) }
                    Spacer().frame(width: 8)
                    // O-09: call the rider from outside the app (their number, while the ride is on).
                    if let phone = t.callPhone {
                        RoundAction(icon: "phone.fill", label: L("trip_call"), bg: pal.success) { tripDial(phone) }
                        Spacer().frame(width: 8)
                    }
                    // O-10: the chat, with how many messages wait unread.
                    ZStack(alignment: .topTrailing) {
                        RoundAction(icon: "bubble.left.fill", label: L("trip_chat"), bg: pal.navy) { chatOpen = true }
                            .frame(width: 50, height: 50)
                        if unread > 0 {
                            Text(verbatim: unread > 9 ? "9+" : "\(unread)").font(.dorr(10, .bold)).foregroundStyle(.white)
                                .frame(width: 20, height: 20).background(pal.danger, in: Circle())
                        }
                    }
                    .frame(width: 50, height: 50)
                }
            }
            Spacer().frame(height: 12)
            Text(L("offer_pickup")).font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.55))
            Text(verbatim: t.origin?.address ?? "—").font(.dorr(16, .semibold)).foregroundStyle(pal.ink)
            Spacer().frame(height: 6)
            Text(L("offer_dropoff")).font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.55))
            Text(verbatim: t.destination?.address ?? L("offer_open_destination")).font(.dorr(16, .semibold)).foregroundStyle(pal.ink)
            RiderDetails(passengers: t.passengers, note: (t.status == "accepted" || t.status == "arrived") ? t.pickupNote : nil,
                         beneficiary: t.beneficiary?.name, pickupPoint: t.pickupPoint, dropoffPoint: t.dropoffPoint,
                         fromCircle: t.fromCircle ?? false, options: t.options ?? [])
            // «راحتي»: the rider's requests, and "can't do this" (with why) — the rider is told.
            TripComfortCard(t, onUpdated: { model.trip = $0 })
            // Block 06: the next trip taken while driving this one / this trip waits for the one before it.
            NextTripCard(t)
            Spacer().frame(height: 8)
            HStack(spacing: 0) {
                Text(L("trip_earned", money(t.earningMinor ?? 0, t.currency))).font(.dorr(16, .bold)).foregroundStyle(pal.success)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Text(L(t.paymentMethod == "cash" ? "offer_cash" : "offer_paid")).font(.dorr(13)).foregroundStyle(pal.ink)
            }
        }

        // D-129: the time and distance left to the next point.
        ProgressChip(progress: t.progress)
        // Navigation outside the app (Google Maps), to the next point.
        if let nav = t.navigation {
            Button {
                // D-341: never stuck without a way there.
                openNavigation(lat: nav.lat ?? 0, lng: nav.lng ?? 0, webUrl: nav.googleMapsUrl)
            } label: {
                HStack(spacing: 10) {
                    DorrIcon("location.north.line.fill", size: 24, tint: pal.navy)
                    Text(L("trip_navigate")).font(.dorr(16, .bold)).foregroundStyle(pal.navy)
                    Spacer(minLength: 0)
                }
                .padding(16)
                .frame(maxWidth: .infinity, alignment: .leading)
                .overlay(RoundedRectangle(cornerRadius: 16).strokeBorder(pal.navy.opacity(0.3), lineWidth: 1))
                .contentShape(RoundedRectangle(cornerRadius: 16))
            }
            .buttonStyle(.plain)
        }

        if t.status == "in_progress" { DriverSeatbeltBanner(uuid: t.uuid ?? "") }
        // O-17: a ride by the hour — it ends wherever the rider gets off; minutes past the hours are added.
        if let h = t.hourly {
            let until = h.endsAt.map { busTime($0) }
            Banner(until != nil ? L("hr_trip_until", h.hours ?? 0, until ?? "") : L("hr_trip_hours", h.hours ?? 0), kind: .success)
        }
        if t.status == "completed" {
            Completed(t: t, onNext: { Duty.shared.setOnTrip(false); onDone() }, onUpdate: { model.trip = $0 })
            BlockRiderButton(t: t)
        }
        if let error { Text(verbatim: error).font(.dorr(16)).foregroundStyle(pal.danger) }
    }

    /// The one big action — first in the sheet, so it is there even pulled down.
    @ViewBuilder
    private func actions(_ t: TripDto) -> some View {
        if t.status != "completed" {
            let uuid = t.uuid ?? ""
            VStack(spacing: 10) {
                switch t.status {
                case "accepted":
                    BigButton(L("trip_arrived"), color: pal.navy, busy: busy) {
                        act { b in await DriverApi.arrive(key: newIdempotencyKey(), uuid: uuid, body: b) }
                    }
                case "arrived":
                    BigButton(L("trip_start"), color: pal.success, busy: busy) {
                        if t.needsPin == true { pinOpen = true } else { act { b in await DriverApi.start(key: newIdempotencyKey(), uuid: uuid, body: b) } }
                    }
                case "in_progress":
                    if let stop = (t.stops ?? []).first(where: { $0.status != "done" }) {
                        let arrivedAtStop = stop.status == "arrived"
                        BigButton(L(arrivedAtStop ? "trip_stop_departed" : "trip_stop_arrived", stop.seq ?? 0), color: pal.warning, busy: busy) {
                            busy = true
                            Task {
                                if let r = await DriverApi.stop(uuid: uuid, seq: stop.seq ?? 0, body: ["action": arrivedAtStop ? "departed" : "arrived"]).value { model.trip = r }
                                busy = false
                            }
                        }
                    }
                    BigButton(L("trip_end"), color: pal.danger, busy: busy) { endConfirm = true }
                default:
                    EmptyView()
                }
                if t.status == "accepted" {
                    Button { cancelOpen = true } label: {
                        Text(L("trip_cancel")).font(.dorr(16, .semibold)).foregroundStyle(pal.danger).multilineTextAlignment(.center)
                            .frame(maxWidth: .infinity).padding(6).contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
                if t.status == "arrived" {
                    NoShowButton(t: t) {
                        busy = true
                        Task {
                            switch await DriverApi.noShow(key: newIdempotencyKey(), uuid: uuid) {
                            case .ok: Duty.shared.setOnTrip(false); onDone()
                            case let .failed(e): error = e.displayMessage
                            case .offline: break
                            }
                            busy = false
                        }
                    }
                }
            }
            .frame(maxWidth: .infinity)
        }
    }
}

/// `CoreHttp.newIdempotencyKey()`: a fresh random UUID per action.
private func newIdempotencyKey() -> String { UUID().uuidString.lowercased() }

/// A round 46 button on the rider's row (call, chat).
private struct RoundAction: View {
    let icon: String
    let label: String
    let bg: Color
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            Image(systemName: icon).font(.system(size: 19, weight: .semibold)).foregroundStyle(.white)
                .frame(width: 46, height: 46).background(bg, in: Circle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label)
    }
}

/// The band's shape: square at the top (under the status bar), 28 round at the bottom.
private struct BandShape: Shape {
    let radius: CGFloat

    func path(in rect: CGRect) -> Path {
        Path(UIBezierPath(roundedRect: rect, byRoundingCorners: [.bottomLeft, .bottomRight], cornerRadii: CGSize(width: radius, height: radius)).cgPath)
    }
}

// MARK: - the sheet over the map (Material's BottomSheetScaffold)

/// The map behind, and a sheet over it showing `peek` points from the bottom; pulled up it grows to its content (up to under
/// the status bar) and scrolls. The drag handle on top, as Material draws it.
private struct TripSheetScaffold<Sheet: View, Content: View>: View {
    @Environment(\.pal) private var pal
    let peek: CGFloat
    @ViewBuilder let sheet: () -> Sheet
    @ViewBuilder let content: () -> Content

    @State private var expanded = false
    @State private var drag: CGFloat = 0
    @State private var contentHeight: CGFloat = 0

    private static var handleHeight: CGFloat { 20 }

    var body: some View {
        GeometryReader { outer in
            let insets = outer.safeAreaInsets
            let full = outer.size.height + insets.top + insets.bottom
            let maxHeight = full - insets.top
            let height = Swift.min(Swift.max(contentHeight + Self.handleHeight, peek), maxHeight)
            let collapsed = Swift.max(height - peek, 0)
            let base = expanded ? 0 : collapsed
            let offset = Swift.min(Swift.max(base + drag, 0), collapsed)
            let gesture = DragGesture(minimumDistance: 10)
                .onChanged { drag = $0.translation.height }
                .onEnded { v in
                    let end = base + v.predictedEndTranslation.height
                    withAnimation(.tween(300)) {
                        expanded = end < collapsed / 2
                        drag = 0
                    }
                }
            ZStack(alignment: .bottom) {
                content()
                VStack(spacing: 0) {
                    // The drag handle.
                    Capsule().fill(pal.ink.opacity(0.15)).frame(width: 40, height: 4)
                        .padding(.top, 10).padding(.bottom, 6)
                        .frame(maxWidth: .infinity)
                        .contentShape(Rectangle())
                        .gesture(gesture)
                        .onTapGesture { withAnimation(.tween(300)) { expanded.toggle() } }
                    ScrollView {
                        VStack(spacing: 14) { sheet() }
                            .padding(.horizontal, 18)
                            .padding(.bottom, 18 + insets.bottom)
                            .background(GeometryReader { g in
                                Color.clear.preference(key: SheetContentHeight.self, value: g.size.height)
                            })
                    }
                    .scrollDisabled(!expanded)
                }
                .frame(height: height, alignment: .top)
                .frame(maxWidth: .infinity)
                .background(TopRoundedShape(radius: 28).fill(pal.card).shadow(color: .black.opacity(0.18), radius: 16, y: -2))
                .gesture(gesture, including: expanded ? .subviews : .all)
                .offset(y: offset)
            }
            .frame(width: outer.size.width, height: full)
            .background(pal.bg)
            .ignoresSafeArea()
            .onPreferenceChange(SheetContentHeight.self) { contentHeight = $0 }
        }
    }
}

private struct SheetContentHeight: PreferenceKey {
    static var defaultValue: CGFloat = 0
    static func reduce(value: inout CGFloat, nextValue: () -> CGFloat) { value = Swift.max(value, nextValue()) }
}

// MARK: - the waiting at the pickup

/// The free waiting at the pickup, counting down (the phone's clock, each second).
private struct ArrivalClock: View {
    let t: TripDto

    var body: some View {
        TimelineView(.periodic(from: .now, by: 1)) { ctx in
            let now = ctx.date
            if let from = waitingFrom(t) {
                // A booked ride: the waiting starts at its time, however early the captain came (D-119).
                if from > now {
                    Text(L("trip_waiting_starts", timeOfDay(from))).font(.dorr(16, .semibold)).foregroundStyle(.white).padding(.top, 6)
                } else {
                    let left = (t.waiting?.freeS ?? 180) - Int(now.timeIntervalSince(from))
                    WaitClock(left: left, warnBeforeS: t.waiting?.warnBeforeS ?? 60)
                }
            }
        }
    }
}

private struct NoShowButton: View {
    @Environment(\.pal) private var pal
    let t: TripDto
    let onClick: () -> Void

    var body: some View {
        TimelineView(.periodic(from: .now, by: 1)) { ctx in
            if let from = waitingFrom(t) {
                let left = (t.waiting?.noShowAfterS ?? 300) - Int(ctx.date.timeIntervalSince(from))
                Button(action: onClick) {
                    Text(left > 0 ? L("trip_no_show_in", clock(left)) : L("trip_no_show"))
                        .font(.dorr(16, .semibold))
                        .foregroundStyle(left > 0 ? pal.ink.opacity(0.5) : pal.danger)
                        .multilineTextAlignment(.center)
                        .frame(maxWidth: .infinity).padding(6).contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(left > 0)
            }
        }
    }
}

// MARK: - dialogs

/// Material's `AlertDialog` look: the title, the text, the buttons at the end.
private struct TripAlert<Body: View>: View {
    @Environment(\.pal) private var pal
    let title: String
    @ViewBuilder let content: () -> Body
    let confirm: String
    var confirmEnabled = true
    var confirmColor: Color? = nil
    var confirmBold = false
    let onConfirm: () -> Void
    let dismiss: String
    let onDismiss: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: title).font(.dorr(24)).foregroundStyle(pal.ink)
            content().padding(.top, 16)
            HStack(spacing: 8) {
                Spacer()
                Button(action: onDismiss) {
                    Text(verbatim: dismiss).font(.dorr(14, .semibold)).foregroundStyle(pal.accent).padding(.horizontal, 12).frame(height: 40)
                }
                .buttonStyle(.plain)
                Button(action: onConfirm) {
                    Text(verbatim: confirm).font(.dorr(14, confirmBold ? .bold : .semibold))
                        .foregroundStyle((confirmColor ?? pal.accent).opacity(confirmEnabled ? 1 : 0.38))
                        .padding(.horizontal, 12).frame(height: 40)
                }
                .buttonStyle(.plain)
                .disabled(!confirmEnabled)
            }
            .padding(.top, 24)
        }
        .padding(24)
        .frame(maxWidth: 560, alignment: .leading)
        .background(pal.card, in: RoundedRectangle(cornerRadius: 28))
        .padding(.horizontal, 40)
    }
}

extension TripAlert where Body == AnyView {
    init(title: String, text: String?, confirm: String, confirmColor: Color? = nil, confirmBold: Bool = false,
         onConfirm: @escaping () -> Void, dismiss: String, onDismiss: @escaping () -> Void) {
        self.init(title: title, content: {
            AnyView(Group { if let text { Text(verbatim: text).font(.dorr(14)) } })
        }, confirm: confirm, confirmColor: confirmColor, confirmBold: confirmBold, onConfirm: onConfirm, dismiss: dismiss, onDismiss: onDismiss)
    }
}

private struct PinDialog: View {
    @Environment(\.pal) private var pal
    let onDismiss: () -> Void
    let onPin: (String) -> Void
    @State private var pin = ""

    var body: some View {
        TripAlert(title: L("trip_pin_title"), content: {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("trip_pin_hint")).font(.dorr(13)).foregroundStyle(pal.mut)
                Spacer().frame(height: 10)
                DorrField(value: pin, onValueChange: { pin = String($0.filter(\.isNumber).prefix(4)) }, singleLine: true,
                          keyboardOptions: KeyboardOptions(keyboardType: .numberPad))
            }
        }, confirm: L("trip_start"), confirmEnabled: pin.count == 4, onConfirm: { onPin(pin) }, dismiss: L("offer_decline"), onDismiss: onDismiss)
    }
}

// MARK: - after the ride

/// After the ride: the cash (and the change as wallet credit), then rating the rider.
private struct Completed: View {
    @Environment(\.pal) private var pal
    let t: TripDto
    let onNext: () -> Void
    let onUpdate: (TripDto) -> Void

    @State private var received: String
    @State private var busy = false
    @State private var error: String?
    @State private var stars = 0
    @State private var tags: [TagDto] = []
    @State private var chosen = Set<Int>()
    @State private var rated: Bool
    // O-41: a row of stars per criterion, starting at the overall stars.
    @State private var scores: [String: Int] = [:]

    init(t: TripDto, onNext: @escaping () -> Void, onUpdate: @escaping (TripDto) -> Void) {
        self.t = t
        self.onNext = onNext
        self.onUpdate = onUpdate
        let due = Double(t.cashToCollectMinor ?? 0) / 100.0
        _received = State(initialValue: due.truncatingRemainder(dividingBy: 1) == 0 ? String(Int(due)) : String(due))
        _rated = State(initialValue: t.rated == true)
    }

    var body: some View {
        VStack(spacing: 14) {
            Card {
                Text(L("trip_done")).font(.dorr(20, .heavy)).foregroundStyle(pal.ink)
                Text(L("trip_total", money(t.finalTotalMinor ?? 0, t.currency))).font(.dorr(15)).foregroundStyle(pal.ink)
                Text(L("trip_earned", money(t.earningMinor ?? 0, t.currency))).font(.dorr(16, .bold)).foregroundStyle(pal.success)
                // D-337: paid by card / wallet but not confirmed yet, or failed.
                switch t.paymentStatus {
                case "pending", "awaiting_topup", "held":
                    if t.paymentMethod != "cash" { Text(L("ss_pay_pending")).font(.dorr(13)).foregroundStyle(pal.orange) }
                case "failed":
                    Text(L("ss_pay_failed")).font(.dorr(13)).foregroundStyle(pal.danger)
                default:
                    EmptyView()
                }
                if let tip = t.tipMinor {
                    Spacer().frame(height: 10)
                    TipBanner(tipMinor: tip, currency: t.currency)
                }
            }
            if let due = t.cashToCollectMinor {
                cash(due)
            } else {
                if !rated { rating }
                BigButton(L("trip_next"), onClick: onNext)
            }
        }
        .task {
            if let list = await DriverApi.ratingTags().value { tags = list }
        }
    }

    private func cash(_ due: Int) -> some View {
        let receivedMinor = Int((Double(received) ?? 0) * 100)
        return Card {
            Text(L("trip_collect", money(due, t.currency))).font(.dorr(18, .heavy)).foregroundStyle(pal.orange)
            Spacer().frame(height: 8)
            DorrField(value: received, onValueChange: { received = $0.filter { $0.isNumber || $0 == "." } }, label: L("trip_received"),
                      singleLine: true, keyboardOptions: KeyboardOptions(keyboardType: .decimalPad))
            if receivedMinor > due {
                Text(L("trip_change", money(receivedMinor - due, t.currency))).font(.dorr(13)).foregroundStyle(pal.info).padding(.top, 4)
            }
            if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(pal.danger) }
            Spacer().frame(height: 10)
            BigButton(L("trip_collected"), color: pal.success, enabled: receivedMinor >= due, busy: busy) {
                busy = true
                Task {
                    switch await DriverApi.collectCash(uuid: t.uuid ?? "", body: ["received_minor": receivedMinor]) {
                    case let .ok(r, _): onUpdate(r)
                    case let .failed(e): error = e.displayMessage
                    case .offline: break
                    }
                    busy = false
                }
            }
        }
    }

    private var rating: some View {
        let criteria = t.ratingCriteria ?? []
        return Card {
            HStack(spacing: 0) {
                Text(L("trip_rate", t.rider?.name ?? "")).font(.dorr(16, .bold)).foregroundStyle(pal.ink).multilineTextAlignment(.center)
                RiderTick(verified: t.rider?.isVerified == true)
            }
            .frame(maxWidth: .infinity)
            HStack(spacing: 0) {
                ForEach(1...5, id: \.self) { i in
                    Image(systemName: i <= stars ? "star.fill" : "star").font(.system(size: 32))
                        .foregroundStyle(i <= stars ? pal.warning : Color.gray)
                        .frame(width: 40, height: 40).contentShape(Rectangle())
                        .onTapGesture { stars = i }
                }
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 6)
            if stars > 0 && !criteria.isEmpty {
                VStack(spacing: 0) {
                    ForEach(Array(criteria.enumerated()), id: \.offset) { _, c in
                        let key = c.key ?? ""
                        let value = scores[key] ?? stars
                        HStack(spacing: 0) {
                            Text(verbatim: c.label ?? key).font(.dorr(13, .semibold)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                            ForEach(1...5, id: \.self) { i in
                                Image(systemName: i <= value ? "star.fill" : "star").font(.system(size: 21))
                                    .foregroundStyle(i <= value ? pal.warning : Color.gray)
                                    .frame(width: 26, height: 26).contentShape(Rectangle())
                                    .onTapGesture { scores[key] = i }
                            }
                        }
                        .padding(.vertical, 4)
                    }
                }
                .padding(.horizontal, 12).padding(.vertical, 6)
                .frame(maxWidth: .infinity)
                .background(pal.ink.opacity(0.05), in: RoundedRectangle(cornerRadius: 16))
                .padding(.bottom, 8)
            }
            if stars > 0 {
                FlowRow(spacing: 6) {
                    ForEach(tags.filter { stars >= 4 ? $0.sentiment == "positive" : $0.sentiment != "positive" }, id: \.id) { tag in
                        let on = tag.id.map { chosen.contains($0) } ?? false
                        Text(verbatim: tag.label ?? "").font(.dorr(12)).foregroundStyle(on ? Color.white : pal.ink)
                            .padding(.horizontal, 12).padding(.vertical, 6)
                            .background(on ? pal.orange : pal.ink.opacity(0.07), in: Capsule())
                            .onTapGesture {
                                guard let id = tag.id else { return }
                                if on { chosen.remove(id) } else { chosen.insert(id) }
                            }
                    }
                }
                Spacer().frame(height: 10)
                BigButton(L("trip_rate_send"), color: pal.navy, busy: busy) {
                    busy = true
                    Task {
                        var body: JsonBody = ["stars": stars]
                        if !criteria.isEmpty {
                            var s: [String: Any] = [:]
                            for c in criteria { s[c.key ?? ""] = scores[c.key ?? ""] ?? stars }
                            body["scores"] = s
                        }
                        body["tags"] = Array(chosen)
                        _ = await DriverApi.rate(uuid: t.uuid ?? "", body: body)
                        rated = true
                        busy = false
                    }
                }
            }
        }
    }
}

/// Compose's `FlowRow`: the chips in rows, wrapping at the width.
private struct FlowRow: Layout {
    var spacing: CGFloat = 6

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let maxWidth = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, row: CGFloat = 0, widest: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > maxWidth { y += row + spacing; x = 0; row = 0 }
            x += size.width + spacing
            row = Swift.max(row, size.height)
            widest = Swift.max(widest, x - spacing)
        }
        return CGSize(width: proposal.width ?? widest, height: y + row)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x: CGFloat = 0, y: CGFloat = 0, row: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > bounds.width { y += row + spacing; x = 0; row = 0 }
            s.place(at: CGPoint(x: bounds.minX + x, y: bounds.minY + y), proposal: ProposedViewSize(size))
            x += size.width + spacing
            row = Swift.max(row, size.height)
        }
    }
}

// MARK: - cancelling

/// The reasons sheet's content (Android's ModalBottomSheet, fully open).
private struct CancelRide: View {
    @Environment(\.pal) private var pal
    let t: TripDto
    let onClose: () -> Void
    let onReleased: () -> Void
    @State private var reasons: [ReasonDto] = []
    @State private var reason: Int?
    @State private var error: String?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("trip_cancel_reason")).font(.dorr(17, .bold)).foregroundStyle(pal.ink)
                ForEach(Array(reasons.enumerated()), id: \.offset) { _, r in
                    Button { reason = r.id } label: {
                        HStack(spacing: 0) {
                            RadioMark(selected: reason != nil && reason == r.id)
                            Text(verbatim: r.label ?? "").font(.dorr(16)).foregroundStyle(pal.ink)
                            Spacer(minLength: 0)
                        }
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                }
                if let error { Text(verbatim: error).font(.dorr(16)).foregroundStyle(pal.danger) }
                Spacer().frame(height: 10)
                BigButton(L("trip_cancel_confirm"), color: pal.danger, enabled: reason != nil) {
                    Task {
                        switch await DriverApi.cancel(key: newIdempotencyKey(), uuid: t.uuid ?? "", body: ["reason_id": reason]) {
                        case .ok: onReleased()
                        case let .failed(e): error = e.displayMessage
                        case .offline: break
                        }
                    }
                }
            }
            .padding(20)
        }
        .background(pal.card.ignoresSafeArea())
        .task { if let list = await DriverApi.cancelReasons().value { reasons = list } }
    }
}

/// Material's `RadioButton` (48 touch box, 20 ring, 12 dot).
private struct RadioMark: View {
    @Environment(\.pal) private var pal
    let selected: Bool

    var body: some View {
        ZStack {
            Circle().strokeBorder(selected ? pal.accent : pal.mut, lineWidth: 2).frame(width: 20, height: 20)
            if selected { Circle().fill(pal.accent).frame(width: 10, height: 10) }
        }
        .frame(width: 48, height: 48)
    }
}

// MARK: - the chat

private struct TripChat: View {
    let t: TripDto
    let onClose: () -> Void
    @State private var messages: [MessageDto] = []
    @State private var quick: [QuickDto] = []
    @State private var open = true

    private func load() async {
        if let chat = await DriverApi.messages(uuid: t.uuid ?? "").value {
            messages = chat.messages ?? []
            quick = chat.quickReplies ?? []
            open = chat.open ?? false
        }
    }

    // The trip screen keeps the ride's channel for the whole ride; the chat doesn't close it.
    private func send(_ body: String?, _ key: String?) {
        Task {
            var payload: JsonBody = ["client_id": UUID().uuidString.lowercased()]
            if let body { payload["body"] = body }
            if let key { payload["quick"] = key }
            if let m = await DriverApi.send(uuid: t.uuid ?? "", body: payload).value { messages.append(m) }
        }
    }

    var body: some View {
        // O-61: the conversation look of the DORR app's chat.
        TripChatScreen(
            title: t.rider?.name ?? "",
            titleVerified: t.rider?.isVerified == true,
            subtitle: L("trip_chat_subtitle", t.number ?? ""),
            messages: messages, quick: quick, open: open,
            onSend: { body, key in send(body, key) },
            onCall: ["accepted", "arrived", "in_progress"].contains(t.status ?? "") ? { Task { if await askMicrophone() { startRideCall(t) } } } : nil,
            onClose: onClose
        )
        // The chat: loaded once, then each message arrives on the ride's channel as it is sent.
        .task(id: t.uuid) {
            if let uuid = t.uuid { DriverLive.shared.watchRide(uuid) }
            await load()
            for await e in DriverLive.shared.events.values {
                if e.name == "ride.message" || e.name == "ride.changed" { await load() }
            }
        }
    }
}
