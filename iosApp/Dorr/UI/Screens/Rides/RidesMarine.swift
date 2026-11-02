import SwiftUI
import UIKit

// androidApp `rides/RidesMarine.kt` — boats & yachts (docs/rides_tasks.md O-25; R-29, R-41, R-42): a service of its
// own inside rides, in sea colours. Two kinds — a sea trip at a set time (seats, or the whole boat) and a yacht by
// the hour (a day, a start hour, how many hours; the boat's taken hours are greyed). Paid on the payment screen, a
// boarding QR, the captain one tap away, and on a hire «more time» that the captain accepts or not.

private let deepSea = Color(hex: 0x01579B)
private let sea = Color(hex: 0x0288D1)
private let foam = Color(hex: 0x26C6DA)

/// A QR drawn from its modules (the wallet's generator), white around it.
struct ServiceQr: View {
    let text: String

    var body: some View {
        if let m = QrMatrix(text: text) {
            Canvas { gc, size in
                let cell = size.width / CGFloat(m.size)
                for y in 0..<m.size {
                    for x in 0..<m.size where m.isSet(x, y) {
                        gc.fill(Path(CGRect(x: CGFloat(x) * cell, y: CGFloat(y) * cell, width: cell + 0.3, height: cell + 0.3)), with: .color(.black))
                    }
                }
            }
            .environment(\.layoutDirection, .leftToRight)
            .aspectRatio(1, contentMode: .fit)
        }
    }
}

private func marineFormat(_ iso: String?, _ pattern: String) -> String? {
    guard let d = parseRideTime(iso) else { return nil }
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.dateFormat = pattern
    return f.string(from: d)
}

private func marineTime(_ iso: String?) -> String { marineFormat(iso, "h:mm a") ?? "—" }
private func marineDay(_ iso: String?) -> String { marineFormat(iso, "EEEE d MMM") ?? "" }

private func marineFormatter(_ pattern: String) -> DateFormatter {
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.dateFormat = pattern
    return f
}

private func durationText(_ min: Int?) -> String {
    guard let min else { return "" }
    return min % 60 == 0 ? L("mar_n_hours", min / 60) : L("mar_n_minutes", min)
}

private func amenityIcon(_ key: String) -> String {
    switch key {
    case "life_jackets": return "cross.case.fill"
    case "snorkeling": return "water.waves"
    case "fishing": return "fish.fill"
    case "food": return "fork.knife"
    case "drinks": return "wineglass.fill"
    case "music": return "music.note"
    case "cabin": return "bed.double.fill"
    case "toilet": return "toilet.fill"
    case "crew": return "person.3.fill"
    case "swimming": return "figure.pool.swim"
    case "sunbeds": return "beach.umbrella.fill"
    case "photos": return "camera.fill"
    default: return "star.fill"
    }
}

private func amenityLabel(_ key: String) -> String {
    switch key {
    case "life_jackets": return L("mar_am_life_jackets")
    case "snorkeling": return L("mar_am_snorkeling")
    case "fishing": return L("mar_am_fishing")
    case "food": return L("mar_am_food")
    case "drinks": return L("mar_am_drinks")
    case "music": return L("mar_am_music")
    case "cabin": return L("mar_am_cabin")
    case "toilet": return L("mar_am_toilet")
    case "crew": return L("mar_am_crew")
    case "swimming": return L("mar_am_swimming")
    case "sunbeds": return L("mar_am_sunbeds")
    default: return L("mar_am_photos")
    }
}

private func priceFrom(_ l: MarineListingDto) -> Int? { l.kind == "rental" ? l.prices?.hourMinor : (l.prices?.seatMinor ?? l.prices?.privateMinor) }

private enum MarinePage: Equatable {
    case browse, bookings
    case details(MarineListingDto)
    case booking(MarineBookingDto, fresh: Bool)
}

