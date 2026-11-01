import SwiftUI

// androidApp `rides/RidesSafetyCenter.kt` — the Safety Center (R-219..230, wireflow 16 and ج1): SOS, trusted
// contacts (with when each gets the ride's link), the ride code always / at night, a check-in after arriving, the
// seatbelt reminder, Smart Ride Check, favourite and blocked captains — plus the in-ride pieces: the "is this your
// car?" card, the seatbelt banner, sharing with contacts, reporting and "Are you OK?".

private let sosRed = Color(hex: 0xA32D2D)

struct SafetyCenterPage: View {
    @Environment(\.wa) private var wa
    @Environment(\.openURL) private var openURL
    @ObservedObject var state: RidesState
    let onClose: () -> Void
    @State private var data: SafetyCenterDto?
    @State private var editing: TrustedContactDto?
    @State private var adding = false
    @State private var sos = false
    @State private var error: String?

    private func load() async { data = (try? await RidesApi.safety().data) ?? data }

    private func savePrefs(_ body: [String: JSONValue]) {
        Task {
            do {
                if let p = try await RidesApi.savePreferences(body).data { data?.preferences = p }
            } catch {
                self.error = error.apiFailure.message
            }
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 10) {
                RideRoundButton(icon: "arrow.backward", label: L("common_back"), action: onClose)
                Text(L("rd_safety_center")).font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk)
                Spacer()
            }
            .padding(12)
            if let d = data { content(d) } else { ProgressView().tint(wa.accent).frame(maxWidth: .infinity, maxHeight: .infinity) }
        }
        .background(wa.settingsBackground.ignoresSafeArea())
        .task { await load() }
        .sheet(isPresented: Binding(get: { adding || editing != nil }, set: { if !$0 { adding = false; editing = nil } })) {
            ContactSheet(contact: editing, onClose: { adding = false; editing = nil }) { Task { await load() } }.dorrThemed().presentationDetents([.large])
        }
        .sheet(isPresented: $sos) {
            if let ride = state.ride { SosSheet(ride: ride) { sos = false }.dorrThemed().presentationDetents([.large]) }
        }
    }

    @ViewBuilder
    private func content(_ d: SafetyCenterDto) -> some View {
        let prefs = d.preferences ?? SafetyPreferencesDto()
        let number = d.emergencyNumber ?? "911"
        ScrollView {
            VStack(spacing: 12) {
                // The hero: "Your safety comes first" and the SOS button.
                VStack(spacing: 8) {
                    Text(L("rd_safety_first")).font(.dorr(13)).foregroundStyle(.white.opacity(0.85))
                    Button {
                        if state.ride != nil { sos = true } else if let url = URL(string: "tel:\(number)") { openURL(url) }
                    } label: {
                        Text(verbatim: "SOS").font(.dorr(22, .black)).foregroundStyle(.white)
                            .frame(width: 84, height: 84).background(sosRed, in: Circle())
                            .overlay(Circle().stroke(sosRed.opacity(0.3), lineWidth: 6))
                    }
                    .buttonStyle(WaPressStyle())
                    Text(verbatim: L("rd_safety_sos_hint", number)).font(.dorr(12)).foregroundStyle(.white).multilineTextAlignment(.center)
                }
                .frame(maxWidth: .infinity)
                .padding(18)
                .background(LinearGradient(colors: [AppColors.brandNavy, AppColors.brandNavy.opacity(0.82)], startPoint: .topLeading, endPoint: .bottomTrailing),
                            in: RoundedRectangle(cornerRadius: 22, style: .continuous))
                // 👥 Trusted contacts (R-220, R-221).
                SafetyCard(emoji: "👥", title: L("rd_safety_contacts"), hint: L("rd_safety_contacts_hint")) {
                    ForEach(d.contacts ?? [], id: \.id) { c in
                        Button { editing = c } label: {
                            HStack(spacing: 10) {
                                Text(verbatim: String((c.name ?? "").prefix(1))).font(.dorr(15, .bold)).foregroundStyle(wa.accent)
                                    .frame(width: 36, height: 36).background(wa.accent.opacity(0.12), in: Circle())
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(verbatim: c.name ?? "").font(.dorr(15, .semibold)).foregroundStyle(wa.settingsInk)
                                    Text(verbatim: c.phone ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut)
                                }
                                Spacer(minLength: 0)
                                pill(shareModeLabel(c.shareMode))
                            }
                            .padding(.vertical, 8)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                    if (d.contacts ?? []).count < (d.maxContacts ?? 5) {
                        Button { adding = true } label: {
                            HStack(spacing: 8) {
                                Image(systemName: "plus")
                                Text(L("rd_safety_add_contact")).font(.dorr(15, .semibold))
                                Spacer()
                            }
                            .foregroundStyle(wa.accent).padding(.vertical, 10)
                        }
                        .buttonStyle(.plain)
                    }
                }
                // 🔐 The ride code (R-229).
                SafetyCard(emoji: "🔐", title: L("rd_safety_pin"), hint: L("rd_safety_pin_hint")) {
                    ChoiceRow(options: [("policy", L("rd_safety_pin_policy")), ("always", L("rd_safety_always")), ("night", L("rd_safety_night"))],
                              selected: prefs.pinMode ?? "policy") { v in
                        data?.preferences = SafetyPreferencesDto(pinMode: v, checkinAfterMin: prefs.checkinAfterMin, seatbeltReminder: prefs.seatbeltReminder)
                        savePrefs(["pin_mode": .string(v)])
                    }
                }
                // ⏰ Check-in after arriving (R-223).
                SafetyCard(emoji: "⏰", title: L("rd_safety_checkin"), hint: L("rd_safety_checkin_hint")) {
                    ChoiceRow(options: [("0", L("rd_safety_off"))] + ["10", "20", "30"].map { ($0, L("rd_safety_minutes", $0)) },
                              selected: String(prefs.checkinAfterMin ?? 0)) { v in
                        let minutes = Int(v) ?? 0
                        data?.preferences = SafetyPreferencesDto(pinMode: prefs.pinMode, checkinAfterMin: minutes > 0 ? minutes : nil, seatbeltReminder: prefs.seatbeltReminder)
                        savePrefs(["checkin_after_min": minutes > 0 ? .int(minutes) : .null])
                    }
                }
                // 🪢 Seatbelt (R-230) and 🛡️ Smart Ride Check (R-222 — always on).
                SafetyCard(emoji: "🪢", title: L("rd_safety_seatbelt"), hint: L("rd_safety_seatbelt_hint"), trailing: {
                    RedToggle(on: prefs.seatbeltReminder ?? true) {
                        let on = !(prefs.seatbeltReminder ?? true)
                        data?.preferences = SafetyPreferencesDto(pinMode: prefs.pinMode, checkinAfterMin: prefs.checkinAfterMin, seatbeltReminder: on)
                        savePrefs(["seatbelt_reminder": .bool(on)])
                    }
                })
                SafetyCard(emoji: "🛡️", title: L("rd_safety_ride_check"), hint: L("rd_safety_ride_check_hint"))
                // ⭐ Favourite captains (R-226) and 🚫 blocked ones (R-225).
                if let favs = d.favorites, !favs.isEmpty {
                    SafetyCard(emoji: "⭐", title: L("rd_safety_favorites"), hint: nil) {
                        ForEach(favs, id: \.driverId) { f in
                            PersonRow(name: f.name ?? "", sub: f.rating.map { String(format: "%.1f ★", $0) }) {
                                Task { _ = try? await RidesApi.unfavorite(f.driverId); await load() }
                            }
                        }
                    }
                }
                if let blocked = d.blocked, !blocked.isEmpty {
                    SafetyCard(emoji: "🚫", title: L("rd_safety_blocked"), hint: L("rd_safety_blocked_hint")) {
                        ForEach(blocked, id: \.id) { b in
                            PersonRow(name: b.name ?? "", sub: nil) { Task { _ = try? await RidesApi.unblock(b.id); await load() } }
                        }
                    }
                }
                if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger) }
            }
            .padding(.horizontal, 16).padding(.bottom, 24)
        }
    }

    private func pill(_ text: String) -> some View {
        Text(verbatim: text).font(.dorr(11, .semibold)).foregroundStyle(wa.accent)
            .padding(.horizontal, 10).padding(.vertical, 4).background(wa.accent.opacity(0.1), in: Capsule())
    }
}

