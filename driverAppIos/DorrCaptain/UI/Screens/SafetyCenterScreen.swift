import SwiftUI
import UIKit

// driverApp `ui/SafetyCenterScreen.kt`.

/*
 * The captain's safety (D-140..160, D-381, D-396, D-410): the Safety Center (SOS, trusted contacts
 * and when they get the trip's link, riders they won't be matched with), reporting a problem — an
 * accident comes back with what to do now (D-155) — "Are you OK?" during the trip, the seatbelt
 * banner, and "don't match me with this rider again".
 */

private let SosRed = Color(hex: 0xA32D2D)

/// `Intent.ACTION_DIAL` with `tel:` — the phone's dialer with the number in it.
@MainActor
private func dial(_ number: String) {
    let digits = number.filter { $0.isNumber || $0 == "+" || $0 == "*" || $0 == "#" }
    if let url = URL(string: "tel:\(digits)") { UIApplication.shared.open(url) }
}

struct SafetyCenterScreen: View {
    let onBack: () -> Void

    @Environment(\.pal) private var pal
    @State private var data: DriverSafetyDto?
    @State private var editing: TrustedContactDto?
    @State private var adding = false

    init(onBack: @escaping () -> Void) { self.onBack = onBack }

    private func load() async {
        if let d = await DriverApi.safety().value { data = d }
    }

    var body: some View {
        ScreenScaffold(L("sc_title"), onBack: onBack) {
            // The SOS button first: one tap to the emergency number.
            let number = data?.emergencyNumber ?? "911"
            VStack(spacing: 0) {
                Text(L("sc_first")).font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.7))
                VSpace(10)
                ZStack {
                    Circle().fill(SosRed)
                    Circle().strokeBorder(SosRed.opacity(0.35), lineWidth: 6)
                    Text("SOS").font(.dorr(22, .black)).foregroundStyle(.white)
                }
                .frame(width: 84, height: 84)
                .bounceClick { dial(number) }
                VSpace(6)
                Text(L("trip_sos_call", number)).font(.dorr(12)).foregroundStyle(pal.ink)
            }
            .padding(18)
            .frame(maxWidth: .infinity)
            .surface(24, elevation: 8)
            .padding(.horizontal, 16)

