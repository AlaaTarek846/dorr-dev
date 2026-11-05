import SwiftUI

// driverApp `ui/ScheduledScreen.kt` — scheduled rides (D-260..269).

/// Scheduled rides (D-260..269): the bookings near me I may reserve — when, where, the fixed net and
/// when to be online — and my own: head to the pickup once it's time, or give one back (told first
/// whether that counts as a cancel).
struct ScheduledScreen: View {
    let onBack: () -> Void
    let onStarted: () -> Void

    @Environment(\.pal) private var pal
    @State private var tab = 0
    @State private var items: [ScheduledRideDto]?
    @State private var busy: String?
    @State private var error: String?
    @State private var releasing: ScheduledRideDto?
    @State private var refresh = 0

    private func act<T>(_ uuid: String, _ block: @escaping () async -> ApiResult<T>, after: @escaping () -> Void) {
        busy = uuid
        error = nil
        Task {
            switch await block() {
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            case .ok: after()
            }
            busy = nil
        }
    }

    private func newIdempotencyKey() -> String { UUID().uuidString.lowercased() }

    var body: some View {
        ScreenScaffold(L("sch_title"), onBack: onBack) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 8) {
                    ForEach(Array(["sch_available", "sch_mine"].enumerated()), id: \.offset) { i, label in
                        Chip(L(label), selected: tab == i, onClick: { tab = i })
                    }
                }
                .padding(.horizontal, 16)
                .padding(.bottom, 8)
                if let error {
                    Text(error).font(.dorr(16)).foregroundStyle(pal.danger).padding(.horizontal, 16).padding(.vertical, 8)
                }
                ZStack {
                    if let list = items {
                        if list.isEmpty {
                            VStack(spacing: 0) {
                                DorrIcon("calendar", size: 36, tint: pal.orange)
                                    .frame(width: 76, height: 76)
                                    .background(Circle().fill(pal.orange.opacity(0.12)))
                                VSpace(10)
                                Text(L(tab == 0 ? "sch_empty" : "sch_mine_empty")).font(.dorr(16)).multilineTextAlignment(.center)
                                    .foregroundStyle(pal.ink.opacity(0.6))
                            }
                            .padding(32)
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                            .transition(.opacity)
                        } else {
                            ScrollView {
                                LazyVStack(spacing: 12) {
                                    VSpace(4)
                                    ForEach(list, id: \.uuid) { ride in
                                        let uuid = ride.uuid ?? ""
                                        ScheduledCard(ride: ride, busy: busy == uuid,
                                                      onReserve: {
                                                          act(uuid, { await DriverApi.reserve(key: newIdempotencyKey(), uuid: uuid) }) {
                                                              items = items?.filter { $0.uuid != uuid }
                                                          }
                                                      },
                                                      onStart: {
                                                          act(uuid, { await DriverApi.startScheduled(key: newIdempotencyKey(), uuid: uuid) }) {
                                                              Duty.shared.setOnTrip(true)
                                                              onStarted()
                                                          }
                                                      },
                                                      onRelease: { releasing = ride })
                                    }
                                    VSpace(16)
                                }
                                .padding(.horizontal, 16)
                            }
                            .transition(.opacity.combined(with: .offset(y: 30)))
                        }
                    } else {
                        CircularProgress(color: pal.orange, size: 40, lineWidth: 4).frame(maxWidth: .infinity, maxHeight: .infinity)
                    }
                }
                .animation(.tween(240), value: items)
            }
        }
        .task(id: "\(tab)|\(refresh)") {
            items = nil
            error = nil
            let r = tab == 0 ? await DriverApi.scheduled() : await DriverApi.scheduledMine()
            items = r.value ?? []
            if case .failed(let e) = r { error = e.displayMessage }
            if case .offline = r { error = L("error_offline") }
        }
        // "Head out" turns on by itself when the time comes — by the phone's clock, no request; and a change of mine reloads the list.
        .onReceive(DriverLive.shared.events) { e in
            if e.name == DriverLive.driverChanged { refresh += 1 }
        }
        // Giving a booking back: the cost is said first (D-170).
        .dorrDialog(isPresented: Binding(get: { releasing != nil }, set: { if !$0 { releasing = nil } })) {
            if let ride = releasing {
                ReleaseDialog(ride: ride, onKeep: { releasing = nil }, onRelease: {
                    releasing = nil
                    let uuid = ride.uuid ?? ""
                    act(uuid, { await DriverApi.releaseReservation(uuid: uuid, body: [:]) }) {
                        items = items?.filter { $0.uuid != uuid }
                    }
                })
                .environment(\.pal, pal)
            }
        }
    }
}

/// Material's `AlertDialog` for giving a booking back: the warning, the title, whether it counts, Keep / Release.
private struct ReleaseDialog: View {
    let ride: ScheduledRideDto
    let onKeep: () -> Void
    let onRelease: () -> Void
    @Environment(\.pal) private var pal

    var body: some View {
        let counts = ride.releaseCountsAsCancel == true
        VStack(spacing: 0) {
            DorrIcon("exclamationmark.triangle", tint: counts ? pal.danger : pal.warning)
            VSpace(16)
            Text(L("sch_release_title")).font(.dorr(24)).foregroundStyle(pal.ink).multilineTextAlignment(.center)
            VSpace(16)
            Text(L(counts ? "sch_release_counts" : "sch_release_free")).font(.dorr(14)).foregroundStyle(pal.mut)
                .frame(maxWidth: .infinity, alignment: .leading)
            VSpace(24)
            HStack(spacing: 8) {
                Spacer(minLength: 0)
                Text(L("sch_keep")).font(.dorr(14, .semibold)).foregroundStyle(pal.accent)
                    .padding(.horizontal, 12).padding(.vertical, 10)
                    .contentShape(Rectangle())
                    .onTapGesture(perform: onKeep)
                Text(L("sch_release")).font(.dorr(14, .semibold)).foregroundStyle(pal.danger)
                    .padding(.horizontal, 12).padding(.vertical, 10)
                    .contentShape(Rectangle())
                    .onTapGesture(perform: onRelease)
            }
        }
        .padding(24)
        .frame(maxWidth: 400)
        .background(RoundedRectangle(cornerRadius: 28).fill(pal.card))
        .padding(.horizontal, 32)
    }
}

