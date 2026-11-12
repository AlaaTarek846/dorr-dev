import CoreLocation
import SwiftUI

// androidApp `rides/RidesHire.kt` — find a driver (docs/rides_tasks.md O-26c): I have the car, I need someone to drive
// it — where, when (now or a time), for how many hours or days, automatic or manual, which car. The price shows as I
// choose (one request per change), it's paid on the payment screen, and the nearby captains hear about it at once.
// The request page reads on open (Android also reloads on each `hire.*` notification — Chat section) and counts the
// time down on the phone's clock.

private let deep = Color(hex: 0x00695C)
private let mint = Color(hex: 0x4DB6AC)

/// "Thu 16 Oct · 9:30 PM" in the app's language.
func serviceDayTime(_ iso: String?) -> String {
    guard let d = parseRideTime(iso) else { return "—" }
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.dateFormat = "EEE d MMM · h:mm a"
    return f.string(from: d)
}

// MARK: - The pieces the services share (Android keeps a copy in each file)

struct SvcCard<Content: View>: View {
    @Environment(\.wa) private var wa
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0) { content() }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
    }
}

struct SvcSegmented<T: Hashable>: View {
    @Environment(\.wa) private var wa
    let options: [(T, String)]
    let value: T
    let tint: Color
    let onPick: (T) -> Void

    var body: some View {
        HStack(spacing: 0) {
            ForEach(options, id: \.0) { v, label in
                let on = v == value
                Button { onPick(v) } label: {
                    Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(on ? .white : wa.settingsInk)
                        .frame(maxWidth: .infinity).padding(.vertical, 10)
                        .background(on ? tint : .clear, in: Capsule())
                }
                .buttonStyle(.plain)
            }
        }
        .padding(3)
        .background(wa.settingsBackground, in: Capsule())
        .animation(.easeInOut(duration: 0.2), value: value)
    }
}

struct SvcChip: View {
    @Environment(\.wa) private var wa
    let text: String
    let on: Bool
    var enabled = true
    let tint: Color
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(13, on ? .bold : .medium))
                .foregroundStyle(on ? .white : (enabled ? wa.settingsInk : wa.settingsMut.opacity(0.5)))
                .padding(.horizontal, 14).padding(.vertical, 8)
                .background(on ? tint : wa.settingsBackground, in: Capsule())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

struct SvcStep: View {
    @Environment(\.wa) private var wa
    let icon: String
    let enabled: Bool
    let tint: Color
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 15, weight: .bold)).foregroundStyle(enabled ? tint : wa.settingsMut)
                .frame(width: 38, height: 38).background(enabled ? tint.opacity(0.12) : wa.settingsBackground, in: Circle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}

struct SvcDot: View {
    let icon: String
    let tint: Color

    var body: some View {
        Image(systemName: icon).font(.system(size: 15)).foregroundStyle(tint).frame(width: 34, height: 34).background(tint.opacity(0.12), in: Circle())
    }
}

struct SvcRound: View {
    let icon: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 17, weight: .semibold)).foregroundStyle(.white).frame(width: 40, height: 40).background(Color.white.opacity(0.2), in: Circle())
        }
        .buttonStyle(WaPressStyle())
    }
}

struct SvcLine: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String

    var body: some View {
        HStack(alignment: .top, spacing: 8) {
            Text(verbatim: label).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading).layoutPriority(0.4)
            Text(verbatim: value).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading).layoutPriority(0.6)
        }
        .padding(.vertical, 5)
    }
}

/// A gradient header with back and a title (the services' own pages).
struct SvcBar: View {
    let title: String
    let colors: [Color]
    let onBack: () -> Void

    var body: some View {
        HStack(spacing: 12) {
            SvcRound(icon: "arrow.backward", action: onBack)
            Text(verbatim: title).font(.dorr(20, .black)).foregroundStyle(.white)
            Spacer()
        }
        .padding(.horizontal, 16).padding(.vertical, 12)
        .background(LinearGradient(colors: colors, startPoint: .leading, endPoint: .trailing).ignoresSafeArea(edges: .top))
    }
}

