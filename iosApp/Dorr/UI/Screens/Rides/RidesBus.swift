import SwiftUI

// androidApp `rides/RidesBus.kt` — bus booking (docs/rides_tasks.md O-22, O-23): a service of its own inside rides.
// Where I leave from and / or where I go (either alone filters too) → the trips captains published → a seat map like
// a plane's, for going, coming back, or both → paid on the payment screen → tickets with a QR per seat. The seat page
// reads on open (Android also reloads on `bus.seats` from the trip's public channel — Chat section); a seat taken
// meanwhile answers on booking and the page reloads.

private enum BusPage: Equatable {
    case search
    case seats(BusTripDto)
    case tickets
    case ticket(BusBookingDto, fresh: Bool)
}

struct BusHost: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let onClose: () -> Void
    @State private var page = BusPage.search
    @State private var forward = true
    // The search keeps its places and results while a seat page is opened and closed.
    @StateObject private var search = BusSearchState()

    private func go(_ to: BusPage, ahead: Bool = true) {
        forward = ahead
        page = to
    }

    var body: some View {
        ZStack {
            wa.settingsBackground.ignoresSafeArea()
            Group {
                switch page {
                case .search:
                    BusSearchPage(rides: state, s: search, onBack: onClose, onTickets: { go(.tickets) }, onPick: { go(.seats($0)) })
                case .seats(let trip):
                    BusSeatsPage(initial: trip, onBack: { go(.search, ahead: false) }, onBooked: { go(.ticket($0, fresh: true)) })
                case .tickets:
                    BusTicketsPage(onBack: { go(.search, ahead: false) }, onOpen: { go(.ticket($0, fresh: false)) })
                case .ticket(let b, let fresh):
                    BusTicketPage(initial: b, fresh: fresh, onBack: { go(.tickets, ahead: false) })
                }
            }
            .transition(.asymmetric(insertion: .move(edge: forward ? .trailing : .leading).combined(with: .opacity), removal: .opacity))
        }
        .animation(.easeInOut(duration: 0.3), value: page)
        .task {
            #if DEBUG
            if UserDefaults.standard.string(forKey: "DorrRidesStep") == "seats", let t = try? await BusApi.trip("t1").data { page = .seats(t) }
            #endif
            // Opened from a notification: that ticket (or my tickets).
            guard ServiceLink.shared.pending?.kind == "bus" else { return }
            let uuid = ServiceLink.shared.take("bus")
            if let uuid, let b = try? await BusApi.booking(uuid).data { page = .ticket(b, fresh: false) } else { page = .tickets }
        }
    }
}

@MainActor
private final class BusSearchState: ObservableObject {
    @Published var from: RidePoint?
    @Published var to: RidePoint?
    @Published var day: Date?
    @Published var results: [BusTripDto]?
    @Published var loading = false
    @Published var editing: String?
}

private func busFormat(_ iso: String?, _ pattern: String) -> String? {
    guard let d = parseRideTime(iso) else { return nil }
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.dateFormat = pattern
    return f.string(from: d)
}

private func busTime(_ iso: String?) -> String { busFormat(iso, "h:mm a") ?? "—" }
private func busDay(_ iso: String?) -> String { busFormat(iso, "EEEE d MMM") ?? "" }

// MARK: - Search

private struct BusSearchPage: View {
    @Environment(\.wa) private var wa
    @ObservedObject var rides: RidesState
    @ObservedObject var s: BusSearchState
    let onBack: () -> Void
    let onTickets: () -> Void
    let onPick: (BusTripDto) -> Void
    @State private var error: String?
    @State private var arrived = false
    @State private var spun = false

    private var key: String { "\(s.from?.lat ?? 0),\(s.from?.lng ?? 0)|\(s.to?.lat ?? 0),\(s.to?.lng ?? 0)|\(s.day?.timeIntervalSince1970 ?? 0)" }

    private func run() async {
        guard s.from != nil || s.to != nil else { return }
        s.loading = true
        error = nil
        let date = s.day.map { d -> String in
            let f = DateFormatter()
            f.locale = Locale(identifier: "en_US_POSIX")
            f.dateFormat = "yyyy-MM-dd"
            return f.string(from: d)
        }
        do {
            s.results = try await BusApi.search(fromLat: s.from?.lat, fromLng: s.from?.lng, toLat: s.to?.lat, toLng: s.to?.lng, date: date).data ?? []
        } catch {
            // A newer search replaced this one (the places changed): its own answer shows.
            if Task.isCancelled || error is CancellationError || (error as? URLError)?.code == .cancelled { return }
            self.error = error.apiFailure.message ?? L("rd_error")
        }
        s.loading = false
    }

    var body: some View {
        ZStack {
            ScrollView {
                VStack(spacing: 12) {
                    hero
                    places
                    results
                    Spacer(minLength: 24)
                }
            }
            .ignoresSafeArea(edges: .top)
            if let which = s.editing {
                SvcPlaceSearch(near: rides.me ?? rides.pickup?.latLng, tint: wa.accent, onClose: { s.editing = nil }) { point in
                    if which == "from" { s.from = point } else { s.to = point }
                    s.editing = nil
                }
                .transition(.move(edge: .bottom).combined(with: .opacity))
                .zIndex(2)
            }
        }
        .animation(.easeInOut(duration: 0.25), value: s.editing)
        // The pickup the rides map already knows is where I leave from, until I choose.
        .onAppear { if s.from == nil && s.results == nil { s.from = rides.pickup } }
        .task(id: key) { await run() }
    }

