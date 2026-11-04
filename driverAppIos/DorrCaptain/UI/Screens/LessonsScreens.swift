import SwiftUI

// driverApp `ui/LessonsScreens.kt`.
//
// Driving lessons, the trainer's side (docs/rides_tasks.md O-26b): my lesson packs (publish, pause),
// the lessons learners ask for (accept / decline, call them, the pickup), and «done» once given — the
// lesson's share lands in the wallet. Requests arrive as notifications (`lesson.*`); the page reloads once.

private let Plum = Color(hex: 0x7B1FA2)

@MainActor
struct TrainerScreen: View {
    @Environment(\.pal) private var pal
    let onBack: () -> Void

    @State private var tab = "lessons"
    @State private var packs: [TrainerPackDto]?
    @State private var sessions: [TrainerSessionDto]?
    @State private var error: String?
    @State private var formOpen = false
    // O-62: the pack being edited, and the one asked about removing.
    @State private var editing: TrainerPackDto?
    @State private var removing: TrainerPackDto?
    @State private var tick = 0

    var body: some View {
        ZStack {
            VStack(spacing: 0) {
                ScreenHeader(L("tr_title"), onBack: onBack)
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        HStack(spacing: 8) {
                            Chip(L("tr_lessons_tab", (sessions ?? []).filter { $0.status == "requested" }.count), selected: tab == "lessons", tint: Plum) { tab = "lessons" }
                            Chip(L("tr_packs_tab"), selected: tab == "packs", tint: Plum) { tab = "packs" }
                        }
                        Banner(error)
                        if tab == "packs" { packsTab } else { lessonsTab }
                    }
                    .padding(16)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
            .background(pal.bg.ignoresSafeArea())

            if formOpen {
                PackForm(onClose: { formOpen = false }, onDone: { formOpen = false; tab = "packs"; tick += 1 })
            }
            if let p = editing {
                PackForm(existing: p, onClose: { editing = nil }, onDone: { editing = nil; tick += 1 })
            }
        }
        .task(id: tick) {
            switch await DriverLessonsApi.sessions() {
            case .ok(let v, _): sessions = v
            case .failed(let e): error = e.displayMessage
            default: break
            }
            if let v = await DriverLessonsApi.packs().value { packs = v }
        }
        .onReceive(DriverLive.shared.events.receive(on: DispatchQueue.main)) { e in if e.name.hasPrefix("lesson.") { tick += 1 } }
        .alert(L("tr_delete_title"), isPresented: Binding(get: { removing != nil }, set: { if !$0 { removing = nil } }), presenting: removing) { p in
            Button(L("tr_delete"), role: .destructive) {
                removing = nil
                if let id = p.uuid { act { await DriverLessonsApi.remove(uuid: id) } }
            }
            Button(L("tr_keep"), role: .cancel) { removing = nil }
        } message: { p in
            Text(L((p.learners ?? 0) > 0 ? "tr_delete_archive" : "tr_delete_body", p.title ?? ""))
        }
    }

