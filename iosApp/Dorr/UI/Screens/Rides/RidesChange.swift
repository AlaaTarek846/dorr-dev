import SwiftUI
import UIKit

// androidApp `rides/RidesChange.kt` (R-91..95), `RidesSafety.kt` (R-84..86, R-110, R-111) and `RidesLostItem.kt`
// (D-294..D-297): changing the trip during the ride, the row of actions under the captain with the SOS sheet, and
// «I left something in the car».

// MARK: - Changing the trip

/// "Change destination" · "Add a stop" — under the trip on the ride card.
struct ChangeTripLinks: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let ride: RideDto

    var body: some View {
        if ride.openDestination != true, ride.destination != nil, ride.changeRequest?.status != "pending" {
            HStack(spacing: 8) {
                if state.feature("change_destination") { chip("mappin.and.ellipse", L("rd_change_destination")) { state.changing = "destination" } }
                if state.feature("multi_stop") && (ride.stops ?? []).count < 3 { chip("plus.circle", L("rd_add_stop")) { state.changing = "add_stop" } }
            }
            .padding(.top, 10)
        }
    }

    private func chip(_ icon: String, _ label: String, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 15))
                Text(verbatim: label).font(.dorr(13, .semibold)).lineLimit(1)
            }
            .foregroundStyle(wa.accent)
            .frame(maxWidth: .infinity).padding(.horizontal, 10).padding(.vertical, 9)
            .background(wa.accent.opacity(0.08), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(WaPressStyle())
    }
}

private func signedMoney(_ minor: Int, _ ride: RideDto) -> String { (minor > 0 ? "+" : "−") + rideMoney(abs(minor), ride.currency) }

private struct ImpactRow: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String
    var bold = false
    var color: Color?

    var body: some View {
        HStack {
            Text(verbatim: label).font(.dorr(13)).foregroundStyle(wa.settingsMut)
            Spacer(minLength: 6)
            Text(verbatim: value).font(.dorr(13, bold ? .heavy : .semibold)).foregroundStyle(color ?? wa.settingsInk).lineLimit(1)
        }
        .padding(.vertical, 2)
    }
}

/// The state of a change on the ride card: waiting for the captain (a draining ring), or the answer.
struct ChangeBanner: View {
    @Environment(\.wa) private var wa
    let ride: RideDto
    @State private var dismissed: String?
    @State private var left = 0