struct MarineHost: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let onClose: () -> Void
    @State private var page = MarinePage.browse
    @State private var kind = "trip"

    var body: some View {
        ZStack {
            wa.settingsBackground.ignoresSafeArea()
            switch page {
            case .browse:
                MarineBrowse(rides: state, kind: $kind, onBack: onClose, onBookings: { page = .bookings }, onPick: { page = .details($0) }).transition(.opacity)
            case .details(let l):
                MarineDetails(initial: l, onBack: { page = .browse }, onBooked: { page = .booking($0, fresh: true) }).transition(.move(edge: .trailing))
            case .bookings:
                MarineBookings(onBack: { page = .browse }, onOpen: { page = .booking($0, fresh: false) }).transition(.move(edge: .trailing))
            case .booking(let b, let fresh):
                MarineBookingPage(initial: b, fresh: fresh, onBack: { page = .bookings }).transition(.move(edge: .trailing))
            }
        }
        .animation(.easeInOut(duration: 0.3), value: page)
        .task {
            // Opened from a notification: that booking (or my bookings).
            guard ServiceLink.shared.pending?.kind == "marine" else { return }
            let uuid = ServiceLink.shared.take("marine")
            if let uuid, let b = try? await MarineApi.booking(uuid).data { page = .booking(b, fresh: false) } else { page = .bookings }
        }
    }
}

private struct SeaCard<Content: View>: View {
    @ViewBuilder let content: () -> Content
    var body: some View { SvcCard(content: content) }
}

private struct SeaInfo: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let value: String

    var body: some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: icon).font(.system(size: 14)).foregroundStyle(sea).frame(width: 30, height: 30).background(sea.opacity(0.1), in: Circle())
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: label).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                Text(verbatim: value).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
            }
        }
        .padding(.vertical, 5)
    }
}

private struct SeaTag: View {
    let text: String
    let color: Color
    var action: (() -> Void)?

    var body: some View {
        let label = Text(verbatim: text).font(.dorr(12, .bold)).foregroundStyle(color).padding(.horizontal, 10).padding(.vertical, 5).background(color.opacity(0.12), in: Capsule())
        if let action { Button(action: action) { label }.buttonStyle(.plain) } else { label }
    }
}

private struct MarineStatusTag: View {
    @Environment(\.wa) private var wa
    let status: String

    var body: some View {
        switch status {
        case "boarded": SeaTag(text: L("mar_status_boarded"), color: sea)
        case "completed": SeaTag(text: L("mar_status_completed"), color: wa.settingsMut)
        case "canceled", "lapsed": SeaTag(text: L("mar_status_canceled"), color: AppColors.danger)
        case "unpaid": SeaTag(text: L("svc_pay_waiting"), color: AppColors.warning)
        default: SeaTag(text: L("mar_status_confirmed"), color: AppColors.success)
        }
    }
}

private struct EmptySea: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        VStack(spacing: 8) {
            Image(systemName: "ferry.fill").font(.system(size: 50)).foregroundStyle(sea.opacity(0.45))
            Text(verbatim: text).font(.dorr(15)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
        }
        .frame(maxWidth: .infinity)
        .padding(36)
    }
}

private struct MarineBrowse: View {
    @Environment(\.wa) private var wa
    @ObservedObject var rides: RidesState
    @Binding var kind: String
    let onBack: () -> Void
    let onBookings: () -> Void
    let onPick: (MarineListingDto) -> Void
    @State private var list: [MarineListingDto]?
    @State private var error: String?