private func shareModeLabel(_ mode: String?) -> String {
    switch mode {
    case "all": return L("rd_safety_every_ride")
    case "night": return L("rd_safety_night")
    default: return L("rd_safety_manual")
    }
}

private struct SafetyCard<Trailing: View, Content: View>: View {
    @Environment(\.wa) private var wa
    let emoji: String
    let title: String
    let hint: String?
    let trailing: Trailing
    let content: Content

    init(emoji: String, title: String, hint: String?, @ViewBuilder trailing: () -> Trailing = { EmptyView() }, @ViewBuilder content: () -> Content = { EmptyView() }) {
        self.emoji = emoji
        self.title = title
        self.hint = hint
        self.trailing = trailing()
        self.content = content()
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 10) {
                Text(verbatim: emoji).font(.system(size: 18)).frame(width: 38, height: 38).background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: title).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                    if let hint { Text(verbatim: hint).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                }
                Spacer(minLength: 0)
                trailing
            }
            content
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

private struct ChoiceRow: View {
    @Environment(\.wa) private var wa
    let options: [(String, String)]
    let selected: String
    let onPick: (String) -> Void

    var body: some View {
        FlowLayout(spacing: 8) {
            ForEach(options, id: \.0) { key, label in
                let on = key == selected
                Button { onPick(key) } label: {
                    Text(verbatim: label).font(.dorr(13, on ? .bold : .regular)).foregroundStyle(on ? .white : wa.settingsInk)
                        .padding(.horizontal, 14).padding(.vertical, 8).background(on ? wa.accent : wa.settingsBackground, in: Capsule())
                }
                .buttonStyle(.plain)
                .animation(.easeInOut(duration: 0.2), value: on)
            }
        }
    }
}