/// Picking a place for a service (the server's search, like the booking's).
struct SvcPlaceSearch: View {
    @Environment(\.wa) private var wa
    let near: CLLocationCoordinate2D?
    let tint: Color
    let onClose: () -> Void
    let onPick: (RidePoint) -> Void
    @State private var query = ""
    @State private var results: [RidePoint] = []
    @FocusState private var focused: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                RideRoundButton(icon: "arrow.backward", label: L("common_back"), action: onClose)
                HStack(spacing: 8) {
                    Image(systemName: "magnifyingglass").foregroundStyle(tint)
                    TextField(L("rd_search_hint"), text: $query).font(.dorr(15)).foregroundStyle(wa.settingsInk).focused($focused).tint(tint)
                }
                .padding(.horizontal, 16).frame(minHeight: 50)
                .background(wa.settingsCard, in: Capsule())
            }
            ScrollView {
                VStack(spacing: 0) {
                    ForEach(Array(results.enumerated()), id: \.offset) { i, p in
                        Button { onPick(p) } label: {
                            HStack(spacing: 10) {
                                Image(systemName: "mappin.circle.fill").foregroundStyle(tint)
                                Text(verbatim: p.address).font(.dorr(14)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.leading)
                                Spacer(minLength: 0)
                            }
                            .padding(12)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .staggerIn(i)
                    }
                }
            }
        }
        .padding(16)
        .background(wa.settingsBackground.ignoresSafeArea())
        .onAppear { focused = true }
        .task(id: query) {
            try? await Task.sleep(nanoseconds: 300_000_000)
            guard !Task.isCancelled else { return }
            results = await searchPlaces(query, near: near)
        }
    }
}

// MARK: - Find a driver

private enum HirePage: Equatable {
    case form, list
    case one(HireDto)
}

struct HireHost: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let onClose: () -> Void
    @State private var page = HirePage.form

    var body: some View {
        ZStack {
            wa.settingsBackground.ignoresSafeArea()
            switch page {
            case .form: HireForm(rides: state, onBack: onClose, onHistory: { page = .list }, onSent: { page = .one($0) }).transition(.opacity)
            case .list: HireList(onBack: { page = .form }, onOpen: { page = .one($0) }).transition(.move(edge: .trailing))
            case .one(let h): HireStatus(initial: h, onBack: { page = .form }).transition(.move(edge: .trailing))
            }
        }
        .animation(.easeInOut(duration: 0.3), value: page)
        .task {
            // Opened from a notification: that request (or my requests).
            if ServiceLink.shared.pending?.kind == "hire" {
                let uuid = ServiceLink.shared.take("hire")
                if let uuid, let h = try? await HireApi.show(uuid).data { page = .one(h) } else { page = .list }
                return
            }
            // A request already going opens straight on it.
            if let going = ((try? await HireApi.mine().data) ?? []).first(where: { ["open", "accepted", "arrived", "started"].contains($0.status) }) { page = .one(going) }
        }
    }
}

private struct HireForm: View {
    @Environment(\.wa) private var wa
    @ObservedObject var rides: RidesState
    let onBack: () -> Void
    let onHistory: () -> Void
    let onSent: (HireDto) -> Void
    @State private var pickup: RidePoint?
    @State private var searching = false
    @State private var now = true
    @State private var day = Calendar.current.startOfDay(for: Date())
    @State private var hour: Int?
    @State private var unit = "hour"
    @State private var quantity = 2
    @State private var transmission = "automatic"
    @State private var car = ""
    @State private var note = ""
    @State private var quote: HireQuoteDto?
    @State private var error: String?
    @State private var busy = false
    @State private var key = RidesApi.key()

    private var range: ClosedRange<Int> {
        let l = quote?.limits
        return unit == "day" ? 1...max(l?.maxDays ?? 7, 1) : (l?.minHours ?? 1)...max(l?.maxHours ?? 12, l?.minHours ?? 1)
    }

    private var start: Date? {
        guard !now, let hour else { return nil }
        return Calendar.current.date(bySettingHour: hour, minute: 0, second: 0, of: day)
    }

