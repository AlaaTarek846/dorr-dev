import SwiftUI

// driverApp `ui/MarineScreens.kt`.
//
// The captain's boat (docs/rides_tasks.md O-25; D-80, D-100..D-102): a sea trip (the marina, where it goes,
// when it leaves and how long, a price per guest and / or for the whole boat) or the yacht for hire by the
// hour; on each: who booked (call them), boarding by their QR, leaving and ending (then paid), and a guest's
// «more time» to accept or decline. Bookings and requests arrive as notifications (`marine.*`) — the open
// page reloads once on them; nothing polls.

private let Sea = Color(hex: 0x0288D1)

struct MarineListingsScreen: View {
    @Environment(\.pal) private var pal
    let onBack: () -> Void
    let onOpen: (String) -> Void

    @State private var boat: MarineBoatInfoDto?
    @State private var list: [DriverMarineListingDto]?
    @State private var error: String?
    @State private var formOpen = false
    @State private var tick = 0

    var body: some View {
        ZStack {
            VStack(spacing: 0) {
                ScreenHeader(L("mr_title"), onBack: onBack)
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        Banner(error)
                        if let b = boat {
                            HStack(spacing: 0) {
                                TileIcon("ferry.fill", tint: Sea, box: 46)
                                HSpace(12)
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(b.name.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? L(b.type == "yacht" ? "mr_yacht" : "mr_boat"))
                                        .font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                                    Text(L("mr_up_to", b.seats ?? 0)).font(.dorr(12)).foregroundStyle(pal.mut)
                                }
                                .frame(maxWidth: .infinity, alignment: .leading)
                            }
                            .padding(14)
                            .frame(maxWidth: .infinity)
                            .surface()
                            .staggerIn(0)
                            PrimaryButton(L("mr_new"), color: Sea, icon: "plus") { formOpen = true }
                        }
                        if let rows = list {
                            if rows.isEmpty {
                                Text(L("mr_none")).font(.dorr(14)).foregroundStyle(pal.mut).padding(20)
                            } else {
                                ForEach(Array(rows.enumerated()), id: \.offset) { i, l in listingRow(l).staggerIn(i + 1) }
                            }
                        }
                    }
                    .padding(16)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
            .background(pal.bg.ignoresSafeArea())

            if formOpen, let b = boat {
                MarineForm(boat: b, onClose: { formOpen = false }, onDone: { formOpen = false; tick += 1 })
            }
        }
        .task(id: tick) {
            switch await DriverMarineApi.boat() {
            case .ok(let v, _): boat = v
            case .failed(let e): error = e.displayMessage
            default: break
            }
            if let v = await DriverMarineApi.listings().value { list = v }
        }
        .onReceive(DriverLive.shared.events.receive(on: DispatchQueue.main)) { e in if e.name.hasPrefix("marine.") { tick += 1 } }
    }

    private func listingLine(_ l: DriverMarineListingDto) -> String {
        if l.kind == "trip" {
            let when: String = busDay(l.departAt) + " " + busTime(l.departAt)
            return L("mr_kind_trip") + " · " + when
        }
        let price: String = money(l.prices?.hourMinor, l.currency)
        return L("mr_kind_rental") + " · " + price + " / " + L("mr_hour")
    }

    private func listingRow(_ l: DriverMarineListingDto) -> some View {
        let line: String = listingLine(l)
        return VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                Text(l.title ?? "").font(.dorr(14, .black)).foregroundStyle(pal.ink).lineLimit(1).truncationMode(.tail)
                    .frame(maxWidth: .infinity, alignment: .leading)
                MarineStatus(status: l.status ?? "active")
            }
            Text(line)
                .font(.dorr(12)).foregroundStyle(pal.mut).padding(.top, 4)
            Text(l.marina?.address ?? "").font(.dorr(13)).foregroundStyle(pal.ink).lineLimit(1).truncationMode(.tail).padding(.top, 2)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface()
        .contentShape(Rectangle())
        .onTapGesture { if let uuid = l.uuid { onOpen(uuid) } }
    }
}

private struct MarineStatus: View {
    @Environment(\.pal) private var pal
    let status: String