private struct ScheduledCard: View {
    let ride: ScheduledRideDto
    let busy: Bool
    let onReserve: () -> Void
    let onStart: () -> Void
    let onRelease: () -> Void
    @Environment(\.pal) private var pal

    var body: some View {
        let at = instantOf(ride.scheduledAt)
        let onlineFrom = instantOf(ride.onlineFrom)
        Card {
            HStack(spacing: 0) {
                // The day as a small calendar leaf.
                if let at { DayLeaf(at: at) }
                HSpace(12)
                VStack(alignment: .leading, spacing: 0) {
                    Text(at.map { timeOfDay($0) } ?? "—").font(.dorr(22, .heavy)).foregroundStyle(pal.ink)
                    Text(ride.category?.name ?? "").font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.6))
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                VStack(alignment: .trailing, spacing: 0) {
                    Text(money(ride.earningMinor, ride.currency)).font(.dorr(18, .heavy)).foregroundStyle(pal.success)
                    Text(L("sch_fixed_net")).font(.dorr(11)).foregroundStyle(pal.ink.opacity(0.55))
                }
            }
            VSpace(10)
            RoutePoint(destination: false, address: ride.origin?.address)
            if let d = ride.destination { RoutePoint(destination: true, address: d.address) }
            HStack(spacing: 6) {
                if let m = ride.route?.distanceM { SchPill(text: L("sch_trip_km", String(format: "%.1f", Double(m) / 1000.0))) }
                if let m = ride.pickupDistanceM { SchPill(text: L("offer_pickup_km", String(format: "%.1f", Double(m) / 1000.0))) }
                if let r = ride.riderRating { SchPill(text: String(format: "%.1f ★", r)) }
                SchPill(text: L(ride.paymentMethod == "cash" ? "offer_cash" : "offer_paid"))
                Spacer(minLength: 0)
            }
            .padding(.top, 6)
            if let onlineFrom {
                HStack(spacing: 0) {
                    DorrIcon("clock", size: 16, tint: pal.orange)
                    HSpace(6)
                    Text(L("sch_online_from", timeOfDay(onlineFrom))).font(.dorr(12, .semibold)).foregroundStyle(pal.ink)
                }
                .padding(.top, 8)
            }
            VSpace(12)
            if ride.mine != true {
                BigButton(L("sch_reserve"), busy: busy, onClick: onReserve)
            } else {
                let canStart = ride.canStart ?? false
                BigButton(canStart ? L("sch_start") : L("sch_start_at", instantOf(ride.startsAt).map { timeOfDay($0) } ?? "—"),
                          color: pal.success, enabled: canStart, busy: busy, onClick: onStart)
                Text(L("sch_release")).font(.dorr(16, .semibold)).foregroundStyle(pal.danger).multilineTextAlignment(.center)
                    .frame(maxWidth: .infinity)
                    .padding(10)
                    .contentShape(RoundedRectangle(cornerRadius: 12))
                    .onTapGesture { if !busy { onRelease() } }
            }
        }
    }
}

private struct DayLeaf: View {
    let at: Date
    @Environment(\.pal) private var pal

    var body: some View {
        let date = LocalDate(at)
        let locale = Locale(identifier: DriverPrefs.locale)
        let label: String = {
            if date == LocalDate.now() { return L("sch_today") }
            if date == LocalDate.now().plusDays(1) { return L("sch_tomorrow") }
            let f = DateFormatter(); f.locale = locale; f.dateFormat = "EEE"
            return f.string(from: at)
        }()
        let month: String = { let f = DateFormatter(); f.locale = locale; f.dateFormat = "LLL"; return f.string(from: at) }()
        VStack(spacing: 0) {
            Text(month).font(.dorr(11, .bold)).foregroundStyle(.white)
                .frame(maxWidth: .infinity).frame(height: 20)
                .background(pal.orange)
            Text("\(date.day)").font(.dorr(22, .heavy)).foregroundStyle(pal.ink)
            Text(label).font(.dorr(9)).foregroundStyle(pal.ink.opacity(0.6)).lineLimit(1)
            Spacer(minLength: 0)
        }
        .frame(width: 58, height: 64)
        .background(pal.navy.opacity(0.06))
        .clipShape(RoundedRectangle(cornerRadius: 14))
    }
}

private struct RoutePoint: View {
    /// The orange pin (the destination) or the navy dot (the pickup).
    let destination: Bool
    let address: String?
    @Environment(\.pal) private var pal

    var body: some View {
        HStack(spacing: 0) {
            DorrIcon(destination ? "mappin" : "circle.fill", size: destination ? 18 : 12, tint: destination ? pal.orange : pal.navy)
            HSpace(10)
            Text(address ?? "—").font(.dorr(14, .semibold)).foregroundStyle(pal.ink).lineLimit(1)
            Spacer(minLength: 0)
        }
        .padding(.vertical, 3)
    }
}

private struct SchPill: View {
    let text: String
    @Environment(\.pal) private var pal

    var body: some View {
        Text(text).font(.dorr(11)).foregroundStyle(pal.ink)
            .padding(.horizontal, 9).padding(.vertical, 4)
            .background(Capsule().fill(pal.ink.opacity(0.07)))
    }
}