    /// The hero: a gradient band, the bus driving in, the title — the page's one big moment.
    private var hero: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                SvcRound(icon: "arrow.backward", action: onBack)
                Spacer()
                SvcRound(icon: "ticket.fill", action: onTickets)
            }
            HStack {
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("bus_title")).font(.dorr(26, .black)).foregroundStyle(.white)
                    Text(L("bus_subtitle")).font(.dorr(13)).foregroundStyle(.white.opacity(0.85))
                }
                Spacer()
                Image(systemName: "bus.fill").font(.system(size: 50)).foregroundStyle(.white)
                    .flipsForRightToLeftLayoutDirection(true)
                    .rotationEffect(.degrees(arrived ? 0 : -8))
                    .offset(x: arrived ? 0 : 220)
            }
        }
        .padding(.horizontal, 18).padding(.top, 60).padding(.bottom, 22)
        .background(LinearGradient(colors: [wa.accent, wa.accent.opacity(0.78), AppColors.brandOrange.opacity(0.85)], startPoint: .topLeading, endPoint: .bottomTrailing))
        .clipShape(BottomRoundedRectangle(radius: 32))
        .onAppear { withAnimation(.spring(response: 1.1, dampingFraction: 0.62)) { arrived = true } }
    }

    /// From / to, with the swap between them.
    private var places: some View {
        VStack(alignment: .leading, spacing: 0) {
            ZStack(alignment: .trailing) {
                VStack(spacing: 0) {
                    BusPlaceRow(icon: "location.fill", tint: AppColors.success, label: L("bus_from"), value: s.from?.address,
                                onClick: { s.editing = "from" }, onClear: s.from == nil ? nil : { s.from = nil })
                    Rectangle().fill(wa.settingsInk.opacity(0.08)).frame(height: 1).padding(.leading, 38)
                    BusPlaceRow(icon: "mappin.circle.fill", tint: AppColors.brandOrange, label: L("bus_to"), value: s.to?.address,
                                onClick: { s.editing = "to" }, onClear: s.to == nil ? nil : { s.to = nil })
                }
                Button {
                    let f = s.from
                    s.from = s.to
                    s.to = f
                    withAnimation(.easeInOut(duration: 0.38)) { spun.toggle() }
                } label: {
                    Image(systemName: "arrow.up.arrow.down").font(.system(size: 16, weight: .bold)).foregroundStyle(.white)
                        .rotationEffect(.degrees(spun ? 180 : 0))
                        .frame(width: 40, height: 40).background(wa.accent, in: Circle())
                        .shadow(color: .black.opacity(0.18), radius: 4, y: 2)
                }
                .buttonStyle(WaPressStyle())
                .accessibilityLabel(L("bus_swap"))
            }
            BusDayChips(day: s.day) { s.day = $0 }.padding(.top, 12)
            Text(L("bus_filter_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.top, 8)
        }
        .padding(14)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        .shadow(color: wa.night ? .clear : wa.accent.opacity(0.2), radius: 12, y: 4)
        .padding(.horizontal, 16)
    }

    @ViewBuilder private var results: some View {
        if s.loading {
            ProgressView().tint(wa.accent).padding(30)
        } else if let error {
            Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger).frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 20)
        } else if let list = s.results {
            if list.isEmpty {
                EmptyBus(text: L("bus_none"))
            } else {
                Text(verbatim: L("bus_found", list.count)).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                    .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 20)
                ForEach(Array(list.enumerated()), id: \.offset) { i, trip in
                    BusTripCard(trip: trip) { onPick(trip) }.padding(.horizontal, 16).staggerIn(i)
                }
            }
        } else {
            EmptyBus(text: L("bus_start_hint"))
        }
    }
}

private struct BusPlaceRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let tint: Color
    let label: String
    let value: String?
    let onClick: () -> Void
    let onClear: (() -> Void)?

    var body: some View {
        HStack(spacing: 8) {
            Button(action: onClick) {
                HStack(spacing: 8) {
                    Image(systemName: icon).font(.system(size: 14)).foregroundStyle(tint).frame(width: 30, height: 30).background(tint.opacity(0.14), in: Circle())
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: label).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                        Text(verbatim: value ?? L("bus_any_place")).font(.dorr(14, .semibold)).foregroundStyle(value == nil ? wa.settingsMut : wa.settingsInk).lineLimit(1)
                    }
                    Spacer(minLength: 0)
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            if let onClear {
                Button(action: onClear) { Text(verbatim: "✕").foregroundStyle(wa.settingsMut).padding(6) }.buttonStyle(.plain)
            }
        }
        .padding(.vertical, 10)
        .padding(.trailing, 48)
    }
}

private struct BusDayChips: View {
    @Environment(\.wa) private var wa
    let day: Date?
    let onPick: (Date?) -> Void

