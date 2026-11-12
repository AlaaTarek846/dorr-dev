// freelanceApp `ui/screens/HomeScreen.kt`
import SwiftUI

/// The Home tab, a dashboard (the redesign, batch B): what waits for my answer, a slider of the staff's banners and my next steps, my
/// numbers, the next consultation, the work with the nearest dates, projects that fit me and my tools. One `GET home` (and one list of
/// projects) when the tab opens, when the connection returns and when a notice arrives — never on a timer. Each section shows only when
/// it has something.
@MainActor
struct HomeScreen: View {
    let actions: MainActions
    let failed: String?

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @ObservedObject private var session = Session.shared
    @ObservedObject private var hub = HomeHub.shared
    @State private var picks: [ProjectDto]?

    var body: some View {
        let profile = session.me
        let h = hub.home
        // Pulling down reads the home once more (the freelancer's own gesture, not a timer).
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                VStack(alignment: .leading, spacing: 14) {
                    if let profile {
                        // FL-708: shown only while a warning / restriction is active or its appeal waits.
                        SanctionsCard()
                        if profile.availability?.canReceiveOffers == false { PausedCard(onOpen: actions.openAvailability) }
                    }
                    if let h { AttentionStrip(h: h) }
                }
                .padding(.horizontal, 16)
                .padding(.top, 14)

                Spacer().frame(height: 14)
                HeroSlider(slides: heroSlides(h))

                VStack(alignment: .leading, spacing: 14) {
                    if let h {
                        Reveal(index: 0) { StatsRow(h: h, actions: actions) }
                        if let c = h.nextConsultation {
                            Reveal(index: 1) { NextConsultationCard(c: c, serverTime: h.serverTime) { actions.openConsultation(c.uuid ?? "") } }
                        }
                        let work = h.work ?? []
                        if !work.isEmpty {
                            Reveal(index: 2) {
                                VStack(alignment: .leading, spacing: 10) {
                                    SectionHeader(title: L("home_my_work"), action: L("home_see_all"), onAction: { ShellState.shared.open(Tabs.WORK, segment: "projects") })
                                    ForEach(Array(work.enumerated()), id: \.offset) { _, w in
                                        WorkRow(w: w) { if let uuid = w.projectUuid { actions.openProject(uuid) } }
                                    }
                                }
                            }
                        }
                    } else if failed == nil {
                        HomeSkeleton()
                    }
                }
                .padding(.horizontal, 16)

                if let list = picks, !list.isEmpty {
                    Spacer().frame(height: 14)
                    SectionHeader(title: L("home_picks"), action: L("home_see_all"), onAction: { ShellState.shared.open(Tabs.OPPORTUNITIES, segment: "projects") })
                        .padding(.horizontal, 16)
                    Spacer().frame(height: 8)
                    ScrollView(.horizontal, showsIndicators: false) {
                        LazyHStack(spacing: 10) {
                            ForEach(list) { p in PickCard(p: p) { actions.openProject(p.uuid ?? "") } }
                        }
                        .padding(.horizontal, 16)
                        // Room for the cards' shadow.
                        .padding(.vertical, 6)
                    }
                }

                VStack(alignment: .leading, spacing: 10) {
                    if let h { ToolsGrid(h: h, actions: actions) }
                    if let profile, let h, profile.status != "verified" {
                        StartJourney(percent: profile.completeness?.percent ?? 0, status: profile.status, h: h, actions: actions)
                    }
                }
                .padding(.horizontal, 16)
                .padding(.top, 14)
            }
            .padding(.bottom, 24)
        }
        .refreshable { await load() }
        .messageToast(failed)
        .task(id: network.reconnectTick) {
            await load()
            await CommunityHub.shared.refresh()
        }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
    }

    private func load() async {
        await HomeHub.shared.refresh()
        if let list = await ProjectsApi.list(perPage: 8).value { picks = list }
    }

    /// The slider's cards: the staff's banners first, then my next steps.
    private func heroSlides(_ h: HomeDto?) -> [HeroSlide] {
        let me = session.me
        let percent = me?.completeness?.percent ?? 0
        var slides: [HeroSlide] = []
        for p in h?.promos ?? [] {
            slides.append(HeroSlide(
                key: p.uuid ?? "", title: p.title ?? "", body: p.body, cta: p.cta, image: p.imagePath,
                onClick: promoAction(p, actions) { url in if let u = URL(string: url) { UIApplication.shared.open(u) } }
            ))
        }
        if me != nil && percent < 100 {
            slides.append(HeroSlide(key: "coach", title: L("home_slide_coach_title"), body: L("home_slide_coach_body"), cta: L("home_slide_coach_cta"),
                                    icon: "sparkles", big: "\(percent)%", onClick: actions.openCoach))
        }
        if me != nil && me?.status != "verified" {
            slides.append(HeroSlide(key: "verify", title: L("home_slide_verify_title"), body: L("home_slide_verify_body"), cta: L("home_slide_verify_cta"),
                                    icon: "person.text.rectangle.fill", onClick: actions.openVerification))
        }
        if h?.on("community") == true {
            slides.append(HeroSlide(key: "community", title: L("home_slide_community_title"), body: L("home_slide_community_body"), cta: L("home_slide_community_cta"),
                                    icon: "bubble.left.and.bubble.right.fill", onClick: { ShellState.shared.open(Tabs.COMMUNITY) }))
        }
        if let h, h.on("subscriptions"), !(h.promos ?? []).contains(where: { $0.targetType == "screen" && $0.target == "plans" }) {
            slides.append(HeroSlide(key: "plans", title: L("home_slide_plans_title"), body: L("home_slide_plans_body"), cta: L("home_slide_plans_cta"),
                                    icon: "rosette", onClick: actions.openPlans))
        }
        return slides
    }
}