private struct PersonRow: View {
    @Environment(\.wa) private var wa
    let name: String
    let sub: String?
    let onRemove: () -> Void

    var body: some View {
        HStack(spacing: 10) {
            Text(verbatim: String(name.prefix(1))).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).frame(width: 34, height: 34).background(wa.settingsBackground, in: Circle())
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: name).font(.dorr(15, .semibold)).foregroundStyle(wa.settingsInk)
                if let sub { Text(verbatim: sub).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            }
            Spacer(minLength: 0)
            Button(action: onRemove) { Image(systemName: "xmark").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.settingsMut).frame(width: 28, height: 28) }
                .buttonStyle(.plain)
        }
        .padding(.vertical, 6)
    }
}

/// Add or edit a trusted contact: name, phone, when they get the link, "arrived safely".
private struct ContactSheet: View {
    @Environment(\.wa) private var wa
    let contact: TrustedContactDto?
    let onClose: () -> Void
    let onSaved: () -> Void
    @State private var name = ""
    @State private var phone = ""
    @State private var mode = "all"
    @State private var arrival = true
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 10) {
                Text(L(contact == nil ? "rd_safety_add_contact" : "rd_safety_edit_contact")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                DorrTextArea(value: Binding(get: { name }, set: { name = String($0.prefix(80)) }), label: L("rd_safety_name"), singleLine: true)
                DorrTextArea(value: Binding(get: { phone }, set: { phone = String($0.prefix(18)) }), label: L("rd_safety_phone"), singleLine: true).keyboardType(.phonePad)
                Text(L("rd_safety_when_share")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                ChoiceRow(options: [("all", L("rd_safety_every_ride")), ("night", L("rd_safety_night")), ("manual", L("rd_safety_manual"))], selected: mode) { mode = $0 }
                HStack {
                    Text(L("rd_safety_notify_arrival")).font(.dorr(15)).foregroundStyle(wa.settingsInk)
                    Spacer()
                    RedToggle(on: arrival) { arrival.toggle() }
                }
                if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger) }
                AccentButton(text: L("rd_safety_save"), enabled: !name.trimmingCharacters(in: .whitespaces).isEmpty && phone.count >= 8, busy: busy) { save() }
                if let contact {
                    Button {
                        Task { _ = try? await RidesApi.deleteContact(contact.id); onSaved(); onClose() }
                    } label: {
                        Text(L("rd_safety_remove")).font(.dorr(15, .semibold)).foregroundStyle(AppColors.danger).frame(maxWidth: .infinity).padding(10)
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(20)
        }
        .background(wa.settingsCard.ignoresSafeArea())
        .onAppear {
            name = contact?.name ?? ""
            phone = contact?.phone ?? ""
            mode = contact?.shareMode ?? "all"
            arrival = contact?.notifyArrival ?? true
        }
    }

    private func save() {
        busy = true
        error = nil
        Task {
            let body: [String: JSONValue] = ["name": .string(name.trimmingCharacters(in: .whitespaces)), "phone": .string(phone.trimmingCharacters(in: .whitespaces)),
                                             "share_mode": .string(mode), "notify_arrival": .bool(arrival)]
            do {
                if let contact { _ = try await RidesApi.updateContact(contact.id, body) } else { _ = try await RidesApi.addContact(body) }
                onSaved()
                onClose()
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
        }
    }
}

/// 📤 Share: to my trusted contacts (by name), or the link through any app (R-111, R-220).
struct ShareRideSheet: View {
    @Environment(\.wa) private var wa
    let ride: RideDto
    let onClose: () -> Void
    @State private var contacts: [TrustedContactDto]?
    @State private var picked: Set<Int> = []
    @State private var message: String?
    @State private var busy = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                Text(L("rd_share_trip")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).padding(.bottom, 10)
                if let list = contacts, !list.isEmpty {
                    Text(L("rd_safety_contacts")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    ForEach(list, id: \.id) { c in
                        let on = picked.contains(c.id)
                        Button { if on { picked.remove(c.id) } else { picked.insert(c.id) } } label: {
                            HStack(spacing: 10) {
                                Image(systemName: on ? "checkmark.square.fill" : "square").font(.system(size: 20)).foregroundStyle(on ? wa.accent : wa.settingsMut)
                                Text(verbatim: c.name ?? "").font(.dorr(15)).foregroundStyle(wa.settingsInk)
                                Spacer()
                                Text(verbatim: c.phone ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut)
                            }
                            .padding(.vertical, 8)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                    AccentButton(text: L("rd_safety_send_contacts"), enabled: !picked.isEmpty, busy: busy) {
                        busy = true
                        Task {
                            do {
                                let r = try await RidesApi.shareWithContacts(ride.uuid, ["ids": .array(picked.map { .int($0) })])
                                message = r.message
                            } catch {
                                message = error.apiFailure.message
                            }
                            busy = false
                        }
                    }
                    .padding(.vertical, 10)
                } else if contacts != nil {
                    Text(L("rd_safety_no_contacts")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.bottom, 10)
                }
                Button { Task { message = await shareRide(ride) } } label: {
                    Text(L("rd_safety_share_link")).font(.dorr(15, .semibold)).foregroundStyle(wa.settingsInk)
                        .frame(maxWidth: .infinity, minHeight: 50)
                        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.settingsInk.opacity(0.15), lineWidth: 1))
                }
                .buttonStyle(WaPressStyle())
                if let message { Text(verbatim: message).font(.dorr(13)).foregroundStyle(AppColors.success).padding(.top, 8) }
            }
            .padding(20)
        }
        .background(wa.settingsCard.ignoresSafeArea())
        .task {
            let list = (try? await RidesApi.safety().data?.contacts) ?? []
            contacts = list
            picked = Set(list.map(\.id))
        }
    }
}

