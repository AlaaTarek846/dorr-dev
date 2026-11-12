import SwiftUI

// androidApp `moving/MovingHome.kt` — the moving home: a hero that says what the service does (the truck on its road),
// how it works in three steps, the button for a new request, then «طلباتي» with the "My rides" pill tabs and each
// request as its history card. Where the service is off in this country (`options.available`) the rides' unavailable
// card takes the place of the button, before any wizard opens; requests already made can still be followed.

@MainActor
struct MovingHomePage: View {
    @Environment(\.wa) private var wa
    @ObservedObject var nav: MovingNav
    @ObservedObject private var network = NetworkMonitor.shared
    let onExit: () -> Void
    @State private var tab = "active"
    @State private var rows: [MovingRequestDto]?
    @State private var page = 1
    @State private var hasMore = false
    @State private var loadingMore = false
    @State private var error: String?
    @State private var checked = false
    @State private var reloadKey = 0
    @State private var deleting: MovingRequestDto?

    var body: some View {
        MvPage(title: L("mv_title"), onBack: onExit) {
            VStack(spacing: 0) {
                ScrollView {
                    LazyVStack(alignment: .leading, spacing: 12) {
                        MovingHero().staggerIn(0)
                        MovingHowItWorks().staggerIn(1)
                        if !nav.serviceOff && checked {
                            AccentButton(text: L("mv_new_request"), icon: "plus") { nav.push(.wizard(uuid: nil, atReview: false)) }
                                .staggerIn(2)
                        }
                        VStack(alignment: .leading, spacing: 8) {
                            Text(L("mv_my_requests")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk)
                            tabs
                        }
                        .padding(.top, 6)
                        list
                    }
                    .padding(.horizontal, 16)
                    .padding(.top, 4)
                    .padding(.bottom, 24)
                }
                .refreshable { await mvPull() }
                .frame(maxHeight: .infinity)
                if nav.serviceOff {
                    MovingUnavailablePanel {
                        checked = false
                        nav.serviceOff = false
                    }
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
            .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32), value: nav.serviceOff)
        }
        // Is the service open here? Asked on opening, so the button for a new request shows only when it is.
        .task(id: "\(network.reconnectTick)|\(nav.serviceOff)") {
            if !nav.serviceOff {
                let options = try? await MovingApi.options().data
                if options?.available == false { nav.serviceOff = true }
            }
            checked = true
        }
        .task(id: "\(tab)|\(network.reconnectTick)|\(reloadKey)") { await load() }
        // A draft is taken back with `withdraw`: it leaves the running list, and «كل طلباتي» still shows it as withdrawn.
        .sheet(item: $deleting) { draft in
            MovingWithdrawSheet(uuid: draft.uuid, title: L("mv_draft_delete_title"), body: L("mv_draft_delete_body"), action: L("mv_draft_delete"),
                                onClose: { deleting = nil },
                                onDone: { message in
                                    deleting = nil
                                    if let message, !message.isEmpty { AppToast.show(message) }
                                    reloadKey += 1
                                })
                .dorrThemed().presentationDetents([.medium, .large])
        }
    }

    /// The tabs: a pill that fills the chosen one.
    private var tabs: some View {
        HStack(spacing: 0) {
            ForEach([("active", "mv_tab_active"), ("all", "mv_tab_all")], id: \.0) { key, label in
                let on = key == tab
                Button {
                    if !on { tab = key; rows = nil }
                } label: {
                    Text(L(label)).font(.dorr(14, on ? .bold : .semibold)).foregroundStyle(on ? .white : wa.settingsInk)
                        .frame(maxWidth: .infinity).padding(.vertical, 10)
                        .background(on ? wa.accent : .clear, in: Capsule())
                }
                .buttonStyle(.plain)
            }
        }
        .padding(4)
        .background(wa.settingsCard, in: Capsule())
        .animation(.easeInOut(duration: 0.24), value: tab)
    }

