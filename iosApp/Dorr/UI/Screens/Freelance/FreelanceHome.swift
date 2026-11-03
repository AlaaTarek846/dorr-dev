import SwiftUI

// androidApp `freelance/FreelanceHome.kt` — the hub of the client's freelance section (the page it opens on; there is no bottom
// bar), fed by one call to `GET home`: what waits for me, the staff's banners, «Post a project», my numbers (or how it works, for a
// new client), the next consultation, my running projects, the sections and my tools, and a row of suggested services. A section
// whose feature is off in my country does not show. Read on open, on a pull, when a freelance notice arrives and when the connection
// comes back — nothing on a timer.

@MainActor
struct FreelanceHome: View {
    @EnvironmentObject private var network: NetworkMonitor
    let version: Int
    let onExit: () -> Void
    let open: (FlRoute) -> Void
    @State private var home: FlHomeDto?
    @State private var failed = false

    /// Before the first answer every section shows; after it, only those whose feature is on.
    private func on(_ feature: String) -> Bool { home?.on(feature) ?? true }

    var body: some View {
        let h = home
        VStack(spacing: 0) {
            FlPageHeader(title: L("flh_title"), onBack: onExit, subtitle: L("flh_subtitle"))
            ScrollView {
                VStack(alignment: .leading, spacing: 14) {
                    VStack(alignment: .leading, spacing: 14) {
                        // FL-708: shown only while a warning / restriction is active or its appeal waits.
                        SanctionsCard(refreshKey: version)
                        if let h { AttentionStrip(h: h, open: open) }
                        // FL-L09: only while offers wait.
                        SpecialOffersBanner(count: h?.attention?.specialOffers ?? 0) { open(.specialOffers) }
                    }
                    .padding(.horizontal, 16)
                    if let promos = h?.promos, !promos.isEmpty { PromoSlider(promos: promos, open: open) }
                    VStack(alignment: .leading, spacing: 14) {
                        // A banner already sits above (often the same ask), so the card waits for the hub to say there is none; it stays when the read failed.
                        if (h != nil && (h?.promos ?? []).isEmpty) || (h == nil && failed) { NewProjectCard { open(.newProject) } }
                        if let h {
                            if h.isNew == true {
                                StartJourney(servicesOn: h.on("services"), onPost: { open(.newProject) }, onServices: { open(.services) })
                            } else {
                                Numbers(h: h, open: open)
                            }
                        } else if failed {
                            FlRetry(message: L("fl_error_generic"))
                        } else {
                            HomeSkeleton()
                        }
                        if let c = h?.nextConsultation { NextConsultation(c: c) { open(.consultation(c.uuid)) } }
                        if let projects = h?.projects, !projects.isEmpty {
                            FlSectionHeader(title: L("flh_running"), action: L("flh_see_all")) { open(.projects) }.padding(.top, 4)
                            ForEach(projects) { p in ProjectCard(p: p) { open(.project(p.uuid)) } }
                        }

                        FlSectionHeader(title: L("flh_sections")).padding(.top, 4)
                        let sections = sectionTiles()
                        ForEach(0..<((sections.count + 1) / 2), id: \.self) { row in
                            HStack(spacing: 12) {
                                ForEach(sections[(row * 2)..<min(row * 2 + 2, sections.count)], id: \.title) { t in
                                    FlHubTile(icon: t.icon, title: t.title, line: t.line, tint: t.tint) { open(t.route) }
                                }
                                if row * 2 + 1 >= sections.count { Color.clear.frame(maxWidth: .infinity, maxHeight: 1) }
                            }
                        }

                        FlSectionHeader(title: L("flh_tools")).padding(.top, 4)
                        // Three to a row, so the names keep room.
                        let tools = toolTiles(h)
                        ForEach(0..<((tools.count + 2) / 3), id: \.self) { row in
                            HStack(spacing: 10) {
                                ForEach(tools[(row * 3)..<min(row * 3 + 3, tools.count)], id: \.title) { t in
                                    FlToolTile(icon: t.icon, title: t.title, tint: t.tint, badge: t.badge) { open(t.route) }
                                }
                                ForEach(0..<(3 - min(3, tools.count - row * 3)), id: \.self) { _ in Color.clear.frame(maxWidth: .infinity, maxHeight: 1) }
                            }
                        }
                    }
                    .padding(.horizontal, 16)
                    if let services = h?.services, !services.isEmpty {
                        FlSectionHeader(title: L("flh_suggested"), action: L("flh_see_all")) { open(.services) }.padding(.horizontal, 16).padding(.top, 4)
                        ScrollView(.horizontal, showsIndicators: false) {
                            HStack(spacing: 12) {
                                ForEach(services) { s in ServiceTile(s: s) { open(.service(s.uuid)) }.frame(width: 250) }
                            }
                            .padding(.horizontal, 16).padding(.vertical, 4)
                        }
                    }
                }
                .padding(.top, 4).padding(.bottom, 24)
            }
            // Pulling down reads the hub once more (the client's own gesture, not a timer).
            .refreshable { await load() }
        }
        .task(id: "\(network.reconnectTick)|\(version)") { await load() }
    }