/// 🚩 Report a problem on the ride (R-117).
struct ReportSheet: View {
    @Environment(\.wa) private var wa
    let ride: RideDto
    let onClose: () -> Void
    @State private var type: String?
    @State private var note = ""
    @State private var busy = false
    @State private var done: String?
    @State private var error: String?

    private let types = [("unsafe_driving", "rd_report_unsafe_driving"), ("abuse", "rd_report_abuse"), ("harassment", "rd_report_harassment"),
                         ("vehicle_issue", "rd_report_vehicle"), ("other", "rd_report_other")]

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 10) {
                Text(L("rd_report_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                if let done {
                    Text(verbatim: done).font(.dorr(15, .semibold)).foregroundStyle(AppColors.success)
                    AccentButton(text: L("rd_done"), action: onClose)
                } else {
                    FlowLayout(spacing: 8) {
                        ForEach(types, id: \.0) { key, label in
                            let on = key == type
                            Button { type = key } label: {
                                Text(L(label)).font(.dorr(13)).foregroundStyle(on ? .white : wa.settingsInk)
                                    .padding(.horizontal, 14).padding(.vertical, 8).background(on ? sosRed : wa.settingsBackground, in: Capsule())
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    DorrTextArea(value: Binding(get: { note }, set: { note = String($0.prefix(500)) }), placeholder: L("rd_report_note"), minHeight: 90)
                    if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger) }
                    AccentButton(text: L("rd_report_send"), enabled: type != nil, busy: busy, color: sosRed) {
                        busy = true
                        error = nil
                        Task {
                            var body: [String: JSONValue] = ["type": .string(type ?? "other")]
                            let n = note.trimmingCharacters(in: .whitespacesAndNewlines)
                            if !n.isEmpty { body["note"] = .string(n) }
                            do { done = try await RidesApi.report(ride.uuid, body).message ?? "" } catch { self.error = error.apiFailure.message }
                            busy = false
                        }
                    }
                }
            }
            .padding(20)
        }
        .background(wa.settingsCard.ignoresSafeArea())
    }
}

