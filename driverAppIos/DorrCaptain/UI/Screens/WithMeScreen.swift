import SwiftUI

// driverApp `ui/WithMeScreen.kt` — «أنا معك», With Me (O-30).

private let WithMeTeal = Color(hex: 0x00897B)

/// «أنا معك» — With Me (docs/rides_tasks.md O-30): «I'm going there — send me riders on my way». The
/// captain picks where he's going and how far off his road he'll go (inside the admin's limits; no
/// control when the admin fixed it), and while it's on, With Me requests along his way reach him —
/// even with riders already in the car. The riders sharing the car are listed; each opens its own ride.
struct WithMeScreen: View {
    let onBack: () -> Void
    let onOpenRide: (String) -> Void

    @Environment(\.pal) private var pal
    @State private var state: PoolStateDto?
    @State private var destination: PlaceDto?
    @State private var picking = false
    @State private var radiusKm: Double = 0
    @State private var busy = false
    @State private var error: String?
    @State private var tick = 0
    @State private var ring = false

    private func call(_ block: @escaping () async -> ApiResult<PoolStateDto>) {
        busy = true
        error = nil
        Task {
            switch await block() {
            case .ok(let s, _): state = s
            case .failed(let e): error = e.displayMessage
            case .offline: error = L("error_offline")
            }
            busy = false
        }
    }

    var body: some View {
        ZStack {
            VStack(spacing: 0) {
                ScreenHeader(L("wm_title"), onBack: onBack)
                if let s = state {
                    content(s)
                } else {
                    Spacer(minLength: 0)
                }
            }
            .background(pal.bg.ignoresSafeArea())
            if picking {
                PlacePicker(onClose: { picking = false }) { p in destination = p; picking = false }
            }
        }
        .task(id: tick) {
            if let s = await DriverApi.pool().value {
                state = s
                if radiusKm == 0 { radiusKm = Double(s.pool?.radiusM ?? s.radius?.maxM ?? 5000) / 1000 }
            }
        }
        // A rider joining or getting off arrives in real time (the captain's channel): one reload.
        .onReceive(DriverLive.shared.events) { e in
            if e.name == DriverLive.driverChanged || e.name.hasPrefix("ride.") { tick += 1 }
        }
    }

