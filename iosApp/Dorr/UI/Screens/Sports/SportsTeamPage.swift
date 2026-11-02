import SwiftUI

// androidApp `sports/SportsTeamPage.kt` — a team, complete: its stadium behind it, the coach, the next match with a
// countdown, form and places in the tables, every match of the season, the squad, the season's numbers drawn as
// charts, and the transfers. Following and alerts stay here.

let spAlertLabels: [(String, String)] = [
    ("reminder", "sp_al_reminder"), ("kickoff", "sp_al_kickoff"), ("goal", "sp_al_goal"), ("red_card", "sp_al_red"),
    ("half_time", "sp_al_ht"), ("finished", "sp_al_ft"), ("schedule", "sp_al_schedule"), ("lineups", "sp_al_lineups"),
]

/// "red_card" → "redCard" (how the snake-case decoder hands map keys back).
func camel(_ key: String) -> String {
    let parts = key.split(separator: "_")
    return parts.enumerated().map { $0.offset == 0 ? String($0.element) : $0.element.capitalized }.joined()
}

extension JSONAny {
    /// "played.total" → the value at that path.
    func at(_ path: String) -> JSONAny? {
        var cur: JSONAny? = self
        for key in path.split(separator: ".") {
            // The snake-case decoder camel-cases object keys too.
            guard let next = cur?[String(key)] ?? cur?[camel(String(key))] else { return nil }
            if case .null = next { return nil }
            cur = next
        }
        return cur
    }

    func int(_ path: String) -> Int? {
        switch at(path) {
        case .number(let n): return Int(n)
        case .string(let s): return Int(s)
        default: return nil
        }
    }

    func str(_ path: String) -> String? { spText(at(path)) }
}

@MainActor
struct TeamPage: View {
    @Environment(\.wa) private var wa
    let id: Int
    let onBack: () -> Void
    let go: (SpPage) -> Void
    @State private var page: SpTeamPageDto?
    @State private var reload = 0
    @State private var tab = 0
    @State private var squad: SpPart<[SpSquadLineDto]>?
    @State private var stats: SpTeamStatsDto?
    @State private var statsLoaded = false
    @State private var transfers: SpPart<[SpTransferDto]>?

    var body: some View {
        WaPage(title: page?.name ?? L("sp_title"), onBack: onBack) {
            if let p = page {
                let football = p.sport == nil || p.sport == "football"
                let tabs = football ? ["spx_tab_overview", "sp_tab_matches", "spx_tab_squad", "spx_tab_stats", "spx_tab_transfers"] : ["spx_tab_overview", "sp_tab_matches"]
                let current = tabs[min(tab, tabs.count - 1)]
                let color = spTeamColor(p.color, p.id ?? id)
                TeamHeader(p: p, color: color, go: go) {
                    Task {
                        _ = await spToggleFollow("team", p.id ?? id, p.follow == nil)
                        reload += 1
                    }
                }
                Spacer().frame(height: 14)
                ScrollTabs(labels: tabs.map { L($0) }, selected: tabs.firstIndex(of: current) ?? 0) { tab = $0 }
                Spacer().frame(height: 12)
                SpTicker { now in
                    VStack(alignment: .leading, spacing: 0) {
                        switch current {
                        case "spx_tab_overview": TeamOverview(p: p, teamId: id, color: color, now: now, go: go) { reload += 1 }
                        case "sp_tab_matches": TeamMatches(p: p, now: now, go: go)
                        case "spx_tab_squad": TeamSquad(squad: squad, color: color, go: go)
                        case "spx_tab_stats": TeamStats(stats: statsLoaded ? stats : nil, loaded: statsLoaded, color: color) { cid in
                            statsLoaded = false
                            Task {
                                stats = try? await SportsApi.teamStatistics(id, competition: cid).data
                                statsLoaded = true
                            }
                        }
                        default: TransfersList(transfers: transfers, go: go)
                        }
                    }
                    .id(current)
                    .transition(.opacity)
                }
                .task(id: current) {
                    switch current {
                    case "spx_tab_squad": if squad == nil { squad = (try? await SportsApi.squad(id).data) ?? SpPart(state: "pending") }
                    case "spx_tab_stats":
                        if !statsLoaded {
                            stats = try? await SportsApi.teamStatistics(id).data
                            statsLoaded = true
                        }
                    case "spx_tab_transfers": if transfers == nil { transfers = (try? await SportsApi.transfers(id).data) ?? SpPart(state: "pending") }
                    default: break
                    }
                }
                Spacer().frame(height: 24)
            } else {
                WaSkeleton(cornerRadius: 28).frame(height: 230)
                SkeletonRows().padding(.top, 12)
            }
        }
        .task(id: "\(id)|\(reload)") { if let p = try? await SportsApi.team(id, timezone: spZone()).data { page = p } }
    }
}