    var body: some View {
        if let c = ride.changeRequest, dismissed != "\(c.uuid):\(c.status ?? "")" {
            Group {
                switch c.status {
                case "pending":
                    HStack(spacing: 10) {
                        ZStack {
                            Circle().stroke(AppColors.warning.opacity(0.2), lineWidth: 3)
                            Circle().trim(from: 0, to: CGFloat(left) / 30).stroke(AppColors.warning, style: StrokeStyle(lineWidth: 3, lineCap: .round))
                                .rotationEffect(.degrees(-90)).animation(.linear(duration: 0.9), value: left)
                            Text(verbatim: "\(left)").font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                        }
                        .frame(width: 40, height: 40)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(L("rd_change_waiting")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                            Text(verbatim: c.place?.address ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                        }
                        Spacer(minLength: 0)
                    }
                    .padding(12)
                    .background(AppColors.warning.opacity(0.1), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    // The captain's time to answer, on the phone's clock.
                    .task(id: c.uuid) {
                        left = c.secondsLeft ?? 0
                        while left > 0 {
                            try? await Task.sleep(nanoseconds: 1_000_000_000)
                            if Task.isCancelled { return }
                            left -= 1
                        }
                    }
                case "applied":
                    Button { dismissed = "\(c.uuid):\(c.status ?? "")" } label: {
                        VStack(alignment: .leading, spacing: 0) {
                            HStack(spacing: 8) {
                                Image(systemName: "checkmark.circle.fill").foregroundStyle(AppColors.success)
                                Text(L("rd_change_applied")).font(.dorr(15, .heavy)).foregroundStyle(AppColors.success)
                            }
                            .padding(.bottom, 6)
                            ImpactRow(label: L(c.type == "add_stop" ? "rd_change_new_stop" : "rd_change_new_destination"), value: c.place?.address ?? "")
                            if let d = c.route?.distanceM { ImpactRow(label: L("rd_change_total_distance"), value: L("rd_km", String(format: "%.1f", Double(d) / 1000))) }
                            if let s = c.route?.durationS { ImpactRow(label: L("rd_change_time_left"), value: "~" + minutesText(s, L("rd_minutes"), L("rd_hours"))) }
                            if let t = c.newTotalMinor { ImpactRow(label: L("rd_change_new_price"), value: rideMoney(t, ride.currency), bold: true) }
                            if let delta = c.priceDeltaMinor, delta != 0 {
                                Text(verbatim: L("rd_change_on_invoice", signedMoney(delta, ride))).font(.dorr(11)).foregroundStyle(wa.settingsMut).padding(.top, 4)
                            }
                        }
                        .padding(12)
                        .background(AppColors.success.opacity(0.1), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                    .buttonStyle(.plain)
                default:
                    Button { dismissed = "\(c.uuid):\(c.status ?? "")" } label: {
                        HStack(spacing: 8) {
                            Image(systemName: "exclamationmark.triangle").foregroundStyle(wa.settingsMut)
                            Text(L(c.status == "rejected" ? "rd_change_rejected" : "rd_change_expired")).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                            Spacer(minLength: 0)
                        }
                        .padding(12)
                        .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.top, 10)
            .transition(.opacity)
        }
    }
}

/// Wireflow 6أ: the current destination, the search, then the effect and "Send the change".
struct ChangeTripPage: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let onClose: () -> Void
    @State private var query = ""
    @State private var results: [RidePoint] = []
    @State private var searching = false
    @State private var preview: RideChangeDto?
    @State private var picked: RidePoint?
    @State private var busy = false
    @State private var error: String?

    private var adding: Bool { state.changing == "add_stop" }

    var body: some View {
        if let ride = state.ride {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    RideRoundButton(icon: "arrow.backward", label: L("common_back")) {
                        if preview != nil { preview = nil; picked = nil } else { onClose() }
                    }
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L(adding ? "rd_add_stop" : "rd_change_destination")).font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk)
                        Text(L("rd_change_running")).font(.dorr(12)).foregroundStyle(AppColors.success)
                    }
                    Spacer()
                }
                .padding(12)
                // The current destination.
                HStack(spacing: 8) {
                    Image(systemName: "mappin.circle.fill").foregroundStyle(AppColors.brandOrange)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("rd_change_current")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                        Text(verbatim: ride.destination?.address ?? "").font(.dorr(15, .semibold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    }
                    Spacer(minLength: 0)
                }
                .padding(12)
                .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .padding(.horizontal, 16)
                .padding(.bottom, 10)
                if let p = preview { effect(ride, p) } else { search(ride) }
            }
            .background(wa.settingsBackground.ignoresSafeArea())
            .task(id: query) {
                guard query.trimmingCharacters(in: .whitespaces).count >= 2 else { results = []; return }
                try? await Task.sleep(nanoseconds: 350_000_000)
                guard !Task.isCancelled else { return }
                searching = true
                results = await searchPlaces(query, near: ride.destination.map { .init(latitude: $0.lat, longitude: $0.lng) })
                searching = false
            }
        }
    }

    @ViewBuilder
    private func search(_ ride: RideDto) -> some View {
        HStack(spacing: 8) {
            Image(systemName: "magnifyingglass").foregroundStyle(wa.accent)
            TextField(L(adding ? "rd_change_search_stop" : "rd_change_search"), text: $query).font(.dorr(15)).foregroundStyle(wa.settingsInk).tint(wa.accent)
            if searching || busy { ProgressView().tint(wa.accent).scaleEffect(0.8) }
        }
        .padding(.horizontal, 16).frame(minHeight: 52)
        .background(wa.night ? wa.bg : Color(hex: 0xFBF7F8), in: Capsule())
        .overlay(Capsule().stroke(wa.night ? wa.line : Color(hex: 0xF3D5DB), lineWidth: 1))
        .padding(.horizontal, 16)
        if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger).padding(16) }
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 0) {
                ForEach(Array(results.enumerated()), id: \.offset) { _, place in
                    Button { pick(ride, place) } label: {
                        HStack(spacing: 10) {
                            Image(systemName: "mappin").font(.system(size: 15)).foregroundStyle(wa.settingsMut)
                                .frame(width: 34, height: 34).background(wa.settingsCard, in: Circle())
                            Text(verbatim: place.address).font(.dorr(15)).foregroundStyle(wa.settingsInk).lineLimit(2).multilineTextAlignment(.leading)
                            Spacer(minLength: 0)
                        }
                        .padding(.vertical, 12)
                        .contentShape(Rectangle())
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                }
            }
            .padding(.horizontal, 16).padding(.vertical, 8)
        }
    }

    /// The effect, before anything changes (R-92, R-94).
    @ViewBuilder
    private func effect(_ ride: RideDto, _ p: RideChangeDto) -> some View {
        let newTotal = p.newTotalMinor ?? 0
        let delta = p.priceDeltaMinor ?? 0
        let dm = p.distanceDeltaM ?? 0, ds = p.durationDeltaS ?? 0
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 8) {
                Image(systemName: adding ? "plus.circle" : "mappin.and.ellipse").foregroundStyle(wa.accent)
                Text(verbatim: picked?.address ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2)
            }
            Text(verbatim: [(dm >= 0 ? "+" : "−") + L("rd_km", String(format: "%.1f", Double(abs(dm)) / 1000)),
                            (ds >= 0 ? "+" : "−") + minutesText(abs(ds), L("rd_minutes"), L("rd_hours")),
                            signedMoney(delta, ride)].joined(separator: " • "))
                .font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 4)
            Text(L("rd_change_effect")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 12)
            ImpactRow(label: L("rd_change_original"), value: rideMoney(newTotal - delta, ride.currency))
            ImpactRow(label: L("rd_change_extra"), value: signedMoney(delta, ride), color: delta > 0 ? AppColors.warning : AppColors.success)
            ImpactRow(label: L("rd_change_new_price"), value: rideMoney(newTotal, ride.currency), bold: true)
            if ride.pricingMode == "metered" { Text(L("rd_change_metered")).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .padding(.horizontal, 16)
        HStack(spacing: 8) {
            Image(systemName: "hourglass").font(.system(size: 15)).foregroundStyle(AppColors.warning)
            Text(L("rd_change_driver_must_accept")).font(.dorr(13)).foregroundStyle(wa.settingsInk)
            Spacer(minLength: 0)
        }
        .padding(12)
        .background(AppColors.warning.opacity(0.1), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        .padding(.horizontal, 16).padding(.top, 10)
        if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger).padding(.horizontal, 16).padding(.vertical, 8) }
        Spacer()
        AccentButton(text: L("rd_change_send"), busy: busy) { send(ride, p) }.padding(16)
    }

    private func pick(_ ride: RideDto, _ point: RidePoint) {
        picked = point
        busy = true
        error = nil
        Task {
            do {
                preview = try await RidesApi.changePreview(ride.uuid, [
                    "type": .string(adding ? "add_stop" : "destination"), "lat": .double(point.lat), "lng": .double(point.lng),
                    "address": .string(String(point.address.prefix(255))),
                ]).data
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
        }
    }

    private func send(_ ride: RideDto, _ p: RideChangeDto) {
        busy = true
        error = nil
        Task {
            do {
                _ = try await RidesApi.changeSend(ride.uuid, p.uuid)
                if let fresh = try? await RidesApi.ride(ride.uuid).data { state.ride = fresh }
                onClose()
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
        }
    }
}

// MARK: - The safety row and SOS

private let sosRed = Color(hex: 0xA32D2D)

/// 📞 Call · 💬 Message (with the unread count) · 📤 Share · 🚨 SOS — equal tiles, SOS in red. «Call» is the in-app
/// call (LiveKit) or the phone's own call.
struct SafetyActions: View {
    @Environment(\.wa) private var wa
    @Environment(\.openURL) private var openURL
    let onChat: () -> Void
    let onShare: () -> Void
    let onSos: () -> Void
    let sharing: Bool
    var unread = 0
    var callPhone: String?
    /// O-09: call the captain inside the app (free, no numbers shown).
    var onAppCall: (() -> Void)?
    @State private var callMenu = false

    private var phoneUrl: URL? { callPhone.flatMap { URL(string: "tel:\($0.filter { $0.isNumber || $0 == "+" })") } }

    var body: some View {
        HStack(spacing: 8) {
            // O-09: call the captain — inside the app (free, no numbers shown) or the phone's own call.
            if phoneUrl != nil || onAppCall != nil {
                tile("phone.fill", L("rd_call"), AppColors.success) {
                    if let onAppCall, phoneUrl != nil { callMenu = true } else if let onAppCall { onAppCall() } else if let url = phoneUrl { openURL(url) }
                }
                .confirmationDialog(L("rd_call"), isPresented: $callMenu) {
                    Button(L("rc_in_app")) { onAppCall?() }
                    if let url = phoneUrl { Button(L("rc_phone")) { openURL(url) } }
                }
            }
            tile("bubble.left.and.bubble.right.fill", L("rd_chat"), wa.accent, badge: unread, action: onChat)
            tile("square.and.arrow.up", L("rd_share"), wa.accent, busy: sharing, action: onShare)
            tile("shield.fill", L("rd_sos"), sosRed, danger: true, action: onSos)
        }
    }

    private func tile(_ icon: String, _ label: String, _ tint: Color, busy: Bool = false, danger: Bool = false, badge: Int = 0, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 4) {
                ZStack(alignment: .topTrailing) {
                    if busy { ProgressView().tint(tint).frame(width: 22, height: 22) } else {
                        Image(systemName: icon).font(.system(size: 18, weight: .semibold)).foregroundStyle(tint).frame(width: 24, height: 22)
                    }
                    // O-10: messages waiting unread.
                    if badge > 0 {
                        Text(verbatim: badge > 9 ? "9+" : "\(badge)").font(.dorr(10, .bold)).foregroundStyle(.white)
                            .frame(width: 18, height: 18).background(AppColors.danger, in: Circle()).offset(x: 10, y: -6)
                    }
                }
                Text(verbatim: label).font(.dorr(12, .semibold)).foregroundStyle(danger ? sosRed : wa.settingsInk).lineLimit(1)
            }
            .frame(maxWidth: .infinity).padding(.vertical, 10)
            .background(danger ? sosRed.opacity(0.06) : .clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(danger ? sosRed.opacity(0.25) : wa.settingsInk.opacity(0.1), lineWidth: 1))
        }
        .buttonStyle(WaPressStyle())
        .disabled(busy)
    }
}