/// "Are you OK?" — a long stop, a detour, or the check-in after arriving (R-222, R-223).
struct SafetyCheckDialog: View {
    @Environment(\.wa) private var wa
    @Environment(\.openURL) private var openURL
    let rideUuid: String
    let check: SafetyCheckDto
    let onDone: () -> Void
    @State private var busy = false

    var body: some View {
        DialogCard {
            VStack(spacing: 12) {
                Image(systemName: "shield.fill").font(.system(size: 34)).foregroundStyle(sosRed)
                Text(L(check.kind == "checkin" ? "rd_check_arrived" : "rd_check_ok")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                Text(L(check.reason == "long_stop" ? "rd_check_long_stop" : (check.reason == "deviation" ? "rd_check_deviation" : "rd_check_checkin")))
                    .font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
                AccentButton(text: L("rd_check_im_ok"), busy: busy, color: AppColors.success) { answer("ok") }
                AccentButton(text: L("rd_check_help"), color: sosRed) { answer("help") }
            }
            .padding(20)
        }
    }

    private func answer(_ value: String) {
        busy = true
        Task {
            var body: [String: JSONValue] = ["answer": .string(value)]
            if value == "help", let here = await RideLocation.shared.current() {
                body["lat"] = .double(here.latitude)
                body["lng"] = .double(here.longitude)
            }
            let number = try? await RidesApi.answerCheck(rideUuid, check.uuid, body).data?.emergencyNumber
            busy = false
            onDone()
            if value == "help", let number, let url = URL(string: "tel:\(number)") { openURL(url) }
        }
    }
}

/// Before getting in (R-224): check the car, and "this isn't the car".
struct VehicleCheckCard: View {
    @Environment(\.wa) private var wa
    let onMismatch: () -> Void
    @State private var confirm = false