    var body: some View {
        ScrollView {
            VStack(spacing: 12) {
                SeaHero(onBack: onBack, onBookings: onBookings)
                // Sea trips | yacht hire — a sliding pill.
                HStack(spacing: 0) {
                    ForEach([("trip", "mar_trips"), ("rental", "mar_rentals")], id: \.0) { k, label in
                        let on = kind == k
                        Button { kind = k } label: {
                            Text(L(label)).font(.dorr(15, .bold)).foregroundStyle(on ? .white : wa.settingsInk)
                                .frame(maxWidth: .infinity).padding(.vertical, 11).background(on ? sea : .clear, in: Capsule())
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(4)
                .background(wa.settingsCard, in: Capsule())
                .padding(.horizontal, 16)
                .animation(.easeInOut(duration: 0.22), value: kind)
                if let rows = list {
                    if let error, rows.isEmpty {
                        Text(verbatim: error).font(.dorr(14)).foregroundStyle(AppColors.danger).padding(.horizontal, 20)
                    } else if rows.isEmpty {
                        EmptySea(text: L(kind == "trip" ? "mar_no_trips" : "mar_no_rentals"))
                    } else {
                        ForEach(Array(rows.enumerated()), id: \.element.uuid) { i, l in ListingCard(l: l) { onPick(l) }.padding(.horizontal, 16).staggerIn(i) }
                    }
                } else {
                    ProgressView().tint(sea).padding(36)
                }
                Spacer(minLength: 24)
            }
        }
        .ignoresSafeArea(edges: .top)
        .task(id: kind) {
            list = nil
            error = nil
            let near = rides.me ?? rides.pickup?.latLng
            do { list = try await MarineApi.listings(kind: kind, lat: near?.latitude, lng: near?.longitude).data ?? [] } catch {
                self.error = error.apiFailure.message
                list = []
            }
        }
    }
}

/// The page's one big moment: deep sea, moving waves, a boat riding them.
private struct SeaHero: View {
    let onBack: () -> Void
    let onBookings: () -> Void
    @State private var bob = false

    static func wave(_ size: CGSize, level: CGFloat, phase: Double) -> Path {
        var path = Path()
        let base = size.height * level
        let amp = size.height * 0.035
        path.move(to: CGPoint(x: 0, y: size.height))
        var x: CGFloat = 0
        while x <= size.width {
            let angle = Double(x / size.width) * 2 * Double.pi * 1.5 + phase
            path.addLine(to: CGPoint(x: x, y: base + amp * CGFloat(sin(angle))))
            x += 3
        }
        path.addLine(to: CGPoint(x: size.width, y: size.height))
        path.closeSubpath()
        return path
    }

    var body: some View {
        ZStack(alignment: .topLeading) {
            TimelineView(.animation) { context in
                Canvas { gc, size in
                    let phase = (context.date.timeIntervalSinceReferenceDate / 4.2).truncatingRemainder(dividingBy: 1) * 2 * .pi
                    let waves: [(CGFloat, Double)] = [(0.62, 0.18), (0.74, 0.28), (0.86, 0.4)]
                    for (i, wave) in waves.enumerated() {
                        gc.fill(Self.wave(size, level: wave.0, phase: phase + Double(i) * 1.3), with: .color(.white.opacity(wave.1)))
                    }
                    // A little sun.
                    let r = size.height * 0.11
                    gc.fill(Path(ellipseIn: CGRect(x: size.width * 0.16 - r, y: size.height * 0.36 - r, width: 2 * r, height: 2 * r)), with: .color(Color(hex: 0xFFF59D).opacity(0.55)))
                }
            }
            .environment(\.layoutDirection, .leftToRight)
            VStack(alignment: .leading, spacing: 14) {
                HStack {
                    SvcRound(icon: "arrow.backward", action: onBack)
                    Spacer()
                    SvcRound(icon: "ticket.fill", action: onBookings)
                }
                HStack {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(L("mar_title")).font(.dorr(27, .black)).foregroundStyle(.white)
                        Text(L("mar_subtitle")).font(.dorr(13)).foregroundStyle(.white.opacity(0.88))
                    }
                    Spacer(minLength: 0)
                    Image(systemName: "ferry.fill").font(.system(size: 54)).foregroundStyle(.white)
                        .offset(y: bob ? 7 : -7).rotationEffect(.degrees(bob ? 4 : -4))
                        .onAppear { withAnimation(.easeInOut(duration: 1.6).repeatForever()) { bob = true } }
                }
            }
            .padding(.horizontal, 18).padding(.top, 56).padding(.bottom, 34)
        }
        .background(LinearGradient(colors: [deepSea, sea, foam], startPoint: .top, endPoint: .bottom))
        .clipShape(BottomRoundedRectangle(radius: 34))
    }
}

private struct ListingCard: View {
    @Environment(\.wa) private var wa
    let l: MarineListingDto
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 0) {
                // A sea band with the boat, the kind and the price.
                HStack(spacing: 10) {
                    Image(systemName: l.boat?.type == "yacht" ? "sailboat.fill" : "ferry.fill").foregroundStyle(.white)
                        .frame(width: 42, height: 42).background(Color.white.opacity(0.2), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: l.title).font(.dorr(16, .black)).foregroundStyle(.white).lineLimit(1)
                        let boat = [l.boat?.make, l.boat?.model].compactMap { $0 }.joined(separator: " ")
                        Text(verbatim: boat.isEmpty ? L(l.boat?.type == "yacht" ? "mar_yacht" : "mar_boat") : boat).font(.dorr(12)).foregroundStyle(.white.opacity(0.85)).lineLimit(1)
                    }
                    Spacer(minLength: 0)
                    VStack(alignment: .trailing, spacing: 0) {
                        Text(verbatim: rideMoney(priceFrom(l), l.currency)).font(.dorr(17, .black)).foregroundStyle(.white)
                        Text(L(l.kind == "rental" ? "mar_per_hour" : "mar_per_seat")).font(.dorr(11)).foregroundStyle(.white.opacity(0.85))
                    }
                }
                .padding(.horizontal, 16).padding(.vertical, 14)
                .background(LinearGradient(colors: [deepSea, sea, foam], startPoint: .leading, endPoint: .trailing))
                VStack(alignment: .leading, spacing: 6) {
                    HStack(spacing: 4) {
                        Image(systemName: "mappin").font(.system(size: 13)).foregroundStyle(sea)
                        Text(verbatim: l.marina.address ?? "").font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    }
                    HStack(spacing: 4) {
                        Image(systemName: "clock").font(.system(size: 12)).foregroundStyle(wa.settingsMut)
                        Text(verbatim: l.kind == "trip" ? "\(marineDay(l.departAt)) · \(marineTime(l.departAt)) · \(durationText(l.durationMin))" : L("mar_min_hours", l.prices?.minHours ?? 1))
                            .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        Spacer(minLength: 4)
                        let ok = (l.seatsLeft ?? 1) > 0
                        Image(systemName: "person.3.fill").font(.system(size: 11)).foregroundStyle(ok ? AppColors.success : AppColors.danger)
                        Text(verbatim: " " + (l.seatsLeft.map { L("mar_left", $0) } ?? L("mar_up_to", l.capacity ?? 0))).font(.dorr(12, .semibold)).foregroundStyle(ok ? AppColors.success : AppColors.danger)
                    }
                    if let amenities = l.amenities, !amenities.isEmpty {
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 6) {
                                ForEach(amenities, id: \.self) { a in
                                    Image(systemName: amenityIcon(a)).font(.system(size: 13)).foregroundStyle(sea).frame(width: 30, height: 30).background(sea.opacity(0.1), in: Circle())
                                }
                            }
                        }
                        .padding(.top, 4)
                    }
                }
                .padding(14)
            }
            .background(wa.settingsCard)
            .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
            .shadow(color: sea.opacity(0.2), radius: 10, y: 3)
        }
        .buttonStyle(WaPressStyle())
    }
}

