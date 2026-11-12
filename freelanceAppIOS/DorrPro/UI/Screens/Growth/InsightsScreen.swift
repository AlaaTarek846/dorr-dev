import SwiftUI

// freelanceApp `ui/screens/InsightsScreen.kt`.

/// FL-L01, phase 4: «My performance». One request gives everything for the days chosen (30 / 90 / a year, as far as my plan looks back):
/// what I earned, the funnel of my offers (sent → seen → shortlisted → accepted), the orders on my ready-made services, my name with
/// clients and — on the full level — the earnings over time and the specialties I do best in. A plan without it sees what it opens and
/// the button to its plan, not an error. Read when the page opens, when the days change, when the connection returns and when a notice
/// arrives — never on a timer.
struct InsightsScreen: View {
    @EnvironmentObject private var network: NetworkMonitor
    let onBack: () -> Void
    let onOpenPlans: () -> Void

    @State private var days: Int?
    @State private var insights: InsightsDto?
    @State private var failure: String?

    private func load() async {
        let result = await InsightsApi.insights(days)
        if let data = result.value {
            insights = data
            failure = nil
        } else if insights == nil {
            failure = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
        }
    }

    private struct LoadKey: Equatable { let days: Int?; let reconnect: Int }

    var body: some View {
        Page(title: L("insights_title"), onBack: onBack) {
            if let dto = insights {
                if (dto.locked ?? true) || dto.data == nil {
                    LockedInsights(dto: dto, onOpenPlans: onOpenPlans)
                } else if let data = dto.data {
                    InsightsBody(dto: dto, data: data, chosen: days ?? dto.days, onDays: { days = $0 }, onOpenPlans: onOpenPlans)
                }
            } else if let failure {
                EmptyState(icon: "chart.line.uptrend.xyaxis", title: L("insights_title"), body: failure).padding(.top, 70)
            } else {
                SkeletonDetail()
            }
        }
        .task(id: LoadKey(days: days, reconnect: network.reconnectTick)) { await load() }
        .onReceive(Realtime.shared.changed) { Task { await load() } }
        .messageToast(insights != nil ? failure : nil)
    }
}

/// A plan without the numbers: what they are, and the plan that opens them (or that plans are not sold here).
private struct LockedInsights: View {
    @Environment(\.wa) private var wa
    let dto: InsightsDto
    let onOpenPlans: () -> Void