private struct TeamHeader: View {
    let p: SpTeamPageDto
    let color: Color
    let go: (SpPage) -> Void
    let onFollow: () -> Void

    var body: some View {
        let deep = color.scaled(0.35)
        ZStack {
            LinearGradient(colors: [color, deep], startPoint: .topLeading, endPoint: .bottomTrailing)
            // The stadium behind, darkened into the team's colour.
            if let url = ApiClient.mediaUrl(p.venue?.image) {
                AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() }.opacity(0.42)
            }
            LinearGradient(colors: [.black.opacity(0.15), deep.opacity(0.92)], startPoint: .top, endPoint: .bottom)
            VStack(spacing: 0) {
                Crest(team: SpTeamDto(id: p.id ?? 0, name: p.name, logo: p.logo, color: p.color), size: 88, plate: .white)
                Text(verbatim: p.name ?? "").font(.dorr(22, .heavy)).foregroundStyle(.white).multilineTextAlignment(.center).padding(.top, 10)
                Text(verbatim: [p.country, p.founded.map { L("spx_founded", $0) }].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.82))
                HStack(spacing: 8) {
                    let following = p.follow != nil
                    Button(action: onFollow) {
                        Text(verbatim: following ? "★  " + L("sp_following") : "☆  " + L("sp_follow")).font(.dorr(13.5, .heavy)).foregroundStyle(following ? color : .white)
                            .padding(.horizontal, 18).padding(.vertical, 9)
                            .background(following ? Color.white : Color.white.opacity(0.18), in: Capsule())
                    }
                    .buttonStyle(.plain)
                    if let c = p.coach {
                        Button { if let cid = c.id { go(.coach(cid)) } } label: {
                            HStack(spacing: 6) {
                                PhotoAvatar(url: c.photo, name: c.name, size: 30)
                                Text(verbatim: c.name ?? "").font(.dorr(12.5, .bold)).foregroundStyle(.white).lineLimit(1)
                            }
                            .padding(.leading, 4).padding(.trailing, 12).padding(.vertical, 4)
                            .background(Color.white.opacity(0.16), in: Capsule())
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.top, 12)
            }
            .padding(18)
        }
        .clipShape(RoundedRectangle(cornerRadius: 28, style: .continuous))
        .waRise(0)
    }
}

// MARK: - Overview

private struct TeamOverview: View {
    @Environment(\.wa) private var wa
    let p: SpTeamPageDto
    let teamId: Int
    let color: Color
    let now: Date
    let go: (SpPage) -> Void
    let onChanged: () -> Void