    var body: some View {
        let cal = Calendar.current
        let today = cal.startOfDay(for: Date())
        let f = DateFormatter()
        f.locale = Locale(identifier: AppLocale.current)
        f.dateFormat = "EEE d"
        var options: [(Date?, String)] = [(nil, L("bus_any_day")), (today, L("bus_today"))]
        options.append((cal.date(byAdding: .day, value: 1, to: today), L("bus_tomorrow")))
        for n in 2...6 {
            let d = cal.date(byAdding: .day, value: n, to: today)!
            options.append((d, f.string(from: d)))
        }
        return ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(Array(options.enumerated()), id: \.offset) { _, o in
                    let on = o.0 == day
                    Button { onPick(o.0) } label: {
                        HStack(spacing: 4) {
                            if o.0 == nil { Image(systemName: "calendar").font(.system(size: 13)).foregroundStyle(on ? .white : wa.settingsMut) }
                            Text(verbatim: o.1).font(.dorr(13, on ? .bold : .medium)).foregroundStyle(on ? .white : wa.settingsInk)
                        }
                        .padding(.horizontal, 14).padding(.vertical, 8)
                        .background(on ? wa.accent : wa.settingsBackground, in: Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
        }
        .animation(.easeInOut(duration: 0.2), value: day)
    }
}

private struct BusTripCard: View {
    @Environment(\.wa) private var wa
    let trip: BusTripDto
    let onClick: () -> Void

    var body: some View {
        let leg = trip.leg
        let price = leg == "return" ? trip.prices?.returnMinor : trip.prices?.outboundMinor
        let free = (leg == "return" ? trip.free?.return : trip.free?.outbound) ?? 0
        let seatTint = free > 0 ? AppColors.success : AppColors.danger
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 8) {
                    Text(verbatim: busTime(trip.leavesAt(leg))).font(.dorr(22, .black)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: busDay(trip.leavesAt(leg))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    Spacer(minLength: 0)
                    if leg == "return" { BusTag(text: L("bus_return_leg"), color: AppColors.brandOrange) }
                }
                BusRouteLine(from: trip.fromPlace(leg).address, to: trip.toPlace(leg).address).padding(.top, 10)
                HStack(spacing: 4) {
                    Image(systemName: "bus.fill").font(.system(size: 13)).foregroundStyle(wa.settingsMut)
                    let name = [trip.bus?.make, trip.bus?.model].compactMap { $0 }.joined(separator: " ")
                    Text(verbatim: name.isEmpty ? "—" : name).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                    Spacer(minLength: 0)
                    Image(systemName: "chair.lounge.fill").font(.system(size: 13)).foregroundStyle(seatTint)
                    Text(verbatim: L("bus_free_seats", free)).font(.dorr(12, .semibold)).foregroundStyle(seatTint)
                    Text(verbatim: rideMoney(price, trip.currency)).font(.dorr(17, .black)).foregroundStyle(wa.accent).padding(.leading, 6)
                }
                .padding(.top, 12)
                if trip.returnAt != nil && leg != "return" {
                    Text(verbatim: L("bus_has_return", busDay(trip.returnAt) + " " + busTime(trip.returnAt))).font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.top, 6)
                }
            }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
            .shadow(color: wa.night ? .clear : wa.accent.opacity(0.16), radius: 8, y: 3)
        }
        .buttonStyle(WaPressStyle())
    }
}

private struct BusRouteLine: View {
    @Environment(\.wa) private var wa
    let from: String?
    let to: String?

    var body: some View {
        HStack(spacing: 10) {
            VStack(spacing: 0) {
                Circle().fill(AppColors.success).frame(width: 10, height: 10)
                Rectangle().fill(wa.settingsInk.opacity(0.15)).frame(width: 2, height: 18)
                RoundedRectangle(cornerRadius: 2).fill(AppColors.brandOrange).frame(width: 10, height: 10)
            }
            VStack(alignment: .leading, spacing: 8) {
                Text(verbatim: from ?? "").font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                Text(verbatim: to ?? "").font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
            }
        }
    }
}

// MARK: - Seats

/// The seat page (O-23): the bus drawn from above — the windscreen, the captain's wheel, the door — and its seats in
/// rows with an aisle; a seat pops when chosen, a taken one is greyed with a person on it. Going, coming back, or both
/// (then a tab per leg, the same number of seats on each).
private struct BusSeatsPage: View {
    @Environment(\.wa) private var wa
    let initial: BusTripDto
    let onBack: () -> Void
    let onBooked: (BusBookingDto) -> Void
    @State private var trip: BusTripDto
    @State private var type: String
    @State private var leg: String
    @State private var outbound: [Int] = []
    @State private var back: [Int] = []
    @State private var busy = false
    @State private var error: String?
    @State private var idempotency = UUID().uuidString

    init(initial: BusTripDto, onBack: @escaping () -> Void, onBooked: @escaping (BusBookingDto) -> Void) {
        self.initial = initial
        self.onBack = onBack
        self.onBooked = onBooked
        let first = initial.leg == "return" ? "return" : "outbound"
        _trip = State(initialValue: initial)
        _type = State(initialValue: first)
        _leg = State(initialValue: first)
    }