            if let d = data {
                ScrollView {
                    VStack(spacing: 14) {
                        Card {
                            Text("👥  " + L("sc_contacts")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                            Text(L("sc_contacts_hint")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                            VSpace(8)
                            ForEach(Array((d.contacts ?? []).enumerated()), id: \.offset) { _, c in
                                HStack(spacing: 0) {
                                    ZStack {
                                        Circle().fill(pal.orange.opacity(0.15))
                                        Text(String((c.name ?? "").prefix(1))).font(.dorr(16, .bold)).foregroundStyle(pal.orange)
                                    }
                                    .frame(width: 38, height: 38)
                                    HSpace(10)
                                    VStack(alignment: .leading, spacing: 0) {
                                        Text(c.name ?? "").font(.dorr(16, .semibold)).foregroundStyle(pal.ink)
                                        Text(c.phone ?? "").font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                                    }
                                    .frame(maxWidth: .infinity, alignment: .leading)
                                    Text(modeLabel(c.shareMode ?? "manual")).font(.dorr(11)).foregroundStyle(pal.navy)
                                        .padding(.horizontal, 10).padding(.vertical, 4)
                                        .background(Capsule().fill(pal.navy.opacity(0.08)))
                                }
                                .padding(.vertical, 8)
                                .contentShape(Rectangle())
                                .onTapGesture { editing = c }
                            }
                            if (d.contacts ?? []).count < (d.maxContacts ?? 5) {
                                HStack(spacing: 0) {
                                    DorrIcon("plus", size: 24, tint: pal.orange)
                                    HSpace(8)
                                    Text(L("sc_add_contact")).font(.dorr(16, .semibold)).foregroundStyle(pal.orange)
                                }
                                .padding(.vertical, 10)
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .contentShape(Rectangle())
                                .onTapGesture { adding = true }
                            }
                        }
                        Card {
                            Text("🛡️  " + L("sc_ride_check")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                            Text(L("sc_ride_check_hint")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.6))
                        }
                        if !(d.blocked ?? []).isEmpty {
                            Card {
                                Text("🚫  " + L("sc_blocked")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                                ForEach(Array((d.blocked ?? []).enumerated()), id: \.offset) { _, b in
                                    HStack(spacing: 0) {
                                        Text(b.name ?? "").font(.dorr(16, .semibold)).foregroundStyle(pal.ink)
                                            .frame(maxWidth: .infinity, alignment: .leading)
                                        DorrIcon("xmark", size: 20, tint: pal.ink)
                                            .contentShape(Circle())
                                            .onTapGesture {
                                                Task {
                                                    _ = await DriverApi.unblock(id: b.id ?? 0)
                                                    await load()
                                                }
                                            }
                                    }
                                    .padding(.vertical, 6)
                                }
                            }
                        }
                    }
                    .padding(16)
                }
            } else {
                CircularProgress(color: pal.orange, size: 40, lineWidth: 4)
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .task { await load() }
        .sheet(isPresented: Binding(get: { adding || editing != nil }, set: { if !$0 { adding = false; editing = nil } })) {
            ContactSheet(contact: editing, onClose: { adding = false; editing = nil }, onSaved: { Task { await load() } })
                .environment(\.pal, pal)
        }
    }
}

private func modeLabel(_ mode: String) -> String {
    switch mode {
    case "all": return L("sc_every_trip")
    case "night": return L("sc_night")
    default: return L("sc_manual")
    }
}

private struct ContactSheet: View {
    @Environment(\.pal) private var pal
    let contact: TrustedContactDto?
    let onClose: () -> Void
    let onSaved: () -> Void
    @State private var name: String
    @State private var phone: String
    @State private var mode: String
    @State private var arrival: Bool
    @State private var busy = false
    @State private var error: String?

    init(contact: TrustedContactDto?, onClose: @escaping () -> Void, onSaved: @escaping () -> Void) {
        self.contact = contact
        self.onClose = onClose
        self.onSaved = onSaved
        _name = State(initialValue: contact?.name ?? "")
        _phone = State(initialValue: contact?.phone ?? "")
        _mode = State(initialValue: contact?.shareMode ?? "all")
        _arrival = State(initialValue: contact?.notifyArrival ?? true)
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(L(contact == nil ? "sc_add_contact" : "sc_edit_contact")).font(.dorr(18, .heavy)).foregroundStyle(pal.ink)
            DorrField(value: name, onValueChange: { name = String($0.prefix(80)) }, label: L("sc_name"), singleLine: true)
            DorrField(value: phone, onValueChange: { phone = String($0.prefix(18)) }, label: L("sc_phone"), singleLine: true,
                      keyboardOptions: KeyboardOptions(keyboardType: .phonePad))
            Text(L("sc_when")).font(.dorr(12)).foregroundStyle(pal.ink)
            PillFlow(lineSpacing: 0) {
                ForEach(["all", "night", "manual"], id: \.self) { key in
                    let on = key == mode
                    Text(modeLabel(key)).font(.dorr(16)).foregroundStyle(on ? Color.white : pal.navy)
                        .padding(.horizontal, 14).padding(.vertical, 8)
                        .background(Capsule().fill(on ? pal.navy : pal.navy.opacity(0.06)))
                        .animation(.tween(200), value: on)
                        .contentShape(Capsule())
                        .onTapGesture { mode = key }
                }
            }
            HStack(spacing: 0) {
                Text(L("sc_arrival")).font(.dorr(16)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                DorrToggle(on: arrival) { arrival = $0 }
            }
            if let error { Text(error).font(.dorr(13)).foregroundStyle(pal.danger) }
            BigButton(L("ob_save"), color: pal.navy, enabled: !name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty && phone.count >= 8, busy: busy) {
                busy = true
                Task {
                    let body: JsonBody = [
                        "name": name.trimmingCharacters(in: .whitespacesAndNewlines),
                        "phone": phone.trimmingCharacters(in: .whitespacesAndNewlines),
                        "share_mode": mode,
                        "notify_arrival": arrival,
                    ]
                    let r = contact == nil
                        ? await DriverApi.addContact(body: body)
                        : await DriverApi.updateContact(id: contact?.id ?? 0, body: body)
                    switch r {
                    case .ok: onSaved(); onClose()
                    case .failed(let e): error = e.displayMessage
                    case .offline: error = nil
                    }
                    busy = false
                }
            }
            if let contact {
                Text(L("sc_remove")).font(.dorr(16, .semibold)).foregroundStyle(pal.danger).multilineTextAlignment(.center)
                    .padding(10)
                    .frame(maxWidth: .infinity)
                    .contentShape(Rectangle())
                    .onTapGesture {
                        Task {
                            _ = await DriverApi.deleteContact(id: contact.id ?? 0)
                            onSaved()
                            onClose()
                        }
                    }
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 22)
        .padding(.bottom, 28)
        .modifier(FitSheet())
    }
}

/** 🚩 Report a problem on the trip (D-124, D-130, D-152..154); an accident shows what to do now (D-155). The sheet's
 *  content: show it with `.sheet(isPresented:) { DriverReportSheet(t:onClose:) }` (sized to itself, like
 *  `skipPartiallyExpanded`). */
struct DriverReportSheet: View {
    @Environment(\.pal) private var pal
    let t: TripDto
    let onClose: () -> Void
    @State private var type: String?
    @State private var note = ""
    @State private var busy = false
    @State private var error: String?
    @State private var sent = false
    @State private var guidance: [String] = []

    init(t: TripDto, onClose: @escaping () -> Void) {
        self.t = t
        self.onClose = onClose
    }

    private let types: [(String, String)] = [
        ("rider_mismatch", "rp_rider_mismatch"), ("abuse", "rp_abuse"), ("threat", "rp_threat"),
        ("accident", "rp_accident"), ("road_hazard", "rp_hazard"), ("other", "rp_other"),
    ]

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text(L("rp_title")).font(.dorr(19, .heavy)).foregroundStyle(pal.ink)
            if sent {
                Text(L("rp_sent")).font(.dorr(16, .semibold)).foregroundStyle(pal.success)
                if !guidance.isEmpty {
                    Text(L("rp_after_accident")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                    ForEach(Array(guidance.enumerated()), id: \.offset) { i, step in
                        HStack(alignment: .top, spacing: 0) {
                            ZStack {
                                Circle().fill(pal.orange)
                                Text("\(i + 1)").font(.dorr(12, .bold)).foregroundStyle(.white)
                            }
                            .frame(width: 24, height: 24)
                            HSpace(10)
                            Text(step).font(.dorr(14)).foregroundStyle(pal.ink)
                        }
                    }
                }
                BigButton(L("sc_done"), color: pal.navy, onClick: onClose)
            } else {
                PillFlow {
                    ForEach(types, id: \.0) { key, label in
                        let on = key == type
                        Text(L(label)).font(.dorr(14)).foregroundStyle(on ? Color.white : pal.ink)
                            .padding(.horizontal, 14).padding(.vertical, 9)
                            .background(Capsule().fill(on ? SosRed : pal.ink.opacity(0.07)))
                            .contentShape(Capsule())
                            .onTapGesture { type = key }
                    }
                }
                DorrField(value: note, onValueChange: { note = String($0.prefix(500)) }, label: L("rp_note"))
                if let error { Text(error).font(.dorr(13)).foregroundStyle(pal.danger) }
                BigButton(L("rp_send"), color: SosRed, enabled: type != nil, busy: busy) {
                    busy = true
                    Task {
                        let here = await lastPosition()
                        var body: JsonBody = ["type": type]
                        if !note.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty { body["note"] = note.trimmingCharacters(in: .whitespacesAndNewlines) }
                        if let here { body["lat"] = here.0; body["lng"] = here.1 }
                        switch await DriverApi.report(uuid: t.uuid ?? "", body: body) {
                        case .ok(let data, _):
                            sent = true
                            if case .array(let steps)? = data["guidance"] { guidance = steps.map { $0.str ?? "" } } else { guidance = [] }
                        case .failed(let e): error = e.displayMessage
                        case .offline: error = L("error_offline")
                        }
                        busy = false
                    }
                }
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 22)
        .padding(.bottom, 28)
        .modifier(FitSheet())
    }
}

/** "Are you OK?" during the trip (D-144, D-396): few big buttons, safe while driving. The dialog card: show it with
 *  `.dorrDialog(isPresented:, dismissible: false) { DriverSafetyCheckDialog(t:check:onDone:) }` (it can't be dismissed). */
struct DriverSafetyCheckDialog: View {
    @Environment(\.pal) private var pal
    let t: TripDto
    let check: SafetyCheckDto
    let onDone: () -> Void
    @State private var busy = false

    init(t: TripDto, check: SafetyCheckDto, onDone: @escaping () -> Void) {
        self.t = t
        self.check = check
        self.onDone = onDone
    }

    private func answer(_ value: String) {
        busy = true
        Task {
            let here = value == "help" ? await lastPosition() : nil
            var body: JsonBody = ["answer": value]
            if let here { body["lat"] = here.0; body["lng"] = here.1 }
            let r = await DriverApi.answerCheck(uuid: t.uuid ?? "", check: check.uuid ?? "", body: body)
            busy = false
            onDone()
            if value == "help", let number = r.value?.emergencyNumber { await dial(number) }
        }
    }

    var body: some View {
        AlertDialogCard(icon: { DorrIcon("shield.fill", size: 40, tint: SosRed) }, title: {
            Text(L("ck_title")).font(.dorr(22, .heavy)).multilineTextAlignment(.center)
        }, text: {
            Text(L(check.reason == "deviation" ? "ck_deviation" : "ck_long_stop")).multilineTextAlignment(.center).frame(maxWidth: .infinity)
        }, buttons: {
            VStack(spacing: 10) {
                BigButton(L("ck_ok"), color: pal.success, busy: busy) { answer("ok") }
                BigButton(L("ck_help"), color: SosRed) { answer("help") }
            }
            .frame(maxWidth: .infinity)
            .layoutPriority(1)
        })
    }
}

/** 🪢 Fasten your seatbelt — as the trip starts (D-157). */
struct DriverSeatbeltBanner: View {
    @Environment(\.pal) private var pal
    let uuid: String
    @State private var shown = true

    init(uuid: String) { self.uuid = uuid }

    var body: some View {
        VStack(spacing: 0) {
            if shown {
                HStack(spacing: 0) {
                    Text("🪢").font(.dorr(20))
                    HSpace(10)
                    Text(L("sb_text")).font(.dorr(16, .semibold)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                    DorrIcon("xmark", size: 18, tint: pal.ink)
                }
                .padding(14)
                .frame(maxWidth: .infinity)
                .background(RoundedRectangle(cornerRadius: 16).fill(pal.orange.opacity(0.14)))
                .contentShape(Rectangle())
                .onTapGesture { withAnimation(.tween(300)) { shown = false } }
                .transition(.opacity.combined(with: .move(edge: .top)))
            }
        }
        .clipped()
        // `remember(uuid)`: a new trip shows it again.
        .onChange(of: uuid) { _ in withAnimation(.tween(300)) { shown = true } }
    }
}

/** 🚫 After the trip: don't match me with this rider again (D-151, D-187, D-410). */
struct BlockRiderButton: View {
    @Environment(\.pal) private var pal
    let t: TripDto
    @State private var confirm = false
    @State private var done = false

    init(t: TripDto) { self.t = t }

    var body: some View {
        Text(L(done ? "bl_done" : "bl_button")).font(.dorr(16, .semibold)).foregroundStyle(done ? pal.success : pal.danger)
            .multilineTextAlignment(.center)
            .padding(10)
            .frame(maxWidth: .infinity)
            .contentShape(Rectangle())
            .onTapGesture { if !done { confirm = true } }
            .dorrDialog(isPresented: $confirm) {
                AlertDialogCard(title: { Text(L("bl_button")) }, text: { Text(L("bl_body")) }, buttons: {
                    AlertTextButton(L("sch_keep")) { confirm = false }
                    AlertTextButton(L("bl_confirm"), color: pal.danger) {
                        confirm = false
                        Task { if case .ok = await DriverApi.blockRider(uuid: t.uuid ?? "", body: [:]) { done = true } }
                    }
                })
            }
    }
}

/** "Send my trip to my trusted contacts" from the SOS sheet. */
func shareTripWithContacts(_ uuid: String) async -> String? {
    switch await DriverApi.shareWithContacts(uuid: uuid, body: ["ids": [Any]()]) {
    case .ok: return nil
    case .failed(let e): return e.displayMessage
    case .offline: return nil
    }
}

/// `ModalBottomSheet(skipPartiallyExpanded = true)`: the sheet as tall as what it holds, on the card colour.
private struct FitSheet: ViewModifier {
    @Environment(\.pal) private var pal
    @State private var height: CGFloat = 0

    func body(content: Content) -> some View {
        content
            .background(GeometryReader { g in
                Color.clear.onAppear { height = g.size.height }.onChange(of: g.size.height) { height = $0 }
            })
            .frame(maxHeight: .infinity, alignment: .top)
            .background(pal.card.ignoresSafeArea())
            .presentationDetents(height > 0 ? [.height(height)] : [.large])
            .presentationDragIndicator(.visible)
    }
}

/// `FlowRow(horizontalArrangement = spacedBy(8.dp), verticalArrangement = spacedBy(8.dp))`.
private struct PillFlow: Layout {
    var spacing: CGFloat = 8
    /// `verticalArrangement` (none given = 0).
    var lineSpacing: CGFloat = 8

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, row: CGFloat = 0, widest: CGFloat = 0
        for v in subviews {
            let s = v.sizeThatFits(.unspecified)
            if x > 0 && x + s.width > width { y += row + lineSpacing; x = 0; row = 0 }
            x += s.width + spacing
            row = max(row, s.height)
            widest = max(widest, x - spacing)
        }
        return CGSize(width: proposal.width ?? widest, height: y + row)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x: CGFloat = 0, y: CGFloat = 0, row: CGFloat = 0
        for v in subviews {
            let s = v.sizeThatFits(.unspecified)
            if x > 0 && x + s.width > bounds.width { y += row + lineSpacing; x = 0; row = 0 }
            v.place(at: CGPoint(x: bounds.minX + x, y: bounds.minY + y), proposal: ProposedViewSize(s))
            x += s.width + spacing
            row = max(row, s.height)
        }
    }
}