    var body: some View {
        let live = p.live ?? [], next = p.next ?? [], form = p.form ?? [], tables = p.tables ?? []
        if !live.isEmpty {
            WaSectionTitle(title: L("sp_live"), topPadding: 0)
            ForEach(live) { m in LiveCard(m: m, now: now) { go(.match(m.id)) }.padding(.bottom, 8) }
        }
        if let m = next.first { NextMatchCard(m: m, teamId: teamId, color: color, now: now) { go(.match(m.id)) } }
        if !form.isEmpty {
            SectionCard(title: L("spx_last_five")) {
                HStack(spacing: 8) {
                    ForEach(Array(form.reversed().enumerated()), id: \.offset) { i, f in
                        let c = (f.result ?? "D").first ?? "D"
                        Button { if let fid = f.id { go(.match(fid)) } } label: {
                            VStack(spacing: 0) {
                                Text(verbatim: spFormLetter(c)).font(.dorr(16, .black)).foregroundStyle(spFormColor(c))
                                Text(verbatim: f.score ?? "").font(.dorr(11)).foregroundStyle(wa.mut)
                            }
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 8)
                            .background(spFormColor(c).opacity(0.12), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        }
                        .buttonStyle(.plain)
                        .waRise(i)
                    }
                }
            }
        }
        ForEach(Array(tables.enumerated()), id: \.offset) { _, t in
            SectionCard(title: nil) {
                Button { if let cid = t.competition?.id { go(.competition(cid)) } } label: {
                    HStack(spacing: 12) {
                        Text(verbatim: String(t.rank ?? 0)).font(.dorr(24, .black)).foregroundStyle(color)
                            .frame(width: 52, height: 52)
                            .background(color.opacity(0.12), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: t.competition?.name ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                            Text(verbatim: L("spx_table_line", t.points ?? 0, t.played ?? 0, ((t.goalDiff ?? 0) > 0 ? "+" : "") + String(t.goalDiff ?? 0)))
                                .font(.dorr(12)).foregroundStyle(wa.mut)
                            if let d = t.description { Text(verbatim: d).font(.dorr(11, .bold)).foregroundStyle(spZoneColor(d) ?? wa.soft).lineLimit(1) }
                        }
                        Spacer(minLength: 0)
                        FormStrip(form: t.form, size: 16)
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
        if let v = p.venue {
            SectionCard(title: L("spx_stadium")) {
                if let url = ApiClient.mediaUrl(v.image) {
                    AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() }
                        .frame(maxWidth: .infinity).frame(height: 150).clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
                        .padding(.bottom, 8)
                }
                SpInfoLine(emoji: "🏟️", label: L("spx_name"), value: v.name)
                SpInfoLine(emoji: "📍", label: L("spx_city"), value: [v.city, v.address].compactMap { $0 }.joined(separator: " · "))
                SpInfoLine(emoji: "👥", label: L("spx_capacity"), value: v.capacity.map { NumberFormatter.localizedString(from: NSNumber(value: $0), number: .decimal) })
                SpInfoLine(emoji: "🌱", label: L("spx_surface"), value: v.surface)
            }
        }
        // Following: what to be told about.
        if let f = p.follow {
            SectionCard(title: L("sp_alerts_for_team")) {
                FlowLayout {
                    ForEach(spAlertLabels, id: \.0) { key, label in
                        let on = (f.alerts?[camel(key)] ?? f.alerts?[key]) == true
                        Button {
                            Task {
                                _ = try? await SportsApi.follow(["kind": .string("team"), "target_id": .int(teamId), "alerts": .object([key: .bool(!on)])])
                                onChanged()
                            }
                        } label: {
                            Text(verbatim: (on ? "🔔 " : "🔕 ") + L(label)).font(.dorr(12.5, .bold)).foregroundStyle(on ? .white : wa.ink)
                                .padding(.horizontal, 11).padding(.vertical, 7)
                                .background(on ? wa.red : wa.field, in: Capsule())
                        }
                        .buttonStyle(.plain)
                    }
                }
                SpToggleLine(title: L("sp_no_spoilers_team"), on: f.noSpoilers ?? false, sub: L("sp_no_spoilers_sub")) { on in
                    Task {
                        _ = try? await SportsApi.follow(["kind": .string("team"), "target_id": .int(teamId), "no_spoilers": .bool(on)])
                        onChanged()
                    }
                }
            }
        }
        if live.isEmpty && next.isEmpty && form.isEmpty && tables.isEmpty { StateNote(state: nil, empty: "sp_no_comp_matches").padding(.top, 12) }
    }
}

/// The next match, big, with the time left.
private struct NextMatchCard: View {
    let m: SpMatchDto
    let teamId: Int
    let color: Color
    let now: Date
    let onClick: () -> Void

    var body: some View {
        let left = parseInstant(m.startsAt).map { $0.timeIntervalSince(now) }
        Button(action: onClick) {
            VStack(spacing: 0) {
                HStack {
                    Text(L("spx_next_match")).font(.dorr(12, .bold)).foregroundStyle(.white.opacity(0.75))
                    Spacer()
                    Text(verbatim: m.competition?.name ?? "").font(.dorr(11.5)).foregroundStyle(.white.opacity(0.75)).lineLimit(1)
                }
                HStack(spacing: 0) {
                    side(m.home)
                    VStack(spacing: 0) {
                        if let left, left >= 0 {
                            let total = Int(left / 60)
                            HStack(spacing: 4) {
                                ForEach([(total / 1440, "spx_days"), ((total / 60) % 24, "spx_hours"), (total % 60, "spx_minutes")], id: \.1) { v, l in
                                    VStack(spacing: 0) {
                                        Text(verbatim: String(v)).font(.dorr(16, .black)).foregroundStyle(.white)
                                        Text(L(l)).font(.dorr(8.5)).foregroundStyle(.white.opacity(0.7))
                                    }
                                    .padding(.horizontal, 6).padding(.vertical, 4)
                                    .background(Color.white.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
                                }
                            }
                        }
                        Text(verbatim: [m.localDate.map(spShortDate), m.localTime].compactMap { $0 }.joined(separator: " · ")).font(.dorr(11.5)).foregroundStyle(.white.opacity(0.8)).padding(.top, 6)
                    }
                    .frame(width: 110)
                    side(m.away)
                }
                .padding(.top, 12)
            }
            .padding(16)
            .background(LinearGradient(colors: [Color(hex: 0x0B1220), color.opacity(0.85)], startPoint: .topLeading, endPoint: .bottomTrailing),
                        in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        }
        .buttonStyle(.plain)
        .padding(.top, 4)
        .waRise(1)
    }

    private func side(_ t: SpTeamDto?) -> some View {
        VStack(spacing: 6) {
            Crest(team: t, size: 54, plate: .white)
            Text(verbatim: t?.name ?? "").font(.dorr(12.5, t?.id == teamId ? .heavy : .semibold)).foregroundStyle(.white).lineLimit(1)
        }
        .frame(maxWidth: .infinity)
    }
}

// MARK: - Matches

private struct TeamMatches: View {
    let p: SpTeamPageDto
    let now: Date
    let go: (SpPage) -> Void
    @State private var which: String?

    var body: some View {
        let live = p.live ?? [], next = p.next ?? [], last = p.last ?? []
        let pick = which ?? (!next.isEmpty || !live.isEmpty ? "next" : "last")
        ChoiceChips(options: [("next", L("sp_next")), ("last", L("sp_last"))], selected: pick) { which = $0 }
        Spacer().frame(height: 10)
        let list = pick == "next" ? live + next : last
        if list.isEmpty { StateNote(state: nil, empty: "sp_no_comp_matches") }
        ForEach(list) { m in MatchDayRow(m: m, now: now) { go(.match(m.id)) } }
    }
}

// MARK: - Squad

private let squadLines = ["Goalkeeper": "spx_goalkeepers", "Defender": "spx_defenders", "Midfielder": "spx_midfielders", "Attacker": "spx_attackers"]

private struct TeamSquad: View {
    @Environment(\.wa) private var wa
    let squad: SpPart<[SpSquadLineDto]>?
    let color: Color
    let go: (SpPage) -> Void

    var body: some View {
        if let squad {
            if let lines = squad.data, !lines.isEmpty {
                ForEach(Array(lines.enumerated()), id: \.offset) { _, line in
                    let players = line.players ?? []
                    WaSectionTitle(title: squadLines[line.position ?? ""].map { L($0) } ?? (line.position ?? ""), topPadding: 8) {
                        Text(verbatim: String(players.count)).font(.dorr(12, .bold)).foregroundStyle(wa.soft)
                    }
                    LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 8), count: 3), spacing: 8) {
                        ForEach(Array(players.enumerated()), id: \.offset) { i, pl in
                            Button { if let pid = pl.id { go(.player(pid)) } } label: {
                                VStack(spacing: 0) {
                                    PhotoAvatar(url: pl.photo, name: pl.name, size: 60)
                                        .overlay(alignment: .bottomTrailing) {
                                            if let n = pl.number {
                                                Text(verbatim: String(n)).font(.dorr(10.5, .black)).foregroundStyle(.white)
                                                    .frame(width: 22, height: 22).background(color, in: Circle())
                                            }
                                        }
                                    Text(verbatim: pl.name ?? "").font(.dorr(12, .bold)).foregroundStyle(wa.ink).lineLimit(1).padding(.top, 6)
                                    if let age = pl.age { Text(verbatim: L("spx_age", age)).font(.dorr(10.5)).foregroundStyle(wa.soft) }
                                }
                                .frame(maxWidth: .infinity)
                                .padding(.vertical, 12).padding(.horizontal, 6)
                                .background(wa.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                            }
                            .buttonStyle(.plain)
                            .waRise(min(i / 3, 6))
                        }
                    }
                }
            } else {
                StateNote(state: squad.state)
            }
        } else {
            SkeletonRows(count: 6)
        }
    }
}

// MARK: - Statistics

/// A big number with its label, on a soft tile.
struct StatTile: View {
    @Environment(\.wa) private var wa
    let value: String
    let label: String
    var accent: Color?
    var emoji: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            if let emoji { Text(verbatim: emoji).font(.system(size: 16)) }
            Text(verbatim: value).font(.dorr(22, .black)).foregroundStyle(accent ?? wa.ink).lineLimit(1)
            Text(verbatim: label).font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(2)
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

/// Tiles three to a row.
struct StatGrid: View {
    let tiles: [(String, String, String?)]
    var columns = 3
    var accent: Color?

    var body: some View {
        LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 8), count: columns), spacing: 8) {
            ForEach(Array(tiles.enumerated()), id: \.offset) { i, t in StatTile(value: t.0, label: t.1, accent: accent, emoji: t.2).waRise(i / columns) }
        }
        .padding(.bottom, 8)
    }
}

/// Columns by 15-minute slice (goals, cards…), rising in one after another.
struct MinuteChart: View {
    @Environment(\.wa) private var wa
    let items: [SpMinuteDto]
    let color: Color
    @State private var grow: CGFloat = 0