/// The home while its first answer is on its way: the slider, a row of number tiles and two rows of work, in grey.
private struct HomeSkeleton: View {
    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            SkeletonBlock(height: 16, fraction: nil, width: 110)
            HStack(spacing: 10) {
                ForEach(0..<3, id: \.self) { _ in SkeletonBlock(height: 96, radius: 18).frame(maxWidth: .infinity) }
            }
            SkeletonBlock(height: 16, fraction: nil, width: 140)
            ForEach(0..<2, id: \.self) { _ in SkeletonBlock(height: 72, radius: 18) }
        }
    }
}

// MARK: - what waits for my answer

private struct AttentionStrip: View {
    @Environment(\.wa) private var wa
    let h: HomeDto

    var body: some View {
        let a = h.attention ?? HomeAttentionDto()
        let orders = a.ordersAwaiting ?? 0
        let consultations = a.consultationsPending ?? 0
        let invitations = a.invitationsPending ?? 0
        let revisions = a.revisions ?? 0
        if orders + consultations + invitations + revisions > 0 {
            VStack(alignment: .leading, spacing: 8) {
                Text(L("home_needs_you")).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                FlowLayout(spacing: 8) {
                    if orders > 0 {
                        AttentionChip(icon: "tray.fill", text: L("home_att_orders", orders), tint: Tints.amber) { ShellState.shared.open(Tabs.WORK, segment: "orders") }
                    }
                    if consultations > 0 {
                        AttentionChip(icon: "video.fill", text: L("home_att_consultations", consultations), tint: Tints.violet) { ShellState.shared.open(Tabs.WORK, segment: "consultations") }
                    }
                    if revisions > 0 {
                        AttentionChip(icon: "arrow.uturn.backward", text: L("home_att_revisions", revisions), tint: Tints.rose) { ShellState.shared.open(Tabs.WORK, segment: "projects") }
                    }
                    if invitations > 0 {
                        AttentionChip(icon: "envelope.badge.fill", text: L("home_att_invitations", invitations), tint: Tints.blue) { ShellState.shared.open(Tabs.OPPORTUNITIES, segment: "invitations") }
                    }
                }
            }
        }
    }
}