private struct MarineStepper: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: Int
    let range: ClosedRange<Int>
    let onChange: (Int) -> Void

    var body: some View {
        HStack {
            Text(verbatim: label).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
            Spacer()
            SvcStep(icon: "minus", enabled: value > range.lowerBound, tint: sea) { onChange(value - 1) }
            Text(verbatim: "\(value)").font(.dorr(18, .black)).foregroundStyle(wa.settingsInk).frame(width: 44)
            SvcStep(icon: "plus", enabled: value < range.upperBound, tint: sea) { onChange(value + 1) }
        }
        .padding(.vertical, 4)
    }
}

private struct MarineDetails: View {
    @Environment(\.wa) private var wa
    let initial: MarineListingDto
    let onBack: () -> Void
    let onBooked: (MarineBookingDto) -> Void
    @State private var l: MarineListingDto?
    @State private var mode: String?
    @State private var guests = 1
    @State private var day = Calendar.current.startOfDay(for: Date())
    @State private var hour: Int?
    @State private var hours: Int?
    @State private var busy = false
    @State private var error: String?
    @State private var key = RidesApi.key()

    private func slot(_ s: MarineSlotDto) -> (Date, Date)? {
        guard let f = parseRideTime(s.from), let t = parseRideTime(s.to) else { return nil }
        return (f, t)
    }

