import SwiftUI

// androidApp `sports/SportsScreen.kt` + `SportsMatch.kt`.

indirect enum SpPage: Equatable {
    case home
    case match(String)
    case competition(Int)
    case team(Int)
    case follow, settings, contests
    case contest(String)
    case prizes
    case player(Int)
    case coach(Int)
    case search

    var key: String { String(describing: self) }
}

@MainActor
struct SportsScreen: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var link = SportsLink.shared
    @ObservedObject private var live = SportsLive.shared
    let onExit: () -> Void
    @State private var stack: [SpPage] = [.home]
    @State private var forward = true

    var body: some View {
        ZStack {
            page(stack.last ?? .home)
                .id("\(stack.count)-\(stack.last?.key ?? "")")
                .transition(.asymmetric(
                    insertion: .modifier(active: SpSlide(fraction: forward ? 1.0 / 6 : -1.0 / 6), identity: SpSlide(fraction: 0)),
                    removal: .modifier(active: SpSlide(fraction: forward ? -0.1 : 0.1), identity: SpSlide(fraction: 0))
                ))
        }
        .background(wa.bg.ignoresSafeArea())
        .onAppear {
            live.start()
            openLinked()
        }
        .onChange(of: link.matchId) { _ in openLinked() }
    }

    private func openLinked() {
        guard let id = link.matchId else { return }
        if stack.last != .match(id) { go(.match(id)) }
        link.matchId = nil
    }

    @ViewBuilder private func page(_ p: SpPage) -> some View {
        switch p {
        case .home: SportsHome(onBack: back, go: go)
        case .match(let id): MatchPage(id: id, onBack: back, go: go)
        case .competition(let id): CompetitionPage(id: id, onBack: back, go: go)
        case .team(let id): TeamPage(id: id, onBack: back, go: go)
        case .follow: FollowPage(onBack: back, go: go)
        case .settings: SportsSettings(onBack: back)
        case .contests: ContestsPage(onBack: back, onOpen: { go(.contest($0)) }, onPrizes: { go(.prizes) })
        case .contest(let id): ContestPage(id: id, onBack: back) { go(.match($0)) }
        case .prizes: PrizesPage(onBack: back)
        case .player(let id): PlayerPage(id: id, onBack: back, go: go)
        case .coach(let id): CoachPage(id: id, onBack: back, go: go)
        case .search: SportsSearchPage(onBack: back, go: go)
        }
    }

    private func go(_ p: SpPage) {
        forward = true
        withAnimation(.easeOut(duration: 0.32)) { stack.append(p) }
    }

    private func back() {
        if stack.count <= 1 { onExit(); return }
        forward = false
        withAnimation(.easeOut(duration: 0.28)) { _ = stack.removeLast() }
    }
}

private struct SpSlide: ViewModifier {
    let fraction: CGFloat
    func body(content: Content) -> some View {
        GeometryReader { proxy in content.offset(x: proxy.size.width * fraction).opacity(fraction == 0 ? 1 : 0) }
    }
}

// MARK: - Home