    var body: some View {
        InfoCard {
            VStack(spacing: 10) {
                Image(systemName: "lock.fill").font(.system(size: 26)).foregroundStyle(wa.red)
                    .frame(width: 64, height: 64).background(wa.red.opacity(0.12), in: Circle())
                Text(L("insights_locked_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                Text(L("insights_locked_body")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4).multilineTextAlignment(.center)
            }
            .frame(maxWidth: .infinity)
            VStack(alignment: .leading, spacing: 8) {
                ForEach(["insights_locked_b1", "insights_locked_b2", "insights_locked_b3"], id: \.self) { Perk(text: L($0)) }
            }
            if let plan = dto.upgrade {
                SettingsPrimaryButton(text: L("insights_unlock", plan.name ?? plan.slug ?? ""), action: onOpenPlans)
            } else {
                Text(L("insights_no_plans")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                    .multilineTextAlignment(.center).frame(maxWidth: .infinity)
            }
        }
    }
}

private struct Perk: View {
    @Environment(\.wa) private var wa
    let text: String

    var body: some View {
        HStack(spacing: 8) {
            Image(systemName: "checkmark.circle.fill").font(.system(size: 16)).foregroundStyle(wa.red).frame(width: 18, height: 18)
            Text(verbatim: text).font(.dorr(13)).foregroundStyle(wa.settingsInk)
        }
    }
}

private struct InsightsBody: View {
    @Environment(\.wa) private var wa
    let dto: InsightsDto
    let data: InsightsDataDto
    let chosen: Int?
    let onDays: (Int) -> Void
    let onOpenPlans: () -> Void

    var body: some View {
        let allowed = dto.allowedDays ?? []
        let period = dto.days ?? chosen ?? allowed.first ?? 30
        let offers = data.offers ?? InsightOffersDto()
        let services = data.services ?? InsightServicesDto()
        let reputation = data.reputation ?? InsightReputationDto()
        if allowed.count > 1 {
            Segmented(options: allowed.map { (String($0), periodLabel($0)) }, selected: String(period)) { onDays(Int($0) ?? period) }
        }

        Hero(data: data, dto: dto, period: period)

        HStack(spacing: 10) {
            InsightStatTile(icon: "paperplane.fill", value: String(offers.sent ?? 0), label: L("insights_stat_sent"))
            InsightStatTile(icon: "chart.line.uptrend.xyaxis", value: percent(offers.acceptRate), label: L("insights_stat_accept"))
        }
        HStack(spacing: 10) {
            InsightStatTile(icon: "storefront.fill", value: String(services.received ?? 0), label: L("insights_services_received"))
            InsightStatTile(
                icon: "star.fill",
                value: reputation.rating.map { String(format: "%.1f", locale: Locale(identifier: "en_US_POSIX"), $0) } ?? L("insights_rating_new"),
                label: L("insights_stat_rating")
            )
        }

        ViewsCard(v: data.views ?? InsightViewsDto(), period: period)
        if let saved = data.saved { SavedCard(s: saved) }
        OffersCard(o: offers)
        ServicesCard(s: services)
        EarningsCard(e: data.earnings ?? InsightEarningsDto(), dto: dto)
        // FS-704: only the full level carries these; at the basic level the fields are absent and nothing shows.
        if let analysis = data.earnings?.analysis { EarningsDetailCard(a: analysis, earned: data.earnings?.earnedMinor ?? 0, dto: dto) }
        ReputationCard(r: reputation)
        if let specialties = data.specialties, !specialties.isEmpty { SpecialtiesCard(rows: specialties) }
        if let portfolio = data.portfolio { PortfolioCard(p: portfolio) }

        // The basic level sees where the rest is.
        if (dto.level ?? 0) < 2, let next = dto.upgrade {
            InfoCard(title: L("insights_more_title"), icon: "chart.line.uptrend.xyaxis") {
                Text(L("insights_more_body", next.name ?? next.slug ?? "")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                Text(L("insights_more_new")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                SettingsPrimaryButton(text: L("insights_unlock", next.name ?? next.slug ?? ""), action: onOpenPlans)
            }
        }
    }
}

private func periodLabel(_ days: Int) -> String {
    switch days {
    case 30: return L("insights_period_30")
    case 90: return L("insights_period_90")
    case 365: return L("insights_period_365")
    default: return L("insights_period_other", days)
    }
}

/// What was earned, large, on the accent colour — the one number most freelancers open this page for.
private struct Hero: View {
    @Environment(\.wa) private var wa
    let data: InsightsDataDto
    let dto: InsightsDto
    let period: Int

    var body: some View {
        let completed = data.reputation?.completedInPeriod ?? 0
        VStack(alignment: .leading, spacing: 6) {
            Text(L("insights_summary", period)).font(.dorr(12, .semibold)).foregroundStyle(.white.opacity(0.85))
            Text(L("insights_stat_earned")).font(.dorr(13)).foregroundStyle(.white.opacity(0.85))
            Text(verbatim: svcMoney(data.earnings?.earnedMinor ?? 0, dto.decimals ?? 2, dto.currency))
                .font(.dorr(30, .heavy)).foregroundStyle(.white)
                .environment(\.layoutDirection, .leftToRight)
            if completed > 0 {
                Text(verbatim: L("insights_rep_in_period") + " · \(completed)").font(.dorr(12)).foregroundStyle(.white.opacity(0.85))
            }
        }
        .padding(20)
        .frame(maxWidth: 520, alignment: .leading)
        .background(LinearGradient(colors: [wa.red, wa.red.opacity(0.72)], startPoint: .topLeading, endPoint: .bottomTrailing),
                    in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        .frame(maxWidth: .infinity)
    }
}

/// InsightsScreen.kt's private `StatTile` (renamed: the new kit has its own `StatTile`).
private struct InsightStatTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let value: String
    let label: String

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red)
                .frame(width: 34, height: 34).background(wa.red.opacity(0.12), in: Circle())
            Text(verbatim: value).font(.dorr(24, .heavy)).foregroundStyle(wa.settingsInk).environment(\.layoutDirection, .leftToRight)
            Text(verbatim: label).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(20)
    }
}

private func percent(_ value: Int?) -> String { value.map { "\($0)%" } ?? "—" }

// MARK: - visits (FL-L11)

/// How often my profile and services were looked at: the two numbers large with how they moved against the period before, where the visits
/// came from, and — on the full level — the visits over time and the services looked at most with the orders they brought. Only numbers: who
/// looked is never kept.
private struct ViewsCard: View {
    @Environment(\.wa) private var wa
    let v: InsightViewsDto
    let period: Int

    var body: some View {
        let profile = v.profile ?? 0, service = v.service ?? 0
        let profileBefore = v.profileBefore ?? 0, serviceBefore = v.serviceBefore ?? 0
        InfoCard(title: L("insights_views_title"), icon: "eye.fill") {
            Text(L("insights_views_sub", period)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            if profile + service + profileBefore + serviceBefore == 0 {
                Text(L("insights_views_none")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            } else {
                HStack(spacing: 10) {
                    ViewsFigure(icon: "person.fill", now: profile, before: profileBefore, label: L("insights_views_profile"))
                    ViewsFigure(icon: "storefront.fill", now: service, before: serviceBefore, label: L("insights_views_service"))
                }
                let total = profile + service
                if total > 0 { SourceBar(by: v.bySource ?? [:], total: total) }
                if let series = v.series, series.contains(where: { ($0.profile ?? 0) + ($0.service ?? 0) > 0 }) {
                    Text(L("insights_views_over_time")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 4)
                    PairBars(points: series.map { (chartLabel($0.from ?? "", v.bucket), $0.profile ?? 0, $0.service ?? 0) })
                }
                if let top = v.topServices, !top.isEmpty {
                    Text(L("insights_views_top")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 4)
                    ForEach(Array(top.enumerated()), id: \.offset) { _, t in
                        HStack(spacing: 8) {
                            Text(verbatim: t.title ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineLimit(1).truncationMode(.tail)
                                .frame(maxWidth: .infinity, alignment: .leading)
                            Text(L("insights_views_top_row", t.views ?? 0, t.orders ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        }
                    }
                }
            }
        }
    }
}

/// One of the two big numbers, with a chip for how it moved against the period before (nothing to compare with → «new»).
private struct ViewsFigure: View {
    @Environment(\.wa) private var wa
    let icon: String
    let now: Int
    let before: Int
    let label: String

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.red).frame(width: 16, height: 16)
                Text(verbatim: label).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
            }
            Text(verbatim: "\(now)").font(.dorr(26, .heavy)).foregroundStyle(wa.settingsInk).environment(\.layoutDirection, .leftToRight)
            Trend(now: now, before: before)
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.red.opacity(0.07), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
    }
}

private struct Trend: View {
    @Environment(\.wa) private var wa
    let now: Int
    let before: Int

    var body: some View {
        let up = Color(hex: 0x16A34A)
        let down = Color(hex: 0xDC2626)
        if now == 0 && before == 0 {
            Text(L("insights_views_flat")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
        } else if before == 0 {
            SvcPill(text: L("insights_views_new"), tone: SvcTone(ink: up, fill: up.opacity(0.12)))
        } else {
            let change = Int(Float(now - before) * 100 / Float(before))
            let tone = change > 0 ? up : (change < 0 ? down : wa.settingsMut)
            HStack(spacing: 3) {
                if change != 0 {
                    Image(systemName: change > 0 ? "arrow.up" : "arrow.down").font(.system(size: 10, weight: .bold)).foregroundStyle(tone)
                }
                Text(verbatim: change == 0 ? "0%" : "\(abs(change))%").font(.dorr(11, .bold)).foregroundStyle(tone)
                    .environment(\.layoutDirection, .leftToRight)
                Text(L("insights_views_vs_before")).font(.dorr(10.5)).foregroundStyle(tone)
            }
            .padding(.horizontal, 8).padding(.vertical, 3)
            .background(tone.opacity(0.12), in: Capsule())
        }
    }
}

/// Where the visits came from, as one bar cut in parts with a line of names under it.
private struct SourceBar: View {
    @Environment(\.wa) private var wa
    let by: [String: Int]
    let total: Int
    @State private var shown = false

    var body: some View {
        let parts: [(n: Int, label: String, color: Color)] = [
            ("offer", "insights_views_src_offer", wa.red),
            ("portfolio", "insights_views_src_portfolio", Color(hex: 0x0EA5E9)),
            ("market", "insights_views_src_market", Color(hex: 0xF59E0B)),
        ].map { (n: by[$0.0] ?? 0, label: L($0.1), color: $0.2) }.filter { $0.n > 0 }
        if !parts.isEmpty {
            VStack(alignment: .leading, spacing: 8) {
                GeometryReader { geo in
                    let gaps = CGFloat(max(parts.count - 1, 0)) * 2
                    let width = max(geo.size.width - gaps, 0)
                    HStack(spacing: 2) {
                        ForEach(Array(parts.enumerated()), id: \.offset) { _, part in
                            Rectangle().fill(part.color).frame(width: shown ? width * CGFloat(part.n) / CGFloat(total) : 0)
                        }
                        Spacer(minLength: 0)
                    }
                }
                .frame(height: 10)
                .clipShape(Capsule())
                .environment(\.layoutDirection, .leftToRight)
                HStack(spacing: 14) {
                    ForEach(Array(parts.enumerated()), id: \.offset) { _, part in
                        HStack(spacing: 5) {
                            Circle().fill(part.color).frame(width: 8, height: 8)
                            Text(verbatim: part.label + " · \(part.n)").font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                        }
                    }
                }
            }
            .onAppear { withAnimation(.easeOut(duration: 0.7)) { shown = true } }
        }
    }
}

/// Two bars per bucket — the profile and the services — time running left to right in either language.
private struct PairBars: View {
    @Environment(\.wa) private var wa
    let points: [(String, Int, Int)]
    @State private var shown = false

    var body: some View {
        let top = max(points.map { max($0.1, $0.2) }.max() ?? 1, 1)
        let service = Color(hex: 0xF59E0B)
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .bottom, spacing: 8) {
                ForEach(Array(points.enumerated()), id: \.offset) { _, point in
                    VStack(spacing: 0) {
                        Spacer(minLength: 0)
                        HStack(alignment: .bottom, spacing: 3) {
                            ForEach(Array([(point.1, wa.red), (point.2, service)].enumerated()), id: \.offset) { _, bar in
                                let fraction = shown ? CGFloat(bar.0) / CGFloat(top) : 0
                                UnevenTopBar(radius: 5)
                                    .fill(bar.0 > 0 ? bar.1 : wa.settingsMut.opacity(0.25))
                                    .frame(width: 12, height: max(fraction * 100, 3))
                            }
                        }
                        Spacer().frame(height: 6)
                        Text(verbatim: point.0).font(.dorr(10)).foregroundStyle(wa.settingsMut).lineLimit(1)
                    }
                    .frame(maxWidth: .infinity)
                }
            }
            .frame(height: 150)
            .environment(\.layoutDirection, .leftToRight)
            HStack(spacing: 14) {
                ForEach(Array([("insights_views_profile", wa.red), ("insights_views_service", service)].enumerated()), id: \.offset) { _, item in
                    HStack(spacing: 5) {
                        Circle().fill(item.1).frame(width: 8, height: 8)
                        Text(L(item.0)).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
                    }
                }
            }
        }
        .onAppear { withAnimation(.easeOut(duration: 0.7)) { shown = true } }
    }
}

/// A bar with only its top corners rounded (Compose `RoundedCornerShape(topStart, topEnd)`).
private struct UnevenTopBar: Shape {
    let radius: CGFloat

    func path(in rect: CGRect) -> Path {
        let r = min(radius, rect.width / 2, rect.height / 2)
        var p = Path()
        p.move(to: CGPoint(x: rect.minX, y: rect.maxY))
        p.addLine(to: CGPoint(x: rect.minX, y: rect.minY + r))
        p.addQuadCurve(to: CGPoint(x: rect.minX + r, y: rect.minY), control: CGPoint(x: rect.minX, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX - r, y: rect.minY))
        p.addQuadCurve(to: CGPoint(x: rect.maxX, y: rect.minY + r), control: CGPoint(x: rect.maxX, y: rect.minY))
        p.addLine(to: CGPoint(x: rect.maxX, y: rect.maxY))
        p.closeSubpath()
        return p
    }
}

/// FS-103: how many client lists I am in - a number, how many were added in the period, and that who is never shown.
private struct SavedCard: View {
    @Environment(\.wa) private var wa
    let s: InsightSavedDto

    var body: some View {
        let lists = s.lists ?? 0
        let added = s.new ?? 0
        InfoCard(title: L("insights_saved_title"), icon: "bookmark.fill") {
            Text(verbatim: "\(lists)").font(.dorr(30, .heavy)).foregroundStyle(wa.settingsInk).environment(\.layoutDirection, .leftToRight)
            if added > 0 {
                SvcPill(text: L("insights_saved_new", added), tone: SvcTone(ink: Color(hex: 0x16A34A), fill: Color(hex: 0x16A34A).opacity(0.12)))
            }
            Text(L("insights_saved_note")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(4)
        }
    }
}

// MARK: - offers

private struct OffersCard: View {
    @Environment(\.wa) private var wa
    let o: InsightOffersDto

    var body: some View {
        let sent = o.sent ?? 0
        InfoCard(title: L("insights_offers_title"), icon: "paperplane.fill") {
            if sent == 0 {
                Text(L("insights_offers_none")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            } else {
                let base = max(sent, 1)
                FunnelRow(icon: "paperplane.fill", label: L("insights_funnel_sent"), count: sent, base: base)
                FunnelRow(icon: "eye.fill", label: L("insights_funnel_viewed"), count: o.viewed ?? 0, base: base)
                FunnelRow(icon: "checkmark.seal.fill", label: L("insights_funnel_shortlisted"), count: o.shortlisted ?? 0, base: base)
                FunnelRow(icon: "checkmark.circle.fill", label: L("insights_funnel_accepted"), count: o.accepted ?? 0, base: base)
                if let rate = o.viewRate {
                    Text(L("insights_view_rate", rate)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk)
                }
                if (o.withdrawn ?? 0) > 0 || (o.rejected ?? 0) > 0 {
                    Text(L("insights_offers_extra", o.withdrawn ?? 0, o.rejected ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
            }
        }
    }
}

/// One step of the funnel: its label and count, and a bar as long as its share of the offers sent.
private struct FunnelRow: View {
    @Environment(\.wa) private var wa
    let icon: String
    let label: String
    let count: Int
    let base: Int
    @State private var shown = false

    var body: some View {
        let share = min(max(CGFloat(count) / CGFloat(base), 0), 1)
        VStack(alignment: .leading, spacing: 5) {
            HStack(spacing: 8) {
                Image(systemName: icon).font(.system(size: 13, weight: .semibold)).foregroundStyle(wa.red).frame(width: 16, height: 16)
                Text(verbatim: label).font(.dorr(13)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: "\(count)").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).environment(\.layoutDirection, .leftToRight)
            }
            GeometryReader { geo in
                ZStack(alignment: .leading) {
                    Capsule().fill(wa.red.opacity(0.12))
                    Capsule().fill(wa.red).frame(width: geo.size.width * (shown ? share : 0))
                }
            }
            .frame(height: 8)
        }
        .onAppear { withAnimation(.easeOut(duration: 0.6)) { shown = true } }
    }
}

// MARK: - services

private struct ServicesCard: View {
    @Environment(\.wa) private var wa
    let s: InsightServicesDto

    var body: some View {
        InfoCard(title: L("insights_services_title"), icon: "storefront.fill") {
            if (s.received ?? 0) == 0 {
                Text(L("insights_services_none")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            } else {
                let amber = Color(hex: 0xD97706)
                HStack(spacing: 8) {
                    MiniCount(count: s.awaiting ?? 0, label: L("insights_services_awaiting"), tone: SvcTone(ink: amber, fill: amber.opacity(0.12)))
                    MiniCount(count: s.active ?? 0, label: L("insights_services_active"), tone: serviceTone("active", night: wa.night))
                    MiniCount(count: s.completed ?? 0, label: L("insights_services_completed"), tone: SvcTone(ink: wa.red, fill: wa.red.opacity(0.12)))
                    MiniCount(count: (s.declined ?? 0) + (s.expired ?? 0), label: L("insights_services_declined"), tone: serviceTone("declined", night: wa.night))
                }
                if let rate = s.acceptRate {
                    Text(L("insights_services_rate", rate)).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsInk)
                }
            }
        }
    }
}

private struct MiniCount: View {
    let count: Int
    let label: String
    let tone: SvcTone

    var body: some View {
        VStack(spacing: 2) {
            Text(verbatim: "\(count)").font(.dorr(20, .heavy)).foregroundStyle(tone.ink)
            Text(verbatim: label).font(.dorr(10.5)).foregroundStyle(tone.ink).multilineTextAlignment(.center).lineLimit(2)
        }
        .padding(.vertical, 10).padding(.horizontal, 4)
        .frame(maxWidth: .infinity)
        .background(tone.fill, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

// MARK: - earnings

private struct EarningsCard: View {
    @Environment(\.wa) private var wa
    let e: InsightEarningsDto
    let dto: InsightsDto

    var body: some View {
        let earned = e.earnedMinor ?? 0
        InfoCard(title: L("insights_earn_title"), icon: "banknote.fill") {
            Text(L("insights_earn_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            if let series = e.series, series.contains(where: { ($0.earnedMinor ?? 0) > 0 }) {
                BarChart(points: series.map { (chartLabel($0.from ?? "", e.bucket), $0.earnedMinor ?? 0) }, decimals: dto.decimals ?? 2)
            } else {
                Text(verbatim: earned > 0 ? svcMoney(earned, dto.decimals ?? 2, dto.currency) : L("insights_earn_none"))
                    .font(earned > 0 ? .dorr(18, .heavy) : .dorr(13)).foregroundStyle(wa.settingsInk)
            }
        }
    }
}

/// Month (`Oct`) or day (`12/10`) under a bar, in the app's language.
private func chartLabel(_ from: String, _ bucket: String?) -> String {
    let parse = DateFormatter()
    parse.locale = Locale(identifier: "en_US_POSIX")
    parse.calendar = Calendar(identifier: .gregorian)
    parse.dateFormat = "yyyy-MM-dd"
    guard let date = parse.date(from: from) else { return from }
    if bucket == "month" {
        let f = DateFormatter()
        f.locale = AppLocale.locale
        f.dateFormat = "MMM"
        return f.string(from: date)
    }
    let parts = Calendar(identifier: .gregorian).dateComponents([.day, .month], from: date)
    return "\(parts.day ?? 0)/\(parts.month ?? 0)"
}

/// Money without the clutter, for the top of a bar: 950, 1.2k, 3.4M.
private func compactMoney(_ minor: Int, _ decimals: Int) -> String {
    let major = Double(minor) / pow(10.0, Double(decimals))
    let posix = Locale(identifier: "en_US_POSIX")
    if major >= 1_000_000 { return String(format: "%.1fM", locale: posix, major / 1_000_000) }
    if major >= 1_000 { return String(format: "%.1fk", locale: posix, major / 1_000) }
    return String(format: "%.0f", locale: posix, major)
}

/// Bars of the earnings, time running left to right in either language; they grow in when the card appears.
private struct BarChart: View {
    @Environment(\.wa) private var wa
    let points: [(String, Int)]
    let decimals: Int
    @State private var shown = false

    var body: some View {
        let top = max(points.map(\.1).max() ?? 1, 1)
        HStack(alignment: .bottom, spacing: 6) {
            ForEach(Array(points.enumerated()), id: \.offset) { _, point in
                let fraction = shown ? CGFloat(point.1) / CGFloat(top) : 0
                VStack(spacing: 0) {
                    Spacer(minLength: 0)
                    if point.1 > 0 {
                        Text(verbatim: compactMoney(point.1, decimals)).font(.dorr(10, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1)
                            .minimumScaleFactor(0.7)
                    }
                    Spacer().frame(height: 4)
                    UnevenTopBar(radius: 7)
                        .fill(point.1 > 0 ? wa.red : wa.settingsMut.opacity(0.3))
                        .frame(width: 22, height: max(fraction * 110, 3))
                    Spacer().frame(height: 6)
                    Text(verbatim: point.0).font(.dorr(10)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                .frame(maxWidth: .infinity)
            }
        }
        .frame(height: 170)
        .environment(\.layoutDirection, .leftToRight)
        .onAppear { withAnimation(.easeOut(duration: 0.7)) { shown = true } }
    }
}

// MARK: - reputation and specialties

private struct ReputationCard: View {
    let r: InsightReputationDto

    var body: some View {
        InfoCard(title: L("insights_rep_title"), icon: "star.fill") {
            RepLine(
                label: L("insights_stat_rating"),
                value: r.rating.map {
                    String(format: "%.1f", locale: Locale(identifier: "en_US_POSIX"), $0) + "  ·  " + L("insights_rep_reviews", r.reviewsCount ?? 0)
                } ?? L("insights_rating_new")
            )
            RepLine(label: L("insights_rep_completed"), value: String(r.completedProjects ?? 0))
            RepLine(label: L("insights_rep_completion"), value: percent(r.completionRate))
            RepLine(label: L("insights_rep_ontime"), value: percent(r.onTimeRate))
        }
    }
}

private struct RepLine: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String

    var body: some View {
        HStack {
            Text(verbatim: label).font(.dorr(13)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
            Text(verbatim: value).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).environment(\.layoutDirection, .leftToRight)
        }
    }
}

private struct SpecialtiesCard: View {
    @Environment(\.wa) private var wa
    let rows: [InsightSpecialtyDto]

    var body: some View {
        InfoCard(title: L("insights_spec_title"), icon: "chart.line.uptrend.xyaxis") {
            ForEach(Array(rows.enumerated()), id: \.offset) { _, s in
                HStack(spacing: 0) {
                    VStack(alignment: .leading, spacing: 1) {
                        Text(verbatim: s.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk)
                        Text(L("insights_spec_row", s.sent ?? 0, s.accepted ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                    Spacer().frame(width: 8)
                    SvcPill(text: percent(s.acceptRate), tone: SvcTone(ink: wa.red, fill: wa.red.opacity(0.12)))
                }
            }
        }
    }
}

// MARK: - earnings in detail (FS-704)

/// FS-704, the full level: what the period's earnings are made of — how they moved against the stretch before (the server's own percent; nothing
/// before = «new»), where they came from (projects, ready-made services, consultations), the specialties and clients that brought the most (each with
/// its share), how many projects paid and what one brings on average.
private struct EarningsDetailCard: View {
    @Environment(\.wa) private var wa
    let a: InsightEarningsAnalysisDto
    let earned: Int
    let dto: InsightsDto

    var body: some View {
        let decimals = dto.decimals ?? 2
        let s = a.sources ?? InsightSourcesDto()
        let total = (s.projects ?? 0) + (s.services ?? 0) + (s.consultations ?? 0)
        InfoCard(title: L("insights_detail_title"), icon: "banknote.fill") {
            Text(L("insights_detail_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            if earned <= 0 && (a.beforeMinor ?? 0) <= 0 {
                Text(L("insights_detail_none")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            } else {
                HStack(spacing: 10) {
                    ChangeChip(percent: a.changePercent)
                    Text(L("insights_detail_before", svcMoney(a.beforeMinor ?? 0, decimals, dto.currency))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                if total > 0 {
                    Text(L("insights_detail_sources")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 4)
                    SegmentBar(
                        parts: [
                            (label: L("insights_src_projects"), n: s.projects ?? 0, color: wa.red),
                            (label: L("insights_src_services"), n: s.services ?? 0, color: Color(hex: 0xF59E0B)),
                            (label: L("insights_src_consultations"), n: s.consultations ?? 0, color: Color(hex: 0x0EA5E9)),
                        ].filter { $0.n > 0 },
                        total: total, decimals: decimals, currency: dto.currency
                    )
                }
                if let rows = a.bySpecialty, !rows.isEmpty {
                    Text(L("insights_detail_specialty")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 4)
                    ForEach(Array(rows.enumerated()), id: \.offset) { _, r in
                        ShareRow(label: (r.name ?? "").isEmpty ? "—" : (r.name ?? ""), money: svcMoney(r.earnedMinor ?? 0, decimals, dto.currency), share: r.share)
                    }
                }
                if let rows = a.topClients, !rows.isEmpty {
                    Text(L("insights_detail_clients")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 4)
                    ForEach(Array(rows.enumerated()), id: \.offset) { _, r in
                        ShareRow(label: (r.name ?? "").isEmpty ? L("insights_detail_client_unknown") : (r.name ?? ""),
                                 money: svcMoney(r.earnedMinor ?? 0, decimals, dto.currency), share: r.share)
                    }
                }
                HStack(spacing: 10) {
                    DetailFigure(value: String(a.projectsPaid ?? 0), label: L("insights_detail_paid"))
                    DetailFigure(value: a.averageProjectMinor.map { svcMoney($0, decimals, dto.currency) } ?? "—", label: L("insights_detail_avg"))
                }
            }
        }
    }
}

/// How a number moved against the period before: an arrow and the server's percent; nil (nothing before) is «new». Read out in words for VoiceOver.
private struct ChangeChip: View {
    @Environment(\.wa) private var wa
    let percent: Int?

    private var spoken: String {
        guard let percent else { return L("insights_detail_first") }
        if percent > 0 { return L("insights_detail_up", percent) }
        if percent < 0 { return L("insights_detail_down", -percent) }
        return L("insights_detail_same")
    }

    var body: some View {
        let up = Color(hex: 0x16A34A)
        let down = Color(hex: 0xDC2626)
        Group {
            if let percent {
                let tone = percent > 0 ? up : (percent < 0 ? down : wa.settingsMut)
                HStack(spacing: 3) {
                    if percent != 0 {
                        Image(systemName: percent > 0 ? "arrow.up" : "arrow.down").font(.system(size: 11, weight: .bold)).foregroundStyle(tone)
                    }
                    Text(verbatim: "\(abs(percent))%").font(.dorr(12, .heavy)).foregroundStyle(tone).environment(\.layoutDirection, .leftToRight)
                }
                .padding(.horizontal, 9).padding(.vertical, 4)
                .background(tone.opacity(0.12), in: Capsule())
            } else {
                SvcPill(text: L("insights_views_new"), tone: SvcTone(ink: up, fill: up.opacity(0.12)))
            }
        }
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(spoken)
    }
}

/// One bar cut in the parts of `total` (animated), then a row for each part: its colour, name, amount and share.
private struct SegmentBar: View {
    @Environment(\.wa) private var wa
    let parts: [(label: String, n: Int, color: Color)]
    let total: Int
    let decimals: Int
    let currency: String?
    @State private var shown = false

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            GeometryReader { geo in
                let gaps = CGFloat(max(parts.count - 1, 0)) * 2
                let width = max(geo.size.width - gaps, 0)
                HStack(spacing: 2) {
                    ForEach(Array(parts.enumerated()), id: \.offset) { _, part in
                        Rectangle().fill(part.color).frame(width: shown ? width * CGFloat(part.n) / CGFloat(max(total, 1)) : 0)
                    }
                    Spacer(minLength: 0)
                }
            }
            .frame(height: 10)
            .clipShape(Capsule())
            .environment(\.layoutDirection, .leftToRight)
            ForEach(Array(parts.enumerated()), id: \.offset) { _, part in
                HStack(spacing: 8) {
                    Circle().fill(part.color).frame(width: 8, height: 8)
                    Text(verbatim: part.label).font(.dorr(13)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                    Text(verbatim: svcMoney(part.n, decimals, currency)).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk)
                        .environment(\.layoutDirection, .leftToRight)
                    Text(L("insights_detail_share", part.n * 100 / max(total, 1))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        .frame(minWidth: 34, alignment: .trailing).environment(\.layoutDirection, .leftToRight)
                }
            }
        }
        .onAppear { withAnimation(.easeOut(duration: 0.7)) { shown = true } }
    }
}

/// A name with what it earned and its share of the period, and a bar as long as the share.
private struct ShareRow: View {
    @Environment(\.wa) private var wa
    let label: String
    let money: String
    let share: Int?
    @State private var shown = false

    var body: some View {
        let fraction = min(max(CGFloat(share ?? 0) / 100, 0), 1)
        VStack(alignment: .leading, spacing: 5) {
            HStack(spacing: 8) {
                Text(verbatim: label).font(.dorr(13)).foregroundStyle(wa.settingsInk).lineLimit(1).truncationMode(.tail)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: money).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).environment(\.layoutDirection, .leftToRight)
                if let share {
                    Text(L("insights_detail_share", share)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                        .frame(minWidth: 34, alignment: .trailing).environment(\.layoutDirection, .leftToRight)
                }
            }
            GeometryReader { geo in
                ZStack(alignment: .leading) {
                    Capsule().fill(wa.red.opacity(0.12))
                    Capsule().fill(wa.red).frame(width: geo.size.width * (shown ? fraction : 0))
                }
            }
            .frame(height: 6)
        }
        .onAppear { withAnimation(.easeOut(duration: 0.6)) { shown = true } }
    }
}

/// A small tinted figure: a label over a value, and an optional line under them.
private struct DetailFigure<Below: View>: View {
    @Environment(\.wa) private var wa
    let value: String
    let label: String
    let below: () -> Below

    init(value: String, label: String, @ViewBuilder below: @escaping () -> Below) {
        self.value = value
        self.label = label
        self.below = below
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 5) {
            Text(verbatim: label).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2).lineLimit(2)
            Text(verbatim: value).font(.dorr(18, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1).minimumScaleFactor(0.7)
                .environment(\.layoutDirection, .leftToRight)
            below()
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.red.opacity(0.07), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
    }
}

extension DetailFigure where Below == EmptyView {
    init(value: String, label: String) {
        self.init(value: value, label: label, below: { EmptyView() })
    }
}

// MARK: - the portfolio (FS-704)

/// FS-704, the full level: how clients look at my works — how many there are, how many are shown and verified, the visits to the portfolio, the
/// works opened (against the period before) and the ones opened most. Counted per day, never per person.
private struct PortfolioCard: View {
    @Environment(\.wa) private var wa
    let p: InsightPortfolioDto

    var body: some View {
        InfoCard(title: L("insights_pf_title"), icon: "photo.on.rectangle.angled") {
            Text(L("insights_pf_sub")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            if (p.works ?? 0) == 0 {
                Text(L("insights_pf_no_works")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
            } else {
                HStack(spacing: 8) {
                    DetailFigure(value: String(p.works ?? 0), label: L("insights_pf_works"))
                    DetailFigure(value: String(p.shown ?? 0), label: L("insights_pf_shown"))
                    DetailFigure(value: String(p.verified ?? 0), label: L("insights_pf_verified"))
                }
                HStack(spacing: 10) {
                    DetailFigure(value: String(p.visits ?? 0), label: L("insights_pf_visits"))
                    DetailFigure(value: String(p.opened ?? 0), label: L("insights_pf_opened")) { ChangeChip(percent: p.openedChangePercent) }
                }
                if (p.opened ?? 0) == 0 {
                    Text(L("insights_pf_none")).font(.dorr(13)).foregroundStyle(wa.settingsMut).lineSpacing(4)
                } else if let top = p.top, !top.isEmpty {
                    Text(L("insights_pf_top")).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.top, 4)
                    let most = max(top.map { $0.views ?? 0 }.max() ?? 1, 1)
                    ForEach(Array(top.enumerated()), id: \.offset) { _, w in
                        TopWorkRow(w: w, most: most)
                    }
                }
            }
        }
    }
}

/// One of the works opened most: the verified mark, the title, where it shows, the views and a bar as long as its share of the most opened.
private struct TopWorkRow: View {
    @Environment(\.wa) private var wa
    let w: InsightTopWorkDto
    let most: Int
    @State private var shown = false

    var body: some View {
        let fraction = min(max(CGFloat(w.views ?? 0) / CGFloat(most), 0), 1)
        let isPublic = (w.visibility ?? "public") == "public"
        let amber = Color(hex: 0xD97706)
        VStack(alignment: .leading, spacing: 5) {
            HStack(spacing: 6) {
                if w.verified ?? false {
                    Image(systemName: "checkmark.seal.fill").font(.system(size: 14)).foregroundStyle(wa.red).frame(width: 16, height: 16)
                        .accessibilityLabel(L("insights_pf_verified_work"))
                }
                Text(verbatim: w.title ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk).lineLimit(1).truncationMode(.tail)
                    .frame(maxWidth: .infinity, alignment: .leading)
                SvcPill(text: visibilityText(w.visibility),
                        tone: isPublic ? SvcTone(ink: wa.red, fill: wa.red.opacity(0.12)) : SvcTone(ink: amber, fill: amber.opacity(0.12)))
                Text(L("insights_pf_views", w.views ?? 0)).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk)
                    .frame(minWidth: 26, alignment: .trailing).environment(\.layoutDirection, .leftToRight)
            }
            GeometryReader { geo in
                ZStack(alignment: .leading) {
                    Capsule().fill(wa.red.opacity(0.12))
                    Capsule().fill(wa.red).frame(width: geo.size.width * (shown ? fraction : 0))
                }
            }
            .frame(height: 6)
        }
        .onAppear { withAnimation(.easeOut(duration: 0.6)) { shown = true } }
    }

    private func visibilityText(_ v: String?) -> String {
        switch v {
        case "clients_only": return L("vis_clients")
        case "private": return L("vis_private")
        default: return L("vis_public")
        }
    }
}