    var body: some View {
        let l = self.l ?? initial
        let trip = l.kind == "trip"
        let prices = l.prices ?? MarinePricesDto()
        let mode = self.mode ?? (trip ? (prices.seatMinor != nil ? "shared" : "private") : "rental")
        let hours = self.hours ?? prices.minHours ?? 1
        let capacity = l.capacity ?? 1
        let maxGuests = trip && mode == "shared" ? (l.seatsLeft ?? capacity) : capacity
        let total: Int? = mode == "shared" ? prices.seatMinor.map { $0 * guests } : (mode == "private" ? prices.privateMinor : prices.hourMinor.map { $0 * hours })
        let start = hour.flatMap { Calendar.current.date(bySettingHour: $0, minute: 0, second: 0, of: day) }
        let clash = start.map { s in (l.busy ?? []).compactMap(slot).contains { $0.0 < s.addingTimeInterval(TimeInterval(hours * 3600)) && $0.1 > s } } ?? false
        let canBook = !busy && total != nil && (1...max(maxGuests, 1)).contains(guests) && (trip || (start != nil && !clash))
        VStack(spacing: 0) {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    VStack(alignment: .leading, spacing: 4) {
                        SvcRound(icon: "arrow.backward", action: onBack).padding(.bottom, 8)
                        Text(verbatim: l.title).font(.dorr(24, .black)).foregroundStyle(.white)
                        Text(verbatim: [L(l.boat?.type == "yacht" ? "mar_yacht" : "mar_boat"), l.boat?.make, l.boat?.model, l.captain?.name.map { "⚓ \($0)" }]
                            .compactMap { $0 }.joined(separator: " · "))
                            .font(.dorr(13)).foregroundStyle(.white.opacity(0.88))
                    }
                    .padding(18).padding(.top, 40)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(LinearGradient(colors: [deepSea, sea, foam], startPoint: .top, endPoint: .bottom))
                    .clipShape(BottomRoundedRectangle(radius: 30))
                    VStack(alignment: .leading, spacing: 12) {
                        SeaCard {
                            SeaInfo(icon: "mappin", label: L("mar_marina"), value: l.marina.address ?? "")
                            if let r = l.routeNote { SeaInfo(icon: "water.waves", label: L("mar_route"), value: r) }
                            if trip { SeaInfo(icon: "clock", label: L("mar_when"), value: "\(marineDay(l.departAt)) · \(marineTime(l.departAt)) · \(durationText(l.durationMin))") }
                            SeaInfo(icon: "person.3.fill", label: L("mar_capacity"), value: L("mar_up_to", capacity))
                            if let d = l.description, !d.isEmpty { Text(verbatim: d).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 6) }
                        }
                        if let amenities = l.amenities, !amenities.isEmpty {
                            SeaCard {
                                Text(L("mar_amenities")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 8)
                                FlowLayout(spacing: 8) {
                                    ForEach(amenities, id: \.self) { a in
                                        HStack(spacing: 4) {
                                            Image(systemName: amenityIcon(a)).font(.system(size: 12)).foregroundStyle(sea)
                                            Text(verbatim: amenityLabel(a)).font(.dorr(12)).foregroundStyle(wa.settingsInk)
                                        }
                                        .padding(.horizontal, 10).padding(.vertical, 6).background(sea.opacity(0.1), in: Capsule())
                                    }
                                }
                            }
                        }
                        SeaCard {
                            if trip {
                                // Seats or the whole boat.
                                HStack(spacing: 8) {
                                    if let seat = prices.seatMinor {
                                        modeTile(L("mar_shared"), rideMoney(seat, l.currency) + " / " + L("mar_seat"), on: mode == "shared") { self.mode = "shared" }
                                    }
                                    if let whole = prices.privateMinor {
                                        modeTile(L("mar_private"), rideMoney(whole, l.currency), on: mode == "private", enabled: (l.seatsLeft ?? 0) >= capacity) { self.mode = "private" }
                                    }
                                }
                                .padding(.bottom, 12)
                            } else {
                                rentalPicker(l, hours: hours, clash: clash)
                            }
                            MarineStepper(label: L("mar_guests"), value: guests, range: 1...max(1, maxGuests)) { guests = $0 }
                        }
                        if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger) }
                        Text(L("mar_pay_note")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    .padding(16)
                }
            }
            .ignoresSafeArea(edges: .top)
            // The total and the one button.
            HStack {
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("mar_total")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: rideMoney(total, l.currency)).font(.dorr(20, .black)).foregroundStyle(wa.settingsInk)
                }
                Spacer()
                Button { book(l, trip: trip, mode: mode, hours: hours, start: start) } label: {
                    ZStack {
                        if busy { ProgressView().tint(.white) } else { Text(L("mar_book")).font(.dorr(15, .bold)).foregroundStyle(.white) }
                    }
                    .padding(.horizontal, 26).padding(.vertical, 14)
                    .background(LinearGradient(colors: canBook ? [deepSea, sea] : [wa.settingsMut, wa.settingsMut], startPoint: .leading, endPoint: .trailing), in: Capsule())
                }
                .buttonStyle(WaPressStyle())
                .disabled(!canBook)
            }
            .padding(16)
            .background(wa.settingsCard.ignoresSafeArea(edges: .bottom).shadow(color: .black.opacity(0.12), radius: 18, y: -2))
        }
        .task(id: initial.uuid) { if let fresh = try? await MarineApi.listing(initial.uuid).data { self.l = fresh } }
    }

    @ViewBuilder
    private func rentalPicker(_ l: MarineListingDto, hours: Int, clash: Bool) -> some View {
        let cal = Calendar.current
        let dayF = marineFormatter("EEE d")
        let hourF = marineFormatter("h a")
        Text(L("mar_pick_day")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 8)
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(0..<14, id: \.self) { n in
                    let d = cal.date(byAdding: .day, value: n, to: cal.startOfDay(for: Date()))!
                    SvcChip(text: dayF.string(from: d), on: d == day, tint: sea) { day = d; hour = nil }
                }
            }
        }
        Text(L("mar_pick_hour")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 12).padding(.bottom, 8)
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(6...23, id: \.self) { h in
                    let at = cal.date(bySettingHour: h, minute: 0, second: 0, of: day) ?? day
                    let taken = (l.busy ?? []).compactMap(slot).contains { at >= $0.0 && at < $0.1 }
                    SvcChip(text: hourF.string(from: at), on: hour == h, enabled: at > Date() && !taken, tint: sea) { hour = h }
                }
            }
        }
        MarineStepper(label: L("mar_hours"), value: hours, range: (l.prices?.minHours ?? 1)...12) { self.hours = $0 }.padding(.top, 12)
        if clash { Text(L("mar_clash")).font(.dorr(12)).foregroundStyle(AppColors.danger).padding(.top, 6) }
    }

    private func modeTile(_ title: String, _ sub: String, on: Bool, enabled: Bool = true, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 2) {
                HStack {
                    Text(verbatim: title).font(.dorr(15, .bold)).foregroundStyle(on ? sea : wa.settingsInk)
                    Spacer(minLength: 0)
                    if on { Image(systemName: "checkmark.circle.fill").foregroundStyle(sea) }
                }
                Text(verbatim: sub).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(on ? sea.opacity(0.12) : wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .opacity(enabled ? 1 : 0.45)
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }

    private func book(_ l: MarineListingDto, trip: Bool, mode: String, hours: Int, start: Date?) {
        busy = true
        error = nil
        Task {
            var body: [String: JSONValue] = ["mode": .string(mode), "guests": .int(guests)]
            if !trip {
                let f = ISO8601DateFormatter()
                f.timeZone = .current
                body["starts_at"] = start.map { .string(f.string(from: $0)) } ?? .null
                body["hours"] = .int(hours)
            }
            do { if let b = try await MarineApi.book(l.uuid, body, key: key).data { onBooked(b) } } catch { self.error = error.apiFailure.message }
            busy = false
        }
    }
}