// MARK: - the slider

/// One card of the slider: the staff's banner (with its image) or one of my next steps (drawn on the app colour with an icon).
private struct HeroSlide: Identifiable {
    var id: String { key }
    let key: String
    let title: String
    let body: String?
    let cta: String?
    var image: String? = nil
    var icon: String? = nil
    var big: String? = nil
    let onClick: (() -> Void)?
}

/// Where a banner's tap leads: a page of the app (by its key), a web page, or nowhere.
@MainActor
private func promoAction(_ p: PromoDto, _ actions: MainActions, openUrl: @escaping (String) -> Void) -> (() -> Void)? {
    switch p.targetType ?? "none" {
    case "url":
        guard let url = p.target, url.hasPrefix("https://") else { return nil }
        return { openUrl(url) }
    case "screen":
        switch p.target {
        case "projects": return { ShellState.shared.open(Tabs.OPPORTUNITIES, segment: "projects") }
        case "invitations": return { ShellState.shared.open(Tabs.OPPORTUNITIES, segment: "invitations") }
        case "community": return { ShellState.shared.open(Tabs.COMMUNITY) }
        case "plans": return actions.openPlans
        case "insights": return actions.openInsights
        case "crm": return actions.openCrm
        case "services": return actions.openServices
        case "consultations": return actions.openConsultations
        case "special_offers": return actions.openSpecialOffers
        case "loyalty": return actions.openLoyalty
        case "skill_tests": return actions.openSkillTests
        case "verification": return actions.openVerification
        case "coach": return actions.openCoach
        case "portfolio": return actions.openPortfolio
        case "wallet": return actions.openWallet
        case "availability": return actions.openAvailability
        case "capacity": return actions.openCapacity
        case "teams": return actions.openTeams
        default: return nil
        }
    default:
        return nil
    }
}

/// The slider: one card at a time, swiped by hand (it never moves by itself), with the dots under it.
private struct HeroSlider: View {
    @Environment(\.wa) private var wa
    let slides: [HeroSlide]
    @State private var page = 0
    @ScaledMetric(relativeTo: .body) private var scaledHeight: CGFloat = 156

    var body: some View {
        if !slides.isEmpty {
            // The card grows with the text size, up to 1.6 times.
            let height = min(max(scaledHeight, 156), 156 * 1.6)
            let current = min(page, slides.count - 1)
            VStack(spacing: 0) {
                TabView(selection: $page) {
                    ForEach(Array(slides.enumerated()), id: \.element.key) { i, s in
                        HeroCard(s: s, height: height).padding(.horizontal, 16).tag(i)
                    }
                }
                .tabViewStyle(.page(indexDisplayMode: .never))
                .frame(height: height)
                if slides.count > 1 {
                    HStack(spacing: 6) {
                        ForEach(0..<slides.count, id: \.self) { i in
                            let on = current == i
                            Capsule().fill(on ? wa.red : wa.settingsMut.opacity(0.35))
                                .frame(width: on ? 18 : 6, height: 6)
                                .animation(.easeInOut(duration: 0.22), value: current)
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.top, 10)
                    .padding(.bottom, 4)
                }
                Spacer().frame(height: 10)
            }
            .frame(maxWidth: .infinity)
            .onChange(of: slides.count) { n in if page >= n { page = max(n - 1, 0) } }
        }
    }
}

private struct HeroCard: View {
    @Environment(\.wa) private var wa
    let s: HeroSlide
    let height: CGFloat