    var body: some View {
        let (label, color): (String, Color) = {
            switch status {
            case "paused": return (L("mr_st_paused"), pal.warning)
            case "departed": return (L("mr_st_departed"), pal.success)
            case "completed": return (L("mr_st_completed"), pal.mut)
            case "canceled": return (L("mr_st_canceled"), pal.danger)
            default: return (L("mr_st_active"), Sea)
            }
        }()
        Text(label).font(.dorr(11, .bold)).foregroundStyle(color)
            .padding(.horizontal, 10).padding(.vertical, 4)
            .background(color.opacity(0.12), in: Capsule())
    }
}

// MARK: - publish

/// `minor(text)` of the forms: the amount in minor units, above zero, or nil.
func positiveMinor(_ text: String) -> Int? { busMinor(text).flatMap { $0 > 0 ? $0 : nil } }

private struct MarineForm: View {
    @Environment(\.pal) private var pal
    let boat: MarineBoatInfoDto
    let onClose: () -> Void
    let onDone: () -> Void

    @State private var kind = "trip"
    @State private var title = ""
    @State private var description = ""
    @State private var marina: PlaceDto?
    @State private var picking = false
    @State private var routeNote = ""
    @State private var amenities: Set<String> = []
    @State private var capacity: String
    @State private var day: LocalDate?
    @State private var time: PickedTime?
    @State private var dateOpen = false
    @State private var timeOpen = false
    @State private var duration = "120"
    @State private var seatPrice = ""
    @State private var privatePrice = ""
    @State private var hourPrice = ""
    @State private var minHours = "1"
    @State private var busy = false
    @State private var error: String?

    init(boat: MarineBoatInfoDto, onClose: @escaping () -> Void, onDone: @escaping () -> Void) {
        self.boat = boat
        self.onClose = onClose
        self.onDone = onDone
        _capacity = State(initialValue: String(boat.seats ?? 0))
    }

    private var ready: Bool {
        guard !title.trimmingCharacters(in: .whitespaces).isEmpty, marina != nil else { return false }
        if kind == "trip" {
            return day != nil && time != nil && Int(duration) != nil && (positiveMinor(seatPrice) != nil || positiveMinor(privatePrice) != nil)
        }
        return positiveMinor(hourPrice) != nil
    }

