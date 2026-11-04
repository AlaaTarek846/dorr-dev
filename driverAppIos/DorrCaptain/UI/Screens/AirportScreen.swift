import SwiftUI

// driverApp `ui/AirportScreen.kt` — the airport queue (D-276..D-283).

/// The Airport screen (D-276..D-283): where I am, my place in the airport queue and a wait estimate when
/// there is enough to estimate from, joining and leaving with the rules said plainly, the airport's official
/// pickup points, and the areas where pickups aren't allowed. Live: a change of my place (someone ahead left,
/// my turn came) arrives as `driver.changed` and the screen reloads once — it never asks on a timer.
struct AirportScreen: View {
    let onBack: () -> Void

    @Environment(\.pal) private var pal
    @State private var status: AirportQueueDto?
    @State private var zones: [RestrictedZoneDto] = []
    @State private var busy = false
    @State private var error: String?
    @State private var refresh = 0

    private func act(_ block: @escaping () async -> ApiResult<AirportQueueDto>) {
        busy = true
        error = nil
        Task {
            switch await block() {
            case .ok(let s, _): status = s
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    var body: some View {
        ScreenScaffold(L("air_title"), onBack: onBack) {
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    if let error { Text(error).font(.dorr(16)).foregroundStyle(pal.danger) }
                    if let s = status {
                        if s.zone == nil {
                            Card {
                                HStack(spacing: 0) {
                                    DorrIcon("airplane", tint: pal.navy)
                                    HSpace(10)
                                    Text(L("air_none")).font(.dorr(16)).foregroundStyle(pal.ink.opacity(0.75))
                                }
                            }
                        } else {
                            QueueCard(s: s, busy: busy,
                                      onJoin: { act { await DriverApi.joinAirportQueue() } },
                                      onLeave: { act { await DriverApi.leaveAirportQueue() } })
                            if !(s.pickupPoints ?? []).isEmpty { PointsCard(points: s.pickupPoints ?? []) }
                        }
                    } else if error == nil {
                        CircularProgress(color: pal.orange, size: 40, lineWidth: 4).frame(maxWidth: .infinity).frame(height: 160)
                    }
                    if !zones.isEmpty { RestrictedCard(zones: zones) }
                    VSpace(8)
                }
                .padding(16)
            }
        }
        .task(id: refresh) {
            switch await DriverApi.airportQueue() {
            case .ok(let s, _): status = s; error = nil
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            if let z = await DriverApi.restrictedZones().value { zones = z }
        }
        .onReceive(DriverLive.shared.events) { e in
            if e.name == DriverLive.driverChanged { refresh += 1 }
        }
    }
}

private struct QueueCard: View {
    let s: AirportQueueDto
    let busy: Bool
    let onJoin: () -> Void
    let onLeave: () -> Void
    @Environment(\.pal) private var pal

    var body: some View {
        let size = s.size ?? 0
        Card {
            HStack(spacing: 0) {
                DorrIcon("airplane", tint: pal.navy)
                HSpace(10)
                Text(L("air_at", s.zone?.name ?? "")).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
            }
            VSpace(14)
            if s.queued == true {
                // The place rolls to its new number when the line moves.
                VStack(spacing: 0) {
                    Text(L("air_position")).font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.6))
                    let n = s.position ?? 0
                    ZStack {
                        Text("\(n)").font(.dorr(44, .heavy)).foregroundStyle(pal.orange)
                            .id(n)
                            .transition(.asymmetric(insertion: .move(edge: .top).combined(with: .opacity),
                                                    removal: .move(edge: .bottom).combined(with: .opacity)))
                    }
                    .frame(width: 104, height: 104)
                    .background(Circle().fill(pal.orange.opacity(0.14)))
                    .clipShape(Circle())
                    .animation(.tween(300), value: n)
                    Text(L("air_of", size)).font(.dorr(13)).foregroundStyle(pal.ink).padding(.top, 6)
                    HStack(spacing: 0) {
                        DorrIcon("clock", size: 18, tint: pal.navy)
                        HSpace(6)
                        Text(s.estimatedWaitS.map { L("air_wait", L("air_minutes", ($0 + 59) / 60)) } ?? L("air_wait_unknown"))
                            .font(.dorr(13, .semibold)).foregroundStyle(pal.ink).multilineTextAlignment(.center)
                    }
                    .padding(.top, 10)
                    Text(L(s.entry == "auto" ? "air_joined_auto" : "air_joined_manual")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.55)).padding(.top, 6)
                }
                .frame(maxWidth: .infinity)
                VSpace(16)
                BigButton(L("air_leave"), color: pal.danger, busy: busy, onClick: onLeave)
                if let rules = s.rules {
                    VSpace(12)
                    Text(L("air_rules_title")).font(.dorr(13, .bold)).foregroundStyle(pal.ink)
                    let maxWait = rules.maxWaitS ?? 0
                    let lines: [String] = [
                        L("air_rule_manual"),
                        L("air_rule_offline"),
                        L("air_rule_zone", ((rules.leaveGraceS ?? 0) + 59) / 60),
                        maxWait > 0 ? L("air_rule_wait", (maxWait + 59) / 60) : nil,
                        L("air_rule_trip"),
                    ].compactMap { $0 }
                    ForEach(Array(lines.enumerated()), id: \.offset) { _, line in
                        Text("• \(line)").font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.7)).padding(.top, 2)
                    }
                }
            } else {
                Text(L(s.entryMode == "auto" ? "air_entry_auto" : "air_entry_manual")).font(.dorr(13)).foregroundStyle(pal.ink.opacity(0.7))
                if size > 0 { Text(L("air_of", size)).font(.dorr(13, .semibold)).foregroundStyle(pal.ink).padding(.top, 4) }
                VSpace(12)
                BigButton(L("air_join"), enabled: s.canJoin ?? false, busy: busy, onClick: onJoin)
                if let reason = reasonText(s.cannotJoinReason) {
                    Text(reason).font(.dorr(12)).foregroundStyle(pal.danger).padding(.top, 8)
                }
            }
        }
    }
}