    @ViewBuilder
    private func content(_ s: PoolStateDto) -> some View {
        let pool = s.pool
        let editable = s.radius?.editable ?? true
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                // The hero: on → a pulsing ring around the riders' icon; off → the idea in one line.
                VStack(spacing: 0) {
                    ZStack {
                        if pool != nil {
                            Circle().fill(Color.white).frame(width: 80, height: 80)
                                .scaleEffect(ring ? 1.25 : 1).opacity(0.25)
                                .onAppear { withAnimation(.tween(1200).repeatForever(autoreverses: true)) { ring = true } }
                        }
                        DorrIcon("person.3.fill", size: 38, tint: .white)
                            .frame(width: 70, height: 70)
                            .background(Circle().fill(Color.white.opacity(0.2)))
                    }
                    .frame(width: 96, height: 96)
                    Text(L(pool != nil ? "wm_on" : "wm_off")).font(.dorr(18, .black)).foregroundStyle(.white)
                    Text(L("wm_explain")).font(.dorr(12)).foregroundStyle(Color.white.opacity(0.85)).padding(.top, 4)
                        .multilineTextAlignment(.center)
                }
                .padding(18)
                .frame(maxWidth: .infinity)
                .background(RoundedRectangle(cornerRadius: 26).fill(LinearGradient(colors: [WithMeTeal, Color(hex: 0x26A69A)],
                                                                                   startPoint: .topLeading, endPoint: .bottomTrailing)))
                .staggerIn(0)
                Banner(error)
                if let pool {
                    let rides = pool.rides ?? []
                    VStack(alignment: .leading, spacing: 0) {
                        HStack(spacing: 0) {
                            TileIcon("mappin.circle.fill", tint: pal.orange, box: 40)
                            HSpace(10)
                            VStack(alignment: .leading, spacing: 0) {
                                Text(L("wm_going_to")).font(.dorr(11)).foregroundStyle(pal.mut)
                                Text(pool.destination?.address ?? "—").font(.dorr(16, .bold)).foregroundStyle(pal.ink).lineLimit(2)
                            }
                            .frame(maxWidth: .infinity, alignment: .leading)
                        }
                        Text(L("wm_corridor", String(format: "%.1f", locale: Locale(identifier: DriverPrefs.locale), Double(pool.radiusM ?? 0) / 1000))
                             + " · " + L("wm_seats", rides.count, pool.seats ?? 4))
                            .font(.dorr(12)).foregroundStyle(pal.mut).padding(.top, 8)
                    }
                    .padding(14)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .surface()
                    Text(L("wm_riders", rides.count)).font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                    if rides.isEmpty { Text(L("wm_waiting")).font(.dorr(13)).foregroundStyle(pal.mut) }
                    ForEach(Array(rides.enumerated()), id: \.offset) { i, r in
                        HStack(spacing: 0) {
                            TileIcon("person.fill", tint: pal.accent, box: 40)
                            HSpace(10)
                            VStack(alignment: .leading, spacing: 0) {
                                HStack(spacing: 0) {
                                    Text(r.rider?.name ?? "—").font(.dorr(16, .bold)).foregroundStyle(pal.ink)
                                    RiderTick(verified: r.rider?.isVerified == true, size: 14)
                                }
                                Text(r.destination?.address ?? "—").font(.dorr(12)).foregroundStyle(pal.mut).lineLimit(1)
                            }
                            .frame(maxWidth: .infinity, alignment: .leading)
                            Text(statusText(r.status ?? "")).font(.dorr(12, .bold)).foregroundStyle(pal.accent)
                        }
                        .padding(12)
                        .frame(maxWidth: .infinity)
                        .surface()
                        .contentShape(Rectangle())
                        .onTapGesture { onOpenRide(r.uuid ?? "") }
                        .staggerIn(i + 1)
                    }
                    OutlineButton(L("wm_stop"), color: pal.danger) { call { await DriverApi.closePool() } }
                } else {
                    VStack(spacing: 0) {
                        ListRow(icon: "mappin.circle.fill", title: destination?.address ?? L("wm_pick_destination"),
                                subtitle: L("wm_going_to"), tint: pal.orange) { picking = true }
                    }
                    .frame(maxWidth: .infinity)
                    .surface()
                    RadiusControl(s: s, km: radiusKm) { radiusKm = $0 }
                    PrimaryButton(L("wm_start"), enabled: destination != nil, busy: busy, color: WithMeTeal) {
                        guard let d = destination else { return }
                        var body: JsonBody = ["destination": ["lat": d.lat, "lng": d.lng, "address": d.address] as JsonBody]
                        if editable { body["radius_m"] = Int(radiusKm * 1000) }
                        call { await DriverApi.openPool(body: body) }
                    }
                }
            }
            .padding(16)
        }
    }
}

/// How far off his road the captain will go — shown only when the admin left a range to choose from.
private struct RadiusControl: View {
    let s: PoolStateDto
    let km: Double
    let onChange: (Double) -> Void
    @Environment(\.pal) private var pal

    var body: some View {
        let minKm = Double(s.radius?.minM ?? 1000) / 1000
        let maxKm = max(Double(s.radius?.maxM ?? 5000) / 1000, minKm)
        let editable = s.radius?.editable ?? true
        let shown = min(max(km, minKm), maxKm)
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 0) {
                Text(L("wm_radius")).font(.dorr(16, .bold)).foregroundStyle(pal.ink).frame(maxWidth: .infinity, alignment: .leading)
                Text(String(format: "%.1f km", locale: Locale(identifier: DriverPrefs.locale), editable ? shown : maxKm))
                    .font(.dorr(16, .black)).foregroundStyle(WithMeTeal)
            }
            if editable {
                Slider(value: Binding(get: { shown }, set: { onChange($0) }), in: minKm...maxKm)
                    .tint(WithMeTeal)
                Text(L("wm_radius_hint")).font(.dorr(11)).foregroundStyle(pal.mut)
            } else {
                Text(L("wm_radius_fixed")).font(.dorr(11)).foregroundStyle(pal.mut).padding(.top, 4)
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .surface()
    }
}

private func statusText(_ status: String) -> String {
    switch status {
    case "arrived": return L("trip_at_pickup")
    case "in_progress": return L("trip_on_ride")
    default: return L("trip_to_pickup")
    }
}