    private func load() async {
        do {
            home = try await FreelanceHomeApi.home().data
            failed = false
        } catch {
            failed = home == nil
        }
    }

    private struct Tile {
        let icon: String
        let title: String
        let line: String
        let tint: Color
        let route: FlRoute
    }

    private struct Tool {
        let icon: String
        let title: String
        let tint: Color
        var badge = 0
        let route: FlRoute
    }

    private func toolTiles(_ h: FlHomeDto?) -> [Tool] {
        var out: [Tool] = []
        if on("consultations") { out.append(Tool(icon: "calendar.badge.checkmark", title: L("cs_mine_title"), tint: FlTints.mine, route: .consultations)) }
        if on("special_offers") { out.append(Tool(icon: "tag.fill", title: L("spc_title"), tint: FlTints.special, badge: h?.attention?.specialOffers ?? 0, route: .specialOffers)) }
        if on("loyalty") { out.append(Tool(icon: "gift.fill", title: L("loyc_title"), tint: FlTints.loyalty, route: .loyalty)) }
        out.append(Tool(icon: "heart.fill", title: L("flh_favorites"), tint: FlTints.favorites, route: .favorites))
        out.append(Tool(icon: "doc.text.fill", title: L("flr_title"), tint: FlTints.projects, route: .receipts))
        return out
    }

    private func sectionTiles() -> [Tile] {
        var out = [Tile(icon: "briefcase.fill", title: L("fl_my_projects"), line: L("flh_projects_line"), tint: FlTints.projects, route: .projects)]
        if on("services") { out.append(Tile(icon: "storefront.fill", title: L("flh_services"), line: L("flh_services_line"), tint: FlTints.services, route: .services)) }
        if on("consultations") { out.append(Tile(icon: "video.bubble.left.fill", title: L("cs_market_title"), line: L("flh_consult_line"), tint: FlTints.consultations, route: .consultMarket)) }
        // «My activity»: my orders, consultations and special offers.
        out.append(Tile(icon: "doc.text.fill", title: L("fla_activity"), line: L("fla_activity_line"), tint: FlTints.orders, route: .orders))
        return out
    }
}

/// What waits for me, as a row of chips coloured by how much it matters: a delivery to review, a part to fund, new offers, a project
/// sent back. One of a kind opens its project's page; more open my projects.
private struct AttentionStrip: View {
    @Environment(\.wa) private var wa
    let h: FlHomeDto
    let open: (FlRoute) -> Void

    private struct Item: Identifiable {
        let icon: String
        let text: String
        let count: Int
        let tint: Color
        let route: FlRoute
        var id: String { text }
    }

    private var items: [Item] {
        let a = h.attention
        func one(_ w: FlWaitingDto?, _ route: (String) -> FlRoute) -> FlRoute {
            if let w, w.count == 1, let p = w.projectUuid { return route(p) }
            return .projects
        }
        var out: [Item] = []
        if let w = a?.toReview, (w.count ?? 0) > 0 { out.append(Item(icon: "checklist", text: L("flh_att_review"), count: w.count ?? 0, tint: Color(hex: 0x7C3AED), route: one(w) { .agreement($0) })) }
        if let w = a?.toFund, (w.count ?? 0) > 0 { out.append(Item(icon: "banknote.fill", text: L("flh_att_fund"), count: w.count ?? 0, tint: flAmber, route: one(w) { .agreement($0) })) }
        if let w = a?.newOffers, (w.count ?? 0) > 0 { out.append(Item(icon: "doc.text.magnifyingglass", text: L("flh_att_offers"), count: w.count ?? 0, tint: flGreen, route: one(w) { .offers($0) })) }
        if let w = a?.toFix, (w.count ?? 0) > 0 { out.append(Item(icon: "wrench.and.screwdriver.fill", text: L("flh_att_fix"), count: w.count ?? 0, tint: Color(hex: 0x2563EB), route: one(w) { .project($0) })) }
        return out
    }

    var body: some View {
        let list = items
        if !list.isEmpty {
            VStack(alignment: .leading, spacing: 8) {
                Text(L("flh_att_title")).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(list) { c in
                            Button { open(c.route) } label: {
                                HStack(spacing: 7) {
                                    Image(systemName: c.icon).font(.system(size: 14, weight: .semibold))
                                    Text(verbatim: c.text).font(.dorr(13, .heavy)).lineLimit(1)
                                    Text(verbatim: "\(c.count)").font(.dorr(11, .heavy)).foregroundStyle(.white)
                                        .padding(.horizontal, 7).padding(.vertical, 1).background(c.tint, in: Capsule())
                                }
                                .foregroundStyle(c.tint)
                                .padding(.horizontal, 12).padding(.vertical, 10)
                                .background(c.tint.opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            }
                            .buttonStyle(.plain)
                            .accessibilityElement(children: .combine)
                        }
                    }
                }
            }
        }
    }
}