private func reasonText(_ code: String?) -> String? {
    switch code {
    case "offline": return L("air_reason_offline")
    case "stale": return L("air_reason_stale")
    case "busy": return L("air_reason_busy")
    case "not_eligible": return L("air_reason_not_eligible")
    default: return nil
    }
}

/// D-281: the airport's official pickup points — where to meet the rider.
private struct PointsCard: View {
    let points: [PickupPointDto]
    @Environment(\.pal) private var pal

    var body: some View {
        Card {
            Text(L("air_points_title")).font(.dorr(15, .bold)).foregroundStyle(pal.ink)
            ForEach(Array(points.enumerated()), id: \.offset) { _, point in
                HStack(alignment: .top, spacing: 0) {
                    DorrIcon("mappin.and.ellipse", size: 20, tint: pal.navy)
                    HSpace(10)
                    VStack(alignment: .leading, spacing: 0) {
                        Text("\(point.name ?? "") · \(point.kindLabel ?? "")").font(.dorr(14, .semibold)).foregroundStyle(pal.ink)
                        if let ins = point.instructions, !ins.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                            Text(ins).font(.dorr(12)).italic().foregroundStyle(pal.ink.opacity(0.65))
                        }
                    }
                    Spacer(minLength: 0)
                }
                .padding(.top, 10)
            }
        }
    }
}

/// D-282: where pickups aren't allowed, or only at approved points.
private struct RestrictedCard: View {
    let zones: [RestrictedZoneDto]
    @Environment(\.pal) private var pal

    var body: some View {
        Card {
            Text(L("air_restricted_title")).font(.dorr(15, .bold)).foregroundStyle(pal.ink)
            ForEach(Array(zones.enumerated()), id: \.offset) { _, zone in
                let blocked = zone.type == "blocked"
                HStack(spacing: 0) {
                    DorrIcon("nosign", size: 20, tint: blocked ? pal.danger : pal.warning)
                    HSpace(10)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(zone.name ?? "").font(.dorr(14, .semibold)).foregroundStyle(pal.ink)
                        Text(L(blocked ? "air_restricted_blocked" : "air_restricted_pickup_only")).font(.dorr(12)).foregroundStyle(pal.ink.opacity(0.65))
                    }
                    Spacer(minLength: 0)
                }
                .padding(.top, 10)
            }
        }
    }
}