    private func reload() async {
        guard let fresh = try? await BusApi.trip(trip.uuid).data else { return }
        var t = fresh
        t.leg = trip.leg
        trip = t
        // A seat someone else just took leaves my choice.
        outbound.removeAll { (fresh.taken?.outbound ?? []).contains($0) }
        back.removeAll { (fresh.taken?.return ?? []).contains($0) }
    }

    var body: some View {
        let canReturn = trip.prices?.returnMinor != nil && trip.returnAt != nil
        let perSeat: Int = {
            switch type {
            case "return": return trip.prices?.returnMinor ?? 0
            case "round": return trip.prices?.roundMinor ?? 0
            default: return trip.prices?.outboundMinor ?? 0
            }
        }()
        let count = type == "return" ? back.count : outbound.count
        let ready: Bool = {
            switch type {
            case "outbound": return !outbound.isEmpty
            case "return": return !back.isEmpty
            default: return !outbound.isEmpty && back.count == outbound.count
            }
        }()
        VStack(spacing: 0) {
            header(canReturn: canReturn)
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    if type == "round" {
                        HStack(spacing: 8) {
                            BusLegTab(label: L("bus_leg_outbound", outbound.count), on: leg == "outbound") { leg = "outbound" }
                            BusLegTab(label: L("bus_leg_return", back.count), on: leg == "return") { leg = "return" }
                        }
                        .padding(.bottom, 10)
                    }
                    HStack {
                        Spacer()
                        BusLegend(look: .free, label: L("bus_seat_free"))
                        Spacer()
                        BusLegend(look: .chosen, label: L("bus_seat_chosen"))
                        Spacer()
                        BusLegend(look: .taken, label: L("bus_seat_taken"))
                        Spacer()
                    }
                    let taken = Set(leg == "return" ? trip.taken?.return ?? [] : trip.taken?.outbound ?? [])
                    let chosen = Set(leg == "return" ? back : outbound)
                    BusDeck(seats: trip.seatsTotal ?? 0, perRow: min(max(trip.seatsPerRow ?? 4, 2), 6), taken: taken, chosen: chosen) { n in tapSeat(n) }
                        .id(leg)
                        .padding(.top, 12)
                    if type == "round" && outbound.count != back.count {
                        Text(L("bus_round_same")).font(.dorr(12, .semibold)).foregroundStyle(AppColors.warning).padding(.top, 10)
                    }
                    if let note = trip.note, !note.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: "📝 \(note)").font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 10)
                    }
                }
                .padding(.horizontal, 16).padding(.vertical, 12)
            }
            summary(perSeat: perSeat, count: count, ready: ready)
        }
        .task(id: trip.uuid) { await reload() }
        .onChange(of: type) { t in leg = t == "return" ? "return" : "outbound" }
    }

    private func tapSeat(_ n: Int) {
        error = nil
        if leg == "return" {
            if let i = back.firstIndex(of: n) { back.remove(at: i) } else { back.append(n) }
        } else {
            if let i = outbound.firstIndex(of: n) { outbound.remove(at: i) } else { outbound.append(n) }
        }
        // Going and back: the same number each way — after picking going, the coming-back tab is next.
        if type == "round" && leg == "outbound" && outbound.count > back.count && back.isEmpty {
            Task {
                try? await Task.sleep(nanoseconds: 350_000_000)
                leg = "return"
            }
        }
    }

    /// Header: the route and when, then going · coming back · both.
    private func header(canReturn: Bool) -> some View {
        var types: [(String, String)] = [("outbound", L("bus_type_outbound"))]
        if canReturn {
            types.append(("return", L("bus_type_return")))
            types.append(("round", L("bus_type_round")))
        }
        return VStack(alignment: .leading, spacing: 12) {
            HStack(spacing: 10) {
                SvcRound(icon: "arrow.backward", action: onBack)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: (trip.fromPlace(trip.leg).address ?? "") + "  ←  " + (trip.toPlace(trip.leg).address ?? ""))
                        .font(.dorr(14, .bold)).foregroundStyle(.white).lineLimit(2)
                    Text(verbatim: busDay(trip.leavesAt(trip.leg)) + " · " + busTime(trip.leavesAt(trip.leg))).font(.dorr(12)).foregroundStyle(.white.opacity(0.85))
                }
                Spacer(minLength: 0)
            }
            HStack(spacing: 0) {
                ForEach(types, id: \.0) { key, label in
                    let on = type == key
                    Button { type = key } label: {
                        Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(on ? wa.accent : .white)
                            .frame(maxWidth: .infinity).padding(.vertical, 9)
                            .background(on ? Color.white : .clear, in: Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(4)
            .background(Color.white.opacity(0.16), in: Capsule())
            .animation(.easeInOut(duration: 0.22), value: type)
        }
        .padding(.horizontal, 16).padding(.top, 8).padding(.bottom, 16)
        .background(LinearGradient(colors: [wa.accent, wa.accent.opacity(0.85)], startPoint: .top, endPoint: .bottom).clipShape(BottomRoundedRectangle(radius: 28)).ignoresSafeArea(edges: .top))
    }

    /// The summary: chosen seats, the total, pay.
    private func summary(perSeat: Int, count: Int, ready: Bool) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            if count > 0 {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 6) {
                        ForEach((type == "return" ? back : outbound).sorted(), id: \.self) { BusTag(text: "💺 \($0)", color: wa.accent) }
                        if type == "round" { ForEach(back.sorted(), id: \.self) { BusTag(text: "↩ \($0)", color: AppColors.brandOrange) } }
                    }
                }
                .padding(.bottom, 10)
                .transition(.opacity.combined(with: .move(edge: .bottom)))
            }
            HStack(spacing: 10) {
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: L("bus_total_for", count)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: rideMoney(perSeat * count, trip.currency)).font(.dorr(22, .black)).foregroundStyle(wa.settingsInk)
                        .contentTransition(.numericText())
                    Text(verbatim: L("bus_per_seat", rideMoney(perSeat, trip.currency))).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                }
                Spacer(minLength: 0)
                AccentButton(text: L("bus_book_pay"), enabled: ready, busy: busy) { book() }.frame(width: 170)
            }
            if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(AppColors.danger).padding(.top, 6) }
        }
        .padding(16)
        .background(wa.settingsCard.clipShape(UnevenRoundedRectangleCompat(topRadius: 26)).shadow(color: .black.opacity(0.12), radius: 14, y: -4).ignoresSafeArea(edges: .bottom))
        .animation(.easeInOut(duration: 0.25), value: count)
    }

    private func book() {
        busy = true
        error = nil
        let body: [String: JSONValue] = [
            "trip_type": .string(type),
            "seats_outbound": .array(type != "return" ? outbound.map { .int($0) } : []),
            "seats_return": .array(type != "outbound" ? back.map { .int($0) } : []),
        ]
        Task {
            do {
                if var b = try await BusApi.book(trip.uuid, body, key: idempotency).data {
                    if b.trip == nil { b.trip = trip }
                    onBooked(b)
                }
            } catch {
                let f = error.apiFailure
                self.error = f.message ?? L("rd_error")
                if f.errorCode == "rides_bus_seat_taken" { await reload() }
            }
            busy = false
        }
    }
}