    var body: some View {
        let ready = pickup != nil && quote != nil && !busy && (now || start != nil)
        VStack(spacing: 0) {
            ScrollView {
                VStack(spacing: 0) {
                    HireHero(onBack: onBack, onHistory: onHistory)
                    VStack(alignment: .leading, spacing: 12) {
                        SvcCard {
                            Button { searching = true } label: {
                                HStack(spacing: 10) {
                                    SvcDot(icon: "mappin.and.ellipse", tint: deep)
                                    VStack(alignment: .leading, spacing: 0) {
                                        Text(L("hire_where")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                                        Text(verbatim: pickup?.address ?? L("hire_pick_place")).font(.dorr(15, .semibold)).foregroundStyle(pickup == nil ? wa.settingsMut : wa.settingsInk).lineLimit(2)
                                    }
                                    Spacer(minLength: 0)
                                    Image(systemName: "magnifyingglass").foregroundStyle(deep)
                                }
                                .padding(.vertical, 6)
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                        }
                        SvcCard {
                            HStack(spacing: 10) { SvcDot(icon: "clock", tint: deep); Text(L("hire_when")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk) }
                            SvcSegmented(options: [(true, L("hire_now")), (false, L("hire_later"))], value: now, tint: deep) { now = $0 }.padding(.top, 10)
                            if !now { laterPicker }
                        }
                        SvcCard {
                            SvcSegmented(options: [("hour", L("hire_by_hours")), ("day", L("hire_by_days"))], value: unit, tint: deep) { unit = $0 }
                            HStack {
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(L(unit == "day" ? "hire_days" : "hire_hours")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                                    if let l = quote?.limits {
                                        let each = unit == "day" ? (l.dayPriceMinor ?? 0) : (l.hourPriceMinor ?? 0)
                                        if each > 0 { Text(verbatim: L(unit == "day" ? "hire_per_day" : "hire_per_hour", rideMoney(each, quote?.currency))).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                                    }
                                }
                                Spacer()
                                SvcStep(icon: "minus", enabled: quantity > range.lowerBound, tint: deep) { quantity -= 1 }
                                Text(verbatim: "\(quantity)").font(.dorr(20, .black)).foregroundStyle(wa.settingsInk).frame(width: 48)
                                SvcStep(icon: "plus", enabled: quantity < range.upperBound, tint: deep) { quantity += 1 }
                            }
                            .padding(.top, 10)
                        }
                        SvcCard {
                            HStack(spacing: 10) { SvcDot(icon: "car.fill", tint: deep); Text(L("hire_your_car")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk) }
                            SvcSegmented(options: [("automatic", L("hire_automatic")), ("manual", L("hire_manual"))], value: transmission, tint: deep) { transmission = $0 }.padding(.top, 10)
                            DorrTextArea(value: Binding(get: { car }, set: { car = String($0.prefix(120)) }), placeholder: L("hire_car_hint"), icon: "car.fill", singleLine: true).padding(.top, 10)
                            DorrTextArea(value: Binding(get: { note }, set: { note = String($0.prefix(255)) }), placeholder: L("hire_note_hint"), icon: "text.alignleft", singleLine: true).padding(.top, 8)
                        }
                        if let l = quote?.limits, (l.overtimePerMinMinor ?? 0) > 0 {
                            Text(verbatim: L("hire_overtime", rideMoney(l.overtimePerMinMinor, quote?.currency))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        }
                        if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger) }
                    }
                    .padding(16)
                }
            }
            HStack {
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("hire_total")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: quote.map { rideMoney($0.amountMinor, $0.currency) } ?? "—").font(.dorr(20, .black)).foregroundStyle(wa.settingsInk)
                }
                Spacer()
                Button { send() } label: {
                    ZStack {
                        if busy { ProgressView().tint(.white) } else { Text(L("hire_request")).font(.dorr(15, .bold)).foregroundStyle(.white) }
                    }
                    .padding(.horizontal, 24).padding(.vertical, 14)
                    .background(LinearGradient(colors: ready ? [deep, mint] : [wa.settingsMut, wa.settingsMut], startPoint: .leading, endPoint: .trailing), in: Capsule())
                }
                .buttonStyle(WaPressStyle())
                .disabled(!ready)
            }
            .padding(16)
            .background(wa.settingsCard.ignoresSafeArea(edges: .bottom).shadow(color: .black.opacity(0.12), radius: 18, y: -2))
        }
        .onAppear { pickup = pickup ?? rides.pickup }
        // The price for what is chosen — one request per change (after a short pause), never on a timer.
        .task(id: "\(pickup?.lat ?? 0)|\(unit)|\(quantity)") {
            guard let p = pickup else { return }
            try? await Task.sleep(nanoseconds: 250_000_000)
            guard !Task.isCancelled else { return }
            do {
                quote = try await HireApi.quote(lat: p.lat, lng: p.lng, unit: unit, quantity: quantity).data
                error = nil
                quantity = min(max(quantity, range.lowerBound), range.upperBound)
            } catch {
                self.error = error.apiFailure.message
            }
        }
        .fullScreenCover(isPresented: $searching) {
            SvcPlaceSearch(near: rides.me ?? rides.pickup?.latLng, tint: deep, onClose: { searching = false }) {
                pickup = $0
                searching = false
            }
            .dorrThemed()
        }
    }