@MainActor
private struct SportsHome: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var live = SportsLive.shared
    let onBack: () -> Void
    let go: (SpPage) -> Void
    @State private var sport: String?
    @State private var date = calToday()
    @State private var home: SpHomeDto?
    @State private var off = false
    @State private var followedComps: [Int: Bool] = [:]

    var body: some View {
        WaPage(title: L("sp_title"), onBack: onBack, actions: {
            WaCircleButton(icon: "magnifyingglass", label: L("spx_search")) { go(.search) }
            WaCircleButton(icon: "trophy.fill", label: L("sp_contests")) { go(.contests) }
            WaCircleButton(icon: "star", label: L("sp_follow_title")) { go(.follow) }
            WaCircleButton(icon: "slider.horizontal.3", label: L("sp_settings")) { go(.settings) }
        }) {
            if off {
                WaCard { WaEmpty(icon: "soccerball", tone: .gray, title: L("sp_off_title"), text: L("sp_off_text")) }
            } else {
                content
            }
        }
        .task(id: "\(sport ?? "")|\(calKey(date))") {
            home = nil
            do {
                let h = try await SportsApi.home(timezone: spZone(), date: calKey(date), sport: sport).data
                home = h
                if let p = h?.preferences { live.prefs = p }
            } catch {
                if error is CancellationError { return }
                off = error.apiFailure.httpStatus == 403
                if !off { home = try? JSONDecoder().decode(SpHomeDto.self, from: Data("{}".utf8)) }
            }
        }
    }

    @ViewBuilder private var content: some View {
        let sports = home?.sports ?? []
        if sports.count > 1 {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    SportChip(emoji: "🏆", label: L("sp_all"), selected: sport == nil) { sport = nil }
                    ForEach(sports, id: \.key) { s in SportChip(emoji: s.emoji ?? "🏅", label: s.name ?? s.key, selected: sport == s.key) { sport = s.key } }
                }
            }
            .waRise(0)
            .padding(.bottom, 10)
        }
        DateStrip(selected: date) { date = $0 }.waRise(1)

        if let h = home {
            SpTicker { now in
                VStack(alignment: .leading, spacing: 0) {
                    homeBody(h, now)
                }
            }
        } else {
            HStack(spacing: 12) { ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 26).frame(width: 300, height: 170) } }.padding(.top, 14)
            SkeletonRows(count: 4, height: 64).padding(.top, 14)
        }
    }

    @ViewBuilder private func homeBody(_ h: SpHomeDto, _ now: Date) -> some View {
        let hide = live.prefs.hideScores
        let mine = Set(h.following?.teams ?? [])
        if (h.liveCount ?? 0) > 0 {
            HStack(spacing: 8) {
                LiveDot()
                Text(verbatim: L("sp_live_now", h.liveCount ?? 0)).font(.dorr(13, .heavy)).foregroundStyle(spLive)
            }
            .padding(.horizontal, 12).padding(.vertical, 6)
            .background(spLive.opacity(0.1), in: Capsule())
            .padding(.top, 12)
            .waRise(2)
        }
        if let myMatches = h.mine, !myMatches.isEmpty {
            WaSectionTitle(title: L("sp_my_teams")).waRise(3)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 12) {
                    ForEach(myMatches) { m in LiveCard(m: m, now: now, hideScore: hide) { go(.match(m.id)) } }
                }
            }
            .waRise(3)
        } else if (h.following?.teams ?? []).isEmpty {
            Button { go(.follow) } label: {
                HStack(spacing: 12) {
                    Text(verbatim: "⭐").font(.system(size: 28))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("sp_pick_teams")).font(.dorr(16, .heavy)).foregroundStyle(.white)
                        Text(L("sp_pick_teams_sub")).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.88))
                    }
                    Spacer(minLength: 0)
                }
                .padding(16)
                .background(LinearGradient(colors: [spGreen, Color(hex: 0x065F46)], startPoint: .topLeading, endPoint: .bottomTrailing),
                            in: RoundedRectangle(cornerRadius: 22, style: .continuous))
            }
            .buttonStyle(.plain)
            .padding(.top, 14)
            .waRise(3)
        }
        ForEach(Array((h.competitions ?? []).enumerated()), id: \.offset) { i, g in
            if let c = g.competition {
                let followed = followedComps[c.id] ?? (g.followed ?? false)
                CompetitionHeading(name: c.name, logo: c.logo, country: c.country, followed: followed, onStar: {
                    followedComps[c.id] = !followed
                    Task { _ = await spToggleFollow("competition", c.id, !followed) }
                }) { go(.competition(c.id)) }
                .padding(.top, 16)
                .waRise(4 + min(i, 5))
                ForEach(g.matches ?? []) { m in
                    MatchRow(m: m, now: now, mine: mine, hideScore: hide) { go(.match(m.id)) }.padding(.top, 6)
                }
            }
        }
        if (h.competitions ?? []).isEmpty {
            WaCard { WaEmpty(icon: "soccerball", tone: .green, title: L("sp_no_matches"), text: L("sp_no_matches_text")) }.padding(.top, 16)
        }
        Spacer().frame(height: 24)
    }
}

private struct SportChip: View {
    @Environment(\.wa) private var wa
    let emoji: String
    let label: String
    let selected: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Text(verbatim: emoji).font(.system(size: 15))
                Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(selected ? .white : wa.ink)
            }
            .padding(.horizontal, 14).padding(.vertical, 8)
            .background(selected ? wa.red : wa.surface, in: Capsule())
            .overlay(Capsule().stroke(selected ? .clear : wa.line, lineWidth: 1))
            .animation(.easeInOut(duration: 0.2), value: selected)
        }
        .buttonStyle(.plain)
    }
}

/// Seven days around today: yesterday, today, tomorrow…
private struct DateStrip: View {
    @Environment(\.wa) private var wa
    let selected: Date
    let onPick: (Date) -> Void