    var body: some View {
        let accent = wa.red
        let deep = accent.scaled(0.62)
        let card = ZStack(alignment: .topLeading) {
            LinearGradient(colors: [accent, deep], startPoint: .topLeading, endPoint: .bottomTrailing)
            if let image = s.image {
                AsyncImage(url: Api.mediaUrl(image)) { phase in
                    if let img = phase.image { img.resizable().scaledToFill() } else { Color.clear }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .clipped()
                // A shade from the text's side keeps the words readable on any picture.
                LinearGradient(colors: [Color.black.opacity(0.05), Color.black.opacity(0.60)], startPoint: UnitPoint(x: 0, y: 0.5), endPoint: UnitPoint(x: 1, y: 0.5))
            } else {
                // Soft rings of light on the plain colour.
                Circle().fill(Color.white.opacity(0.08)).frame(width: 170, height: 170)
                Circle().fill(Color.white.opacity(0.07)).frame(width: 90, height: 90)
                    .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottomLeading)
            }
            HStack(spacing: 12) {
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: s.title).font(.dorr(18, .black)).foregroundStyle(.white).lineLimit(2).lineSpacing(3)
                    if let body = s.body, !body.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: body).font(.dorr(12.5)).foregroundStyle(Color.white.opacity(0.88)).lineLimit(2).padding(.top, 4)
                    }
                    if let cta = s.cta, !cta.trimmingCharacters(in: .whitespaces).isEmpty, s.onClick != nil {
                        Text(verbatim: cta).font(.dorr(12, .heavy)).foregroundStyle(accent)
                            .padding(.horizontal, 14).padding(.vertical, 7)
                            .background(Color.white, in: Capsule())
                            .padding(.top, 10)
                    }
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .leading)
                if s.image == nil && (s.icon != nil || s.big != nil) {
                    ZStack {
                        Circle().fill(Color.white.opacity(0.16))
                        if let big = s.big {
                            Text(verbatim: big).font(.dorr(20, .black)).foregroundStyle(.white)
                        } else if let icon = s.icon {
                            Image(systemName: icon).font(.system(size: 30, weight: .semibold)).foregroundStyle(.white)
                        }
                    }
                    .frame(width: 76, height: 76)
                }
            }
            .padding(18)
        }
        .frame(maxWidth: .infinity)
        .frame(height: height)
        .clipShape(RoundedRectangle(cornerRadius: Corners.hero, style: .continuous))
        .accessibilityElement(children: .combine)
        if let onClick = s.onClick {
            Button(action: onClick) { card.contentShape(Rectangle()) }.buttonStyle(.plain)
        } else {
            card
        }
    }
}

// MARK: - numbers

private struct StatsRow: View {
    let h: HomeDto
    let actions: MainActions

    var body: some View {
        let s = h.stats ?? HomeStatsDto()
        let money = { (minor: Int) -> String in
            formatMinor(minor, nil) + (s.currency.map { " " + (CurrencyLabel.signs[$0.uppercased()] ?? $0) } ?? "")
        }
        VStack(alignment: .leading, spacing: 10) {
            SectionHeader(title: L("home_numbers"), action: L("home_more_numbers"), onAction: actions.openInsights)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 10) {
                    StatTile(icon: "banknote.fill", value: "\u{200E}" + money(s.earnedMonthMinor ?? 0), label: L("home_stat_earned_month"), tint: Tints.green,
                             onClick: actions.openWallet).frame(width: 150)
                    if (s.pendingMinor ?? 0) > 0 {
                        StatTile(icon: "hourglass", value: "\u{200E}" + money(s.pendingMinor ?? 0), label: L("home_stat_pending"), tint: Tints.amber,
                                 onClick: actions.openWallet).frame(width: 150)
                    }
                    StatTile(icon: "paperplane.fill", value: "\(s.liveOffers ?? 0)", label: L("home_stat_offers"), tint: Tints.blue,
                             onClick: { ShellState.shared.open(Tabs.WORK, segment: "offers") }).frame(width: 118)
                    StatTile(icon: "briefcase.fill", value: "\(s.activeProjects ?? 0)", label: L("home_stat_projects"), tint: Tints.violet,
                             onClick: { ShellState.shared.open(Tabs.WORK, segment: "projects") }).frame(width: 118)
                    StatTile(
                        icon: "star.fill", value: s.rating.map { String(format: "%.1f", locale: Locale(identifier: "en_US_POSIX"), $0) } ?? "—",
                        label: (s.reviewsCount ?? 0) > 0 ? L("home_stat_rating_n", s.reviewsCount ?? 0) : L("home_stat_rating"), tint: Tints.amber,
                        onClick: actions.openInsights
                    ).frame(width: 118)
                }
                // Room for the tiles' shadow.
                .padding(.vertical, 6)
            }
            .padding(.vertical, -6)
        }
    }
}