private struct BusLegTab: View {
    @Environment(\.wa) private var wa
    let label: String
    let on: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(on ? .white : wa.settingsInk)
                .frame(maxWidth: .infinity).padding(.vertical, 10)
                .background(on ? wa.accent : wa.settingsCard, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.accent.opacity(0.3), lineWidth: 1))
        }
        .buttonStyle(.plain)
        .animation(.easeInOut(duration: 0.2), value: on)
    }
}

private enum SeatLook { case free, chosen, taken }

private struct BusLegend: View {
    @Environment(\.wa) private var wa
    let look: SeatLook
    let label: String

    var body: some View {
        HStack(spacing: 6) {
            BusSeat(look: look, number: nil).frame(width: 20, height: 20)
            Text(verbatim: label).font(.dorr(12)).foregroundStyle(wa.settingsMut)
        }
    }
}

/// The bus from above: a rounded body with the windscreen, the captain's wheel and the door, then the rows.
private struct BusDeck: View {
    @Environment(\.wa) private var wa
    let seats: Int
    let perRow: Int
    let taken: Set<Int>
    let chosen: Set<Int>
    let onSeat: (Int) -> Void

    var body: some View {
        let left = perRow / 2
        let rows = (seats + perRow - 1) / perRow
        let shape = BusBodyShape()
        VStack(spacing: 0) {
            // The front: the wheel on the captain's side, the windscreen, the door on the other.
            HStack(spacing: 12) {
                Image(systemName: "steeringwheel").font(.system(size: 28)).foregroundStyle(wa.accent.opacity(0.7))
                Capsule().fill(wa.accent.opacity(0.12)).frame(height: 16)
                Text(L("bus_door")).font(.dorr(10)).foregroundStyle(wa.settingsMut)
                    .padding(.horizontal, 8).padding(.vertical, 3)
                    .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 6))
            }
            .padding(.bottom, 14)
            ForEach(0..<max(rows, 0), id: \.self) { r in
                HStack(spacing: 0) {
                    ForEach(0..<perRow, id: \.self) { c in
                        if c == left {
                            // The aisle, with the row number.
                            Text(verbatim: "\(r + 1)").font(.dorr(10)).foregroundStyle(wa.settingsMut.opacity(0.6)).frame(width: 26)
                        }
                        let n = r * perRow + c + 1
                        ZStack {
                            if n <= seats {
                                let look: SeatLook = taken.contains(n) ? .taken : chosen.contains(n) ? .chosen : .free
                                BusSeatCell(look: look, number: n, row: r) { onSeat(n) }
                            }
                        }
                        .frame(maxWidth: .infinity)
                        .aspectRatio(0.9, contentMode: .fit)
                        .padding(3)
                    }
                }
                .padding(.vertical, 4)
            }
        }
        .padding(.horizontal, 14).padding(.vertical, 16)
        .background(wa.settingsCard, in: shape)
        .overlay(shape.stroke(wa.accent.opacity(0.18), lineWidth: 2))
    }
}