/// Gets (or makes) the ride's family link and opens the share sheet with it; returns an error to show, if any.
@MainActor
func shareRide(_ ride: RideDto) async -> String? {
    do {
        guard let url = try await RidesApi.share(ride.uuid).data?.url else { return nil }
        shareText(L("rd_share_text", url))
        return nil
    } catch {
        return error.apiFailure.message
    }
}

/// SOS: hold the red button to alert the safety team (with where you are); call the emergency number.
struct SosSheet: View {
    @Environment(\.wa) private var wa
    @Environment(\.openURL) private var openURL
    let ride: RideDto
    let onClose: () -> Void
    var onReport: (() -> Void)?
    @State private var sent = false
    @State private var sending = false
    @State private var error: String?
    @State private var number = ""
    @State private var sharing = false
    @State private var pressed = false
    @State private var ring = false

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 8) {
                Image(systemName: "shield.fill").foregroundStyle(sosRed)
                Text(L("rd_sos_title")).font(.dorr(19, .heavy)).foregroundStyle(wa.settingsInk)
            }
            // The big button: two rings breathing out of it; hold it to send.
            ZStack {
                if !sent {
                    ForEach(0..<2, id: \.self) { i in
                        Circle().fill(sosRed.opacity(0.22)).frame(width: 190, height: 190)
                            .scaleEffect(ring ? 1 : 0.62).opacity(ring ? 0 : 1)
                            .animation(.linear(duration: 1.6).repeatForever(autoreverses: false).delay(Double(i) * 0.8), value: ring)
                    }
                }
                ZStack {
                    Circle().fill(sent ? AppColors.success : sosRed)
                    if sent {
                        Image(systemName: "checkmark").font(.system(size: 54, weight: .bold)).foregroundStyle(.white)
                    } else if sending {
                        ProgressView().tint(.white).scaleEffect(1.4)
                    } else {
                        Text(verbatim: "SOS").font(.dorr(34, .black)).foregroundStyle(.white)
                    }
                }
                .frame(width: 128, height: 128)
                .scaleEffect(pressed ? 0.92 : 1)
                .animation(.spring(response: 0.3, dampingFraction: 0.5), value: pressed)
                .onLongPressGesture(minimumDuration: 0.6, pressing: { pressed = $0 }) { alert() }
            }
            .frame(width: 190, height: 190)
            .padding(.vertical, 18)
            .onAppear { ring = true; number = ride.safety?.emergencyNumber ?? "911" }
            Text(L(sent ? "rd_sos_sent" : "rd_sos_hold")).font(.dorr(14, sent ? .bold : .regular)).foregroundStyle(sent ? AppColors.success : wa.settingsMut)
                .multilineTextAlignment(.center)
            if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger).multilineTextAlignment(.center).padding(.top, 6) }
            // The emergency number: the phone dials it.
            Button {
                if let url = URL(string: "tel:\(number)") { openURL(url) }
            } label: {
                HStack(spacing: 8) {
                    Image(systemName: "phone.fill")
                    Text(verbatim: L("rd_sos_call", number)).font(.dorr(16, .bold))
                }
                .foregroundStyle(.white)
                .frame(maxWidth: .infinity, minHeight: 54)
                .background(sosRed, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            }
            .buttonStyle(WaPressStyle())
            .padding(.top, 18)
            Button {
                sharing = true
                Task { if let e = await shareRide(ride) { error = e }; sharing = false }
            } label: {
                HStack(spacing: 8) {
                    Image(systemName: "square.and.arrow.up")
                    Text(L("rd_share_trip")).font(.dorr(15, .semibold))
                }
                .foregroundStyle(wa.settingsInk)
                .frame(maxWidth: .infinity, minHeight: 50)
                .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.settingsInk.opacity(0.15), lineWidth: 1))
            }
            .buttonStyle(WaPressStyle())
            .disabled(sharing)
            .padding(.top, 10)
            // 🚩 Not an emergency: report a problem on the ride (R-117).
            if let onReport {
                Button(action: onReport) { Text(L("rd_report_title")).font(.dorr(15, .semibold)).foregroundStyle(sosRed).frame(maxWidth: .infinity).padding(10) }
                    .buttonStyle(.plain)
                    .padding(.top, 10)
            }
        }
        .padding(.horizontal, 22).padding(.top, 22).padding(.bottom, 28)
        .frame(maxHeight: .infinity, alignment: .top)
        .background(wa.settingsCard.ignoresSafeArea())
    }

    private func alert() {
        guard !sent, !sending else { return }
        sending = true
        error = nil
        UIImpactFeedbackGenerator(style: .heavy).impactOccurred()
        Task {
            var body: [String: JSONValue] = [:]
            if let here = await RideLocation.shared.current() {
                body["lat"] = .double(here.latitude)
                body["lng"] = .double(here.longitude)
            }
            do {
                let result = try await RidesApi.sos(ride.uuid, body).data
                sent = true
                if let n = result?.emergencyNumber { number = n }
            } catch {
                self.error = error.apiFailure.message
            }
            sending = false
        }
    }
}