// MARK: - the next consultation

/// «In 2 hours» / «Now» / a day and an hour, from the server's start time against the phone's clock.
private func startsIn(_ iso: String?) -> String {
    guard let at = parseInstant(iso) else { return "" }
    let minutes = Int(at.timeIntervalSince(Date()) / 60)
    if minutes <= 0 { return L("home_cns_now") }
    if minutes < 60 { return L("home_in_minutes", minutes) }
    if minutes < 24 * 60 { return L("home_in_hours", minutes / 60) }
    let f = DateFormatter()
    f.calendar = Calendar(identifier: .gregorian)
    f.locale = AppLocale.locale
    f.dateFormat = "EEEE d MMM · HH:mm"
    return f.string(from: at)
}

private struct NextConsultationCard: View {
    @Environment(\.wa) private var wa
    let c: HomeConsultationDto
    let serverTime: String?
    let onOpen: () -> Void

    var body: some View {
        let pending = c.status == "pending"
        let tint = pending ? Tints.amber : Tints.violet
        Button(action: onOpen) {
            HStack(spacing: 12) {
                Image(systemName: "video.fill").font(.system(size: 20)).foregroundStyle(tint)
                    .frame(width: 48, height: 48)
                    .background(tint.opacity(0.14), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(L(pending ? "cns_pending_home" : "cns_next_banner", c.clientName ?? "")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    if let title = c.title, !title.trimmingCharacters(in: .whitespaces).isEmpty {
                        Text(verbatim: title).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                HStack(spacing: 4) {
                    Image(systemName: "clock").font(.system(size: 11, weight: .semibold))
                    Text(verbatim: startsIn(c.startsAt)).font(.dorr(11, .heavy)).lineLimit(1)
                }
                .foregroundStyle(tint)
                .padding(.horizontal, 10).padding(.vertical, 6)
                .background(tint.opacity(0.14), in: Capsule())
            }
            .padding(14)
            .appCard()
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - my work

private struct WorkRow: View {
    @Environment(\.wa) private var wa
    let w: HomeWorkDto
    let onOpen: () -> Void

    var body: some View {
        let m = w.milestone
        // Whole days, cut toward zero (Duration.toDays()).
        let left: Int? = parseInstant(m?.dueAt).map { Int($0.timeIntervalSince(Date()) / 86_400) }
        let late = (left ?? 0) < 0
        let tint: Color = (m?.status == "revision" || late) ? Tints.rose : m?.status == "delivered" ? Tints.green : Tints.blue
        let label: String? = {
            if m?.status == "revision" { return L("home_work_revision") }
            if m?.status == "delivered" { return L("home_work_delivered") }
            if late { return L("home_work_late") }
            if let left { return left == 0 ? L("home_work_today") : L("home_work_days_left", left) }
            return nil
        }()
        Button(action: onOpen) {
            HStack(spacing: 12) {
                Image(systemName: "doc.text.fill").font(.system(size: 18)).foregroundStyle(tint)
                    .frame(width: 44, height: 44)
                    .background(tint.opacity(0.13), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: w.title ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    Text(verbatim: [m?.title, w.clientName].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if let label {
                    Text(verbatim: label).font(.dorr(11, .heavy)).foregroundStyle(tint).lineLimit(1)
                        .padding(.horizontal, 10).padding(.vertical, 5)
                        .background(tint.opacity(0.13), in: Capsule())
                }
            }
            .padding(14)
            .appCard()
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - projects that fit me

private struct PickCard: View {
    @Environment(\.wa) private var wa
    let p: ProjectDto
    let onOpen: () -> Void

    var body: some View {
        Button(action: onOpen) {
            VStack(alignment: .leading, spacing: 0) {
                VStack(alignment: .leading, spacing: 6) {
                    HStack(spacing: 0) {
                        if let name = p.specialty?.name {
                            Text(verbatim: name).font(.dorr(10.5, .bold)).foregroundStyle(wa.red).lineLimit(1)
                                .padding(.horizontal, 8).padding(.vertical, 3)
                                .background(wa.red.opacity(0.10), in: Capsule())
                        }
                        Spacer(minLength: 0)
                        if p.offered == true {
                            Image(systemName: "checkmark.circle.fill").font(.system(size: 14)).foregroundStyle(Tints.green)
                                .accessibilityLabel(L("home_pick_offered"))
                        }
                    }
                    Text(verbatim: p.title ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(2).lineSpacing(2)
                        .multilineTextAlignment(.leading)
                }
                Spacer(minLength: 0)
                HStack(spacing: 0) {
                    Text(verbatim: budgetText(p.budget) ?? "").font(.dorr(12.5, .black)).foregroundStyle(wa.settingsInk).lineLimit(1)
                        .frame(maxWidth: .infinity, alignment: .leading)
                    if let ago = publishedAgo(p.publishedAt) { Text(verbatim: ago).font(.dorr(11)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                }
            }
            .padding(14)
            .frame(width: 250, height: 150, alignment: .topLeading)
            .settingsSurface(Corners.card, elevation: 4)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

// MARK: - my tools

private struct Tool: Identifiable {
    var id: String { label }
    let icon: String
    let label: String
    let tint: Color
    var count = 0
    let onClick: () -> Void
}

private struct ToolsGrid: View {
    let h: HomeDto
    let actions: MainActions

    var body: some View {
        let a = h.attention ?? HomeAttentionDto()
        var tools: [Tool] = []
        if h.on("services") { tools.append(Tool(icon: "storefront.fill", label: "svc_home_card", tint: Tints.teal, count: a.ordersAwaiting ?? 0, onClick: actions.openServices)) }
        if h.on("consultations") { tools.append(Tool(icon: "video.fill", label: "cns_home_card", tint: Tints.violet, count: a.consultationsPending ?? 0, onClick: actions.openConsultations)) }
        if h.on("special_offers") { tools.append(Tool(icon: "tag.fill", label: "spo_home_card", tint: Tints.rose, onClick: actions.openSpecialOffers)) }
        if h.on("loyalty") { tools.append(Tool(icon: LOYALTY_ICON, label: "loy_home_card", tint: Tints.amber, onClick: actions.openLoyalty)) }
        tools.append(Tool(icon: "chart.line.uptrend.xyaxis", label: "insights_home_card", tint: Tints.blue, onClick: actions.openInsights))
        // FS-703: my clients - always there; a plan without the CRM sees what it opens and the plan that opens it.
        tools.append(Tool(icon: "person.crop.rectangle.stack.fill", label: "crm_home_card", tint: Tints.teal, onClick: actions.openCrm))
        if h.on("subscriptions") { tools.append(Tool(icon: "rosette", label: "plans_title", tint: Tints.amber, onClick: actions.openPlans)) }
        tools.append(Tool(icon: "calendar.badge.checkmark", label: "availability_title", tint: Tints.green, onClick: actions.openAvailability))
        if h.on("skill_tests") { tools.append(Tool(icon: "questionmark.square.fill", label: "skt_home_card", tint: Tints.blue, onClick: actions.openSkillTests)) }
        if h.on("teams") { tools.append(Tool(icon: "person.3.fill", label: "team_home_card", tint: Tints.violet, onClick: actions.openTeams)) }
        let rows = stride(from: 0, to: tools.count, by: 4).map { Array(tools[$0..<min($0 + 4, tools.count)]) }
        return Group {
            if !tools.isEmpty {
                SectionHeader(title: L("home_tools"))
                ForEach(Array(rows.enumerated()), id: \.offset) { _, row in
                    HStack(alignment: .top, spacing: 8) {
                        ForEach(row) { t in
                            ShortcutTile(icon: t.icon, label: L(t.label), tint: t.tint, count: t.count, onClick: t.onClick).frame(maxWidth: .infinity)
                        }
                        ForEach(0..<(4 - row.count), id: \.self) { _ in Color.clear.frame(maxWidth: .infinity, maxHeight: 1) }
                    }
                }
            }
        }
    }
}

// MARK: - a new freelancer's first steps

private struct StartJourney: View {
    @Environment(\.wa) private var wa
    let percent: Int
    let status: String?
    let h: HomeDto
    let actions: MainActions

    var body: some View {
        let stats = h.stats ?? HomeStatsDto()
        let steps: [(label: String, ok: Bool, open: () -> Void)] = [
            ("home_step_profile", percent >= 80, actions.openCoach),
            ("home_step_portfolio", percent >= 100, actions.openPortfolio),
            ("home_step_verify", status == "verified" || status == "verification_submitted", actions.openVerification),
            ("home_step_offer", (stats.liveOffers ?? 0) > 0 || (stats.activeProjects ?? 0) > 0, { ShellState.shared.open(Tabs.OPPORTUNITIES, segment: "projects") }),
        ]
        let done = steps.filter(\.ok).count
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Image(systemName: "flag.checkered").font(.system(size: 18, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 40, height: 40)
                    .background(wa.red.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(L("home_start_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(L("home_start_progress", done, steps.count)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            ForEach(Array(steps.enumerated()), id: \.offset) { _, step in
                Button(action: step.open) {
                    HStack(spacing: 10) {
                        Image(systemName: step.ok ? "checkmark.circle.fill" : "circle").font(.system(size: 18)).foregroundStyle(step.ok ? Tints.green : wa.settingsMut)
                            .frame(width: 20, height: 20)
                        Text(L(step.label)).font(.dorr(13, step.ok ? .medium : .bold)).foregroundStyle(step.ok ? wa.settingsMut : wa.settingsInk)
                            .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .padding(.vertical, 6)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .disabled(step.ok)
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .appCard(Corners.hero)
    }
}

// MARK: - helpers

/// A section that rises in softly the first time the home shows, one after the other.
private struct Reveal<Content: View>: View {
    let index: Int
    @ViewBuilder let content: () -> Content
    @State private var shown = false

    var body: some View {
        content()
            .opacity(shown ? 1 : 0)
            .offset(y: shown ? 0 : 16)
            .onAppear {
                guard !shown else { return }
                withAnimation(.easeOut(duration: 0.36).delay(Double(index) * 0.07)) { shown = true }
            }
    }
}

/// The account status in the app language; an unknown one is shown as the server sent it.
func statusLabel(_ status: String?) -> String {
    switch status {
    case "registered": return L("status_registered")
    case "profile_ready": return L("status_profile_ready")
    case "verification_submitted": return L("status_verification_submitted")
    case "verified": return L("status_verified")
    case "verification_rejected": return L("status_verification_rejected")
    case "changes_requested": return L("status_changes_requested")
    case "suspended": return L("status_suspended")
    case "blocked": return L("status_blocked")
    default: return status ?? ""
    }
}

/// A short notice on the home while the freelancer is not taking new offers (paused or on time off); a tap opens the availability page.
private struct PausedCard: View {
    @Environment(\.wa) private var wa
    let onOpen: () -> Void

    var body: some View {
        Button(action: onOpen) {
            HStack(spacing: 10) {
                Image(systemName: "calendar.badge.exclamationmark").font(.system(size: 19)).foregroundStyle(Tints.amber).frame(width: 22, height: 22)
                Text(L("availability_paused_banner")).font(.dorr(13, .semibold)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(14)
            .background(wa.night ? wa.darkCard : Tints.amber.opacity(0.12), in: RoundedRectangle(cornerRadius: Corners.card, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}