    private var laterPicker: some View {
        let cal = Calendar.current
        let dayF = DateFormatter()
        dayF.locale = Locale(identifier: AppLocale.current)
        dayF.dateFormat = "EEE d"
        let hourF = DateFormatter()
        hourF.locale = Locale(identifier: AppLocale.current)
        hourF.dateFormat = "h a"
        return VStack(spacing: 8) {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(0..<14, id: \.self) { n in
                        let d = cal.date(byAdding: .day, value: n, to: cal.startOfDay(for: Date()))!
                        SvcChip(text: dayF.string(from: d), on: d == day, tint: deep) { day = d; hour = nil }
                    }
                }
            }
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(0..<24, id: \.self) { h in
                        let at = cal.date(bySettingHour: h, minute: 0, second: 0, of: day) ?? day
                        SvcChip(text: hourF.string(from: at), on: hour == h, enabled: at > Date(), tint: deep) { hour = h }
                    }
                }
            }
        }
        .padding(.top, 10)
    }

    private func send() {
        guard let p = pickup else { return }
        busy = true
        error = nil
        Task {
            var body: [String: JSONValue] = ["pickup": .object(p.json), "unit": .string(unit), "quantity": .int(quantity), "transmission": .string(transmission)]
            if let start {
                let f = ISO8601DateFormatter()
                f.timeZone = .current
                body["starts_at"] = .string(f.string(from: start))
            }
            let c = car.trimmingCharacters(in: .whitespaces), n = note.trimmingCharacters(in: .whitespaces)
            if !c.isEmpty { body["car"] = .string(c) }
            if !n.isEmpty { body["note"] = .string(n) }
            do {
                if let h = try await HireApi.request(body, key: key).data { onSent(h) }
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
        }
    }
}

/// The hero: a steering wheel turning slowly in a mint glow.
private struct HireHero: View {
    let onBack: () -> Void
    let onHistory: () -> Void
    @State private var turn = false

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                SvcRound(icon: "arrow.backward", action: onBack)
                Spacer()
                SvcRound(icon: "clock.arrow.circlepath", action: onHistory)
            }
            HStack {
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("hire_title")).font(.dorr(26, .black)).foregroundStyle(.white)
                    Text(L("hire_subtitle")).font(.dorr(13)).foregroundStyle(.white.opacity(0.88))
                }
                Spacer(minLength: 0)
                ZStack {
                    Circle().fill(Color.white.opacity(turn ? 0.45 : 0.2)).frame(width: 78, height: 78)
                    Canvas { gc, size in
                        let r = min(size.width, size.height) / 2
                        let c = CGPoint(x: size.width / 2, y: size.height / 2)
                        gc.stroke(Path(ellipseIn: CGRect(x: c.x - r * 0.9, y: c.y - r * 0.9, width: r * 1.8, height: r * 1.8)), with: .color(.white), lineWidth: r * 0.18)
                        gc.fill(Path(ellipseIn: CGRect(x: c.x - r * 0.22, y: c.y - r * 0.22, width: r * 0.44, height: r * 0.44)), with: .color(.white))
                        var spokes = Path()
                        spokes.move(to: c); spokes.addLine(to: CGPoint(x: c.x - r * 0.82, y: c.y))
                        spokes.move(to: c); spokes.addLine(to: CGPoint(x: c.x + r * 0.82, y: c.y))
                        spokes.move(to: c); spokes.addLine(to: CGPoint(x: c.x, y: c.y + r * 0.82))
                        gc.stroke(spokes, with: .color(.white), lineWidth: r * 0.16)
                    }
                    .frame(width: 54, height: 54)
                    .rotationEffect(.degrees(turn ? 18 : -18))
                }
                .onAppear { withAnimation(.easeInOut(duration: 1.8).repeatForever()) { turn = true } }
            }
        }
        .padding(.horizontal, 18).padding(.top, 10).padding(.bottom, 26)
        .background(LinearGradient(colors: [deep, mint], startPoint: .topLeading, endPoint: .bottomTrailing).ignoresSafeArea(edges: .top))
        .clipShape(BottomRoundedRectangle(radius: 34))
    }
}