/// The bus body: a rounded front (48) and a squarer back (20).
private struct BusBodyShape: Shape {
    func path(in rect: CGRect) -> Path {
        let top: CGFloat = min(48, rect.width / 2, rect.height / 2)
        let bottom: CGFloat = min(20, rect.height / 2)
        var p = Path()
        p.move(to: CGPoint(x: rect.minX + top, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX - top, y: rect.minY))
        p.addArc(center: CGPoint(x: rect.maxX - top, y: rect.minY + top), radius: top, startAngle: .degrees(-90), endAngle: .degrees(0), clockwise: false)
        p.addLine(to: CGPoint(x: rect.maxX, y: rect.maxY - bottom))
        p.addArc(center: CGPoint(x: rect.maxX - bottom, y: rect.maxY - bottom), radius: bottom, startAngle: .degrees(0), endAngle: .degrees(90), clockwise: false)
        p.addLine(to: CGPoint(x: rect.minX + bottom, y: rect.maxY))
        p.addArc(center: CGPoint(x: rect.minX + bottom, y: rect.maxY - bottom), radius: bottom, startAngle: .degrees(90), endAngle: .degrees(180), clockwise: false)
        p.addLine(to: CGPoint(x: rect.minX, y: rect.minY + top))
        p.addArc(center: CGPoint(x: rect.minX + top, y: rect.minY + top), radius: top, startAngle: .degrees(180), endAngle: .degrees(270), clockwise: false)
        p.closeSubpath()
        return p
    }
}

/// O-69: the seats arrive row by row.
private struct BusSeatCell: View {
    let look: SeatLook
    let number: Int
    let row: Int
    let onTap: () -> Void
    @State private var appear = false

    var body: some View {
        BusSeat(look: look, number: number)
            .scaleEffect(appear ? 1 : 0.01).opacity(appear ? 1 : 0)
            .onTapGesture { if look != .taken { onTap() } }
            .onAppear { withAnimation(.spring(response: 0.45, dampingFraction: 0.6).delay(Double(row) * 0.045)) { appear = true } }
    }
}

/// One seat (O-69): a real seat icon on a soft tile — free in the app colour, chosen fills and pops with a tick,
/// taken greys out with a person on it.
private struct BusSeat: View {
    @Environment(\.wa) private var wa
    let look: SeatLook
    let number: Int?

    var body: some View {
        let tint: Color = look == .chosen ? .white : look == .taken ? wa.settingsMut.opacity(0.5) : wa.accent
        let fill: Color = look == .chosen ? wa.accent : look == .taken ? wa.settingsInk.opacity(0.07) : wa.accent.opacity(0.08)
        let edge: Color = look == .chosen ? wa.accent : look == .taken ? .clear : wa.accent.opacity(0.3)
        GeometryReader { geo in
            let side = min(geo.size.width, geo.size.height)
            ZStack {
                RoundedRectangle(cornerRadius: min(12, side * 0.3), style: .continuous).fill(fill)
                RoundedRectangle(cornerRadius: min(12, side * 0.3), style: .continuous).stroke(edge, lineWidth: 1.5)
                VStack(spacing: 0) {
                    Image(systemName: look == .taken ? "person.fill" : "chair.fill").font(.system(size: side * (number == nil ? 0.5 : 0.36))).foregroundStyle(tint)
                    if let number { Text(verbatim: "\(number)").font(.dorr(10, .black)).foregroundStyle(tint) }
                }
                if look == .chosen && number != nil {
                    Image(systemName: "checkmark").font(.system(size: 7, weight: .black)).foregroundStyle(wa.accent)
                        .frame(width: 14, height: 14).background(Color.white, in: Circle())
                        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topTrailing).padding(3)
                        .transition(.scale.combined(with: .opacity))
                }
            }
            .frame(width: geo.size.width, height: geo.size.height)
        }
        .scaleEffect(look == .chosen ? 1.1 : 1)
        .animation(.spring(response: 0.3, dampingFraction: 0.35), value: look)
    }
}

// MARK: - Tickets