    @ViewBuilder
    private var list: some View {
        if let list = rows {
            if list.isEmpty {
                MovingHomeEmpty(text: error ?? L(nav.serviceOff ? "mv_empty_off" : "mv_empty"))
            } else {
                ForEach(Array(list.enumerated()), id: \.element.uuid) { i, row in
                    RequestCard(item: row, onDelete: row.status == "draft" ? { deleting = row } : nil) {
                        nav.push(row.status == "draft" ? .wizard(uuid: row.uuid, atReview: false) : .request(row.uuid))
                    }
                    .staggerIn(i)
                }
                if hasMore {
                    ProgressView().tint(wa.accent).frame(maxWidth: .infinity).padding(.vertical, 8)
                        .onAppear { loadMore() }
                }
            }
        } else {
            ProgressView().tint(wa.accent).frame(maxWidth: .infinity).frame(height: 140)
        }
    }

    private func load() async {
        error = nil
        do {
            let envelope = try await MovingApi.requests(status: tab == "active" ? "active" : nil, page: 1)
            rows = envelope.data ?? []
            page = 1
            hasMore = envelope.pagination?.hasMorePages == true
        } catch {
            let refusal = error.movingRefusal
            if refusal.code == "moving_service_off" {
                nav.serviceOff = true
                if rows == nil { rows = [] }
            } else {
                self.error = refusal.message ?? L("rd_error")
                if rows == nil { rows = [] }
            }
        }
    }

    private func loadMore() {
        guard !loadingMore, hasMore else { return }
        loadingMore = true
        Task {
            do {
                let envelope = try await MovingApi.requests(status: tab == "active" ? "active" : nil, page: page + 1)
                let known = Set((rows ?? []).map(\.uuid))
                rows = (rows ?? []) + (envelope.data ?? []).filter { !known.contains($0.uuid) }
                page += 1
                hasMore = envelope.pagination?.hasMorePages == true
            } catch {
                hasMore = false
            }
            loadingMore = false
        }
    }
}

/// The hero's colours: the same navy-to-accent wash as the app's dark hero panels (calendar, Moments), by day and night.
@MainActor
private func mvHeroColors(_ appearance: AppearanceState, night: Bool) -> [Color] {
    if night {
        let accent = appearance.color("primary", Color(hex: 0xFA7552), dark: true)
        return [accent, accent.scaled(0.62), accent.scaled(0.40)]
    }
    let primary = appearance.color("primary", Color(hex: 0x001B53), dark: false)
    let light = appearance.color("primaryLight", Color(hex: 0x1E3A7B), dark: false)
    let dark = appearance.color("primaryDark", Color(hex: 0x00113A), dark: false)
    let accent = appearance.color("accent", Color(hex: 0xFA7552), dark: false)
    var lr: CGFloat = 0, lg: CGFloat = 0, lb: CGFloat = 0, la: CGFloat = 0
    var ar: CGFloat = 0, ag: CGFloat = 0, ab: CGFloat = 0, aa: CGFloat = 0
    UIColor(light).getRed(&lr, green: &lg, blue: &lb, alpha: &la)
    UIColor(accent).getRed(&ar, green: &ag, blue: &ab, alpha: &aa)
    let top = Color(red: Double(min(lr * 0.70 + ar * 0.30, 1)), green: Double(min(lg * 0.70 + ag * 0.30, 1)), blue: Double(min(lb * 0.70 + ab * 0.30, 1)))
    return [top, primary, dark]
}