    var body: some View {
        let today = calToday()
        HStack(spacing: 0) {
            ForEach(-2...4, id: \.self) { offset in
                let d = calAddDays(today, offset)
                let on = calSameDay(d, selected)
                let label: String = {
                    switch offset {
                    case 0: return L("sp_today")
                    case 1: return L("sp_tomorrow")
                    case -1: return L("sp_yesterday")
                    default: return evDayShort(d)
                    }
                }()
                Button { onPick(d) } label: {
                    VStack(spacing: 0) {
                        Text(verbatim: label).font(.dorr(10.5, .bold)).foregroundStyle(on ? .white : wa.mut).lineLimit(1)
                        Text(verbatim: String(Calendar(identifier: .gregorian).component(.day, from: d))).font(.dorr(15, .heavy)).foregroundStyle(on ? .white : wa.ink)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 7)
                    .background(on ? wa.red : .clear, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(4)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .animation(.easeInOut(duration: 0.2), value: calKey(selected))
    }
}

/// "Sat" in the app's language.
func evDayShort(_ d: Date) -> String {
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current + "@numbers=latn")
    f.dateFormat = "EEE"
    return f.string(from: d)
}

// MARK: - Follow

@MainActor
private struct FollowPage: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let go: (SpPage) -> Void
    @State private var query = ""
    @State private var teams: [SpTeamDto] = []
    @State private var competitions: [SpCompetitionDto]?
    @State private var follows: [SpFollowDto] = []

    var body: some View {
        let followedTeams = Set(follows.filter { $0.kind == "team" }.compactMap { $0.target?.id })
        let followedComps = Set(follows.filter { $0.kind == "competition" }.compactMap { $0.target?.id })
        let blank = query.trimmingCharacters(in: .whitespaces).isEmpty
        WaPage(title: L("sp_follow_title"), onBack: onBack) {
            DorrTextArea(value: Binding(get: { query }, set: { query = String($0.prefix(60)) }), placeholder: L("sp_search_team"), icon: "magnifyingglass", singleLine: true)
                .waRise(0)
            ForEach(teams) { t in
                FollowRow(title: t.name ?? "", sub: t.country, on: followedTeams.contains(t.id), onOpen: { go(.team(t.id)) },
                          onToggle: { toggle("team", t.id, !followedTeams.contains(t.id)) }) { Crest(team: t, size: 40) }
                    .padding(.top, 8)
            }
            if !follows.isEmpty && blank {
                WaSectionTitle(title: L("sp_my_follows"))
                ForEach(follows) { f in
                    if let t = f.target {
                        FollowRow(title: t.name ?? "", sub: t.country, on: true,
                                  onOpen: { go(f.kind == "team" ? .team(t.id) : .competition(t.id)) },
                                  onToggle: { toggle(f.kind ?? "team", t.id, false) }) {
                            if f.kind == "team" {
                                Crest(team: SpTeamDto(id: t.id, name: t.name, logo: t.logo, color: t.color), size: 40)
                            } else {
                                ZStack {
                                    if let url = ApiClient.mediaUrl(t.logo) {
                                        AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFit() }.frame(width: 28, height: 28)
                                    } else {
                                        Text(verbatim: "🏆")
                                    }
                                }
                                .frame(width: 40, height: 40)
                                .background(wa.field, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                            }
                        }
                        .padding(.top, 8)
                    }
                }
            }
            if blank {
                WaSectionTitle(title: L("sp_competitions"))
                if let list = competitions {
                    ForEach(list) { c in
                        CompetitionHeading(name: c.name, logo: c.logo, country: c.country, followed: followedComps.contains(c.id),
                                           onStar: { toggle("competition", c.id, !followedComps.contains(c.id)) }) { go(.competition(c.id)) }
                            .padding(.top, 4)
                    }
                } else {
                    SkeletonRows(count: 4, height: 44)
                }
            }
            Spacer().frame(height: 24)
        }
        .task {
            follows = (try? await SportsApi.follows().data) ?? []
            competitions = (try? await SportsApi.competitions().data) ?? []
        }
        .task(id: query) {
            try? await Task.sleep(nanoseconds: 300_000_000)
            guard !Task.isCancelled else { return }
            let q = query.trimmingCharacters(in: .whitespaces)
            teams = q.count >= 2 ? ((try? await SportsApi.teams(q: q).data) ?? []) : []
        }
    }

    private func toggle(_ kind: String, _ id: Int, _ on: Bool) {
        Task {
            if let list = await spToggleFollow(kind, id, on) { follows = list } else { follows = (try? await SportsApi.follows().data) ?? [] }
        }
    }
}

private struct FollowRow<Icon: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    let sub: String?
    let on: Bool
    let onOpen: () -> Void
    let onToggle: () -> Void
    @ViewBuilder let icon: () -> Icon

    var body: some View {
        HStack(spacing: 10) {
            Button(action: onOpen) {
                HStack(spacing: 10) {
                    icon()
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: title).font(.dorr(14.5, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                        if let sub { Text(verbatim: sub).font(.dorr(11.5)).foregroundStyle(wa.soft).lineLimit(1) }
                    }
                    Spacer(minLength: 0)
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(WaPressStyle(scale: 0.98))
            Button(action: onToggle) {
                Text(verbatim: on ? "★" : "☆").font(.system(size: 24)).foregroundStyle(on ? Color(hex: 0xF59E0B) : wa.soft).padding(.horizontal, 8)
            }
            .buttonStyle(.plain)
        }
        .padding(10)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

// MARK: - My choices

@MainActor
private struct SportsSettings: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var live = SportsLive.shared
    let onBack: () -> Void

    var body: some View {
        let prefs = live.prefs
        WaPage(title: L("sp_settings"), onBack: onBack) {
            WaSectionTitle(title: L("sp_celebration"), topPadding: 4)
            Text(L("sp_celebration_sub")).font(.dorr(12.5)).foregroundStyle(wa.mut)
            HStack(spacing: 8) {
                ForEach([("off", "🔕", "sp_cel_off"), ("calm", "🙂", "sp_cel_calm"), ("normal", "🎉", "sp_cel_normal"), ("festive", "🎆", "sp_cel_festive")], id: \.0) { key, emoji, label in
                    let on = prefs.celebrationLevel == key
                    Button { save { $0.celebration = key } } label: {
                        VStack(spacing: 0) {
                            Text(verbatim: emoji).font(.system(size: 24))
                            Text(L(label)).font(.dorr(12, .bold)).foregroundStyle(on ? .white : wa.ink)
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)
                        .background(on ? wa.red : wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(on ? .clear : wa.line, lineWidth: 1))
                    }
                    .buttonStyle(.plain)
                }
            }
            .padding(.top, 10)
            if prefs.celebrationLevel != "off" {
                Button {
                    live.celebration = SportsAlert(matchId: "preview", type: "goal", hidden: false, homeScore: 1, awayScore: 0, side: "home",
                                                   scorer: "DORR", minute: 90, celebrate: prefs.celebrationLevel)
                    if prefs.sounds ?? true { SportsSounds.play(.goal) }
                } label: {
                    Text(verbatim: "▶  " + L("sp_try_it")).font(.dorr(13.5, .heavy)).foregroundStyle(wa.red)
                        .padding(.horizontal, 16).padding(.vertical, 9)
                        .background(wa.red.opacity(0.08), in: Capsule())
                }
                .buttonStyle(.plain)
                .padding(.top, 10)
            }

            WaSectionTitle(title: L("sp_sound_section"))
            WaCard(padding: 12) {
                SpToggleLine(title: L("sp_sounds"), on: prefs.sounds ?? true, sub: L("sp_sounds_sub")) { v in save { $0.sounds = v } }
                SpToggleLine(title: L("sp_vibrate"), on: prefs.vibrate ?? true) { v in save { $0.vibrate = v } }
            }

            WaSectionTitle(title: L("sp_alerts_section"))
            WaCard(padding: 12) {
                SpToggleLine(title: L("sp_no_spoilers"), on: prefs.hideScores, sub: L("sp_no_spoilers_sub")) { v in save { $0.noSpoilers = v } }
                SpToggleLine(title: L("sp_goals_quiet"), on: prefs.goalsInQuiet ?? false, sub: L("sp_goals_quiet_sub")) { v in save { $0.goalsInQuiet = v } }
                Text(L("sp_reminder")).font(.dorr(14.5, .bold)).foregroundStyle(wa.ink).padding(.top, 8)
                FlowLayout {
                    ForEach([0, 5, 15, 30, 60], id: \.self) { mins in
                        let on = (prefs.reminderMinutes ?? 15) == mins
                        Button { save { $0.reminderMinutes = mins } } label: {
                            Text(verbatim: mins == 0 ? L("sp_reminder_off") : L("sp_reminder_n", mins)).font(.dorr(12.5, .bold)).foregroundStyle(on ? .white : wa.ink)
                                .padding(.horizontal, 12).padding(.vertical, 7)
                                .background(on ? wa.red : wa.field, in: Capsule())
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.top, 8)
            }
            WaNote(text: L("sp_data_note"), icon: "bell.badge.fill").padding(.top, 14)
            Spacer().frame(height: 24)
        }
        .task { if let p = try? await SportsApi.preferences().data { live.prefs = p } }
    }

    private func save(_ change: (inout SpPrefsDto) -> Void) {
        var p = live.prefs
        change(&p)
        live.prefs = p
        Task {
            let body: [String: JSONValue] = [
                "no_spoilers": .bool(p.hideScores), "celebration": .string(p.celebrationLevel), "sounds": .bool(p.sounds ?? true),
                "vibrate": .bool(p.vibrate ?? true), "reminder_minutes": .int(p.reminderMinutes ?? 15), "goals_in_quiet": .bool(p.goalsInQuiet ?? false),
            ]
            if let saved = try? await SportsApi.savePreferences(body).data { live.prefs = saved }
        }
    }
}

// MARK: - The match page

@MainActor
struct MatchPage: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var live = SportsLive.shared
    let id: String
    let onBack: () -> Void
    let go: (SpPage) -> Void
    @State private var match: SpMatchDto?
    @State private var table: SpCompetitionPageDto?
    @State private var tabKey: String?
    @State private var revealed = false
    @State private var insights: SpInsightsDto?
    @State private var shareSheet: String?

    var body: some View {
        WaPage(title: match?.competition?.name ?? L("sp_title"), onBack: onBack) {
            if let m = match {
                SpTicker { now in
                    VStack(alignment: .leading, spacing: 0) { page(m, now) }
                }
            } else {
                WaSkeleton(cornerRadius: 28).frame(height: 230)
                SkeletonRows(count: 3, height: 56).padding(.top, 12)
            }
        }
        .task(id: id) { await load() }
        .sheet(isPresented: Binding(get: { shareSheet == "share" }, set: { if !$0 { shareSheet = nil } })) {
            if let m = match { ShareMatchSheet(m: m) { shareSheet = nil }.dorrThemed() }
        }
        .sheet(isPresented: Binding(get: { shareSheet == "room" }, set: { if !$0 { shareSheet = nil } })) {
            PeoplePickerSheet(initial: [], onDismiss: { shareSheet = nil }) { ids in
                shareSheet = nil
                guard let m = match else { return }
                Task {
                    if let conv = await makeRoom(m, ids) {
                        AppToast.show(L("sp_room_made"))
                        SportsLink.shared.close()
                        ChatPush.shared.open(.conversation(conv))
                    }
                }
            }
            .chatAppearance()
        }
        // Its own channel while the page is open; a newer version reloads the details (events, stats).
        .onAppear { ChatRealtime.shared.watchPublic("sports.match.\(id)", ["sports.match.updated"]) }
        .onDisappear { ChatRealtime.shared.unwatchPublic("sports.match.\(id)") }
        .task(id: live.latest[id]?.version ?? 0) {
            guard let v = live.latest[id]?.version, v > (match?.version ?? Int.max) else { return }
            try? await Task.sleep(nanoseconds: 1_200_000_000)
            if !Task.isCancelled { await load() }
        }
        // While the match is on, a reload every 45 s is the safety net (as on Android).
        .task(id: "\(id)|\(["live", "break"].contains(match?.state ?? ""))") {
            while ["live", "break"].contains(match?.state ?? "") {
                try? await Task.sleep(nanoseconds: 45_000_000_000)
                if Task.isCancelled { return }
                await load()
            }
        }
        // Prediction, head to head, who's missing (football): one request, kept on our server.
        .task(id: "\(id)|\(match?.sport ?? "")") {
            if insights == nil, match?.sport == "football", match?.home != nil {
                insights = (try? await SportsApi.insights(id).data) ?? SpInsightsDto()
            }
        }
    }

    private func load() async {
        if let m = try? await SportsApi.match(id, timezone: spZone()).data { match = m }
    }

    @ViewBuilder private func page(_ m: SpMatchDto, _ now: Date) -> some View {
        let hide = live.prefs.hideScores && !revealed
        if m.home == nil {
            RaceHero(m: m, now: now)
        } else {
            MatchHero(m: m, now: now, hide: hide, onReveal: { revealed = true }, onTeam: { go(.team($0)) }, onFollow: { side in
                let team = side == "home" ? m.home : m.away
                let on = side == "home" ? m.following?.home != true : m.following?.away != true
                if let t = team { Task { _ = await spToggleFollow("team", t.id, on); await load() } }
            })
            if let f = m.fight { FightBanner(m: m, f: f, hide: hide) }
            if !hide { MatchPoster(m: m, poster: m.poster ?? insights?.poster, go: go) }
        }
        // Share, watch together (195).
        HStack(spacing: 8) {
            ForEach([("share", "sp_share", "📤"), ("room", "sp_watch_together", "🍿")], id: \.0) { key, label, emoji in
                Button { shareSheet = key } label: {
                    HStack(spacing: 6) {
                        Text(verbatim: emoji).font(.system(size: 16))
                        Text(L(label)).font(.dorr(13, .bold)).foregroundStyle(wa.ink)
                    }
                    .frame(maxWidth: .infinity).padding(.vertical, 11)
                    .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.top, 10)
        PlayCard(m: m) { go(.contest($0)) }
        Spacer().frame(height: 12)

        let tabs = tabKeys(m)
        let current = tabKey.flatMap { tabs.contains($0) ? $0 : nil } ?? tabs.first ?? "sp_tab_table"
        ScrollTabs(labels: tabs.map { L($0) }, selected: tabs.firstIndex(of: current) ?? 0) { tabKey = tabs[$0] }
        Spacer().frame(height: 12)
        tabBody(current, m, hide)
            .task(id: current) {
                if current == "sp_tab_table", table == nil, let cid = m.competition?.id {
                    table = try? await SportsApi.competition(cid, timezone: spZone()).data
                }
            }
        // Where and who.
        let info = [m.venue.map { "🏟️ \($0)" + (m.city.map { ", \($0)" } ?? "") }, m.referee.map { "🧑‍⚖️ \($0)" }, m.round.map { "🗓️ \($0)" }].compactMap { $0 }
        if !info.isEmpty {
            VStack(alignment: .leading, spacing: 4) {
                ForEach(info, id: \.self) { Text(verbatim: $0).font(.dorr(13)).foregroundStyle(wa.mut) }
            }
            .padding(14)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .padding(.top, 16)
        }
        Text(L("sp_official_data")).font(.dorr(11)).foregroundStyle(wa.soft).padding(.top, 10)
        Spacer().frame(height: 24)
    }

    private func tabKeys(_ m: SpMatchDto) -> [String] {
        var tabs: [String] = []
        if m.home == nil { tabs.append("sp_tab_results") } else if m.sport != "football" && m.sport != "mma" { tabs.append("sp_tab_periods") } else if m.sport == "football" { tabs.append("sp_tab_events") }
        if !(m.statistics ?? []).isEmpty { tabs.append("sp_tab_stats") }
        if !(m.lineups ?? [:]).isEmpty { tabs.append("sp_tab_lineups") }
        if !(m.players ?? [:]).isEmpty { tabs.append("spx_tab_players") }
        if m.sport == "football" && m.home != nil {
            tabs.append("spx_tab_forecast")
            tabs.append("spx_h2h")
            if !(insights?.injuries?.data ?? []).isEmpty { tabs.append("spx_tab_missing") }
        }
        if m.sport != "mma" { tabs.append("sp_tab_table") }
        return tabs
    }

    @ViewBuilder private func tabBody(_ key: String, _ m: SpMatchDto, _ hide: Bool) -> some View {
        switch key {
        case "sp_tab_events": if hide { hiddenNote } else { Timeline(m: m, go: go) }
        case "sp_tab_results": if hide { hiddenNote } else { RaceResults(m: m) }
        case "sp_tab_periods": if hide { hiddenNote } else { PeriodsTable(m: m) }
        case "sp_tab_stats":
            MatchStats(stats: m.statistics ?? [], home: spTeamColor(m.home?.color, m.home?.id ?? 0), away: spTeamColor(m.away?.color, m.away?.id ?? 1))
        case "sp_tab_lineups": PitchLineups(m: m, go: go)
        case "spx_tab_players": PlayersSheet(m: m, go: go)
        case "spx_tab_forecast": ForecastTab(m: m, insights: insights)
        case "spx_h2h": H2hTab(m: m, insights: insights, go: go)
        case "spx_tab_missing": MissingTab(m: m, insights: insights, go: go)
        default:
            if let t = table {
                let group = m.home == nil ? t.standings?.first : t.standings?.first { g in (g.rows ?? []).contains { $0.team?.id == m.home?.id } }
                if let g = group {
                    StandingsTable(rows: g.rows ?? [], highlight: Set([m.home?.id, m.away?.id].compactMap { $0 })) { go(.team($0.id)) }
                } else {
                    Text(L("sp_no_table")).font(.dorr(13)).foregroundStyle(wa.mut)
                }
            } else {
                WaSkeleton(cornerRadius: 20).frame(height: 200)
            }
        }
    }

    private var hiddenNote: some View {
        Text(L("sp_hidden_events")).font(.dorr(13)).foregroundStyle(wa.mut)
    }
}

/// The top of the match page: both kits, crests, a big score, the minute in a ring.
private struct MatchHero: View {
    let m: SpMatchDto
    let now: Date
    let hide: Bool
    let onReveal: () -> Void
    let onTeam: (Int) -> Void
    let onFollow: (String) -> Void

    var body: some View {
        let home = spTeamColor(m.home?.color, m.home?.id ?? 0)
        let away = spTeamColor(m.away?.color, m.away?.id ?? 1)
        ZStack {
            LinearGradient(colors: [home, Color(hex: 0x0B1220), away], startPoint: .topLeading, endPoint: .bottomTrailing)
            // The stadium behind (its photo), and each side's captain, faint — a poster.
            if let url = ApiClient.mediaUrl(m.poster?.venue?.image) {
                AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() }.opacity(0.30)
            }
            if !hide {
                HStack {
                    if let url = ApiClient.mediaUrl(m.poster?.home?.captain?.photo) {
                        AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() }.frame(width: 150, height: 150).clipped().opacity(0.18)
                    }
                    Spacer()
                    if let url = ApiClient.mediaUrl(m.poster?.away?.captain?.photo) {
                        AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() }.frame(width: 150, height: 150).clipped().opacity(0.18)
                    }
                }
                .frame(maxHeight: .infinity, alignment: .bottom)
            }
            LinearGradient(colors: [.black.opacity(0.10), .black.opacity(0.35)], startPoint: .top, endPoint: .bottom)
            // Pitch lines, faint.
            GeometryReader { proxy in
                let size = proxy.size
                Path { p in
                    p.addEllipse(in: CGRect(x: size.width / 2 - min(size.width, size.height) * 0.22, y: size.height / 2 - min(size.width, size.height) * 0.22,
                                            width: min(size.width, size.height) * 0.44, height: min(size.width, size.height) * 0.44))
                    p.move(to: CGPoint(x: size.width / 2, y: 0))
                    p.addLine(to: CGPoint(x: size.width / 2, y: size.height))
                }
                .stroke(Color.white.opacity(0.10), lineWidth: 1.2)
            }
            content.padding(18)
        }
        .clipShape(RoundedRectangle(cornerRadius: 28, style: .continuous))
        .waRise(0)
    }

    private var content: some View {
        VStack(spacing: 0) {
            HStack {
                Text(verbatim: m.round ?? "").font(.dorr(11.5)).foregroundStyle(.white.opacity(0.75)).lineLimit(1)
                Spacer()
                StatusChip(m: m, now: now, onDark: true)
            }
            HStack(spacing: 0) {
                HeroTeam(team: m.home, followed: m.following?.home == true, onTeam: onTeam) { onFollow("home") }
                ZStack {
                    Circle().fill(RadialGradient(colors: [.white.opacity(0.16), .clear], center: .center, startRadius: 0, endRadius: 68))
                    Circle().fill(Color(hex: 0x0B1220).opacity(0.55)).frame(width: 112, height: 112)
                        .overlay(Circle().stroke(Color.white.opacity(0.10), lineWidth: 1))
                    if m.started && !hide {
                        let isLive = m.state == "live"
                        MinuteRing(m: m, now: now, size: 112, color: isLive ? Color(hex: 0x4ADE80) : .white) {
                            VStack(spacing: 0) {
                                HStack(spacing: 4) {
                                    FlipScore(value: m.homeScore, size: 30, color: .white)
                                    Text(verbatim: ":").font(.dorr(24, .black)).foregroundStyle(.white.opacity(0.55))
                                    FlipScore(value: m.awayScore, size: 30, color: .white)
                                }
                                Text(verbatim: spClockText(m, now)).font(.dorr(11.5, .bold)).foregroundStyle(isLive ? Color(hex: 0x4ADE80) : .white.opacity(0.7)).lineLimit(1)
                            }
                        }
                    } else if m.started {
                        Button(action: onReveal) {
                            Text(L("sp_reveal")).font(.dorr(13, .heavy)).foregroundStyle(Color(hex: 0x0B1220))
                                .padding(.horizontal, 14).padding(.vertical, 8)
                                .background(Color.white, in: Capsule())
                        }
                        .buttonStyle(.plain)
                    } else {
                        VStack(spacing: 0) {
                            Text(verbatim: m.localTime ?? "").font(.dorr(26, .black)).foregroundStyle(.white)
                            if let d = m.localDate { Text(verbatim: d).font(.dorr(11)).foregroundStyle(.white.opacity(0.7)).lineLimit(1) }
                        }
                    }
                }
                .frame(width: 136, height: 136)
                HeroTeam(team: m.away, followed: m.following?.away == true, onTeam: onTeam) { onFollow("away") }
            }
            .padding(.top, 14)
            if m.started && !hide {
                // Goal scorers under each side (football).
                let goals = (m.events ?? []).filter { ["goal", "penalty", "own_goal"].contains($0.kind) }
                if !goals.isEmpty {
                    HStack(alignment: .top) {
                        ForEach(["home", "away"], id: \.self) { side in
                            VStack(alignment: side == "home" ? .leading : .trailing, spacing: 2) {
                                ForEach(Array(goals.filter { $0.side == side }.enumerated()), id: \.offset) { _, g in
                                    Text(verbatim: "⚽ \(g.player ?? "") \(g.minute.map(String.init) ?? "")'" + (g.kind == "penalty" ? " (P)" : (g.kind == "own_goal" ? " (OG)" : "")))
                                        .font(.dorr(11.5)).foregroundStyle(.white.opacity(0.88)).lineLimit(1)
                                }
                            }
                            .frame(maxWidth: .infinity, alignment: side == "home" ? .leading : .trailing)
                        }
                    }
                    .padding(.top, 12)
                }
                if !m.allPeriods.isEmpty { PeriodStrip(m: m, onDark: true).padding(.top, 12) }
            }
        }
    }
}

private struct HeroTeam: View {
    let team: SpTeamDto?
    let followed: Bool
    let onTeam: (Int) -> Void
    let onFollow: () -> Void

    var body: some View {
        VStack(spacing: 0) {
            Button { if let id = team?.id { onTeam(id) } } label: { Crest(team: team, size: 66, plate: .white) }.buttonStyle(.plain)
            Text(verbatim: team?.name ?? "").font(.dorr(13.5, .heavy)).foregroundStyle(.white).multilineTextAlignment(.center).lineLimit(2).padding(.top, 8)
            Button(action: onFollow) {
                Text(verbatim: followed ? "★" : "☆").font(.system(size: 20)).foregroundStyle(followed ? Color(hex: 0xFACC15) : .white.opacity(0.75))
                    .padding(.horizontal, 10).padding(.vertical, 2)
            }
            .buttonStyle(.plain)
        }
        .frame(maxWidth: .infinity)
    }
}

/// Events down the middle: the home side on one side, the away side on the other, newest on top.
private struct Timeline: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    let go: (SpPage) -> Void

    var body: some View {
        let events = Array((m.events ?? []).filter { $0.kind != "other" }.reversed())
        if events.isEmpty {
            Text(L(m.state == "scheduled" ? "sp_not_started" : "sp_no_events")).font(.dorr(13)).foregroundStyle(wa.mut)
        } else {
            WaCard(padding: 12) {
                ForEach(Array(events.enumerated()), id: \.offset) { i, e in
                    HStack(spacing: 0) {
                        ZStack(alignment: .trailing) { if e.side == "home" { EventText(e: e, alignEnd: true, go: go) } }.frame(maxWidth: .infinity, alignment: .trailing)
                        VStack(spacing: 0) {
                            Text(verbatim: eventIcon(e)).font(.system(size: 13))
                            Text(verbatim: "\(e.minute.map(String.init) ?? "")\(e.extra.map { "+\($0)" } ?? "")'").font(.dorr(9.5, .bold)).foregroundStyle(wa.ink)
                        }
                        .frame(width: 40, height: 40)
                        .background(eventColor(e).opacity(0.14), in: Circle())
                        .padding(.horizontal, 8)
                        ZStack(alignment: .leading) { if e.side == "away" { EventText(e: e, alignEnd: false, go: go) } }.frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .padding(.vertical, 5)
                    .waRise(min(i, 10))
                }
            }
        }
    }
}

private struct EventText: View {
    @Environment(\.wa) private var wa
    let e: SpEventDto
    let alignEnd: Bool
    let go: (SpPage) -> Void

    var body: some View {
        // The player opens his page.
        let sub: String? = {
            switch e.kind {
            case "sub": return e.assist.map { "↩ \($0)" }
            case "goal": return e.assist.map { "🅰 \($0)" }
            case "penalty": return L("sp_ev_penalty")
            case "own_goal": return L("sp_ev_own_goal")
            case "missed_penalty": return L("sp_ev_missed")
            case "var": return e.detail
            default: return nil
            }
        }()
        Button { if let pid = e.playerId { go(.player(pid)) } } label: {
            VStack(alignment: alignEnd ? .trailing : .leading, spacing: 0) {
                Text(verbatim: e.player ?? "").font(.dorr(13.5, ["goal", "penalty", "own_goal"].contains(e.kind) ? .heavy : .semibold)).foregroundStyle(wa.ink).lineLimit(1)
                if let sub { Text(verbatim: sub).font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(1) }
            }
        }
        .buttonStyle(.plain)
        .allowsHitTesting(e.playerId != nil)
    }
}

private func eventIcon(_ e: SpEventDto) -> String {
    switch e.kind {
    case "goal", "penalty": return "⚽"
    case "own_goal": return "🥅"
    case "missed_penalty": return "❌"
    case "yellow": return "🟨"
    case "second_yellow": return "🟨🟥"
    case "red": return "🟥"
    case "sub": return "🔁"
    case "var": return "📺"
    default: return "•"
    }
}

private func eventColor(_ e: SpEventDto) -> Color {
    switch e.kind {
    case "goal", "penalty": return spGreen
    case "own_goal", "red", "second_yellow", "missed_penalty": return spLive
    case "yellow": return Color(hex: 0xEAB308)
    default: return Color(hex: 0x64748B)
    }
}

/// A JSON value as the text the provider sent ("55%", 12).
func spText(_ v: JSONAny?) -> String? {
    switch v {
    case .string(let s): return s
    case .number(let n): return n.truncatingRemainder(dividingBy: 1) == 0 ? String(Int(n)) : String(n)
    case .bool(let b): return String(b)
    default: return nil
    }
}

/// Each statistic as two bars that fill in from the middle, in the teams' colours.
private struct MatchStats: View {
    @Environment(\.wa) private var wa
    let stats: [SpStatDto]
    let home: Color
    let away: Color

    var body: some View {
        WaCard(padding: 14) {
            ForEach(Array(stats.enumerated()), id: \.offset) { i, s in
                let h = Double((spText(s.home) ?? "").replacingOccurrences(of: "%", with: "")) ?? 0
                let a = Double((spText(s.away) ?? "").replacingOccurrences(of: "%", with: "")) ?? 0
                CompareRow(label: statName(s.type ?? ""), home: h, away: a, homeText: spText(s.home) ?? "0", awayText: spText(s.away) ?? "0",
                           homeColor: home, awayColor: away, index: i, plainText: true)
            }
        }
    }

    private func statName(_ type: String) -> String {
        switch type {
        case "Ball Possession": return L("sp_st_possession")
        case "Total Shots": return L("sp_st_shots")
        case "Shots on Goal": return L("sp_st_on_target")
        case "Corner Kicks": return L("sp_st_corners")
        case "Fouls": return L("sp_st_fouls")
        case "Offsides": return L("sp_st_offsides")
        case "Yellow Cards": return L("sp_st_yellow")
        case "Red Cards": return L("sp_st_red")
        case "Goalkeeper Saves": return L("sp_st_saves")
        case "Total passes": return L("sp_st_passes")
        case "Passes %": return L("sp_st_pass_acc")
        case "expected_goals": return "xG"
        default: return type
        }
    }
}

/// Two sides of one number, each bar growing from the middle out.
struct CompareRow: View {
    @Environment(\.wa) private var wa
    let label: String
    let home: Double
    let away: Double
    let homeText: String
    let awayText: String
    let homeColor: Color
    let awayColor: Color
    var index = 0
    /// The match statistics write both numbers in ink; comparisons dim the smaller one.
    var plainText = false
    @State private var grow: CGFloat = 0

    var body: some View {
        let total = max(home + away, 0.0001) == 0.0001 ? 1 : home + away
        VStack(spacing: 4) {
            HStack(spacing: 0) {
                Text(verbatim: homeText).font(.dorr(13, .heavy)).foregroundStyle(plainText || home >= away ? wa.ink : wa.mut).frame(width: 56, alignment: .leading)
                Text(verbatim: label).font(.dorr(12, .semibold)).foregroundStyle(wa.mut).lineLimit(1).frame(maxWidth: .infinity)
                Text(verbatim: awayText).font(.dorr(13, .heavy)).foregroundStyle(plainText || away >= home ? wa.ink : wa.mut).frame(width: 56, alignment: .trailing)
            }
            HStack(spacing: 4) {
                GeometryReader { p in
                    ZStack(alignment: .trailing) {
                        Capsule().fill(wa.field)
                        Capsule().fill(homeColor).frame(width: p.size.width * CGFloat(home / total) * grow)
                    }
                }
                GeometryReader { p in
                    ZStack(alignment: .leading) {
                        Capsule().fill(wa.field)
                        Capsule().fill(awayColor).frame(width: p.size.width * CGFloat(away / total) * grow)
                    }
                }
            }
            .frame(height: 7)
        }
        .padding(.vertical, 6)
        .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.7).delay(Double(index) * 0.06)) { grow = 1 } }
    }
}