private struct BusTicketsPage: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onOpen: (BusBookingDto) -> Void
    @State private var list: [BusBookingDto]?

    var body: some View {
        VStack(spacing: 0) {
            BusPageBar(title: L("bus_my_tickets"), onBack: onBack)
            if let rows = list {
                if rows.isEmpty {
                    EmptyBus(text: L("bus_no_tickets"))
                    Spacer()
                } else {
                    ScrollView {
                        VStack(spacing: 10) {
                            ForEach(Array(rows.enumerated()), id: \.element.uuid) { i, b in
                                if let trip = b.trip { row(b, trip).staggerIn(i) }
                            }
                        }
                        .padding(.horizontal, 16).padding(.bottom, 16)
                    }
                }
            } else {
                ProgressView().tint(wa.accent).padding(40)
                Spacer()
            }
        }
        .task { list = (try? await BusApi.bookings().data) ?? [] }
    }

    private func row(_ b: BusBookingDto, _ trip: BusTripDto) -> some View {
        let leg = b.tripType == "return" ? "return" : "outbound"
        return Button { onOpen(b) } label: {
            HStack(spacing: 10) {
                Image(systemName: "ticket.fill").foregroundStyle(wa.accent)
                    .frame(width: 44, height: 44).background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: (trip.fromPlace(leg).address ?? "") + " ← " + (trip.toPlace(leg).address ?? "")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Text(verbatim: busDay(trip.leavesAt(leg)) + " · " + busTime(trip.leavesAt(leg))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                Spacer(minLength: 0)
                BusTag(text: busStatusLabel(b.status), color: b.status == "confirmed" ? AppColors.success : wa.settingsMut)
            }
            .padding(14)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(WaPressStyle())
    }
}

@MainActor
private struct BusTicketPage: View {
    @Environment(\.wa) private var wa
    let initial: BusBookingDto
    let fresh: Bool
    let onBack: () -> Void
    @State private var booking: BusBookingDto
    @State private var busy = false
    @State private var error: String?
    @State private var chatOpen = false
    @State private var shown = false

    init(initial: BusBookingDto, fresh: Bool, onBack: @escaping () -> Void) {
        self.initial = initial
        self.fresh = fresh
        self.onBack = onBack
        _booking = State(initialValue: initial)
    }

    private func reload() async {
        guard var b = try? await BusApi.booking(booking.uuid).data else { return }
        if b.trip == nil { b.trip = booking.trip }
        booking = b
    }

    var body: some View {
        let trip = booking.trip
        // O-24, O-68: reach the captain inside the app while the ticket is live.
        let talkOpen = booking.status == "confirmed" && ["scheduled", "departed", "returning"].contains(trip?.status ?? "")
        VStack(spacing: 0) {
            BusPageBar(title: L("bus_ticket"), onBack: onBack)
            ScrollView {
                VStack(spacing: 12) {
                    // O-67: held for its payment — the one payment screen (coupon, top-up), then the ticket.
                    if booking.status == "unpaid" {
                        ServicePayCard(purpose: ServicePay.bus, uuid: booking.uuid, expiresAt: booking.paymentExpiresAt, autoOpen: fresh) {
                            Task { await reload() }
                        }
                    }
                    if fresh && booking.status != "unpaid" {
                        VStack(spacing: 4) {
                            Image(systemName: "checkmark.circle.fill").font(.system(size: 64)).foregroundStyle(AppColors.success)
                            Text(L("bus_booked_done")).font(.dorr(20, .black)).foregroundStyle(wa.settingsInk)
                        }
                        .scaleEffect(shown ? 1 : 0.3).opacity(shown ? 1 : 0)
                        .onAppear { withAnimation(.spring(response: 0.5, dampingFraction: 0.45)) { shown = true } }
                    }
                    if let t = trip { tripCard(t, talkOpen: talkOpen) }
                    // One QR per seat per leg — what the captain scans at the door.
                    ForEach(Array((booking.tickets ?? []).enumerated()), id: \.offset) { _, tk in
                        HStack(spacing: 14) {
                            if let qr = tk.qr {
                                ServiceQr(text: qr).padding(6).frame(width: 110, height: 110).background(Color.white, in: RoundedRectangle(cornerRadius: 12))
                            }
                            VStack(alignment: .leading, spacing: 2) {
                                Text(L(tk.leg == "return" ? "bus_type_return" : "bus_type_outbound")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                                Text(verbatim: L("bus_seat_n", tk.seat)).font(.dorr(22, .black)).foregroundStyle(wa.settingsInk)
                                if tk.boarded == true { BusTag(text: L("bus_boarded"), color: AppColors.success) }
                            }
                            Spacer(minLength: 0)
                        }
                        .padding(14)
                        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                    }
                    if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(AppColors.danger) }
                    if booking.status == "confirmed" || booking.status == "unpaid" {
                        Button { cancel() } label: {
                            Text(L("bus_cancel_ticket")).font(.dorr(15, .bold)).foregroundStyle(AppColors.danger).multilineTextAlignment(.center)
                                .frame(maxWidth: .infinity).padding(12).contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .disabled(busy)
                    }
                }
                .padding(16)
            }
        }
        .task(id: booking.uuid) { await reload() }
        // O-68: a message, a ring or a hang-up from the captain arrives as an event — one reload; nothing on a timer.
        .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { e in
            // Every move of my ticket: confirmed, on board, left, back, over, a message, a ring.
            if e.name.hasPrefix("bus.") && e.name != "bus.seats" { Task { await reload() } }
            if e.name == "bus.call_changed" {
                let status = e.str("status")
                if status != "ringing" && status != "active" { RideCallSession.shared.endedElsewhere(e.str("call_uuid")) }
            }
        }
        .onChange(of: "\(booking.call?.uuid ?? "")|\(booking.call?.status ?? "")") { _ in ringIfCalled() }
        .onAppear { ringIfCalled() }
        .fullScreenCover(isPresented: $chatOpen, onDismiss: { Task { await reload() } }) {
            let uuid = booking.uuid
            RideChatScreen(
                title: trip?.captain?.name ?? "", subtitle: L("bus_your_captain"),
                load: { try await BusApi.messages(uuid).data },
                post: { try await BusApi.send(uuid, $0).data },
                onCall: talkOpen ? { callCaptain() } : nil,
                onClose: { chatOpen = false }
            )
            .dorrThemed()
        }
    }

    private func tripCard(_ t: BusTripDto, talkOpen: Bool) -> some View {
        let leg = booking.tripType == "return" ? "return" : "outbound"
        return VStack(alignment: .leading, spacing: 0) {
            BusRouteLine(from: t.fromPlace(leg).address, to: t.toPlace(leg).address)
            Text(verbatim: busDay(t.leavesAt(leg)) + " · " + busTime(t.leavesAt(leg))).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 8)
            if booking.tripType == "round" {
                Text(verbatim: L("bus_has_return", busDay(t.returnAt) + " " + busTime(t.returnAt))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            Text(verbatim: [t.bus?.make, t.bus?.model, t.bus?.plate].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut).padding(.top, 4)
            Text(verbatim: rideMoney(booking.amountMinor, t.currency)).font(.dorr(18, .black)).foregroundStyle(wa.accent).padding(.top, 6)
            // O-24, O-68: reach the captain inside the app — chat and a call, no numbers.
            if talkOpen {
                HStack(spacing: 10) {
                    Image(systemName: "bus.fill").font(.system(size: 18)).foregroundStyle(wa.accent).frame(width: 40, height: 40).background(wa.accent.opacity(0.14), in: Circle())
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: t.captain?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                        Text(L("bus_your_captain")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                    }
                    Spacer(minLength: 0)
                    BusTalkButton(icon: "bubble.left.and.bubble.right.fill", tint: wa.accent, badge: booking.unread ?? 0) { chatOpen = true }
                    BusTalkButton(icon: "phone.fill", tint: AppColors.success, badge: 0) { callCaptain() }
                }
                .padding(.top, 12)
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
    }

    /// The captain is ringing my ticket.
    @MainActor private func ringIfCalled() {
        guard let call = booking.call, call.status == "ringing", call.caller == "driver" else { return }
        RideCallSession.shared.incoming(
            ride: booking.uuid, call: call.uuid, peer: booking.trip?.captain?.name ?? "",
            end: { b, c in _ = try await BusApi.endCall(b, c) },
            accept: { b, c in
                guard let dto = try await BusApi.acceptCall(b, c).data else { throw NoCallJoin() }
                return RideCallJoin(dto)
            }
        )
    }

    @MainActor private func callCaptain() {
        Task { @MainActor in
            guard await CallPermissions.ask(video: false) else { return }
            RideCallSession.shared.call(
                ride: booking.uuid, peer: booking.trip?.captain?.name ?? "",
                start: { [uuid = booking.uuid] in
                    guard let dto = try await BusApi.startCall(uuid).data else { throw NoCallJoin() }
                    return RideCallJoin(dto)
                },
                end: { b, c in _ = try await BusApi.endCall(b, c) }
            )
        }
    }

    private func cancel() {
        busy = true
        Task {
            do {
                if var b = try await BusApi.cancel(booking.uuid).data {
                    if b.trip == nil { b.trip = booking.trip }
                    booking = b
                }
            } catch {
                self.error = error.apiFailure.message ?? L("rd_error")
            }
            busy = false
        }
    }
}

private struct BusTalkButton: View {
    let icon: String
    let tint: Color
    let badge: Int
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 17)).foregroundStyle(tint)
                .frame(width: 42, height: 42).background(tint.opacity(0.14), in: Circle())
                .overlay(alignment: .topTrailing) {
                    if badge > 0 {
                        Text(verbatim: "\(badge)").font(.dorr(10, .bold)).foregroundStyle(.white)
                            .frame(width: 18, height: 18).background(AppColors.danger, in: Circle())
                    }
                }
        }
        .buttonStyle(WaPressStyle())
    }
}

// MARK: - Small pieces

private struct BusPageBar: View {
    @Environment(\.wa) private var wa
    let title: String
    let onBack: () -> Void

    var body: some View {
        HStack(spacing: 12) {
            RideRoundButton(icon: "arrow.backward", label: L("common_back"), action: onBack)
            Text(verbatim: title).font(.dorr(20, .black)).foregroundStyle(wa.settingsInk)
            Spacer()
        }
        .padding(.horizontal, 16).padding(.vertical, 10)
    }
}

private struct BusTag: View {
    let text: String
    let color: Color

    var body: some View {
        Text(verbatim: text).font(.dorr(12, .bold)).foregroundStyle(color)
            .padding(.horizontal, 10).padding(.vertical, 5)
            .background(color.opacity(0.12), in: Capsule())
    }
}

private struct EmptyBus: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        VStack(spacing: 8) {
            Image(systemName: "bus.fill").font(.system(size: 48)).foregroundStyle(wa.settingsMut.opacity(0.5))
            Text(verbatim: text).font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
        .padding(36)
    }
}

private func busStatusLabel(_ status: String) -> String {
    switch status {
    case "canceled", "lapsed": return L("bus_status_canceled")
    case "completed": return L("bus_status_completed")
    case "unpaid": return L("svc_pay_waiting")
    default: return L("bus_status_confirmed")
    }
}