    var body: some View {
        let shown = items.filter { !(($0.range ?? "").hasPrefix("106") && ($0.total ?? 0) == 0) && !(($0.range ?? "").hasPrefix("91") && ($0.total ?? 0) == 0) }
        let top = max(shown.map { $0.total ?? 0 }.max() ?? 1, 1)
        if !shown.isEmpty {
            HStack(alignment: .bottom, spacing: 6) {
                ForEach(Array(shown.enumerated()), id: \.offset) { _, m in
                    VStack(spacing: 0) {
                        Spacer(minLength: 0)
                        Text(verbatim: String(m.total ?? 0)).font(.dorr(11, .black)).foregroundStyle(wa.ink)
                        UnevenRoundedRectangleCompat(topRadius: 8)
                            .fill(LinearGradient(colors: [color, color.opacity(0.55)], startPoint: .top, endPoint: .bottom))
                            .frame(height: max(86 * CGFloat(m.total ?? 0) / CGFloat(top) * grow, 3))
                            .padding(.top, 3)
                        Text(verbatim: (m.range ?? "").replacingOccurrences(of: "-", with: "–")).font(.dorr(9)).foregroundStyle(wa.soft).lineLimit(1).padding(.top, 4)
                    }
                    .frame(maxWidth: .infinity)
                }
            }
            .frame(height: 130)
            .environment(\.layoutDirection, .leftToRight)
            .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.65)) { grow = 1 } }
        }
    }
}