    @ViewBuilder
    private var packsTab: some View {
        PrimaryButton(L("tr_new_pack"), color: Plum, icon: "plus") { formOpen = true }
        let list = packs ?? []
        if packs != nil && list.isEmpty {
            Text(L("tr_no_packs")).font(.dorr(14)).foregroundStyle(pal.mut).padding(16)
        }
        ForEach(Array(list.enumerated()), id: \.offset) { i, p in
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 0) {
                    TileIcon("graduationcap.fill", tint: Plum, box: 42)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(p.title ?? "").font(.dorr(14, .bold)).foregroundStyle(pal.ink).lineLimit(1).truncationMode(.tail)
                        Text(packLine(p))
                            .font(.dorr(12)).foregroundStyle(pal.mut)
                        if let n = p.learners { Text(L("tr_learners", n)).font(.dorr(12, .semibold)).foregroundStyle(Plum) }
                    }
                    .padding(.horizontal, 10)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    DorrToggle(on: (p.status ?? "active") == "active") { on in
                        if let id = p.uuid { act { await DriverLessonsApi.status(uuid: id, body: ["status": on ? "active" : "paused"]) } }
                    }
                }
                // O-62: edit and remove.
                HStack(spacing: 8) {
                    Chip("✏️ " + L("tr_edit"), tint: Plum) { editing = p }
                    Chip("🗑 " + L("tr_delete"), tint: pal.danger) { removing = p }
                }
                .padding(.top, 10)
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .surface()
            .staggerIn(i)
        }
    }

    @ViewBuilder
    private var lessonsTab: some View {
        let list = sessions ?? []
        if sessions != nil && list.isEmpty {
            VStack(spacing: 0) {
                TileIcon("graduationcap.fill", tint: Plum, box: 56)
                Text(L("tr_no_lessons")).font(.dorr(14)).foregroundStyle(pal.mut).padding(.top, 10)
            }
            .padding(28)
            .frame(maxWidth: .infinity)
        }
        ForEach(Array(list.enumerated()), id: \.offset) { i, s in
            sessionCard(s).staggerIn(i)
        }
    }

    private func packLine(_ p: TrainerPackDto) -> String {
        let line: String = L("tr_pack_line", p.lessons ?? 1, p.minutesPerLesson ?? 60)
        return line + " · " + money(p.priceMinor ?? 0, p.currency)
    }

    private func sessionCard(_ s: TrainerSessionDto) -> some View {
        let confirmed = s.status == "confirmed"
        let badge: Color = confirmed ? pal.success : pal.orange
        let when: String = busDay(s.startsAt) + " · " + busTime(s.startsAt)
        let lessonOf: String = L("tr_lesson_of", s.number ?? 0, s.pack?.lessons ?? 0)
        let learner: String = s.learner?.name ?? "—"
        let packTitle: String = s.pack?.title ?? ""
        let who: String = [learner, lessonOf, packTitle].joined(separator: " · ")
        return VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                Text(when).font(.dorr(14, .black)).foregroundStyle(pal.ink)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Text(L(confirmed ? "tr_confirmed" : "tr_requested")).font(.dorr(11, .bold)).foregroundStyle(badge)
                    .padding(.horizontal, 10).padding(.vertical, 4)
                    .background(badge.opacity(0.12), in: Capsule())
            }
            Text(who)
                .font(.dorr(13)).foregroundStyle(pal.ink).padding(.top, 4)
            if let address = s.pickup?.address {
                Text("📍 \(address)").font(.dorr(12)).foregroundStyle(pal.mut).lineLimit(2).truncationMode(.tail)
            }
            if let phone = s.learner?.phone {
                HStack(spacing: 8) {
                    Chip("📞 " + L("tr_call"), tint: pal.success) { dialPhone(phone) }
                    if let p = s.pickup {
                        Chip("🧭 " + L("hj_navigate"), tint: Plum) { openNavigation(lat: p.lat ?? 0, lng: p.lng ?? 0) }
                    }
                }
                .padding(.top, 8)
            }
            VSpace(6)
            switch s.status {
            case "requested":
                HStack(spacing: 8) {
                    PrimaryButton(L("tr_accept"), color: Plum) {
                        if let id = s.uuid { act { await DriverLessonsApi.answer(uuid: id, body: ["approve": true]) } }
                    }
                    .frame(maxWidth: .infinity)
                    OutlineButton(L("tr_decline"), color: pal.danger, fillMaxWidth: true) {
                        if let id = s.uuid { act { await DriverLessonsApi.answer(uuid: id, body: ["approve": false]) } }
                    }
                    .frame(maxWidth: .infinity)
                }
            case "confirmed":
                let started = parseInstant(s.startsAt).map { $0 < Date() } ?? false
                PrimaryButton(L("tr_done"), enabled: started, color: pal.success) {
                    if let id = s.uuid { act { await DriverLessonsApi.done(uuid: id) } }
                }
            default:
                EmptyView()
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface()
    }

    private func act<T>(_ call: @escaping () async -> ApiResult<T>) {
        error = nil
        Task {
            switch await call() {
            case .ok: tick += 1
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
        }
    }
}

private struct PackForm: View {
    @Environment(\.pal) private var pal
    var existing: TrainerPackDto? = nil
    let onClose: () -> Void
    let onDone: () -> Void

    // O-62: editing fills the form with the pack as it is.
    @State private var title: String
    @State private var description: String
    @State private var transmission: String
    @State private var lessons: String
    @State private var minutes: String
    @State private var price: String
    @State private var ownCar: Bool
    @State private var area: PlaceDto?
    @State private var picking = false
    @State private var busy = false
    @State private var error: String?

