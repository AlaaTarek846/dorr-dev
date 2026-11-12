import SwiftUI

// driverApp `ui/BusTripLive.kt`.
//
// One bus trip, for its captain (docs/rides_tasks.md O-24, O-68, O-69): the route and its time with a
// countdown on the phone's clock; «start» only from its time (a little before — the server's
// `can_depart_at`); booked / on board / free; the bus from above with its seats coming alive; and each
// passenger as a card — their seats to board them, and an in-app chat and call (no numbers exchanged).
// Everything follows the trip's events: a booking, a message, a ring — no timer asks the server.

@MainActor
struct BusTripDetailScreen: View {
    @Environment(\.pal) private var pal
    let uuid: String
    let onBack: () -> Void

    @State private var trip: DriverBusTripDto?
    @State private var leg = "outbound"
    @State private var message: String?
    @State private var error: String?
    @State private var busy = false
    @State private var chatWith: BusPassengerDto?
    @State private var scanning = false
    /// The phone's clock, for the countdown and for when «start» opens.
    @State private var now = Date()

    var body: some View {
        ZStack {
            VStack(spacing: 0) {
                if let t = trip {
                    BusHero(t: t, now: now, onBack: onBack)
                    ScrollView { content(t).padding(16) }
                } else {
                    HStack { BusRoundIcon(icon: "arrow.backward", onClick: onBack); Spacer() }
                        .padding(16)
                        .background(pal.accent.ignoresSafeArea(edges: .top))
                    Spacer()
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
            .background(pal.bg.ignoresSafeArea())

            RideCallOverlay(onAccept: {
                if let booking = RideCallSession.shared.rideUuid, let call = RideCallSession.shared.callUuid {
                    RideCallSession.shared.accept { try callJoin(await DriverBusApi.acceptCall(booking: booking, call: call)) }
                }
            })
        }
        .task(id: uuid) { await reload() }
        // A booking, a message or a ring on this trip arrives as an event: one reload.
        .onReceive(DriverLive.shared.events.receive(on: DispatchQueue.main)) { e in
            if e.name.hasPrefix("bus.") { Task { await reload() } }
            if e.name == "bus.call_changed" {
                let status = e.data[snake: "status"]?.str
                if status != "ringing" && status != "active" { RideCallSession.shared.endedElsewhere(e.data[snake: "call_uuid"]?.str) }
            }
        }
        // A passenger ringing (caught up on open, or the event above): the call screen rings.
        .task(id: trip) {
            guard let t = trip else { return }
            if let p = (t.passengers ?? []).first(where: { $0.call?.status == "ringing" && $0.call?.caller == "user" }),
               let booking = p.bookingUuid, let call = p.call?.uuid {
                RideCallSession.shared.incoming(ride: booking, call: call, peer: p.name ?? "",
                                                end: { b, c in _ = await DriverBusApi.endCall(booking: b, call: c) },
                                                peerVerified: p.isVerified ?? false)
            }
        }
        // The phone's clock, every second.
        .task {
            while !Task.isCancelled {
                try? await Task.sleep(nanoseconds: 1_000_000_000)
                now = Date()
            }
        }
        .fullScreenCover(isPresented: $scanning) {
            TicketScanScreen(prompt: L("bus_scan_prompt")) { code in
                scanning = false
                if let code { board(["qr": code]) }
            }
        }
        .fullScreenCover(isPresented: Binding(get: { chatWith != nil }, set: { if !$0 { chatWith = nil } })) {
            if let p = chatWith {
                BusPassengerChat(p: p, open: ["scheduled", "departed", "returning"].contains(trip?.status ?? "scheduled"),
                                 onClose: { chatWith = nil; Task { await reload() } })
                    .environment(\.pal, pal)
            }
        }
    }

    @ViewBuilder
    private func content(_ t: DriverBusTripDto) -> some View {
        let status = t.status ?? "scheduled"
        let passengers = t.passengers ?? []
        // Booked / on board / free, for the leg shown.
        let takenSeats = Set((leg == "return" ? t.taken?.returnLeg : t.taken?.outbound) ?? [])
        let boardedSeats = Set(passengers.flatMap { p in (p.seats ?? []).filter { $0.leg == leg && ($0.boarded ?? false) }.compactMap(\.seat) })
        let open = ["scheduled", "departed", "returning"].contains(status)
        VStack(alignment: .leading, spacing: 14) {
            if let message { Banner(message, kind: .success) }
            Banner(error)

            HStack(spacing: 10) {
                BusStat(icon: "chair.fill", value: takenSeats.count, label: L("bus_stat_booked"), tint: pal.accent)
                BusStat(icon: "person.fill.checkmark", value: boardedSeats.count, label: L("bus_stat_boarded"), tint: pal.success)
                BusStat(icon: "chair.fill", value: max((t.seatsTotal ?? 0) - takenSeats.count, 0), label: L("bus_stat_free"), tint: pal.mut)
            }

            // The action of the moment — «start» only from its time.
            switch status {
            case "scheduled":
                StartButton(text: L("bus_depart"), opensAt: t.canDepartAt, now: now, busy: busy, color: pal.success) { move("departed") }
            case "departed":
                if t.returnAt != nil {
                    StartButton(text: L("bus_start_return"), opensAt: t.canReturnAt, now: now, busy: busy, color: pal.orange) { move("returning") }
                }
                PrimaryButton(L("bus_finish"), busy: busy) { move("completed") }
            case "returning":
                PrimaryButton(L("bus_finish"), busy: busy) { move("completed") }
            default:
                EmptyView()
            }
            if open {
                HStack(spacing: 0) {
                    Image(systemName: "qrcode.viewfinder").font(.system(size: 20)).foregroundStyle(pal.accent)
                    HSpace(8)
                    Text(L("bus_scan_ticket")).font(.dorr(14, .bold)).foregroundStyle(pal.accent)
                }
                .padding(14)
                .frame(maxWidth: .infinity)
                .background(pal.accent.opacity(0.1), in: RoundedRectangle(cornerRadius: 18))
                .contentShape(RoundedRectangle(cornerRadius: 18))
                .onTapGesture { scanning = true }
            }

            // The seats, per leg.
            if t.returnAt != nil {
                HStack(spacing: 0) {
                    LegTab(label: L("bus_leg_out"), on: leg == "outbound") { leg = "outbound" }
                    LegTab(label: L("bus_leg_back"), on: leg == "return") { leg = "return" }
                }
                .padding(4)
                .background(pal.card, in: Capsule())
                .overlay(Capsule().stroke(pal.line, lineWidth: 1))
            }
            BusDeckView(seats: t.seatsTotal ?? 0, perRow: min(max(t.seatsPerRow ?? 4, 2), 6), taken: takenSeats, boarded: boardedSeats)
            SeatKey()

            // O-24, O-68: who booked — board them, chat or call them inside the app.
            Text(L("bus_passengers", passengers.count)).font(.dorr(17, .black)).foregroundStyle(pal.ink)
            if passengers.isEmpty {
                VStack(spacing: 0) {
                    Image(systemName: "bus.fill").font(.system(size: 34)).foregroundStyle(pal.faint).frame(width: 40, height: 40)
                    Text(L("bus_no_passengers")).font(.dorr(13)).foregroundStyle(pal.mut)
                }
                .padding(24)
                .frame(maxWidth: .infinity)
                .surface()
            }
            ForEach(Array(passengers.enumerated()), id: \.offset) { i, p in
                PassengerCard(
                    p: p, index: i, open: open,
                    onBoard: { l, s in board(["leg": l, "seat": s]) },
                    onChat: { chatWith = p },
                    onCall: { callPassenger(p) }
                )
            }
            if status == "scheduled" {
                Text(L("bus_cancel_trip")).font(.dorr(14, .bold)).foregroundStyle(pal.danger)
                    .padding(.horizontal, 18).padding(.vertical, 10)
                    .contentShape(Capsule())
                    .onTapGesture { act { await DriverBusApi.cancel(uuid: uuid) } }
                    .frame(maxWidth: .infinity)
            }
        }
    }

    private func reload() async {
        if let v = await DriverBusApi.trip(uuid: uuid).value { trip = v }
    }

    private func board(_ body: JsonBody) {
        Task {
            switch await DriverBusApi.board(uuid: uuid, body: body) {
            case .ok(let b, _): message = L("bus_boarded_n", b.name ?? "", b.seat ?? 0); await reload()
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
        }
    }

    private func act(_ call: @escaping () async -> ApiResult<DriverBusTripDto>) {
        busy = true
        error = nil
        Task {
            switch await call() {
            case .ok: await reload()
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    private func move(_ to: String) { act { await DriverBusApi.move(uuid: uuid, body: ["to": to]) } }

    @MainActor private func callPassenger(_ p: BusPassengerDto) {
        guard let booking = p.bookingUuid else { return }
        RideCallSession.shared.call(
            ride: booking, peer: p.name ?? "",
            start: { try callJoin(await DriverBusApi.startCall(booking: booking)) },
            end: { b, c in _ = await DriverBusApi.endCall(booking: b, call: c) },
            peerVerified: p.isVerified ?? false
        )
    }
}

/// The circle in the hero: white on the trip's colours.
private struct BusRoundIcon: View {
    let icon: String
    let onClick: () -> Void

    var body: some View {
        Image(systemName: icon).font(.system(size: 18, weight: .semibold)).foregroundStyle(.white)
            .frame(width: 40, height: 40)
            .background(Color.white.opacity(0.2), in: Circle())
            .contentShape(Circle())
            .onTapGesture(perform: onClick)
    }
}

/// Compose's `spring(dampingRatio, stiffness)` (mass 1).
private func composeSpring(_ ratio: Double, _ stiffness: Double = 1500) -> Animation {
    .interpolatingSpring(mass: 1, stiffness: stiffness, damping: 2 * ratio * stiffness.squareRoot())
}

/// A rounded rectangle with its own radius at each corner (Compose's `RoundedCornerShape(topStart, topEnd, bottomStart, bottomEnd)`).
private struct CornersShape: InsettableShape {
    var topStart: CGFloat = 0
    var topEnd: CGFloat = 0
    var bottomStart: CGFloat = 0
    var bottomEnd: CGFloat = 0
    var inset: CGFloat = 0

    func path(in rect: CGRect) -> Path {
        let r = rect.insetBy(dx: inset, dy: inset)
        let m = min(r.width, r.height) / 2
        let tl = min(topStart, m), tr = min(topEnd, m), bl = min(bottomStart, m), br = min(bottomEnd, m)
        var p = Path()
        p.move(to: CGPoint(x: r.minX + tl, y: r.minY))
        p.addLine(to: CGPoint(x: r.maxX - tr, y: r.minY))
        p.addArc(center: CGPoint(x: r.maxX - tr, y: r.minY + tr), radius: tr, startAngle: .degrees(-90), endAngle: .degrees(0), clockwise: false)
        p.addLine(to: CGPoint(x: r.maxX, y: r.maxY - br))
        p.addArc(center: CGPoint(x: r.maxX - br, y: r.maxY - br), radius: br, startAngle: .degrees(0), endAngle: .degrees(90), clockwise: false)
        p.addLine(to: CGPoint(x: r.minX + bl, y: r.maxY))
        p.addArc(center: CGPoint(x: r.minX + bl, y: r.maxY - bl), radius: bl, startAngle: .degrees(90), endAngle: .degrees(180), clockwise: false)
        p.addLine(to: CGPoint(x: r.minX, y: r.minY + tl))
        p.addArc(center: CGPoint(x: r.minX + tl, y: r.minY + tl), radius: tl, startAngle: .degrees(180), endAngle: .degrees(270), clockwise: false)
        p.closeSubpath()
        return p
    }

    func inset(by amount: CGFloat) -> CornersShape { var s = self; s.inset += amount; return s }
}

/// The route, the day and the time, and how long until it leaves — on the phone's clock.
private struct BusHero: View {
    @Environment(\.pal) private var pal
    @Environment(\.displayScale) private var scale
    @Environment(\.layoutDirection) private var direction
    let t: DriverBusTripDto
    let now: Date
    let onBack: () -> Void
    @State private var start = Date()

    var body: some View {
        let status = t.status ?? "scheduled"
        let leaves = parseInstant(t.departAt)
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                BusRoundIcon(icon: "arrow.backward", onClick: onBack)
                HSpace(10)
                Text(L("bus_trip")).font(.dorr(19, .black)).foregroundStyle(.white).frame(maxWidth: .infinity, alignment: .leading)
                Text(busStatusText(status)).font(.dorr(12, .bold)).foregroundStyle(.white)
                    .padding(.horizontal, 12).padding(.vertical, 5)
                    .background(Color.white.opacity(0.2), in: Capsule())
            }
            VSpace(16)
            // From → to, the bus driving along the line.
            HStack(spacing: 0) {
                VStack(alignment: .leading, spacing: 0) {
                    Text(busTime(t.departAt)).font(.dorr(22, .black)).foregroundStyle(.white)
                    Text(t.origin?.address ?? "").font(.dorr(12)).foregroundStyle(.white.opacity(0.85)).lineLimit(2).truncationMode(.tail)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                ZStack(alignment: .leading) {
                    Path { p in p.move(to: CGPoint(x: 0, y: 0)); p.addLine(to: CGPoint(x: 110, y: 0)) }
                        .stroke(Color.white.opacity(0.6), style: StrokeStyle(lineWidth: 3 / scale, lineCap: .round, dash: [10 / scale, 8 / scale]))
                        .frame(width: 110, height: 2)
                    TimelineView(.animation(paused: status == "scheduled")) { tl in
                        let drive = status == "scheduled" ? 0 : tl.date.timeIntervalSince(start).truncatingRemainder(dividingBy: 2.6) / 2.6
                        Image(systemName: "bus.fill").font(.system(size: 18)).foregroundStyle(.white)
                            .frame(width: 24, height: 24)
                            .offset(x: (110 - 24) * drive * (direction == .rightToLeft ? -1 : 1))
                    }
                }
                .frame(width: 110, height: 34)
                VStack(alignment: .trailing, spacing: 0) {
                    Text(busDay(t.departAt)).font(.dorr(14, .bold)).foregroundStyle(.white)
                    Text(t.destination?.address ?? "").font(.dorr(12)).foregroundStyle(.white.opacity(0.85)).lineLimit(2).truncationMode(.tail)
                        .multilineTextAlignment(.trailing)
                }
                .frame(maxWidth: .infinity, alignment: .trailing)
            }
            if let back = t.returnAt {
                Text(L("bus_back_at", busDay(back) + " " + busTime(back))).font(.dorr(12)).foregroundStyle(.white.opacity(0.85)).padding(.top, 8)
            }
            if status == "scheduled", let leaves {
                VSpace(10)
                HStack(spacing: 0) {
                    Image(systemName: "clock").font(.system(size: 14, weight: .semibold)).foregroundStyle(.white).frame(width: 16, height: 16)
                    HSpace(6)
                    Text(leaves > now ? L("bus_leaves_in", busSpan(leaves.timeIntervalSince(now))) : L("bus_time_to_go"))
                        .font(.dorr(13, .bold)).foregroundStyle(.white)
                }
                .padding(.horizontal, 12).padding(.vertical, 6)
                .background(Color.white.opacity(0.18), in: Capsule())
            }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(
            LinearGradient(colors: [pal.accent, pal.accent.opacity(0.78), pal.orange.opacity(0.85)], startPoint: .topLeading, endPoint: .bottomTrailing)
                .clipShape(CornersShape(bottomStart: 30, bottomEnd: 30))
                .ignoresSafeArea(edges: .top)
        )
    }
}

/// «Start» — locked, with the time it opens, until then (the phone's clock; the server checks it too).
private struct StartButton: View {
    @Environment(\.pal) private var pal
    let text: String
    let opensAt: String?
    let now: Date
    let busy: Bool
    let color: Color
    let onClick: () -> Void

    var body: some View {
        let opens = parseInstant(opensAt)
        let ready = opens == nil || now >= opens!
        PrimaryButton(text, enabled: ready, busy: busy, color: color, icon: ready ? nil : "lock.fill", onClick: onClick)
        if !ready, let opens {
            Text(L("bus_start_opens_at", busTime(opensAt), busSpan(opens.timeIntervalSince(now))))
                .font(.dorr(12)).foregroundStyle(pal.mut).multilineTextAlignment(.center)
                .frame(maxWidth: .infinity).padding(.top, 2)
        }
    }
}

/// `animateIntAsState(value, tween(600))`: the number counts to its new value.
private struct CountingText: View, Animatable {
    var value: Double
    let color: Color
    var animatableData: Double {
        get { value }
        set { value = newValue }
    }

    var body: some View { Text("\(Int(value.rounded()))").font(.dorr(20, .black)).foregroundStyle(color) }
}

private struct BusStat: View {
    @Environment(\.pal) private var pal
    let icon: String
    let value: Int
    let label: String
    let tint: Color

    var body: some View {
        VStack(spacing: 0) {
            Image(systemName: icon).font(.system(size: 16)).foregroundStyle(tint)
                .frame(width: 34, height: 34)
                .background(tint.opacity(0.14), in: Circle())
            CountingText(value: Double(value), color: pal.ink).animation(.tween(600), value: value).padding(.top, 4)
            Text(label).font(.dorr(11)).foregroundStyle(pal.mut)
        }
        .padding(.vertical, 12)
        .frame(maxWidth: .infinity)
        .surface(18)
    }
}

private struct LegTab: View {
    @Environment(\.pal) private var pal
    let label: String
    let on: Bool
    let onClick: () -> Void

    var body: some View {
        Text(label).font(.dorr(13, .bold)).foregroundStyle(on ? .white : pal.mut)
            .padding(.vertical, 10)
            .frame(maxWidth: .infinity)
            .background(on ? pal.accent : .clear, in: Capsule())
            .animation(.tween(220), value: on)
            .contentShape(Capsule())
            .onTapGesture(perform: onClick)
    }
}

/// O-69: the bus from above — the windscreen and the wheel at the front, the door, the aisle; each seat a
/// seat (free / booked / on board with a tick). Seats arrive row by row, and a seat that changes pops.
struct BusDeckView: View {
    @Environment(\.pal) private var pal
    let seats: Int
    let perRow: Int
    let taken: Set<Int>
    let boarded: Set<Int>

    var body: some View {
        let left = perRow / 2
        let rows = (seats + perRow - 1) / max(perRow, 1)
        let shape = CornersShape(topStart: 46, topEnd: 46, bottomStart: 22, bottomEnd: 22)
        VStack(spacing: 0) {
            // The front: windscreen, the wheel on the captain's side, the door across.
            HStack(spacing: 0) {
                SteeringWheel().frame(width: 34, height: 34)
                Spacer(minLength: 0)
                Capsule()
                    .fill(LinearGradient(colors: [pal.info.opacity(0.25), pal.info.opacity(0.6), pal.info.opacity(0.25)], startPoint: .leading, endPoint: .trailing))
                    .frame(width: 70, height: 8)
                Spacer(minLength: 0)
                RoundedRectangle(cornerRadius: 6).stroke(pal.line, lineWidth: 2)
                    .frame(width: 30, height: 34)
                    .overlay(Rectangle().fill(pal.line).frame(width: 2, height: 22))
            }
            .padding(.bottom, 10)
            Rectangle().fill(pal.line).frame(height: 1)
            VSpace(8)
            ForEach(0..<max(rows, 0), id: \.self) { r in
                HStack(spacing: 0) {
                    ForEach(0..<perRow, id: \.self) { c in
                        if c == left {
                            // The aisle.
                            Rectangle().fill(pal.line.opacity(0.6)).frame(width: 2, height: 18).frame(width: 22)
                        }
                        let n = r * perRow + c + 1
                        ZStack {
                            if n <= seats {
                                BusSeat(n: n, row: r, state: boarded.contains(n) ? .boarded : taken.contains(n) ? .booked : .free)
                            } else {
                                Color.clear.aspectRatio(0.92, contentMode: .fit)
                            }
                        }
                        .padding(3)
                        .frame(maxWidth: .infinity)
                    }
                }
                .padding(.vertical, 2)
            }
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 14)
        .background(LinearGradient(colors: [pal.card, pal.bg], startPoint: .top, endPoint: .bottom))
        .clipShape(shape)
        .overlay(shape.strokeBorder(pal.line, lineWidth: 2))
        .padding(.horizontal, 6)
    }
}

private enum SeatState { case free, booked, boarded }

private struct BusSeat: View {
    @Environment(\.pal) private var pal
    let n: Int
    let row: Int
    let state: SeatState
    // Row by row on first show.
    @State private var appear: CGFloat = 0
    // A change (booked, on board) pops.
    @State private var pop = false

    var body: some View {
        let tint: Color = state == .boarded ? pal.success : state == .booked ? pal.accent : pal.faint.opacity(0.55)
        GeometryReader { g in
            ZStack {
                RoundedRectangle(cornerRadius: 12).fill(tint.opacity(state == .free ? 0.08 : 0.14))
                Image(systemName: "chair.fill").resizable().scaledToFit().foregroundStyle(tint)
                    .frame(width: g.size.width * 0.62, height: g.size.height * 0.62)
                    .offset(y: -4)
                Text("\(n)").font(.dorr(10, .black)).foregroundStyle(state == .free ? pal.mut : tint)
                    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottom)
                    .padding(.bottom, 2)
                if state == .boarded {
                    Image(systemName: "checkmark").font(.system(size: 7, weight: .heavy)).foregroundStyle(.white)
                        .frame(width: 14, height: 14)
                        .background(pal.success, in: Circle())
                        .padding(3)
                        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topTrailing)
                        .transition(.scale.combined(with: .opacity).animation(composeSpring(0.4)))
                }
            }
            .animation(.tween(260), value: state)
        }
        .aspectRatio(0.92, contentMode: .fit)
        .opacity(appear)
        .scaleEffect(appear * (pop ? 1.15 : 1))
        .animation(composeSpring(0.4), value: pop)
        .task {
            try? await Task.sleep(nanoseconds: UInt64(row) * 45_000_000)
            withAnimation(composeSpring(0.6, 400)) { appear = 1 }
        }
        .onChange(of: state) { _ in
            pop = true
            Task {
                try? await Task.sleep(nanoseconds: 160_000_000)
                pop = false
            }
        }
    }
}

private struct SteeringWheel: View {
    @Environment(\.pal) private var pal
    @Environment(\.displayScale) private var scale

    var body: some View {
        Canvas { ctx, size in
            let px = 1 / scale
            let r = min(size.width, size.height) / 2
            let c = CGPoint(x: size.width / 2, y: size.height / 2)
            ctx.stroke(Path(ellipseIn: CGRect(x: c.x - (r - 3 * px), y: c.y - (r - 3 * px), width: 2 * (r - 3 * px), height: 2 * (r - 3 * px))),
                       with: .color(pal.mut), lineWidth: 5 * px)
            ctx.fill(Path(ellipseIn: CGRect(x: c.x - r * 0.22, y: c.y - r * 0.22, width: r * 0.44, height: r * 0.44)), with: .color(pal.mut))
            var h = Path()
            h.move(to: CGPoint(x: c.x - r + 4 * px, y: c.y)); h.addLine(to: CGPoint(x: c.x + r - 4 * px, y: c.y))
            ctx.stroke(h, with: .color(pal.mut), lineWidth: 4 * px)
            var v = Path()
            v.move(to: c); v.addLine(to: CGPoint(x: c.x, y: c.y + r - 4 * px))
            ctx.stroke(v, with: .color(pal.mut), lineWidth: 4 * px)
        }
    }
}

private struct SeatKey: View {
    @Environment(\.pal) private var pal

    var body: some View {
        HStack(spacing: 0) {
            Spacer(minLength: 0)
            KeyItem(tint: pal.faint.opacity(0.55), label: L("bus_stat_free"))
            Spacer(minLength: 0)
            KeyItem(tint: pal.accent, label: L("bus_stat_booked"))
            Spacer(minLength: 0)
            KeyItem(tint: pal.success, label: L("bus_stat_boarded"))
            Spacer(minLength: 0)
        }
        .frame(maxWidth: .infinity)
    }
}

private struct KeyItem: View {
    @Environment(\.pal) private var pal
    let tint: Color
    let label: String

    var body: some View {
        HStack(spacing: 0) {
            Image(systemName: "chair.fill").font(.system(size: 13)).foregroundStyle(tint).frame(width: 16, height: 16)
            HSpace(4)
            Text(label).font(.dorr(12)).foregroundStyle(pal.mut)
        }
    }
}

/// A passenger: who, their seats (tap one to board it), and chat / call inside the app.
private struct PassengerCard: View {
    @Environment(\.pal) private var pal
    @Environment(\.displayScale) private var scale
    let p: BusPassengerDto
    let index: Int
    let open: Bool
    let onBoard: (String, Int) -> Void
    let onChat: () -> Void
    let onCall: () -> Void
    @State private var appear: CGFloat = 0

    var body: some View {
        let seats = p.seats ?? []
        let allOn = !seats.isEmpty && seats.allSatisfy { $0.boarded ?? false }
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                ZStack {
                    Circle().fill(LinearGradient(colors: [pal.accent, pal.orange], startPoint: .topLeading, endPoint: .bottomTrailing))
                    Text(String((p.name ?? "?").prefix(1))).font(.dorr(18, .black)).foregroundStyle(.white)
                    if allOn {
                        Image(systemName: "checkmark").font(.system(size: 8, weight: .heavy)).foregroundStyle(.white)
                            .frame(width: 16, height: 16)
                            .background(pal.success, in: Circle())
                            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomTrailing)
                    }
                }
                .frame(width: 46, height: 46)
                HSpace(12)
                VStack(alignment: .leading, spacing: 0) {
                    HStack(spacing: 0) {
                        Text(p.name ?? "—").font(.dorr(15, .bold)).foregroundStyle(pal.ink).lineLimit(1).truncationMode(.tail)
                        RiderTick(verified: p.isVerified ?? false, size: 14)
                    }
                    Text(L(p.tripType == "round" ? "bus_price_round" : p.tripType == "return" ? "bus_leg_back" : "bus_leg_out"))
                        .font(.dorr(12)).foregroundStyle(pal.mut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if open {
                    ActionCircle(icon: "bubble.left.and.bubble.right.fill", tint: pal.accent, badge: p.unread ?? 0, onClick: onChat)
                    HSpace(8)
                    ActionCircle(icon: "phone.fill", tint: pal.success, badge: 0, onClick: onCall)
                }
            }
            VSpace(10)
            HStack(spacing: 6) {
                ForEach(Array(seats.enumerated()), id: \.offset) { _, s in
                    let on = s.boarded ?? false
                    HStack(spacing: 0) {
                        Image(systemName: on ? "checkmark" : "chair.fill").font(.system(size: 11, weight: .bold))
                            .foregroundStyle(on ? .white : pal.accent).frame(width: 14, height: 14)
                        HSpace(4)
                        Text((s.leg == "return" ? "↩ " : "") + "\(s.seat ?? 0)").font(.dorr(12, .bold)).foregroundStyle(on ? .white : pal.accent)
                    }
                    .padding(.horizontal, 10).padding(.vertical, 6)
                    .background(on ? pal.success : pal.accent.opacity(0.1), in: Capsule())
                    .animation(.tween(250), value: on)
                    .contentShape(Capsule())
                    .onTapGesture {
                        if !on && open, let leg = s.leg, let seat = s.seat { onBoard(leg, seat) }
                    }
                }
            }
            if open && !allOn {
                Text(L("bus_tap_to_board")).font(.dorr(11)).foregroundStyle(pal.faint).padding(.top, 6)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface(20)
        .opacity(appear)
        .offset(y: (1 - appear) * 40 / scale)
        .task {
            try? await Task.sleep(nanoseconds: UInt64(index) * 60_000_000)
            withAnimation(.tween(320)) { appear = 1 }
        }
    }
}

private struct ActionCircle: View {
    @Environment(\.pal) private var pal
    let icon: String
    let tint: Color
    let badge: Int
    let onClick: () -> Void
    @State private var pulse = false

    var body: some View {
        ZStack(alignment: .topTrailing) {
            Image(systemName: icon).font(.system(size: 17)).foregroundStyle(tint)
                .frame(width: 42, height: 42)
                .background(tint.opacity(0.14), in: Circle())
                .contentShape(Circle())
                .onTapGesture(perform: onClick)
            if badge > 0 {
                Text("\(badge)").font(.dorr(10, .bold)).foregroundStyle(.white)
                    .frame(width: 18, height: 18)
                    .background(pal.danger, in: Circle())
                    .scaleEffect(pulse ? 1.2 : 1)
                    .onAppear { withAnimation(.tween(600).repeatForever(autoreverses: true)) { pulse = true } }
            }
        }
    }
}

/// The chat with one passenger — the same conversation look as a ride's (O-61).
private struct BusPassengerChat: View {
    let p: BusPassengerDto
    let open: Bool
    let onClose: () -> Void
    @State private var messages: [MessageDto] = []
    @State private var quick: [QuickDto] = []
    @State private var canWrite: Bool

    init(p: BusPassengerDto, open: Bool, onClose: @escaping () -> Void) {
        self.p = p
        self.open = open
        self.onClose = onClose
        _canWrite = State(initialValue: open)
    }

    var body: some View {
        let booking = p.bookingUuid ?? ""
        TripChatScreen(
            title: p.name ?? "",
            titleVerified: p.isVerified ?? false,
            subtitle: L("bus_chat_subtitle", (p.seats ?? []).map { "\($0.seat ?? 0)" }.joined(separator: " · ")),
            messages: messages, quick: quick, open: canWrite,
            onSend: { body, key in send(body, key) },
            onCall: canWrite ? {
                RideCallSession.shared.call(
                    ride: booking, peer: p.name ?? "",
                    start: { try callJoin(await DriverBusApi.startCall(booking: booking)) },
                    end: { b, c in _ = await DriverBusApi.endCall(booking: b, call: c) },
                    peerVerified: p.isVerified ?? false
                )
            } : nil,
            onClose: onClose
        )
        .task(id: booking) { await load() }
        .onReceive(DriverLive.shared.events.receive(on: DispatchQueue.main)) { e in
            if e.name == "bus.message" { Task { await load() } }
        }
    }

    private func load() async {
        guard let booking = p.bookingUuid, let chat = await DriverBusApi.messages(booking: booking).value else { return }
        messages = chat.messages ?? []
        quick = chat.quickReplies ?? []
        canWrite = chat.open ?? false
    }

    private func send(_ body: String?, _ key: String?) {
        guard let booking = p.bookingUuid else { return }
        Task {
            var payload: JsonBody = [:]
            if let body { payload["body"] = body }
            if let key { payload["quick"] = key }
            payload["client_id"] = UUID().uuidString.lowercased()
            if let m = await DriverBusApi.send(booking: booking, body: payload).value { messages = messages + [m] }
        }
    }
}

private func busStatusText(_ status: String) -> String {
    switch status {
    case "departed": return L("bus_st_departed")
    case "returning": return L("bus_st_returning")
    case "completed": return L("bus_st_completed")
    case "canceled": return L("bus_st_canceled")
    default: return L("bus_st_scheduled")
    }
}

/// 2d 3h · 3h 20m · 12:05
private func busSpan(_ interval: TimeInterval) -> String {
    let s = max(Int(interval), 0)
    let d = s / 86_400
    let h = s % 86_400 / 3600
    let m = s % 3600 / 60
    if d > 0 { return "\(d)d \(h)h" }
    if h > 0 { return "\(h)h \(m)m" }
    return String(format: "%d:%02d", m, s % 60)
}