private struct TeamStats: View {
    @Environment(\.wa) private var wa
    let stats: SpTeamStatsDto?
    let loaded: Bool
    let color: Color
    let onCompetition: (Int) -> Void

    var body: some View {
        if let stats {
            if let comps = stats.competitions, comps.count > 1 {
                ChoiceChips(options: comps.map { (String($0.id), $0.name ?? "") }, selected: stats.competition.map { String($0.id) }) { if let n = Int($0) { onCompetition(n) } }
                Spacer().frame(height: 10)
            }
            if let d = stats.data {
                content(d, stats.fetchedAt)
            } else {
                StateNote(state: stats.state)
            }
        } else if loaded {
            StateNote(state: "pending")
        } else {
            SkeletonRows(count: 6)
        }
    }

    @ViewBuilder private func content(_ d: SpTeamStatsDataDto, _ fetchedAt: String?) -> some View {
        let fx = d.fixtures
        FormStrip(form: d.form, size: 20, last: 10).padding(.bottom, 10)
        StatGrid(tiles: [
            (String(fx?.int("played.total") ?? 0), L("spx_played"), "🏟️"),
            (String(fx?.int("wins.total") ?? 0), L("spx_wins"), "✅"),
            (String(fx?.int("draws.total") ?? 0), L("spx_draws"), "🤝"),
            (String(fx?.int("loses.total") ?? 0), L("spx_losses"), "❌"),
            (String(d.cleanSheet?.int("total") ?? 0), L("spx_clean_sheets"), "🧤"),
            (String(d.failedToScore?.int("total") ?? 0), L("spx_failed_to_score"), "🚫"),
        ])
        let gf = d.goals?["for"], ga = d.goals?["against"]
        SectionCard(title: L("spx_goals")) {
            HStack(spacing: 8) {
                StatTile(value: String(gf?.total?.int("total") ?? 0), label: L("spx_scored") + " · " + (gf?.average?.str("total") ?? "-"), accent: spGreen)
                StatTile(value: String(ga?.total?.int("total") ?? 0), label: L("spx_conceded") + " · " + (ga?.average?.str("total") ?? "-"), accent: spLive)
            }
            if let minutes = gf?.minute, !minutes.isEmpty {
                Text(L("spx_when_scored")).font(.dorr(12, .bold)).foregroundStyle(wa.mut).padding(.top, 14).padding(.bottom, 6)
                MinuteChart(items: minutes, color: spGreen)
            }
            if let minutes = ga?.minute, !minutes.isEmpty {
                Text(L("spx_when_conceded")).font(.dorr(12, .bold)).foregroundStyle(wa.mut).padding(.top, 14).padding(.bottom, 6)
                MinuteChart(items: minutes, color: spLive)
            }
        }
        // At home and away.
        SectionCard(title: L("spx_home_away")) {
            ForEach(Array([("wins", "spx_wins"), ("draws", "spx_draws"), ("loses", "spx_losses")].enumerated()), id: \.offset) { i, item in
                let h = fx?.int("\(item.0).home") ?? 0, a = fx?.int("\(item.0).away") ?? 0
                CompareRow(label: L(item.1), home: Double(h), away: Double(a), homeText: String(h), awayText: String(a), homeColor: color, awayColor: Color(hex: 0x64748B), index: i)
            }
            let sh = gf?.total?.int("home") ?? 0, sa = gf?.total?.int("away") ?? 0
            CompareRow(label: L("spx_scored"), home: Double(sh), away: Double(sa), homeText: String(sh), awayText: String(sa), homeColor: color, awayColor: Color(hex: 0x64748B), index: 3)
            HStack {
                Text(verbatim: "🏠 " + L("spx_at_home")).font(.dorr(11)).foregroundStyle(wa.soft)
                Spacer()
                Text(verbatim: L("spx_away") + " ✈️").font(.dorr(11)).foregroundStyle(wa.soft)
            }
            .padding(.top, 4)
        }
        // Records.
        let big = d.biggest
        SectionCard(title: L("spx_records")) {
            SpInfoLine(emoji: "🔥", label: L("spx_win_streak"), value: big?.int("streak.wins").map(String.init))
            SpInfoLine(emoji: "🏆", label: L("spx_biggest_win"), value: [big?.str("wins.home"), big?.str("wins.away")].compactMap { $0 }.joined(separator: " · "))
            SpInfoLine(emoji: "💔", label: L("spx_biggest_loss"), value: [big?.str("loses.home"), big?.str("loses.away")].compactMap { $0 }.joined(separator: " · "))
            SpInfoLine(emoji: "🎯", label: L("spx_penalties"), value: d.penalty.map { "\($0.int("scored.total") ?? 0) / \($0.int("total") ?? 0)" })
        }
        if let lineups = d.lineups, !lineups.isEmpty {
            let top = max(lineups.map { $0.played ?? 0 }.max() ?? 1, 1)
            SectionCard(title: L("spx_formations")) {
                ForEach(Array(lineups.prefix(5).enumerated()), id: \.offset) { _, f in
                    HStack(spacing: 0) {
                        Text(verbatim: f.formation ?? "").font(.dorr(13, .heavy)).foregroundStyle(wa.ink).frame(width: 70, alignment: .leading)
                        GeometryReader { p in
                            ZStack(alignment: .leading) {
                                Capsule().fill(wa.field)
                                Capsule().fill(color).frame(width: p.size.width * CGFloat(f.played ?? 0) / CGFloat(top))
                            }
                        }
                        .frame(height: 10)
                        Text(verbatim: String(f.played ?? 0)).font(.dorr(12, .bold)).foregroundStyle(wa.mut).frame(width: 32, alignment: .trailing)
                    }
                    .padding(.vertical, 4)
                }
            }
        }
        let yellow = d.cards?["yellow"] ?? [], red = d.cards?["red"] ?? []
        if yellow.contains(where: { ($0.total ?? 0) > 0 }) || red.contains(where: { ($0.total ?? 0) > 0 }) {
            SectionCard(title: L("spx_cards_by_minute")) {
                if yellow.contains(where: { ($0.total ?? 0) > 0 }) { MinuteChart(items: yellow, color: Color(hex: 0xF59E0B)) }
                if red.contains(where: { ($0.total ?? 0) > 0 }) { MinuteChart(items: red, color: spLive).padding(.top, 10) }
            }
        }
        if let at = fetchedAt {
            Text(verbatim: L("sp_updated_at", String(at.prefix(16)).replacingOccurrences(of: "T", with: " "))).font(.dorr(11)).foregroundStyle(wa.soft).padding(.top, 8)
        }
    }
}