    init(existing: TrainerPackDto? = nil, onClose: @escaping () -> Void, onDone: @escaping () -> Void) {
        self.existing = existing
        self.onClose = onClose
        self.onDone = onDone
        _title = State(initialValue: existing?.title ?? "")
        _description = State(initialValue: existing?.description ?? "")
        _transmission = State(initialValue: existing?.transmission ?? "automatic")
        _lessons = State(initialValue: String(existing?.lessons ?? 10))
        _minutes = State(initialValue: String(existing?.minutesPerLesson ?? 60))
        // `BigDecimal(priceMinor).movePointLeft(2).stripTrailingZeros().toPlainString()`.
        _price = State(initialValue: existing.map { "\(NSDecimalNumber(decimal: Decimal($0.priceMinor ?? 0) / 100))" } ?? "")
        _ownCar = State(initialValue: existing?.ownCar ?? true)
        _area = State(initialValue: existing?.area)
    }

    private var ready: Bool {
        !title.trimmingCharacters(in: .whitespaces).isEmpty && area != nil && (Int(lessons) ?? 0) > 0 && (Int(minutes) ?? 0) >= 15 && positiveMinor(price) != nil
    }

    var body: some View {
        ZStack {
            VStack(alignment: .leading, spacing: 0) {
                ScreenHeader(L(existing != nil ? "tr_edit_pack" : "tr_new_pack"), onBack: onClose)
                if (existing?.learners ?? 0) > 0 {
                    Text(L("tr_edit_locked")).font(.dorr(12)).foregroundStyle(pal.warning).padding(.horizontal, 16)
                }
                ScrollView {
                    VStack(alignment: .leading, spacing: 12) {
                        DorrField(value: title, onValueChange: { title = String($0.prefix(120)) }, label: L("tr_pack_name"), singleLine: true)
                        HStack(spacing: 8) {
                            Chip(L("hj_automatic"), selected: transmission == "automatic", tint: Plum) { transmission = "automatic" }
                            Chip(L("hj_manual"), selected: transmission == "manual", tint: Plum) { transmission = "manual" }
                        }
                        HStack(spacing: 8) {
                            DorrField(value: lessons, onValueChange: { lessons = String($0.filter(\.isNumber).prefix(2)) }, label: L("tr_lessons"),
                                      singleLine: true, keyboardOptions: KeyboardOptions(keyboardType: .numberPad))
                                .frame(maxWidth: .infinity)
                            DorrField(value: minutes, onValueChange: { minutes = String($0.filter(\.isNumber).prefix(3)) }, label: L("tr_minutes"),
                                      singleLine: true, keyboardOptions: KeyboardOptions(keyboardType: .numberPad))
                                .frame(maxWidth: .infinity)
                        }
                        DorrField(value: price, onValueChange: { price = $0 }, label: L("tr_price"), singleLine: true,
                                  keyboardOptions: KeyboardOptions(keyboardType: .decimalPad))
                        VStack(spacing: 0) {
                            ListRow(icon: "mappin.circle.fill", title: area?.address ?? L("tr_pick_area"), subtitle: L("tr_area"), tint: Plum,
                                    onClick: { picking = true })
                        }
                        .frame(maxWidth: .infinity)
                        .surface()
                        HStack(spacing: 0) {
                            Text(L("tr_own_car")).font(.dorr(14, .bold)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                            DorrToggle(on: ownCar) { ownCar = $0 }
                        }
                        .padding(.vertical, 6)
                        .contentShape(RoundedRectangle(cornerRadius: 14))
                        .onTapGesture { ownCar.toggle() }
                        DorrField(value: description, onValueChange: { description = String($0.prefix(2000)) }, label: L("mr_description"), minLines: 3)
                        Banner(error)
                        PrimaryButton(L("mr_publish"), enabled: ready, busy: busy, color: Plum) { publish() }
                    }
                    .padding(16)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
            .background(pal.bg.ignoresSafeArea())

            if picking {
                PlacePicker(onClose: { picking = false }) { area = $0; picking = false }
            }
        }
    }

    private func publish() {
        guard let area else { return }
        busy = true
        error = nil
        Task {
            var body: JsonBody = ["title": title.trimmingCharacters(in: .whitespacesAndNewlines)]
            let desc = description.trimmingCharacters(in: .whitespacesAndNewlines)
            if !desc.isEmpty { body["description"] = desc }
            body["transmission"] = transmission
            body["lessons"] = Int(lessons) ?? 0
            body["minutes_per_lesson"] = Int(minutes) ?? 0
            body["price_minor"] = positiveMinor(price)
            body["own_car"] = ownCar
            body["area"] = ["lat": area.lat, "lng": area.lng, "address": area.address] as JsonBody
            let r: ApiResult<TrainerPackDto>
            if let id = existing?.uuid {
                r = await DriverLessonsApi.update(uuid: id, body: body)
            } else {
                r = await DriverLessonsApi.publish(body: body)
            }
            switch r {
            case .ok: onDone()
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }
}
