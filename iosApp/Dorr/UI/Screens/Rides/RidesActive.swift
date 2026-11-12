import SwiftUI
import UIKit

// androidApp `rides/RidesActive.kt` (+ PaymentSwitch, EtaChip, TierBadge, WaitingCountdown, BeneficiaryChip of
// `RidesTripDetails.kt`, the cancel sheet of `RidesPickupUi.kt` and the chat of `RidesChatUi.kt`): the ride after
// booking — searching, the captain coming, on the way, and the end (receipt + rating + tip).
//
// The server is the truth, and it tells us when it changes (real-time on the ride's channel) — nothing asks it on a
// timer. A reload on open, on return to the app and after a lost connection catches up on what was missed.

func parseRideTime(_ iso: String?) -> Date? {
    guard let iso else { return nil }
    let f = ISO8601DateFormatter()
    f.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
    if let d = f.date(from: iso) { return d }
    f.formatOptions = [.withInternetDateTime]
    return f.date(from: iso)
}

/// The ride after booking: searching, the captain coming, on the way, and the end.
@MainActor
struct RideActivePanel: View {
    @Environment(\.wa) private var wa
    @Environment(\.scenePhase) private var phase
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject var state: RidesState
    let onExit: () -> Void
    @State private var chatOpen = false
    @State private var cancelOpen = false

    private func reload() async {
        guard let uuid = state.ride?.uuid, let fresh = try? await RidesApi.ride(uuid).data else { return }
        if fresh != state.ride { state.ride = fresh }
    }

    private func onEvent(_ e: ChatEvent) {
        switch e.name {
        // A status change, a call's ring / answer / hang-up (O-09), a new message (O-10 — the chat tile's count).
        case "ride.changed", "ride.call", "ride.message": Task { await reload() }
        // The car moves on the map from the event itself, without a reload.
        case "ride.driver_location":
            guard var current = state.ride, current.driver != nil, let lat = e.data["lat"]?.dbl, let lng = e.data["lng"]?.dbl else { return }
            current.driver?.location = RideDriverLocationDto(lat: lat, lng: lng, heading: e.data["heading"]?.dbl.map { Int($0) }, at: e.str("at"))
            state.ride = current
        default: break
        }
    }

    private func followCall(_ ride: RideDto) {
        let session = RideCallSession.shared
        if let call = ride.call, call.status == "ringing", call.caller == "driver" {
            session.incoming(ride: ride.uuid, call: call.uuid, peer: ride.driver?.name ?? "", end: { r, c in _ = try await RidesApi.endCall(r, c) })
        } else if ride.call == nil, session.rideUuid == ride.uuid, session.phase != .calling {
            session.endedElsewhere(nil)
        }
    }

    var body: some View {
        if let ride = state.ride {
            PanelCard {
                switch ride.status {
                case "awaiting_payment":
                    Text(L("rd_awaiting_payment")).font(.dorr(17, .bold)).foregroundStyle(wa.settingsInk)
                    AccentButton(text: L("rd_pay_now")) { state.openRidePayment() }.padding(.top, 14)
                    // O-38c: not locked on the card — switch to cash / the wallet here and the search starts at once.
                    if !(ride.paymentChange ?? []).isEmpty { PaymentSwitch(state: state, ride: ride).padding(.top, 2) }
                case "scheduled":
                    RideAiBanners(ride: ride)
                    ScheduledPanel(ride: ride, onDone: { state.ride = nil; state.resetBooking() }, onCancel: { cancelOpen = true })
                case "searching":
                    Searching(state: state, ride: ride, onCancel: { cancelOpen = true })
                case "bidding":
                    BiddingPanel(state: state, onCancel: { cancelOpen = true })
                case "accepted", "arrived", "in_progress":
                    OnTheWay(state: state, ride: ride, onChat: { chatOpen = true }, onCancel: { cancelOpen = true })
                case "completed":
                    // The receipt, the rating and the tip: taller than the screen on small phones — it scrolls.
                    ScrollView(showsIndicators: false) {
                        Completed(ride: ride, onTipped: { state.ride = $0 }, onDone: {
                            let fromHistory = state.fromHistory
                            state.ride = nil
                            state.resetBooking()
                            if !fromHistory { onExit() }
                        })
                    }
                    .frame(maxHeight: 620)
                    .fixedSize(horizontal: false, vertical: true)
                default:
                    Ended(ride: ride) { state.ride = nil; state.resetBooking() }
                }
            }
            .task(id: "\(ride.uuid)|\(network.reconnectTick)") { await reload() }
            .onAppear { ChatRealtime.shared.watchRide(ride.uuid) }
            .onDisappear { ChatRealtime.shared.unwatchRide(ride.uuid) }
            .onReceive(ChatRealtime.shared.$connected.removeDuplicates().filter { $0 }) { _ in Task { await reload() } }
            .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { e in onEvent(e) }
            // O-09: the captain's call rings here; a call that's gone from the ride is over on this phone too.
            .onChange(of: "\(ride.call?.uuid ?? "")|\(ride.call?.status ?? "")") { _ in followCall(ride) }
            .onAppear { followCall(ride) }
            .onChange(of: phase) { p in if p == .active { Task { await reload() } } }
            .fullScreenCover(isPresented: $chatOpen, onDismiss: { state.ride?.unreadMessages = 0 }) {
                RideChatSheet(ride: ride) { chatOpen = false }.dorrThemed()
            }
            .sheet(isPresented: $cancelOpen) {
                CancelRideSheet(state: state) { cancelOpen = false }.dorrThemed().presentationDetents([.medium, .large])
            }
        }
    }
}