private struct MarineBookings: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let onOpen: (MarineBookingDto) -> Void
    @State private var list: [MarineBookingDto]?

    var body: some View {
        VStack(spacing: 0) {
            SvcBar(title: L("mar_my_bookings"), colors: [deepSea, sea], onBack: onBack)
            if let rows = list {
                if rows.isEmpty {
                    EmptySea(text: L("mar_no_bookings"))
                    Spacer()
                } else {
                    ScrollView {
                        VStack(spacing: 10) {
                            ForEach(Array(rows.enumerated()), id: \.element.uuid) { i, b in
                                Button { onOpen(b) } label: {
                                    HStack(spacing: 10) {
                                        Image(systemName: "ferry.fill").foregroundStyle(.white)
                                            .frame(width: 44, height: 44)
                                            .background(LinearGradient(colors: [deepSea, foam], startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                                        VStack(alignment: .leading, spacing: 0) {
                                            Text(verbatim: b.listing?.title ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                                            Text(verbatim: "\(marineDay(b.startsAt)) · \(marineTime(b.startsAt))").font(.dorr(12)).foregroundStyle(wa.settingsMut)
                                        }
                                        Spacer(minLength: 0)
                                        MarineStatusTag(status: b.status)
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
                ProgressView().tint(sea).padding(40)
                Spacer()
            }
        }
        .task { list = (try? await MarineApi.bookings().data) ?? [] }
    }
}

private struct MarineBookingPage: View {
    @Environment(\.wa) private var wa
    @Environment(\.openURL) private var openURL
    @EnvironmentObject private var network: NetworkMonitor
    let initial: MarineBookingDto
    let fresh: Bool
    let onBack: () -> Void
    @State private var booking: MarineBookingDto?
    @State private var busy = false
    @State private var error: String?
    @State private var more = 1
    @State private var shown = false

    private func reload() async { if let b = try? await MarineApi.booking(initial.uuid).data { booking = b } }

    var body: some View {
        let b = booking ?? initial
        let l = b.listing
        let live = b.status == "confirmed" || b.status == "boarded"
        VStack(spacing: 0) {
            SvcBar(title: L("mar_booking"), colors: [deepSea, sea], onBack: onBack)
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    // O-67: held for its payment — the one payment screen (coupon, top-up), then the boarding QR.
                    if b.status == "unpaid" {
                        ServicePayCard(purpose: ServicePay.marine, uuid: b.uuid, expiresAt: b.paymentExpiresAt, autoOpen: fresh) { Task { await reload() } }
                    }
                    if fresh && b.status != "unpaid" {
                        VStack(spacing: 4) {
                            Image(systemName: "checkmark.circle.fill").font(.system(size: 64)).foregroundStyle(AppColors.success)
                            Text(L("mar_booked_done")).font(.dorr(20, .black)).foregroundStyle(wa.settingsInk)
                        }
                        .frame(maxWidth: .infinity)
                        .scaleEffect(shown ? 1 : 0.5).opacity(shown ? 1 : 0)
                        .onAppear { withAnimation(.spring(response: 0.5, dampingFraction: 0.45)) { shown = true } }
                    }
                    // The boarding QR, what the captain scans at the gangway.
                    if let qr = b.qr {
                        VStack(spacing: 8) {
                            ServiceQr(text: qr).padding(8).frame(width: 190, height: 190).background(Color.white, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                            Text(L("mar_show_qr")).font(.dorr(13)).foregroundStyle(.white).multilineTextAlignment(.center)
                        }
                        .frame(maxWidth: .infinity)
                        .padding(18)
                        .background(LinearGradient(colors: [deepSea, sea], startPoint: .top, endPoint: .bottom), in: RoundedRectangle(cornerRadius: 24, style: .continuous))
                    }
                    if let l {
                        SeaCard {
                            HStack {
                                Text(verbatim: l.title).font(.dorr(17, .black)).foregroundStyle(wa.settingsInk)
                                Spacer(minLength: 0)
                                MarineStatusTag(status: b.status)
                            }
                            SeaInfo(icon: "mappin", label: L("mar_marina"), value: l.marina.address ?? "")
                            SeaInfo(icon: "clock", label: L("mar_when"), value: "\(marineDay(b.startsAt)) · \(marineTime(b.startsAt)) → \(marineTime(b.endsAt))")
                            SeaInfo(icon: "person.3.fill", label: L("mar_guests"),
                                    value: "\(b.guests ?? 1) · " + L(b.mode == "private" ? "mar_private" : (b.mode == "rental" ? "mar_rental" : "mar_shared")))
                            Text(verbatim: rideMoney((b.amountMinor ?? 0) + (b.extensionMinor ?? 0), l.currency)).font(.dorr(20, .black)).foregroundStyle(sea).padding(.top, 6)
                            if let phone = l.captainPhone {
                                HStack(spacing: 8) {
                                    SeaTag(text: "📞 " + L("rd_call"), color: AppColors.success) { if let u = URL(string: "tel:\(phone)") { openURL(u) } }
                                    SeaTag(text: "✉ " + L("bus_message"), color: sea) { if let u = URL(string: "sms:\(phone)") { openURL(u) } }
                                }
                                .padding(.top, 10)
                            }
                        }
                    }
                    // R-42: more time on a hire — the captain is asked.
                    if b.mode == "rental" && live, let l {
                        SeaCard {
                            Text(L("mar_more_time")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                            if b.extension?.status == "pending" {
                                Text(verbatim: L("mar_more_pending", b.extension?.hours ?? 0)).font(.dorr(13)).foregroundStyle(AppColors.brandOrange).padding(.top, 6)
                            } else {
                                if let s = b.extension?.status {
                                    Text(L(s == "approved" ? "mar_more_approved" : "mar_more_declined")).font(.dorr(12))
                                        .foregroundStyle(s == "approved" ? AppColors.success : AppColors.danger).padding(.top, 4)
                                }
                                MarineStepper(label: L("mar_hours"), value: more, range: 1...6) { more = $0 }
                                Text(verbatim: L("mar_more_cost", rideMoney((l.prices?.hourMinor ?? 0) * more, l.currency))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                                Button {
                                    busy = true
                                    error = nil
                                    Task {
                                        do { if let fresh = try await MarineApi.extend(b.uuid, hours: more).data { booking = fresh } } catch { self.error = error.apiFailure.message }
                                        busy = false
                                    }
                                } label: {
                                    Text(L("mar_ask_captain")).font(.dorr(15, .bold)).foregroundStyle(.white).frame(maxWidth: .infinity).padding(12).background(sea, in: Capsule())
                                }
                                .buttonStyle(WaPressStyle())
                                .disabled(busy)
                                .padding(.top, 10)
                            }
                        }
                    }
                    if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(AppColors.danger) }
                    if b.status == "confirmed" {
                        Button {
                            busy = true
                            error = nil
                            Task {
                                do { if let fresh = try await MarineApi.cancel(b.uuid).data { booking = fresh } } catch { self.error = error.apiFailure.message }
                                busy = false
                            }
                        } label: {
                            Text(L("mar_cancel")).font(.dorr(15, .bold)).foregroundStyle(AppColors.danger).frame(maxWidth: .infinity).padding(12)
                        }
                        .buttonStyle(.plain)
                        .disabled(busy)
                    }
                }
                .padding(16)
            }
        }
        // One catch-up on open (the captain's answer to «more time» may have come meanwhile) and on reconnect.
        .task(id: network.reconnectTick) { await reload() }
        // Each move arrives as an event: one reload (nothing on a timer).
        .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { e in if e.name.hasPrefix("marine.") { Task { await reload() } } }
    }
}