/// The hero: what the service is in one line, and the truck driving on its road (it faces the reading direction).
/// The motion is the device's own animation; nothing is asked of the server.
private struct MovingHero: View {
    @Environment(\.wa) private var wa
    @Environment(\.layoutDirection) private var direction
    @ObservedObject private var appearance = AppearanceState.shared

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 26, style: .continuous)
        VStack(alignment: .leading, spacing: 0) {
            Text(L("mv_title")).font(.dorr(11, .bold)).foregroundStyle(.white)
                .padding(.horizontal, 10).padding(.vertical, 4)
                .background(Color.white.opacity(0.16), in: Capsule())
            Text(L("mv_hero_title")).font(.dorr(22, .black)).foregroundStyle(.white)
                .fixedSize(horizontal: false, vertical: true)
                .padding(.top, 10)
            Text(L("mv_hero_body")).font(.dorr(13)).foregroundStyle(Color.white.opacity(0.82))
                .fixedSize(horizontal: false, vertical: true)
                .padding(.top, 6)
            road.frame(height: 70).padding(.top, 16)
        }
        .padding(20)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background {
            ZStack {
                LinearGradient(colors: mvHeroColors(appearance, night: wa.night), startPoint: .topLeading, endPoint: .bottomTrailing)
                // Soft circles behind, for depth.
                Circle().fill(Color.white.opacity(0.07)).frame(width: 170, height: 170)
                    .offset(x: 40, y: -50)
                    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topTrailing)
                Circle().fill(Color.white.opacity(0.05)).frame(width: 120, height: 120)
                    .offset(x: -30, y: 40)
                    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomLeading)
            }
        }
        .clipShape(shape)
    }

    private var road: some View {
        let rtl = direction == .rightToLeft
        return TimelineView(.animation) { context in
            let t = context.date.timeIntervalSinceReferenceDate
            let phase = (t / 0.9).truncatingRemainder(dividingBy: 1) * 44
            let bobStep = (t / 0.42).truncatingRemainder(dividingBy: 2)
            let bob = -4 * (0.5 - 0.5 * cos(Double.pi * (bobStep < 1 ? bobStep : 2 - bobStep)))
            let glowStep = (t / 1.4).truncatingRemainder(dividingBy: 2)
            let glow = 0.10 + 0.12 * (0.5 - 0.5 * cos(Double.pi * (glowStep < 1 ? glowStep : 2 - glowStep)))
            ZStack {
                HeroRoad(rtl: rtl, phase: phase)
                    .frame(height: 10)
                    .frame(maxHeight: .infinity, alignment: .bottom)
                ZStack {
                    Circle().fill(Color.white.opacity(glow)).frame(width: 64, height: 64)
                    Circle().fill(Color.white).frame(width: 48, height: 48)
                    Image(systemName: "box.truck.fill").font(.system(size: 22)).foregroundStyle(AppColors.brandOrange)
                        .environment(\.layoutDirection, .leftToRight)
                        .scaleEffect(x: rtl ? -1 : 1, y: 1)
                }
                .offset(y: -8 + bob)
            }
        }
    }
}

/// The hero's road: a soft band, and the dashes running against the truck (drawn from its front to its back).
private struct HeroRoad: View {
    let rtl: Bool
    let phase: Double

    var body: some View {
        Canvas { g, size in
            let y = size.height / 2
            var base = Path()
            base.move(to: CGPoint(x: 0, y: y))
            base.addLine(to: CGPoint(x: size.width, y: y))
            g.stroke(base, with: .color(Color.white.opacity(0.14)), style: StrokeStyle(lineWidth: 8, lineCap: .round))
            var dashes = Path()
            dashes.move(to: CGPoint(x: rtl ? 0 : size.width, y: y))
            dashes.addLine(to: CGPoint(x: rtl ? size.width : 0, y: y))
            g.stroke(dashes, with: .color(Color.white.opacity(0.7)), style: StrokeStyle(lineWidth: 2, dash: [22, 22], dashPhase: CGFloat(-phase)))
        }
    }
}

/// How it works, in three steps side by side.
private struct MovingHowItWorks: View {
    var body: some View {
        MvCard {
            MvCardTitle(text: L("mv_how_title"))
            HStack(alignment: .top, spacing: 8) {
                HowStep(number: 1, icon: "square.and.pencil", title: L("mv_how_1_title"), text: L("mv_how_1_body"))
                HowStep(number: 2, icon: "tag.fill", title: L("mv_how_2_title"), text: L("mv_how_2_body"))
                HowStep(number: 3, icon: "checkmark.shield.fill", title: L("mv_how_3_title"), text: L("mv_how_3_body"))
            }
            .padding(.top, 14)
        }
    }
}

private struct HowStep: View {
    @Environment(\.wa) private var wa
    let number: Int
    let icon: String
    let title: String
    let text: String