/// The staff's banners, one at a time, moved by a swipe (never on a timer); a tap opens a page of the hub or a web page.
private struct PromoSlider: View {
    @Environment(\.wa) private var wa
    @Environment(\.openURL) private var openURL
    let promos: [FlPromoDto]
    let open: (FlRoute) -> Void
    @State private var page = 0

    var body: some View {
        VStack(spacing: 8) {
            TabView(selection: $page) {
                ForEach(Array(promos.enumerated()), id: \.element.uuid) { i, p in
                    card(p).padding(.horizontal, 16).tag(i)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: .never))
            .frame(height: 150)
            if promos.count > 1 {
                HStack(spacing: 6) {
                    ForEach(promos.indices, id: \.self) { i in
                        Capsule().fill(i == page ? wa.accent : wa.settingsMut.opacity(0.35)).frame(width: i == page ? 18 : 6, height: 6)
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func card(_ p: FlPromoDto) -> some View {
        let route = promoRoute(p)
        let link = p.targetType == "url" ? p.target.flatMap(URL.init(string:)) : nil
        let tappable = route != nil || link != nil
        Button {
            if let route { open(route) } else if let link { openURL(link) }
        } label: {
            ZStack(alignment: .bottomLeading) {
                LinearGradient(colors: [wa.accent, wa.accent.opacity(0.7)], startPoint: .topLeading, endPoint: .bottomTrailing)
                if let url = ApiClient.mediaUrl(p.imagePath) {
                    AsyncImage(url: url) { $0.resizable().scaledToFill() } placeholder: { Color.clear }
                    LinearGradient(colors: [.black.opacity(0.55), .black.opacity(0.05)], startPoint: .leading, endPoint: .trailing)
                }
                VStack(alignment: .leading, spacing: 4) {
                    Text(verbatim: p.title ?? "").font(.dorr(17, .heavy)).foregroundStyle(.white).lineLimit(2)
                    if let body = p.body, !body.isEmpty { Text(verbatim: body).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.9)).lineLimit(2) }
                    if let cta = p.cta, !cta.isEmpty, tappable {
                        Text(verbatim: cta).font(.dorr(12.5, .heavy)).foregroundStyle(wa.accent)
                            .padding(.horizontal, 12).padding(.vertical, 5).background(Color.white, in: Capsule()).padding(.top, 4)
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.trailing, 60)
                .padding(16)
            }
            .frame(maxWidth: .infinity).frame(height: 150)
            .clipShape(RoundedRectangle(cornerRadius: FlCorners.card, style: .continuous))
        }
        .buttonStyle(.plain)
        .disabled(!tappable)
        .accessibilityElement(children: .combine)
    }
}

/// The page of the hub a banner opens (the names of `FreelancePromo::CLIENT_SCREENS`); nil for none, a link or an unknown page.
private func promoRoute(_ p: FlPromoDto) -> FlRoute? {
    guard p.targetType == "screen" else { return nil }
    switch p.target {
    case "projects": return .projects
    case "new_project": return .newProject
    case "services": return .services
    case "orders": return .orders
    case "consultations": return .consultMarket
    case "my_consultations": return .consultations
    case "special_offers": return .specialOffers
    case "loyalty": return .loyalty
    case "favorites": return .favorites
    default: return nil
    }
}

/// My numbers, side by side (a row that scrolls): each opens its page.
private struct Numbers: View {
    let h: FlHomeDto
    let open: (FlRoute) -> Void

    var body: some View {
        let s = h.stats
        let offers = s?.newOffers ?? 0
        VStack(alignment: .leading, spacing: 10) {
            FlSectionHeader(title: L("flh_numbers"))
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 10) {
                    FlStatTile(icon: "briefcase.fill", value: "\(s?.runningProjects ?? 0)", label: L("flh_stat_projects"), tint: FlTints.projects) { open(.projects) }
                    FlStatTile(icon: "doc.text.magnifyingglass", value: "\(offers)", label: L("flh_stat_offers"), tint: flGreen) {
                        if offers == 1, let p = h.attention?.newOffers?.projectUuid { open(.offers(p)) } else { open(.projects) }
                    }
                    if h.on("services") { FlStatTile(icon: "doc.text.fill", value: "\(s?.runningOrders ?? 0)", label: L("flh_stat_orders"), tint: FlTints.orders) { open(.orders) } }
                    if h.on("consultations") {
                        FlStatTile(icon: "video.fill", value: "\(s?.upcomingConsultations ?? 0)", label: L("flh_stat_consults"), tint: FlTints.consultations) { open(.consultations) }
                    }
                    FlStatTile(icon: "banknote.fill", value: mkMoney(s?.fundedMonthMinor ?? 0, h.decimals, h.currency), label: L("flh_stat_funded"), tint: FlTints.mine) { open(.projects) }
                }
                .padding(.vertical, 4)
            }
        }
    }
}

/// For a client with nothing posted or ordered yet: how the work goes, in four steps, and the two ways to start.
private struct StartJourney: View {
    @Environment(\.wa) private var wa
    let servicesOn: Bool
    let onPost: () -> Void
    let onServices: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(L("flh_journey_title")).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
            ForEach(Array(["flh_step_1", "flh_step_2", "flh_step_3", "flh_step_4"].enumerated()), id: \.offset) { i, key in
                HStack(spacing: 10) {
                    Text(verbatim: "\(i + 1)").font(.dorr(13, .heavy)).foregroundStyle(wa.accent)
                        .frame(width: 28, height: 28).background(wa.accent.opacity(0.12), in: Circle())
                    Text(L(key)).font(.dorr(13.5)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                }
            }
            HStack(spacing: 8) {
                FlAction(text: L("flh_new_action"), icon: "plus", tone: .primary, action: onPost)
                if servicesOn { FlAction(text: L("flh_services"), icon: "storefront.fill", action: onServices) }
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .settingsSurface(FlCorners.card)
    }
}

/// My next consultation: when (on the phone's calendar), with whom, and whether the freelancer has said yes yet.
private struct NextConsultation: View {
    @Environment(\.wa) private var wa
    let c: FlHomeConsultationDto
    let action: () -> Void

    var body: some View {
        let tint = FlTints.consultations
        Button(action: action) {
            HStack(spacing: 12) {
                Image(systemName: "video.bubble.left.fill").font(.system(size: 20)).foregroundStyle(tint)
                    .frame(width: 46, height: 46).background(tint.opacity(0.14), in: RoundedRectangle(cornerRadius: FlCorners.icon, style: .continuous))
                VStack(alignment: .leading, spacing: 2) {
                    Text(L("flh_next_consult")).font(.dorr(12, .heavy)).foregroundStyle(tint)
                    Text(verbatim: c.title ?? L("cs_mine_title")).font(.dorr(14.5, .heavy)).foregroundStyle(wa.settingsInk).lineLimit(1)
                    let line = [whenText(c.startsAt), c.freelancerName.map { L("flh_with", $0) }].compactMap { $0 }.joined(separator: " · ")
                    if !line.isEmpty { Text(verbatim: line).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineLimit(1) }
                    if c.status == "pending" { Text(L("flh_consult_pending")).font(.dorr(11.5, .bold)).foregroundStyle(flAmber) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(14)
            .settingsSurface(FlCorners.card)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .combine)
    }

    /// «Tue 4 Nov · 15:30» on the phone's own clock and language.
    private func whenText(_ iso: String?) -> String? {
        guard let iso else { return nil }
        let parser = ISO8601DateFormatter()
        parser.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        guard let date = parser.date(from: iso) ?? ISO8601DateFormatter().date(from: iso) else { return nil }
        let f = DateFormatter()
        f.locale = Locale(identifier: Locale.preferredLanguages.first ?? "en")
        f.dateFormat = "EEE d MMM · HH:mm"
        return f.string(from: date)
    }
}

/// The hub while its first answer is on its way: a row of numbers and two cards, in grey.
private struct HomeSkeleton: View {
    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            WaSkeleton(cornerRadius: 8).frame(width: 110, height: 16)
            HStack(spacing: 10) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 18).frame(height: 92) } }
            ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 20).frame(height: 96) }
        }
    }
}

/// The first thing under what waits: posting a project, on the app's colour.
private struct NewProjectCard: View {
    @Environment(\.wa) private var wa
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 6) {
                Text(L("flh_new_title")).font(.dorr(18, .heavy)).foregroundStyle(.white)
                Text(L("flh_new_body")).font(.dorr(13)).foregroundStyle(.white.opacity(0.9)).lineSpacing(3)
                HStack(spacing: 6) {
                    Image(systemName: "plus").font(.system(size: 14, weight: .bold))
                    Text(L("flh_new_action")).font(.dorr(14, .heavy))
                }
                .foregroundStyle(wa.accent)
                .padding(.horizontal, 16).padding(.vertical, 9)
                .background(Color.white, in: Capsule())
                .padding(.top, 6)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(18)
            .background(LinearGradient(colors: [wa.accent, wa.accent.opacity(0.78)], startPoint: .topLeading, endPoint: .bottomTrailing),
                        in: RoundedRectangle(cornerRadius: FlCorners.card, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .combine)
    }
}