// MARK: - Transfers

struct TransfersList: View {
    @Environment(\.wa) private var wa
    let transfers: SpPart<[SpTransferDto]>?
    let go: (SpPage) -> Void

    var body: some View {
        if let transfers {
            if let list = transfers.data, !list.isEmpty {
                ForEach(Array(list.enumerated()), id: \.offset) { i, t in
                    WaCard(padding: 12) {
                        Button { if let pid = t.player?.id { go(.player(pid)) } } label: {
                            HStack(spacing: 10) {
                                PhotoAvatar(url: t.player?.photo, name: t.player?.name, size: 42)
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(verbatim: t.player?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                                    Text(verbatim: [t.date.map { spShortDate($0) + " " + String($0.prefix(4)) },
                                                    (t.type ?? "").isEmpty || t.type == "N/A" ? nil : t.type].compactMap { $0 }.joined(separator: " · "))
                                        .font(.dorr(11.5)).foregroundStyle(wa.mut)
                                }
                                Spacer(minLength: 0)
                                if let dir = t.direction {
                                    Text(L(dir == "in" ? "spx_in" : "spx_out")).font(.dorr(11, .black)).foregroundStyle(.white)
                                        .padding(.horizontal, 10).padding(.vertical, 4)
                                        .background(dir == "in" ? spGreen : spLive, in: Capsule())
                                }
                            }
                        }
                        .buttonStyle(.plain)
                        HStack(spacing: 8) {
                            club(t.from)
                            Text(verbatim: "➜").font(.system(size: 18)).foregroundStyle(wa.soft).flipsForRightToLeftLayoutDirection(true)
                            club(t.to)
                        }
                        .padding(.top, 10)
                    }
                    .padding(.bottom, 8)
                    .waRise(min(i, 8))
                }
            } else {
                StateNote(state: transfers.state)
            }
        } else {
            SkeletonRows(count: 6)
        }
    }

    private func club(_ team: SpTeamDto?) -> some View {
        Button { if let id = team?.id, id > 0 { go(.team(id)) } } label: {
            HStack(spacing: 6) {
                Crest(team: team, size: 24)
                Text(verbatim: team?.name ?? "").font(.dorr(12, .semibold)).foregroundStyle(wa.ink).lineLimit(1)
                Spacer(minLength: 0)
            }
            .padding(8)
            .background(wa.field, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
        .frame(maxWidth: .infinity)
    }
}