    var body: some View {
        ZStack {
            VStack(spacing: 0) {
                ScreenHeader(L("mr_new"), onBack: onClose)
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        HStack(spacing: 8) {
                            Chip(L("mr_kind_trip"), selected: kind == "trip", tint: Sea) { kind = "trip" }
                            Chip(L("mr_kind_rental"), selected: kind == "rental", tint: Sea) { kind = "rental" }
                        }
                        Text(L(kind == "trip" ? "mr_kind_trip_hint" : "mr_kind_rental_hint")).font(.dorr(12)).foregroundStyle(pal.mut)
                        DorrField(value: title, onValueChange: { title = String($0.prefix(120)) }, label: L("mr_name"), singleLine: true)
                        VStack(spacing: 0) {
                            ListRow(icon: "mappin.circle.fill", title: marina?.address ?? L("mr_pick_marina"), subtitle: L("mr_marina"), tint: Sea,
                                    onClick: { picking = true })
                        }
                        .frame(maxWidth: .infinity)
                        .surface()
                        DorrField(value: routeNote, onValueChange: { routeNote = String($0.prefix(255)) }, label: L("mr_route"), singleLine: true)
                        if kind == "trip" {
                            Text(L("mr_departure")).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                            HStack(spacing: 8) {
                                OutlineButton(day?.toString() ?? L("bus_day"), fillMaxWidth: true) { dateOpen = true }.frame(maxWidth: .infinity)
                                OutlineButton(time?.toString() ?? L("bus_time"), fillMaxWidth: true) { timeOpen = true }.frame(maxWidth: .infinity)
                            }
                            DorrField(value: duration, onValueChange: { duration = String($0.filter(\.isNumber).prefix(4)) }, label: L("mr_duration"),
                                      singleLine: true, keyboardOptions: KeyboardOptions(keyboardType: .numberPad))
                            DorrField(value: seatPrice, onValueChange: { seatPrice = $0 }, label: L("mr_seat_price"), singleLine: true,
                                      keyboardOptions: KeyboardOptions(keyboardType: .decimalPad))
                            DorrField(value: privatePrice, onValueChange: { privatePrice = $0 }, label: L("mr_private_price"), singleLine: true,
                                      keyboardOptions: KeyboardOptions(keyboardType: .decimalPad))
                        } else {
                            DorrField(value: hourPrice, onValueChange: { hourPrice = $0 }, label: L("mr_hour_price"), singleLine: true,
                                      keyboardOptions: KeyboardOptions(keyboardType: .decimalPad))
                            DorrField(value: minHours, onValueChange: { minHours = String($0.filter(\.isNumber).prefix(2)) }, label: L("mr_min_hours"),
                                      singleLine: true, keyboardOptions: KeyboardOptions(keyboardType: .numberPad))
                        }
                        DorrField(value: capacity, onValueChange: { capacity = String($0.filter(\.isNumber).prefix(3)) }, label: L("mr_capacity"),
                                  singleLine: true, keyboardOptions: KeyboardOptions(keyboardType: .numberPad))
                        Text(L("mr_amenities")).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                        MarineFlow(spacing: 8) {
                            ForEach(boat.amenities ?? [], id: \.self) { a in
                                Chip(amenityText(a), selected: amenities.contains(a), tint: Sea) {
                                    if amenities.contains(a) { amenities.remove(a) } else { amenities.insert(a) }
                                }
                            }
                        }
                        DorrField(value: description, onValueChange: { description = String($0.prefix(2000)) }, label: L("mr_description"), minLines: 3)
                        Banner(error)
                        PrimaryButton(L("mr_publish"), enabled: ready, busy: busy, color: Sea) { publish() }
                    }
                    .padding(16)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
            .background(pal.bg.ignoresSafeArea())

            if picking {
                PlacePicker(onClose: { picking = false }) { marina = $0; picking = false }
            }
        }
        .dorrDialog(isPresented: $dateOpen) {
            DorrDatePickerDialog(initial: day, min: LocalDate.now(), max: LocalDate.now().plusYears(1), onDismiss: { dateOpen = false }) {
                day = $0
                dateOpen = false
            }
        }
        .background(
            Color.clear.dorrDialog(isPresented: $timeOpen) {
                TimePickDialog(onDismiss: { timeOpen = false }) { time = $0; timeOpen = false }
            }
        )
    }

    private func publish() {
        guard let marina else { return }
        busy = true
        error = nil
        Task {
            var body: JsonBody = [
                "kind": kind,
                "title": title.trimmingCharacters(in: .whitespacesAndNewlines),
            ]
            let desc = description.trimmingCharacters(in: .whitespacesAndNewlines)
            if !desc.isEmpty { body["description"] = desc }
            body["marina"] = ["lat": marina.lat, "lng": marina.lng, "address": marina.address] as JsonBody
            let route = routeNote.trimmingCharacters(in: .whitespacesAndNewlines)
            if !route.isEmpty { body["route_note"] = route }
            body["amenities"] = Array(amenities)
            if let n = Int(capacity) { body["capacity"] = n }
            if kind == "trip" {
                body["depart_at"] = busAt(day, time)
                body["duration_min"] = Int(duration) ?? 0
                if let m = positiveMinor(seatPrice) { body["seat_price_minor"] = m }
                if let m = positiveMinor(privatePrice) { body["private_price_minor"] = m }
            } else {
                body["hour_price_minor"] = positiveMinor(hourPrice)
                body["min_hours"] = Int(minHours) ?? 1
            }
            switch await DriverMarineApi.publish(body: body) {
            case .ok: onDone()
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }
}

/// Compose's `FlowRow`: children left to right (start to end), wrapping onto new lines.
private struct MarineFlow: Layout {
    var spacing: CGFloat = 8

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, line: CGFloat = 0, widest: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > width { y += line + spacing; x = 0; line = 0 }
            x += size.width + spacing
            line = max(line, size.height)
            widest = max(widest, x - spacing)
        }
        return CGSize(width: proposal.width ?? widest, height: y + line)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x: CGFloat = 0, y: CGFloat = 0, line: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > bounds.width { y += line + spacing; x = 0; line = 0 }
            s.place(at: CGPoint(x: bounds.minX + x, y: bounds.minY + y), proposal: ProposedViewSize(size))
            x += size.width + spacing
            line = max(line, size.height)
        }
    }
}