private func hireStatusText(_ status: String) -> String {
    switch status {
    case "open": return L("hire_st_open")
    case "accepted": return L("hire_st_accepted")
    case "arrived": return L("hire_st_arrived")
    case "started": return L("hire_st_started")
    case "completed": return L("hire_st_completed")
    case "expired", "lapsed": return L("hire_st_expired")
    case "unpaid": return L("svc_pay_waiting")
    default: return L("hire_st_canceled")
    }
}

private func hireClock(_ s: Int) -> String { String(format: "%d:%02d:%02d", s / 3600, s % 3600 / 60, s % 60) }

private struct HireStatus: View {
    @Environment(\.wa) private var wa
    @Environment(\.openURL) private var openURL
    @EnvironmentObject private var network: NetworkMonitor
    let initial: HireDto
    let onBack: () -> Void
    @State private var hire: HireDto?
    @State private var busy = false
    @State private var error: String?

    private func reload() async { if let h = try? await HireApi.show(initial.uuid).data { hire = h } }

    var body: some View {
        let h = hire ?? initial
        VStack(spacing: 0) {
            SvcBar(title: L("hire_title"), colors: [deep, mint], onBack: onBack)
            ScrollView {
                VStack(spacing: 12) {
                    // O-67: paid first on the one payment screen — then the captains around hear of it.
                    if h.status == "unpaid" {
                        ServicePayCard(purpose: ServicePay.hire, uuid: h.uuid, expiresAt: h.paymentExpiresAt, autoOpen: true) { Task { await reload() } }
                    }
                    switch h.status {
                    case "open": SvcRadar(icon: "person.crop.circle.badge.magnifyingglass", colors: [deep, mint])
                    case "started":
                        // The time left, from the phone's clock.
                        TimelineView(.periodic(from: .now, by: 1)) { context in
                            let left = parseRideTime(h.endsAt).map { Int($0.timeIntervalSince(context.date)) }
                            VStack(spacing: 2) {
                                Text(verbatim: left.map { $0 < 0 ? "+" + hireClock(-$0) : hireClock($0) } ?? "—").font(.dorr(44, .black))
                                    .foregroundStyle((left ?? 0) < 0 ? AppColors.warning : deep)
                                    .environment(\.layoutDirection, .leftToRight)
                                Text(L((left ?? 0) < 0 ? "hire_overtime_running" : "hire_time_left")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                            }
                        }
                    default:
                        Image(systemName: "person.crop.circle.badge.magnifyingglass").font(.system(size: 60)).foregroundStyle(deep)
                    }
                    Text(verbatim: hireStatusText(h.status)).font(.dorr(20, .black)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                    if let c = h.captain {
                        SvcCard {
                            HStack(spacing: 10) {
                                Text(verbatim: String((c.name ?? "?").prefix(1))).font(.dorr(18, .black)).foregroundStyle(.white)
                                    .frame(width: 46, height: 46).background(LinearGradient(colors: [deep, mint], startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(verbatim: c.name ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                                    Text(L("hire_your_driver")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                                }
                                Spacer(minLength: 0)
                                if let phone = c.phone, let url = URL(string: "tel:\(phone)") {
                                    Button { openURL(url) } label: { Text(verbatim: "📞").font(.system(size: 22)).padding(8) }.buttonStyle(.plain)
                                }
                            }
                        }
                    }
                    SvcCard {
                        SvcLine(label: L("hire_where"), value: h.pickup.address ?? "")
                        SvcLine(label: L("hire_when"), value: serviceDayTime(h.startsAt))
                        SvcLine(label: L("hire_length"), value: "\(h.quantity ?? 1) " + L(h.unit == "day" ? "hire_days" : "hire_hours"))
                        SvcLine(label: L("hire_your_car"), value: L(h.transmission == "manual" ? "hire_manual" : "hire_automatic") + (h.car.map { " · \($0)" } ?? ""))
                        SvcLine(label: L("hire_total"), value: rideMoney((h.amountMinor ?? 0) + (h.extraMinor ?? 0), h.currency))
                    }
                    if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger) }
                    if ["open", "accepted", "unpaid"].contains(h.status) {
                        Button {
                            busy = true
                            error = nil
                            Task {
                                do { if let fresh = try await HireApi.cancel(h.uuid).data { hire = fresh } } catch { self.error = error.apiFailure.message }
                                busy = false
                            }
                        } label: {
                            Text(L("hire_cancel")).font(.dorr(15, .bold)).foregroundStyle(AppColors.danger).frame(maxWidth: .infinity).padding(12)
                        }
                        .buttonStyle(.plain)
                        .disabled(busy)
                    }
                }
                .padding(16)
            }
        }
        // One catch-up on open and when the connection comes back.
        .task(id: network.reconnectTick) { await reload() }
        // Each move arrives as an event: one reload (nothing on a timer).
        .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { e in if e.name.hasPrefix("hire.") { Task { await reload() } } }
    }
}

/// «Looking for …»: rings spreading out from the middle.
struct SvcRadar: View {
    let icon: String
    let colors: [Color]

    var body: some View {
        TimelineView(.animation) { context in
            let p = context.date.timeIntervalSinceReferenceDate / 2.2
            ZStack {
                ForEach(0..<3, id: \.self) { i in
                    let f = (p + Double(i) / 3).truncatingRemainder(dividingBy: 1)
                    Circle().stroke(colors.last!.opacity((1 - f) * 0.5), lineWidth: 3).frame(width: 170 * f, height: 170 * f)
                }
                Image(systemName: icon).font(.system(size: 28)).foregroundStyle(.white)
                    .frame(width: 62, height: 62).background(LinearGradient(colors: colors, startPoint: .topLeading, endPoint: .bottomTrailing), in: Circle())
            }
            .frame(width: 170, height: 170)
        }
    }
}

private struct HireList: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onOpen: (HireDto) -> Void
    @State private var list: [HireDto]?

    var body: some View {
        VStack(spacing: 0) {
            SvcBar(title: L("hire_history"), colors: [deep, mint], onBack: onBack)
            if let rows = list {
                if rows.isEmpty {
                    Text(L("hire_none")).font(.dorr(15)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).padding(36)
                    Spacer()
                } else {
                    ScrollView {
                        VStack(spacing: 10) {
                            ForEach(Array(rows.enumerated()), id: \.offset) { i, h in
                                Button { onOpen(h) } label: {
                                    HStack(spacing: 10) {
                                        SvcDot(icon: "person.crop.circle.badge.magnifyingglass", tint: deep)
                                        VStack(alignment: .leading, spacing: 0) {
                                            Text(verbatim: h.pickup.address ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                            Text(verbatim: serviceDayTime(h.startsAt) + " · \(h.quantity ?? 1) " + L(h.unit == "day" ? "hire_days" : "hire_hours"))
                                                .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                                        }
                                        Spacer(minLength: 0)
                                        Text(verbatim: hireStatusText(h.status)).font(.dorr(11, .bold)).foregroundStyle(deep)
                                    }
                                    .padding(14)
                                    .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                                }
                                .buttonStyle(WaPressStyle())
                                .staggerIn(i)
                            }
                        }
                        .padding(16)
                    }
                }
            } else {
                ProgressView().tint(deep).padding(40)
                Spacer()
            }
        }
        .task { list = (try? await HireApi.mine().data) ?? [] }
    }
}