/// A calm radar while the nearest captains are asked one by one.
private struct Searching: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let ride: RideDto
    let onCancel: () -> Void
    @State private var wave = false

    var body: some View {
        VStack(spacing: 4) {
            ZStack {
                ForEach(0..<3, id: \.self) { i in
                    Circle().fill(wa.accent.opacity(0.25))
                        .scaleEffect(wave ? 1 : 0.3)
                        .opacity(wave ? 0 : 1)
                        .animation(.linear(duration: 1.8).repeatForever(autoreverses: false).delay(Double(i) * 0.6), value: wave)
                }
                Image(systemName: "car.fill").font(.system(size: 18)).foregroundStyle(.white).frame(width: 46, height: 46).background(wa.accent, in: Circle())
            }
            .frame(width: 110, height: 110)
            .onAppear { wave = true }
            Text(L("rd_searching")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
            Text(L("rd_searching_sub")).font(.dorr(13)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
            BeneficiaryChip(ride: ride)
            CircleNotice(state: state, ride: ride)
            RideAiBanners(ride: ride)
            PickupPointCard(ride: ride)
            // R-61: still time to switch how to pay.
            PaymentSwitch(state: state, ride: ride)
            Button(action: onCancel) { Text(L("rd_cancel")).font(.dorr(15, .bold)).foregroundStyle(AppColors.danger).padding(10) }
                .buttonStyle(.plain)
                .padding(.top, 16)
        }
        .frame(maxWidth: .infinity)
    }
}

@MainActor
private struct OnTheWay: View {
    @Environment(\.wa) private var wa
    @Environment(\.openURL) private var openURL
    @ObservedObject var state: RidesState
    let ride: RideDto
    let onChat: () -> Void
    let onCancel: () -> Void
    @State private var sosOpen = false
    @State private var shareOpen = false
    @State private var reportOpen = false

    var body: some View {
        let driver = ride.driver
        VStack(alignment: .leading, spacing: 0) {
            Text(L(ride.status == "arrived" ? "rd_status_arrived" : (ride.status == "in_progress" ? "rd_status_in_progress" : "rd_status_accepted")))
                .font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
            if ride.status == "arrived" { ArrivedWaiting(ride: ride) }
            if ride.status == "in_progress", let a = ride.destination?.address { Text(verbatim: a).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineLimit(1) }
            // D-129: the time and distance left — to the pickup, then to the next stop / the destination.
            EtaChip(ride: ride)
            // R-13: booked for someone else.
            BeneficiaryChip(ride: ride)
            // R-16..R-18: the approved point to meet at, and how to find it.
            PickupPointCard(ride: ride)
            // Block 06: the captain is finishing a ride nearby; a booking may be late.
            RideAiBanners(ride: ride)
            // «راحتي»: what I asked for, and what the captain said can't be done.
            RideComfortCard(ride: ride)
            HStack(spacing: 12) {
                ZStack {
                    Circle().fill(wa.accent.opacity(0.12))
                    if let url = driver?.avatarUrl, let u = URL(string: url) {
                        AsyncImage(url: u) { $0.resizable().scaledToFill() } placeholder: { Color.clear }.clipShape(Circle())
                    } else {
                        Text(verbatim: String((driver?.name ?? "").prefix(1))).font(.dorr(20, .bold)).foregroundStyle(wa.accent)
                    }
                }
                .frame(width: 54, height: 54)
                VStack(alignment: .leading, spacing: 1) {
                    HStack(spacing: 6) {
                        Text(verbatim: driver?.name ?? "").font(.dorr(16, .bold)).foregroundStyle(wa.settingsInk)
                        TierBadge(driver: driver)
                    }
                    HStack(spacing: 2) {
                        Image(systemName: "star.fill").font(.system(size: 12)).foregroundStyle(AppColors.warning)
                        Text(verbatim: driver?.rating.map { String(format: "%.1f", $0) } ?? L("rd_new_rating")).font(.dorr(13)).foregroundStyle(wa.settingsInk)
                        if let trips = driver?.tripsCount, trips > 0 { Text(verbatim: " · " + L("rd_trips", trips)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    }
                    if let v = driver?.vehicle {
                        Text(verbatim: [v.make, v.model, v.color].compactMap { $0 }.joined(separator: " ")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                }
                Spacer(minLength: 0)
                if let v = driver?.vehicle { Plate(letters: v.plateLetters, numbers: v.plateNumber, colorHex: v.colorHex) }
            }
            .padding(.top, 12)
            if let pin = ride.pin {
                HStack(spacing: 4) {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("rd_pin")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                        Text(L("rd_pin_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    Spacer(minLength: 0)
                    ForEach(Array(pin.enumerated()), id: \.offset) { _, digit in
                        Text(verbatim: String(digit)).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                            .frame(width: 30, height: 38).background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 8, style: .continuous))
                    }
                    .environment(\.layoutDirection, .leftToRight)
                }
                .padding(12)
                .background(wa.accent.opacity(0.08), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .padding(.top, 12)
            }
            // O-32: a shared ride — how many in the car, and what sharing saved.
            if let pool = ride.pool, (pool.riders ?? 1) > 1 || (pool.discountMinor ?? 0) > 0 { SharedRideBanner(pool: pool, currency: ride.currency) }
            if let h = ride.hourly { HourlyRideBanner(hourly: h, currency: ride.currency) }
            if ride.status == "accepted" || ride.status == "arrived" { PaymentSwitch(state: state, ride: ride) }
            ChangeBanner(ride: ride)
            // Before getting in: check the car (R-224); once on the way: the seatbelt (R-230).
            if ride.status == "accepted" || ride.status == "arrived" { VehicleCheckCard { Task { await state.reportVehicleMismatch() } } }
            if ride.status == "in_progress" { SeatbeltBanner(rideUuid: ride.uuid) }
            ChangeTripLinks(state: state, ride: ride)
            // The wireflow's row: 📞 Call · 💬 Message · 📤 Share · 🚨 SOS.
            // O-09: a free call inside the app (LiveKit) while the captain is on the ride — the mic asked first.
            SafetyActions(onChat: onChat, onShare: { shareOpen = true }, onSos: { sosOpen = true }, sharing: false,
                          unread: ride.unreadMessages ?? 0, callPhone: ride.callPhone,
                          onAppCall: ["accepted", "arrived", "in_progress"].contains(ride.status) ? {
                              Task { if await CallPermissions.ask(video: false) { startRideCall(ride) } }
                          } : nil)
                .padding(.top, 14)
            if ride.status != "in_progress" {
                Button(action: onCancel) {
                    Text(L("rd_cancel")).font(.dorr(15, .bold)).foregroundStyle(AppColors.danger).frame(maxWidth: .infinity).padding(10)
                }
                .buttonStyle(.plain)
                .padding(.top, 8)
            }
        }
        .sheet(isPresented: $sosOpen) {
            SosSheet(ride: ride, onClose: { sosOpen = false }, onReport: { sosOpen = false; reportOpen = true }).dorrThemed().presentationDetents([.large])
        }
        .sheet(isPresented: $shareOpen) { ShareRideSheet(ride: ride) { shareOpen = false }.dorrThemed().presentationDetents([.medium, .large]) }
        .sheet(isPresented: $reportOpen) { ReportSheet(ride: ride) { reportOpen = false }.dorrThemed().presentationDetents([.medium, .large]) }
    }
}

/// The free waiting left at the pickup, counting down on the phone's clock (then the minutes are charged).
private struct ArrivedWaiting: View {
    @Environment(\.wa) private var wa
    let ride: RideDto

    var body: some View {
        // A booked ride: the waiting starts at its time, however early the captain came (R-108).
        if let from = parseRideTime(ride.times?.waitingFrom ?? ride.times?.arrivedAt) {
            TimelineView(.periodic(from: .now, by: 1)) { context in
                if from > context.date {
                    Text(verbatim: L("rd_waiting_starts", from.formatted(date: .omitted, time: .shortened))).font(.dorr(13, .semibold)).foregroundStyle(wa.accent)
                } else {
                    let left = (ride.waiting?.freeS ?? 180) - Int(context.date.timeIntervalSince(from))
                    WaitingCountdown(left: left, warnBeforeS: ride.waiting?.warnBeforeS ?? 60)
                }
            }
        }
    }
}

/// D-122: green, then amber and pulsing near the end, then the paid minutes.
struct WaitingCountdown: View {
    let left: Int
    let warnBeforeS: Int
    @State private var pulse = false

    var body: some View {
        let warning = (1...max(warnBeforeS, 1)).contains(left)
        let color = left <= 0 ? AppColors.danger : (warning ? AppColors.warning : AppColors.success)
        let clock = "\(max(left, 0) / 60):" + String(format: "%02d", max(left, 0) % 60)
        HStack(spacing: 6) {
            Image(systemName: "hourglass.bottomhalf.filled").font(.system(size: 13)).foregroundStyle(color)
            Text(verbatim: left <= 0 ? L("rd_arrived_paid_wait") : (warning ? L("rd_wait_ending", clock) : L("rd_arrived_wait", clock)))
                .font(.dorr(13, .bold)).foregroundStyle(color)
                .environment(\.layoutDirection, .rightToLeft)
        }
        .padding(.horizontal, 12).padding(.vertical, 6)
        .background(color.opacity(0.12), in: Capsule())
        .scaleEffect(warning && pulse ? 1.06 : 1)
        .padding(.top, 4)
        .onAppear { withAnimation(.easeInOut(duration: 0.5).repeatForever()) { pulse = true } }
        .animation(.easeInOut(duration: 0.4), value: color)
    }
}

/// D-129: the time and distance left — to the pickup, then to the next stop / the destination.
struct EtaChip: View {
    @Environment(\.wa) private var wa
    let ride: RideDto
    @State private var breathe = false

    var body: some View {
        if let p = ride.progress {
            let key = p.to == "pickup" ? "rd_eta_pickup" : (p.to == "stop" ? "rd_eta_stop" : "rd_eta_destination")
            HStack(spacing: 6) {
                Circle().fill(AppColors.success).frame(width: 8, height: 8).opacity(breathe ? 1 : 0.35)
                    .onAppear { withAnimation(.easeInOut(duration: 0.9).repeatForever()) { breathe = true } }
                Image(systemName: "car.fill").font(.system(size: 13)).foregroundStyle(wa.accent)
                Text(verbatim: L(key, minutesText(p.etaS, L("rd_minutes"), L("rd_hours")), L("rd_km", String(format: "%.1f", Double(p.distanceM ?? 0) / 1000))))
                    .font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
            }
            .padding(.horizontal, 12).padding(.vertical, 6)
            .background(wa.accent.opacity(0.1), in: Capsule())
            .padding(.top, 6)
        }
    }
}

/// R-81: the captain's level as a badge (gold and diamond shine).
struct TierBadge: View {
    let driver: RideDriverDto?
    @State private var shine = false

    var body: some View {
        if let tier = driver?.tier {
            let (color, icon): (Color, String) = {
                switch tier {
                case "bronze": return (Color(hex: 0xB0793F), "medal.fill")
                case "silver": return (Color(hex: 0x8A94A6), "medal.fill")
                case "gold": return (Color(hex: 0xD4A017), "medal.fill")
                case "diamond": return (Color(hex: 0x2BA8E0), "diamond.fill")
                default: return (AppColors.success, "checkmark.seal.fill")
                }
            }()
            let shines = tier == "gold" || tier == "diamond"
            HStack(spacing: 4) {
                Image(systemName: icon).font(.system(size: 11)).foregroundStyle(color)
                Text(verbatim: driver?.tierLabel ?? tier).font(.dorr(11, .bold)).foregroundStyle(color)
            }
            .padding(.horizontal, 8).padding(.vertical, 3)
            .background(color.opacity(shines ? (shine ? 0.22 : 0.10) : 0.12), in: Capsule())
            .onAppear { if shines { withAnimation(.linear(duration: 1.4).repeatForever()) { shine = true } } }
        }
    }
}

/// R-13: booked for someone else.
struct BeneficiaryChip: View {
    @Environment(\.wa) private var wa
    let ride: RideDto

    var body: some View {
        if let name = ride.beneficiary?.name {
            HStack(spacing: 6) {
                Image(systemName: "person.badge.plus").font(.system(size: 13)).foregroundStyle(wa.accent)
                Text(verbatim: L("rd_for_other_ride", name)).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
            }
            .padding(.horizontal, 12).padding(.vertical, 6)
            .background(wa.accent.opacity(0.1), in: Capsule())
            .padding(.top, 6)
        }
    }
}

/// R-61: wallet <-> cash before the trip starts.
struct PaymentSwitch: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let ride: RideDto
    @State private var open = false
    @State private var busy: String?
    @State private var error: String?

    var body: some View {
        if let method = ride.paymentMethod {
            let options = ride.paymentChange ?? []
            VStack(alignment: .leading, spacing: 0) {
                Button { open.toggle(); error = nil } label: {
                    HStack(spacing: 8) {
                        Image(systemName: method == "wallet" ? "wallet.pass.fill" : (method == "card" ? "creditcard.fill" : "banknote.fill"))
                            .font(.system(size: 17)).foregroundStyle(wa.accent)
                        Text(verbatim: L("rd_paying_with", rideMethodName(method))).font(.dorr(14, .semibold)).foregroundStyle(wa.settingsInk)
                        Spacer(minLength: 0)
                        if !options.isEmpty {
                            Image(systemName: "arrow.left.arrow.right").font(.system(size: 13)).foregroundStyle(wa.accent)
                            Text(L("rd_change")).font(.dorr(13, .bold)).foregroundStyle(wa.accent)
                        }
                    }
                    .padding(.horizontal, 12).padding(.vertical, 10)
                    .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(.plain)
                .disabled(options.isEmpty)
                if open && !options.isEmpty {
                    HStack(spacing: 8) {
                        ForEach(options, id: \.self) { option in
                            Button { change(option) } label: {
                                HStack(spacing: 6) {
                                    if busy == option { ProgressView().tint(wa.accent).scaleEffect(0.7) }
                                    Text(verbatim: rideMethodName(option)).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                                }
                                .frame(maxWidth: .infinity).padding(.vertical, 10)
                                .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(wa.accent.opacity(0.4), lineWidth: 1))
                            }
                            .buttonStyle(.plain)
                            .disabled(busy != nil)
                        }
                    }
                    .padding(.top, 8)
                    .transition(.opacity)
                }
                if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(AppColors.danger).padding(.top, 6) }
            }
            .padding(.top, 10)
            .animation(.easeInOut(duration: 0.2), value: open)
        }
    }

    private func change(_ option: String) {
        busy = option
        Task {
            do {
                if let fresh = try await RidesApi.changePayment(ride.uuid, ["payment_method": .string(option)], key: RidesApi.key()).data { state.ride = fresh }
                open = false
            } catch {
                self.error = error.apiFailure.message
            }
            busy = nil
        }
    }
}

/// A Saudi-style plate: letters | numbers, with the car's colour as a strip (shared with the moving request page).
struct Plate: View {
    let letters: String?
    let numbers: String?
    let colorHex: String?

    var body: some View {
        let strip = colorHex.flatMap { UInt32($0.replacingOccurrences(of: "#", with: ""), radix: 16) }.map { Color(hex: $0) } ?? Color(white: 0.8)
        VStack(spacing: 4) {
            HStack(spacing: 0) {
                Text(verbatim: letters ?? "").font(.dorr(13, .bold)).foregroundStyle(Color(hex: 0x111827)).padding(.horizontal, 6).padding(.vertical, 3)
                Rectangle().fill(Color(hex: 0x1F2937)).frame(width: 2, height: 24)
                Text(verbatim: numbers ?? "").font(.dorr(13, .bold)).foregroundStyle(Color(hex: 0x111827)).padding(.horizontal, 6).padding(.vertical, 3)
            }
            .background(Color.white, in: RoundedRectangle(cornerRadius: 6))
            .overlay(RoundedRectangle(cornerRadius: 6).stroke(Color(hex: 0x1F2937), lineWidth: 2))
            Capsule().fill(strip).frame(width: 40, height: 4)
        }
    }
}

// MARK: - The end

/// The receipt and the rating, right when the ride ends.
private struct Completed: View {
    @Environment(\.wa) private var wa
    let ride: RideDto
    let onTipped: (RideDto) -> Void
    let onDone: () -> Void
    @State private var stars = 0
    @State private var tags: [RideTagDto] = []
    @State private var chosen: Set<Int> = []
    @State private var scores: [String: Int] = [:]
    @State private var comment = ""
    @State private var sent = false
    @State private var busy = false
    @State private var pointsPop: CGFloat = 0

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(L("rd_status_completed")).font(.dorr(20, .heavy)).foregroundStyle(wa.settingsInk).padding(.bottom, 8)
            VStack(alignment: .leading, spacing: 0) {
                ForEach(Array((ride.lines ?? []).filter { $0.code != "total" }.enumerated()), id: \.offset) { _, l in PriceLineRow(line: l, currency: ride.currency) }
                HStack {
                    Text(L("rd_total")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    Spacer()
                    Text(verbatim: rideMoney(ride.finalTotalMinor, ride.currency)).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                }
                .padding(.top, 6)
                // R-144: the tip stays on its own line, after the ride's total.
                if let tip = ride.tip?.amountMinor { PriceLineRow(line: RideLineDto(code: "tip", label: L("rd_tip_line"), amountMinor: tip), currency: ride.currency) }
                if let options = ride.options, !options.isEmpty {
                    FlowLayout(spacing: 6) { ForEach(options, id: \.key) { AddonChip(o: $0) } }.padding(.top, 8)
                }
                if ride.paymentStatus == "cash_due" {
                    Text(verbatim: L("rd_cash_due", rideMoney(ride.finalTotalMinor, ride.currency))).font(.dorr(13, .semibold)).foregroundStyle(AppColors.warning).padding(.top, 6)
                }
            }
            .padding(12)
            .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            // D-294: something left in the car.
            LostItemRow(rideUuid: ride.uuid)
            // R-146: the DORR points this ride earned, popping in.
            if let pts = ride.loyaltyPoints, pts > 0 {
                HStack(spacing: 6) {
                    Image(systemName: "rosette").font(.system(size: 15)).foregroundStyle(Color(hex: 0xB7791F))
                    Text(verbatim: L("loy_ride_points", pts)).font(.dorr(14, .black)).foregroundStyle(Color(hex: 0xB7791F))
                }
                .padding(.horizontal, 14).padding(.vertical, 7)
                .background(Color(hex: 0xE0A526).opacity(0.16), in: Capsule())
                .scaleEffect(pointsPop).opacity(Double(pointsPop))
                .padding(.top, 10)
                .onAppear { withAnimation(.spring(response: 0.5, dampingFraction: 0.45)) { pointsPop = 1 } }
            }
            if sent || ride.rated == true {
                Text(L("rd_rate_thanks")).font(.dorr(15, .bold)).foregroundStyle(AppColors.success).frame(maxWidth: .infinity).padding(.top, 14)
                TipSection(ride: ride, onTipped: onTipped)
                AccentButton(text: L("rd_done"), action: onDone).padding(.top, 12)
            } else {
                rating
            }
        }
        .task { tags = (try? await RidesApi.ratingTags().data) ?? [] }
    }

    @ViewBuilder
    private var rating: some View {
        Text(verbatim: L("rd_rate_title", ride.driver?.name ?? "")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity).padding(.top, 14)
        // ⭐ Keep this captain / 🚫 never again (R-225, R-226).
        AfterRideChoices(ride: ride)
        starRow(value: stars, size: 40) { stars = $0 }.frame(maxWidth: .infinity).padding(.vertical, 8)
        // O-41: a row of stars per criterion — they start at the overall stars and can each be moved.
        if stars > 0, let criteria = ride.ratingCriteria, !criteria.isEmpty {
            VStack(spacing: 0) {
                ForEach(criteria, id: \.key) { c in
                    HStack {
                        Text(verbatim: c.label ?? c.key).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk)
                        Spacer()
                        starRow(value: scores[c.key] ?? stars, size: 26) { scores[c.key] = $0 }
                    }
                    .padding(.vertical, 4)
                }
            }
            .padding(.horizontal, 12).padding(.vertical, 6)
            .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .padding(.bottom, 8)
        }
        if stars > 0 {
            let visible = tags.filter { stars >= 4 ? $0.sentiment == "positive" : $0.sentiment != "positive" }
            FlowLayout(spacing: 6) {
                ForEach(visible, id: \.id) { tag in
                    let on = chosen.contains(tag.id)
                    Button { if on { chosen.remove(tag.id) } else { chosen.insert(tag.id) } } label: {
                        Text(verbatim: tag.label ?? "").font(.dorr(12)).foregroundStyle(on ? .white : wa.settingsInk)
                            .padding(.horizontal, 12).padding(.vertical, 7)
                            .background(on ? wa.accent : wa.settingsBackground, in: Capsule())
                    }
                    .buttonStyle(.plain)
                }
            }
            DorrTextArea(value: Binding(get: { comment }, set: { comment = String($0.prefix(500)) }), placeholder: L("rd_rate_comment"), minHeight: 70).padding(.top, 8)
            // Wireflow step 7: "Tip (optional)" under the note, before sending the rating.
            TipSection(ride: ride, onTipped: onTipped)
            AccentButton(text: L("rd_rate_send"), busy: busy) { send() }.padding(.top, 12)
        } else {
            Button(action: onDone) { Text(L("rd_done")).font(.dorr(15)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity).padding(8) }.buttonStyle(.plain)
        }
    }

    private func starRow(value: Int, size: CGFloat, pick: @escaping (Int) -> Void) -> some View {
        HStack(spacing: 0) {
            ForEach(1...5, id: \.self) { i in
                Image(systemName: i <= value ? "star.fill" : "star").font(.system(size: size * 0.75)).foregroundStyle(i <= value ? AppColors.warning : wa.settingsMut)
                    .frame(width: size, height: size).contentShape(Rectangle())
                    .onTapGesture { pick(i) }
            }
        }
    }

    private func send() {
        busy = true
        Task {
            var body: [String: JSONValue] = ["stars": .int(stars), "tags": .array(chosen.map { .int($0) })]
            if let criteria = ride.ratingCriteria, !criteria.isEmpty {
                body["scores"] = .object(Dictionary(uniqueKeysWithValues: criteria.map { ($0.key, JSONValue.int(scores[$0.key] ?? stars)) }))
            }
            let note = comment.trimmingCharacters(in: .whitespacesAndNewlines)
            if !note.isEmpty { body["comment"] = .string(note) }
            if (try? await RidesApi.rate(ride.uuid, body)) != nil { sent = true }
            busy = false
        }
    }
}

private struct Ended: View {
    @Environment(\.wa) private var wa
    let ride: RideDto
    let onAgain: () -> Void

    var body: some View {
        Text(L(ride.status == "expired" ? "rd_status_expired" : (ride.status == "no_show" ? "rd_status_no_show" : "rd_status_canceled")))
            .font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
        AccentButton(text: L("rd_book_again"), action: onAgain).padding(.top, 14)
    }
}

// MARK: - Cancelling (O-52)

/// The question asked plainly, what it costs (free / the fee) as a badge, the reasons as cards to tap, and «keep
/// my ride» as the main button with cancelling as the quieter one.
struct CancelRideSheet: View {
    @Environment(\.wa) private var wa
    @ObservedObject var state: RidesState
    let onClose: () -> Void
    @State private var fee: Int?
    @State private var reasons: [RideReasonDto] = []
    @State private var reason: Int?
    @State private var busy = false
    @State private var error: String?
    @State private var key = RidesApi.key()

    var body: some View {
        if let ride = state.ride {
            ScrollView {
                VStack(spacing: 0) {
                    Image(systemName: "exclamationmark.triangle.fill").font(.system(size: 28)).foregroundStyle(AppColors.danger)
                        .frame(width: 64, height: 64).background(AppColors.danger.opacity(0.1), in: Circle())
                    Text(L("cx_title")).font(.dorr(20, .black)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center).padding(.top, 12)
                    Text(L(ride.driver != nil ? "cx_body_driver" : "cx_body_search")).font(.dorr(13)).foregroundStyle(wa.settingsMut)
                        .multilineTextAlignment(.center).padding(.top, 4)
                    if let fee {
                        let free = fee <= 0
                        let tint = free ? AppColors.success : AppColors.danger
                        Text(verbatim: free ? L("rd_cancel_free") : L("rd_cancel_fee", rideMoney(fee, ride.currency))).font(.dorr(13, .bold)).foregroundStyle(tint)
                            .padding(.horizontal, 14).padding(.vertical, 6).background(tint.opacity(0.1), in: Capsule()).padding(.top, 10)
                    }
                    if !reasons.isEmpty {
                        Text(L("rd_cancel_reason")).font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                            .frame(maxWidth: .infinity, alignment: .leading).padding(.top, 18).padding(.bottom, 8)
                        FlowLayout(spacing: 8) {
                            ForEach(reasons, id: \.id) { r in
                                let on = reason == r.id
                                Button { reason = on ? nil : r.id } label: {
                                    HStack(spacing: 6) {
                                        if on { Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(wa.accent) }
                                        Text(verbatim: r.label ?? "").font(.dorr(13, on ? .bold : .medium)).foregroundStyle(wa.settingsInk)
                                    }
                                    .padding(.horizontal, 12).padding(.vertical, 10)
                                    .background(on ? wa.accent.opacity(0.12) : wa.settingsBackground, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                                    .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(on ? wa.accent : .clear, lineWidth: 1.5))
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger).padding(.top, 10) }
                    AccentButton(text: L("cx_keep"), action: onClose).padding(.top, 18)
                    Button { cancel(ride) } label: {
                        Text(L("rd_cancel_confirm")).font(.dorr(15, .bold)).foregroundStyle(AppColors.danger)
                            .frame(maxWidth: .infinity).padding(.vertical, 14)
                            .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(AppColors.danger.opacity(0.35), lineWidth: 1.5))
                    }
                    .buttonStyle(.plain)
                    .disabled(busy)
                    .padding(.top, 8)
                }
                .padding(.horizontal, 20).padding(.top, 22).padding(.bottom, 18)
            }
            .background(wa.settingsCard.ignoresSafeArea())
            .task {
                fee = (try? await RidesApi.cancelPreview(ride.uuid).data?.feeMinor) ?? 0
                reasons = (try? await RidesApi.cancelReasons().data) ?? []
            }
        }
    }

    private func cancel(_ ride: RideDto) {
        busy = true
        error = nil
        Task {
            do {
                let fresh = try await RidesApi.cancel(ride.uuid, reason.map { ["reason_id": .int($0)] } ?? [:], key: key).data
                state.ride = fresh
                onClose()
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
        }
    }
}

// MARK: - The chat with the captain (O-61)

/// Ready-made messages and text, open while the captain is on the ride, in the look of a conversation.
@MainActor
private struct RideChatSheet: View {
    let ride: RideDto
    let onClose: () -> Void

    var body: some View {
        RideChatScreen(
            title: ride.driver?.name ?? "", subtitle: L("rd_chat_subtitle", ride.number ?? ""),
            load: { try await RidesApi.messages(ride.uuid).data },
            post: { try await RidesApi.send(ride.uuid, $0).data },
            onCall: ["accepted", "arrived", "in_progress"].contains(ride.status) ? { startRideCall(ride) } : nil,
            onClose: onClose
        )
    }
}

/// androidApp `RidesChatUi.kt` RideChatScreen — the conversation look shared by a ride's captain and a bus captain
/// (O-61, O-68). `onCall` puts a call button in the bar.
struct RideChatScreen: View {
    @Environment(\.wa) private var wa
    let title: String
    let subtitle: String
    let load: () async throws -> RideChatDto?
    let post: ([String: JSONValue]) async throws -> RideMessageDto?
    var onCall: (() -> Void)?
    let onClose: () -> Void
    @State private var messages: [RideMessageDto] = []
    @State private var quick: [RideQuickReplyDto] = []
    @State private var open = true
    @State private var text = ""

    private func refresh() async {
        guard let chat = try? await load() else { return }
        messages = chat.messages ?? []
        quick = chat.quickReplies ?? []
        open = chat.open ?? false
    }

    private func send(_ body: String?, _ quickKey: String?) {
        Task {
            var payload: [String: JSONValue] = ["client_id": .string(UUID().uuidString)]
            if let body { payload["body"] = .string(body) }
            if let quickKey { payload["quick"] = .string(quickKey) }
            if let m = try? await post(payload) { messages.append(m) }
        }
    }

    var body: some View {
        VStack(spacing: 0) {
            HStack(spacing: 10) {
                Button(action: onClose) {
                    Image(systemName: "arrow.backward").font(.system(size: 17, weight: .semibold)).foregroundStyle(.white).frame(width: 40, height: 40)
                }
                .buttonStyle(.plain)
                Text(verbatim: String((title.isEmpty ? "?" : title).prefix(1))).font(.dorr(18, .black)).foregroundStyle(.white)
                    .frame(width: 42, height: 42).background(Color.white.opacity(0.2), in: Circle())
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: title).font(.dorr(16, .bold)).foregroundStyle(.white).lineLimit(1)
                    Text(verbatim: subtitle).font(.dorr(12)).foregroundStyle(.white.opacity(0.8)).lineLimit(1)
                }
                Spacer()
                if let onCall {
                    Button(action: onCall) {
                        Image(systemName: "phone.fill").font(.system(size: 16)).foregroundStyle(.white).frame(width: 40, height: 40).background(Color.white.opacity(0.2), in: Circle())
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(10)
            .background(LinearGradient(colors: [wa.accent, Color(hex: 0x2B4C9B)], startPoint: .leading, endPoint: .trailing).ignoresSafeArea(edges: .top))
            ScrollViewReader { reader in
                ScrollView {
                    LazyVStack(spacing: 6) {
                        if messages.isEmpty {
                            Text(L("rd_chat_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut).padding(.top, 40)
                        }
                        ForEach(messages) { m in RideBubble(m: m).id(m.id) }
                    }
                    .padding(12)
                }
                .onChange(of: messages.count) { _ in if let last = messages.last { withAnimation { reader.scrollTo(last.id, anchor: .bottom) } } }
            }
            .background(wa.settingsBackground)
            if !open {
                Text(L("rd_chat_closed")).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity).padding(16).background(wa.settingsCard)
            } else {
                VStack(spacing: 0) {
                    if !quick.isEmpty {
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 6) {
                                ForEach(quick, id: \.key) { q in
                                    Button { send(nil, q.key) } label: {
                                        Text(verbatim: q.text ?? "").font(.dorr(13, .semibold)).foregroundStyle(wa.accent)
                                            .padding(.horizontal, 14).padding(.vertical, 8)
                                            .background(wa.accent.opacity(0.08), in: Capsule())
                                            .overlay(Capsule().stroke(wa.accent.opacity(0.2), lineWidth: 1))
                                    }
                                    .buttonStyle(.plain)
                                }
                            }
                            .padding(.horizontal, 12).padding(.vertical, 8)
                        }
                    }
                    HStack(alignment: .bottom, spacing: 8) {
                        TextField(L("rd_chat_hint"), text: Binding(get: { text }, set: { text = String($0.prefix(1000)) }), axis: .vertical)
                            .lineLimit(1...5).font(.dorr(15)).foregroundStyle(wa.settingsInk).tint(wa.accent)
                            .padding(.horizontal, 16).padding(.vertical, 12)
                            .background(wa.settingsBackground, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 24, style: .continuous).stroke(wa.line, lineWidth: 1))
                        let blank = text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty
                        Button {
                            send(text.trimmingCharacters(in: .whitespacesAndNewlines), nil)
                            text = ""
                        } label: {
                            Image(systemName: "paperplane.fill").font(.system(size: 17)).foregroundStyle(blank ? wa.settingsMut : .white)
                                .flipsForRightToLeftLayoutDirection(true)
                                .frame(width: 46, height: 46).background(blank ? wa.settingsBackground : wa.accent, in: Circle())
                        }
                        .buttonStyle(.plain)
                        .disabled(blank)
                    }
                    .padding(.horizontal, 10).padding(.bottom, 8)
                }
                .background(wa.settingsCard)
            }
        }
        .task { await refresh() }
    }
}

private struct RideBubble: View {
    @Environment(\.wa) private var wa
    let m: RideMessageDto

    var body: some View {
        let mine = m.mine ?? (m.from == "user")
        HStack {
            if mine { Spacer(minLength: 40) }
            VStack(alignment: .trailing, spacing: 2) {
                Text(verbatim: m.body ?? "").font(.dorr(15)).foregroundStyle(mine ? .white : wa.settingsInk).lineSpacing(4)
                if let at = parseRideTime(m.createdAt) {
                    Text(verbatim: at.formatted(date: .omitted, time: .shortened)).font(.dorr(10)).foregroundStyle((mine ? Color.white : wa.settingsMut).opacity(0.75))
                }
            }
            .padding(.horizontal, 12).padding(.vertical, 8)
            .background(mine ? AnyShapeStyle(LinearGradient(colors: [wa.accent, Color(hex: 0x2B4C9B)], startPoint: .topLeading, endPoint: .bottomTrailing))
                        : AnyShapeStyle(wa.settingsCard),
                        in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: mine ? .clear : .black.opacity(0.06), radius: 1, y: 1)
            if !mine { Spacer(minLength: 40) }
        }
    }
}