// MARK: - one listing

@MainActor
struct MarineListingScreen: View {
    @Environment(\.pal) private var pal
    let uuid: String
    let onBack: () -> Void

    @State private var l: DriverMarineListingDto?
    @State private var message: String?
    @State private var error: String?
    @State private var busy = false
    @State private var scanning = false

    var body: some View {
        VStack(spacing: 0) {
            ScreenHeader(L("mr_listing"), onBack: onBack)
            if let t = l {
                ScrollView { content(t).padding(16) }
            } else {
                Spacer()
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        .background(pal.bg.ignoresSafeArea())
        .task(id: uuid) { await reload() }
        .onReceive(DriverLive.shared.events.receive(on: DispatchQueue.main)) { e in
            if e.name.hasPrefix("marine.") { Task { await reload() } }
        }
        .fullScreenCover(isPresented: $scanning) {
            TicketScanScreen(prompt: L("mr_scan_prompt")) { qr in
                scanning = false
                if let qr {
                    call({ await DriverMarineApi.board(body: ["qr": qr]) }) { b in
                        message = L("mr_boarded", b.guest?.name ?? "", b.guests ?? 1)
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func content(_ t: DriverMarineListingDto) -> some View {
        let status = t.status ?? "active"
        let bookings = t.bookings ?? []
        VStack(alignment: .leading, spacing: 12) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    Text(t.title ?? "").font(.dorr(14, .black)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                    MarineStatus(status: status)
                }
                Text(t.marina?.address ?? "").font(.dorr(13)).foregroundStyle(pal.ink).padding(.top, 6)
                if t.kind == "trip" {
                    Text(tripLine(t)).font(.dorr(12)).foregroundStyle(pal.mut)
                    Text(L("mr_left_of", t.seatsLeft ?? 0, t.capacity ?? 0)).font(.dorr(12)).foregroundStyle(pal.mut)
                } else {
                    Text(rentalLine(t))
                        .font(.dorr(12)).foregroundStyle(pal.mut)
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .surface()
            if let message { Banner(message, kind: .success) }
            Banner(error)
            switch status {
            case "active":
                if t.kind == "trip" {
                    PrimaryButton(L("mr_depart"), busy: busy, color: pal.success) { move("departed") }
                } else {
                    OutlineButton(L("mr_pause")) { move("paused") }
                }
                OutlineButton(L("mr_cancel"), color: pal.danger) { move("canceled") }
            case "paused":
                PrimaryButton(L("mr_resume"), busy: busy, color: Sea) { move("active") }
                OutlineButton(L("mr_cancel"), color: pal.danger) { move("canceled") }
            case "departed":
                PrimaryButton(L("mr_finish"), busy: busy) { move("completed") }
            default:
                EmptyView()
            }
            if status == "active" || status == "departed" || status == "paused" {
                OutlineButton(L("bus_scan_ticket")) { scanning = true }
            }
            Text(L("mr_bookings", bookings.count)).font(.dorr(14, .bold)).foregroundStyle(pal.ink)
            ForEach(Array(bookings.enumerated()), id: \.offset) { _, b in
                GuestRow(
                    b: b, l: t,
                    onFinish: { if let id = b.uuid { call({ await DriverMarineApi.finish(uuid: id) }) } },
                    onExtension: { yes in if let id = b.uuid { call({ await DriverMarineApi.extension(uuid: id, body: ["approve": yes]) }) } }
                )
            }
        }
    }

    private func tripLine(_ t: DriverMarineListingDto) -> String {
        let when: String = busDay(t.departAt) + " · " + busTime(t.departAt)
        return when + " · " + L("mr_minutes", t.durationMin ?? 0)
    }

    private func rentalLine(_ t: DriverMarineListingDto) -> String {
        let price: String = money(t.prices?.hourMinor, t.currency) + " / " + L("mr_hour")
        return price + " · " + L("mr_min_hours_n", t.prices?.minHours ?? 1)
    }

    private func reload() async {
        if let v = await DriverMarineApi.listing(uuid: uuid).value { l = v }
    }

    private func call<T>(_ call: @escaping () async -> ApiResult<T>, ok: @escaping (T) -> Void = { _ in }) {
        busy = true
        error = nil
        Task {
            switch await call() {
            case .ok(let v, _): ok(v); await reload()
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    private func move(_ to: String) { call({ await DriverMarineApi.move(uuid: uuid, body: ["to": to]) }) }
}

/// Android's `ACTION_DIAL` / `ACTION_SENDTO smsto:`.
func dialPhone(_ phone: String) { if let url = URL(string: "tel:\(phone.filter { !$0.isWhitespace })") { UIApplication.shared.open(url) } }

func smsPhone(_ phone: String) { if let url = URL(string: "sms:\(phone.filter { !$0.isWhitespace })") { UIApplication.shared.open(url) } }

private struct GuestRow: View {
    @Environment(\.pal) private var pal
    let b: MarineGuestBookingDto
    let l: DriverMarineListingDto
    let onFinish: () -> Void
    let onExtension: (Bool) -> Void

    var body: some View {
        let modeKey: String = b.mode == "private" ? "mr_private" : b.mode == "rental" ? "mr_kind_rental" : "mr_shared"
        let mode: String = L(modeKey)
        let total: Int = (b.amountMinor ?? 0) + (b.extensionMinor ?? 0)
        let summary: String = L("mr_guests_n", b.guests ?? 1) + " · " + mode + " · " + money(total, l.currency)
        let hours: String = busDay(b.startsAt) + " · " + busTime(b.startsAt) + " → " + busTime(b.endsAt)
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                VStack(alignment: .leading, spacing: 0) {
                    Text(b.guest?.name ?? "—").font(.dorr(14, .bold)).foregroundStyle(pal.ink)
                    Text(summary)
                        .font(.dorr(12)).foregroundStyle(pal.mut)
                    if b.mode == "rental" {
                        Text(hours).font(.dorr(12)).foregroundStyle(pal.mut)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                Text(L(b.status == "boarded" ? "mr_st_boarded" : b.status == "completed" ? "mr_st_completed" : "mr_st_confirmed"))
                    .font(.dorr(11, .bold)).foregroundStyle(b.status == "boarded" ? pal.success : pal.mut)
            }
            if let phone = b.guest?.phone {
                HStack(spacing: 8) {
                    Chip("📞 " + L("mr_call"), tint: pal.success) { dialPhone(phone) }
                    Chip("✉ " + L("mr_sms"), tint: Sea) { smsPhone(phone) }
                }
                .padding(.top, 8)
            }
            // R-42, D-102: the guest asks for more time.
            if b.extension?.status == "pending" {
                Text(L("mr_more_asked", b.extension?.hours ?? 0, money(b.extension?.amountMinor ?? 0, l.currency)))
                    .font(.dorr(13, .bold)).foregroundStyle(pal.orange).padding(.top, 8)
                HStack(spacing: 8) {
                    PrimaryButton(L("mr_accept"), color: pal.success) { onExtension(true) }.frame(maxWidth: .infinity)
                    OutlineButton(L("mr_decline"), color: pal.danger, fillMaxWidth: true) { onExtension(false) }.frame(maxWidth: .infinity)
                }
                .padding(.top, 6)
            }
            if b.mode == "rental" && (b.status == "confirmed" || b.status == "boarded") {
                VSpace(6)
                OutlineButton(L("mr_finish_hire")) { onFinish() }
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface()
    }
}

private func amenityText(_ key: String) -> String {
    switch key {
    case "life_jackets": return L("mr_am_life_jackets")
    case "snorkeling": return L("mr_am_snorkeling")
    case "fishing": return L("mr_am_fishing")
    case "food": return L("mr_am_food")
    case "drinks": return L("mr_am_drinks")
    case "music": return L("mr_am_music")
    case "cabin": return L("mr_am_cabin")
    case "toilet": return L("mr_am_toilet")
    case "crew": return L("mr_am_crew")
    case "swimming": return L("mr_am_swimming")
    case "sunbeds": return L("mr_am_sunbeds")
    default: return L("mr_am_photos")
    }
}