    var body: some View {
        VStack(spacing: 0) {
            // The number sits on the tile's top-end corner, 6 points out.
            ZStack(alignment: .topTrailing) {
                Image(systemName: icon).font(.system(size: 21)).foregroundStyle(wa.accent)
                    .frame(width: 50, height: 50)
                    .background(wa.accent.opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                    .padding(6)
                Text(verbatim: String(number)).font(.dorr(11, .black)).foregroundStyle(.white)
                    .frame(width: 20, height: 20)
                    .background(wa.accent, in: Circle())
            }
            .padding(-6)
            Text(verbatim: title).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                .multilineTextAlignment(.center).lineLimit(1).padding(.top, 8)
            Text(verbatim: text).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                .multilineTextAlignment(.center)
                .fixedSize(horizontal: false, vertical: true)
        }
        .frame(maxWidth: .infinity)
    }
}

/// No requests yet: the truck in its circle and a line, inside the list.
private struct MovingHomeEmpty: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        VStack(spacing: 0) {
            Image(systemName: "box.truck.fill").font(.system(size: 26)).foregroundStyle(wa.accent)
                .frame(width: 64, height: 64).background(wa.accent.opacity(0.1), in: Circle())
            Text(verbatim: text).font(.dorr(13.5)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).padding(.top, 10)
        }
        .padding(24)
        .frame(maxWidth: .infinity)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
    }
}

private struct RequestCard: View {
    @Environment(\.wa) private var wa
    let item: MovingRequestDto
    let onDelete: (() -> Void)?
    let action: () -> Void

    var body: some View {
        let (label, color) = movingStatusLook(item.status, wa)
        Button(action: action) {
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    Image(systemName: "box.truck.fill").font(.system(size: 20)).foregroundStyle(color)
                        .frame(width: 44, height: 44).background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: loadTypeLabel(item.loadType)).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                        if let t = mvTime(item.scheduledAt) { Text(verbatim: rideWhenText(t)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    }
                    Spacer(minLength: 0)
                    MvChip(text: label, color: color)
                }
                let from = item.pickup?.area ?? item.pickup?.address
                let to = item.dropoff?.area ?? item.dropoff?.address
                if from != nil || to != nil {
                    HStack(spacing: 8) {
                        VStack(alignment: .leading, spacing: 0) {
                            TripLine(dot: AppColors.brandNavy, address: from)
                            TripLine(dot: AppColors.brandOrange, address: to)
                        }
                        Spacer(minLength: 0)
                        if let km = item.distanceKm {
                            Text(verbatim: L("mv_km", movingKmText(km))).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                        }
                    }
                    .padding(.top, 8)
                }
                let total = item.money?.totalMinor
                if total != nil || item.establishment?.name != nil {
                    HStack {
                        Text(verbatim: item.establishment?.name ?? "").font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        Spacer()
                        if let total { Text(verbatim: mvMoney(total, item.currencyCode, item.currencyDecimals)).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk) }
                    }
                    .padding(.top, 6)
                }
                if let onDelete {
                    Button(action: onDelete) {
                        Text(L("mv_draft_delete")).font(.dorr(12, .semibold)).foregroundStyle(AppColors.danger).padding(.vertical, 4)
                    }
                    .buttonStyle(.plain)
                    .padding(.top, 6)
                }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        }
        .buttonStyle(WaPressStyle())
    }
}

/// "Moving isn't available here": the rides' unavailable card, with its own words.
struct MovingUnavailablePanel: View {
    @Environment(\.wa) private var wa
    let onRetry: () -> Void
    @State private var pulse = false

    var body: some View {
        PanelCard {
            VStack(spacing: 8) {
                Image(systemName: "location.slash.fill").font(.system(size: 30)).foregroundStyle(wa.accent)
                    .frame(width: 72, height: 72).background(wa.accent.opacity(0.12), in: Circle())
                    .scaleEffect(pulse ? 1.08 : 1)
                    .onAppear { withAnimation(.easeInOut(duration: 1.1).repeatForever()) { pulse = true } }
                Text(L("mv_na_title")).font(.dorr(18, .bold)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                Text(L("mv_na_body")).font(.dorr(14)).foregroundStyle(wa.settingsInk.opacity(0.65)).multilineTextAlignment(.center)
                AccentButton(text: L("rd_retry"), action: onRetry).padding(.top, 6)
            }
            .frame(maxWidth: .infinity)
        }
        .ignoresSafeArea(edges: .bottom)
    }
}