    var body: some View {
        HStack(spacing: 8) {
            Text(verbatim: "🔍").font(.system(size: 18))
            VStack(alignment: .leading, spacing: 0) {
                Text(L("rd_vehicle_check")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                Text(L("rd_vehicle_check_hint")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
            Spacer(minLength: 0)
            Button { confirm = true } label: { Text(L("rd_vehicle_not_it")).font(.dorr(12, .bold)).foregroundStyle(AppColors.danger).padding(6) }
                .buttonStyle(.plain)
        }
        .padding(12)
        .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .padding(.top, 10)
        .dorrDialog(isPresented: $confirm) {
            FlAlert(title: L("rd_vehicle_not_it"), content: {
                Text(L("rd_vehicle_not_it_body")).font(.dorr(14)).foregroundStyle(wa.settingsMut)
            }, confirm: L("rd_vehicle_cancel_free"), confirmColor: AppColors.danger, onConfirm: { confirm = false; onMismatch() },
               dismiss: L("rd_keep"), onDismiss: { confirm = false })
        }
    }
}

extension RidesState {
    func reportVehicleMismatch() async {
        guard let uuid = ride?.uuid else { return }
        _ = try? await RidesApi.vehicleMismatch(uuid, [:], key: RidesApi.key())
        if let fresh = try? await RidesApi.ride(uuid).data { ride = fresh }
    }
}

/// 🪢 Fasten your seatbelt — once, as the ride starts (R-230).
struct SeatbeltBanner: View {
    @Environment(\.wa) private var wa
    let rideUuid: String
    @State private var hidden: String?

    var body: some View {
        if hidden != rideUuid {
            Button { withAnimation { hidden = rideUuid } } label: {
                HStack(spacing: 8) {
                    Text(verbatim: "🪢").font(.system(size: 18))
                    Text(L("rd_seatbelt")).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                    Spacer(minLength: 0)
                    Image(systemName: "xmark").font(.system(size: 13)).foregroundStyle(wa.settingsMut)
                }
                .padding(12)
                .background(AppColors.brandOrange.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            .buttonStyle(.plain)
            .padding(.top, 10)
            .transition(.opacity)
        }
    }
}

/// After the ride: keep this captain as a favourite (R-226), or never be matched again (R-225).
struct AfterRideChoices: View {
    @Environment(\.wa) private var wa
    let ride: RideDto
    @State private var favorite = false
    @State private var blocked = false

    var body: some View {
        HStack(spacing: 8) {
            Button {
                favorite.toggle()
                let on = favorite
                Task { _ = try? await RidesApi.favorite(ride.uuid, ["on": .bool(on)]) }
            } label: {
                HStack(spacing: 6) {
                    Image(systemName: "star.fill").font(.system(size: 15)).foregroundStyle(favorite ? AppColors.warning : wa.settingsMut)
                    Text(L(favorite ? "rd_favorite_on" : "rd_favorite")).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk)
                }
                .frame(maxWidth: .infinity).padding(10)
                .background(favorite ? AppColors.warning.opacity(0.15) : wa.settingsBackground, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            .buttonStyle(.plain)
            .disabled(blocked)
            Button {
                blocked = true
                favorite = false
                Task { _ = try? await RidesApi.blockDriver(ride.uuid, [:]) }
            } label: {
                HStack(spacing: 6) {
                    Text(verbatim: "🚫").font(.system(size: 14))
                    Text(L(blocked ? "rd_blocked_done" : "rd_block_driver")).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk)
                }
                .frame(maxWidth: .infinity).padding(10)
                .background(blocked ? AppColors.danger.opacity(0.12) : wa.settingsBackground, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            .buttonStyle(.plain)
            .disabled(blocked)
        }
        .padding(.top, 8)
        .onAppear { favorite = ride.favoriteDriver == true }
    }
}