// MARK: - Lost items

/// «I left something in the car» on a finished ride — and the report's follow-up.
struct LostItemRow: View {
    @Environment(\.wa) private var wa
    let rideUuid: String
    @State private var open = false

    var body: some View {
        Button { open = true } label: {
            HStack(spacing: 8) {
                Image(systemName: "shippingbox.fill").font(.system(size: 17)).foregroundStyle(wa.accent)
                Text(L("lost_left_something")).font(.dorr(13.5, .semibold)).foregroundStyle(wa.settingsInk)
                Spacer(minLength: 0)
            }
            .padding(12)
            .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        }
        .buttonStyle(WaPressStyle())
        .padding(.top, 10)
        .sheet(isPresented: $open) { LostItemSheet(rideUuid: rideUuid).dorrThemed().presentationDetents([.medium, .large]) }
    }
}

private struct LostItemSheet: View {
    @Environment(\.wa) private var wa
    let rideUuid: String
    @State private var item: LostItemDto?
    @State private var loaded = false
    @State private var text = ""
    @State private var error: String?
    @State private var busy = false

    private func statusText(_ s: String?) -> String {
        switch s {
        case "found": return L("lost_st_found")
        case "not_found": return L("lost_st_not_found")
        case "returned": return L("lost_st_returned")
        case "closed": return L("lost_st_closed")
        default: return L("lost_st_reported")
        }
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 10) {
                Text(L("lost_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                if loaded {
                    if let i = item { follow(i) } else { report }
                }
            }
            .padding(18)
        }
        .background(wa.settingsCard.ignoresSafeArea())
        .task { await reloadLost() }
        // The office's answer (found, returned…) arrives as an event: one reload.
        .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { e in if e.name.hasPrefix("lost.") { Task { await reloadLost() } } }
    }

    private func reloadLost() async {
        item = ((try? await RidesApi.lostItems().data) ?? []).first { $0.ride?.uuid == rideUuid }
        loaded = true
    }

    @ViewBuilder
    private var report: some View {
        Text(L("lost_hint")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
        DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(500)) }), placeholder: L("lost_what"), minHeight: 80)
        if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(AppColors.danger) }
        AccentButton(text: L("lost_report"), enabled: text.trimmingCharacters(in: .whitespaces).count >= 3 && !busy, busy: busy) {
            busy = true
            error = nil
            Task {
                do {
                    item = try await RidesApi.reportLostItem(rideUuid, ["description": .string(text.trimmingCharacters(in: .whitespacesAndNewlines))]).data
                    text = ""
                } catch {
                    self.error = error.apiFailure.message
                }
                busy = false
            }
        }
    }

    @ViewBuilder
    private func follow(_ i: LostItemDto) -> some View {
        Text(verbatim: i.description ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
        Text(verbatim: statusText(i.status)).font(.dorr(13, .semibold)).foregroundStyle(wa.accent)
        if let plan = i.returnPlan, !plan.isEmpty { Text(verbatim: "📍 \(plan)").font(.dorr(13)).foregroundStyle(wa.settingsInk) }
        // D-296: messages with the captain — never numbers.
        ForEach(i.messages ?? [], id: \.id) { m in
            let mine = m.mine == true
            HStack {
                if mine { Spacer(minLength: 40) }
                Text(verbatim: m.body ?? "").font(.dorr(14)).foregroundStyle(mine ? .white : wa.settingsInk)
                    .padding(10).background(mine ? wa.accent : wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                if !mine { Spacer(minLength: 40) }
            }
        }
        if !["returned", "closed"].contains(i.status ?? "") {
            HStack(spacing: 8) {
                DorrTextArea(value: Binding(get: { text }, set: { text = String($0.prefix(1000)) }), placeholder: L("lost_message"), singleLine: true)
                Button {
                    let body = text.trimmingCharacters(in: .whitespacesAndNewlines)
                    Task {
                        if let fresh = try? await RidesApi.lostItemMessage(i.uuid, ["body": .string(body)]).data { item = fresh }
                        text = ""
                    }
                } label: {
                    Image(systemName: "paperplane.fill").foregroundStyle(.white).flipsForRightToLeftLayoutDirection(true)
                        .frame(width: 44, height: 44).background(text.isEmpty ? wa.settingsMut.opacity(0.3) : wa.accent, in: Circle())
                }
                .buttonStyle(.plain)
                .disabled(text.trimmingCharacters(in: .whitespaces).isEmpty)
            }
            Button {
                Task { if let fresh = try? await RidesApi.closeLostItem(i.uuid).data { item = fresh } }
            } label: {
                Text(L("lost_close")).font(.dorr(12.5, .semibold)).foregroundStyle(wa.settingsMut).padding(8)
            }
            .buttonStyle(.plain)
        }
    }
}
