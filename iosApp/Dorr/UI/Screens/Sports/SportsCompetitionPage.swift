import SwiftUI

// androidApp `sports/SportsCompetitionPage.kt` — a competition, complete: overview, the table (all · home · away,
// form, zones), every round's matches, the leaders (goals, assists, cards) and the teams.

/// "2025" → "2025/26" for seasons that cross the new year (most leagues).
func spSeasonLabel(_ season: String) -> String {
    guard let n = Int(season) else { return season }
    return "\(n)/" + String(format: "%02d", (n + 1) % 100)
}

/// "Regular Season - 8" → "الجولة 8" (the provider writes rounds in English).
func spRoundLabel(_ round: String) -> String {
    if round.hasPrefix("Regular Season"), let r = round.range(of: #"(\d+)\s*$"#, options: .regularExpression) {
        return L("spx_round_n", String(round[r]).trimmingCharacters(in: .whitespaces))
    }
    return round
}

@MainActor
struct CompetitionPage: View {
    @Environment(\.wa) private var wa
    let id: Int
    let onBack: () -> Void
    let go: (SpPage) -> Void
    @State private var page: SpCompetitionPageDto?
    @State private var rounds: SpRoundsDto?
    @State private var leaders: [String: SpLeadersDto] = [:]
    @State private var tab = 0
    @State private var followed: Bool?

    var body: some View {
        let p = page
        let isFollowed = followed ?? (p?.following ?? false)
        WaPage(title: p?.name ?? L("sp_title"), onBack: onBack, actions: {
            Button {
                followed = !isFollowed
                Task { _ = await spToggleFollow("competition", id, !isFollowed) }
            } label: {
                Text(verbatim: isFollowed ? "★" : "☆").font(.system(size: 26)).foregroundStyle(isFollowed ? Color(hex: 0xF59E0B) : wa.mut).padding(8)
            }
            .buttonStyle(.plain)
        }) {
            if let p {
                let football = p.sport == nil || p.sport == "football"
                let tabs = football ? ["spx_tab_overview", "sp_tab_table", "sp_tab_matches", "spx_tab_stats", "sp_tab_teams"] : ["sp_tab_table", "sp_tab_matches", "sp_tab_teams"]
                let current = tabs[min(tab, tabs.count - 1)]
                CompetitionHeader(p: p, round: rounds?.current)
                Spacer().frame(height: 14)
                ScrollTabs(labels: tabs.map { L($0) }, selected: min(tab, tabs.count - 1)) { tab = $0 }
                Spacer().frame(height: 12)
                SpTicker { now in
                    VStack(alignment: .leading, spacing: 0) {
                        switch current {
                        case "spx_tab_overview": CompOverview(p: p, rounds: rounds, leaders: leaders, now: now, go: go) { key in tab = tabs.firstIndex(of: key) ?? tab }
                        case "sp_tab_table": CompTable(p: p, go: go)
                        case "sp_tab_matches": CompRounds(id: id, p: p, rounds: $rounds, now: now, go: go)
                        case "spx_tab_stats": CompLeaders(leaders: leaders, load: loadLeaders, go: go)
                        default: CompTeams(p: p, go: go)
                        }
                    }
                    .transition(.opacity)
                    .id(current)
                }
                .task(id: current) {
                    if current == "spx_tab_overview" { loadLeaders("goals"); loadLeaders("assists") }
                    if current == "spx_tab_stats" { loadLeaders("goals") }
                }
                Spacer().frame(height: 24)
            } else {
                WaSkeleton(cornerRadius: 26).frame(height: 150)
                SkeletonRows().padding(.top, 12)
            }
        }
        .task(id: id) {
            page = try? await SportsApi.competition(id, timezone: spZone()).data
            rounds = try? await SportsApi.rounds(id, timezone: spZone()).data
        }
    }

    private func loadLeaders(_ type: String) {
        guard leaders[type] == nil else { return }
        Task { if let l = try? await SportsApi.leaders(id, type: type).data { leaders[type] = l } }
    }
}

private struct CompetitionHeader: View {
    let p: SpCompetitionPageDto
    let round: String?

    var body: some View {
        ZStack(alignment: .trailing) {
            LinearGradient(colors: [Color(hex: 0x0B1220), Color(hex: 0x1E3A8A), Color(hex: 0x0F766E)], startPoint: .topLeading, endPoint: .bottomTrailing)
            // A big faint logo behind, like a watermark.
            if let url = ApiClient.mediaUrl(p.logo) {
                AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFit() }.frame(width: 170, height: 170).padding(8).opacity(0.10).offset(x: 40)
            }
            HStack(spacing: 14) {
                ZStack {
                    if let url = ApiClient.mediaUrl(p.logo) {
                        AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFit() }.frame(width: 52, height: 52)
                    } else {
                        Text(verbatim: "🏆").font(.system(size: 30))
                    }
                }
                .frame(width: 68, height: 68)
                .background(Color.white, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: p.name ?? "").font(.dorr(20, .heavy)).foregroundStyle(.white).lineLimit(2)
                    HStack(spacing: 6) {
                        if let url = ApiClient.mediaUrl(p.flag) {
                            AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() }.frame(width: 18, height: 13).clipShape(RoundedRectangle(cornerRadius: 2))
                        }
                        Text(verbatim: [p.country, p.season.map(spSeasonLabel)].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.8))
                    }
                    .padding(.top, 4)
                    if let round {
                        Text(verbatim: spRoundLabel(round)).font(.dorr(11.5, .bold)).foregroundStyle(.white)
                            .padding(.horizontal, 10).padding(.vertical, 4)
                            .background(Color.white.opacity(0.16), in: Capsule())
                            .padding(.top, 8)
                    }
                }
                Spacer(minLength: 0)
            }
            .padding(18)
        }
        .clipShape(RoundedRectangle(cornerRadius: 26, style: .continuous))
        .waRise(0)
    }
}

private struct LinkText: View {
    @Environment(\.wa) private var wa
    let text: String
    let action: () -> Void
    var body: some View {
        Button(action: action) { Text(verbatim: text).font(.dorr(12.5, .bold)).foregroundStyle(wa.red) }.buttonStyle(.plain)
    }
}

private struct CompOverview: View {
    let p: SpCompetitionPageDto
    let rounds: SpRoundsDto?
    let leaders: [String: SpLeadersDto]
    let now: Date
    let go: (SpPage) -> Void
    let open: (String) -> Void

    var body: some View {
        let live = p.live ?? []
        if !live.isEmpty {
            WaSectionTitle(title: L("sp_live"), topPadding: 0)
            ForEach(live) { m in LiveCard(m: m, now: now) { go(.match(m.id)) }.padding(.bottom, 8) }
        }
        let roundMatches = (rounds?.matches ?? []).isEmpty ? Array((p.next ?? []).prefix(6)) : rounds!.matches!
        if !roundMatches.isEmpty {
            WaSectionTitle(title: rounds?.round.map(spRoundLabel) ?? L("sp_next"), topPadding: live.isEmpty ? 0 : 22) {
                LinkText(text: L("spx_all")) { open("sp_tab_matches") }
            }
            ForEach(roundMatches) { m in MatchDayRow(m: m, now: now) { go(.match(m.id)) } }
        }
        let table = p.standings?.first?.rows ?? []
        if !table.isEmpty {
            WaSectionTitle(title: L("sp_tab_table")) { LinkText(text: L("spx_full_table")) { open("sp_tab_table") } }
            StandingsTable(rows: Array(table.prefix(6))) { t in if t.id > 0 { go(.team(t.id)) } }
        }
        let best: [(SpLeaderDto, String, String)] = [
            leaders["goals"]?.rows?.first.map { ($0, L("spx_top_scorer"), "⚽") },
            leaders["assists"]?.rows?.first.map { ($0, L("spx_top_assist"), "🎯") },
        ].compactMap { $0 }
        if !best.isEmpty {
            WaSectionTitle(title: L("spx_stars"))
            HStack(spacing: 10) {
                ForEach(Array(best.enumerated()), id: \.offset) { i, item in StarCard(l: item.0, label: item.1, emoji: item.2, go: go).waRise(i) }
            }
        }
        if live.isEmpty && roundMatches.isEmpty && table.isEmpty { StateNote(state: p.standingsState, empty: "sp_no_comp_matches") }
    }
}

private struct StarCard: View {
    let l: SpLeaderDto
    let label: String
    let emoji: String
    let go: (SpPage) -> Void

    var body: some View {
        Button { if let id = l.player?.id { go(.player(id)) } } label: {
            VStack(spacing: 0) {
                Text(verbatim: "\(emoji)  \(label)").font(.dorr(11.5, .bold)).foregroundStyle(.white.opacity(0.75))
                PhotoAvatar(url: l.player?.photo, name: l.player?.name, size: 64, ring: Color(hex: 0xFACC15)).padding(.vertical, 10)
                Text(verbatim: l.player?.name ?? "").font(.dorr(14, .heavy)).foregroundStyle(.white).lineLimit(1)
                Text(verbatim: l.team?.name ?? "").font(.dorr(11.5)).foregroundStyle(.white.opacity(0.65)).lineLimit(1)
                Text(verbatim: String(l.value ?? 0)).font(.dorr(28, .black)).foregroundStyle(Color(hex: 0xFACC15)).padding(.top, 4)
            }
            .frame(maxWidth: .infinity)
            .padding(14)
            .background(LinearGradient(colors: [Color(hex: 0x1E293B), Color(hex: 0x0B1220)], startPoint: .top, endPoint: .bottom),
                        in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

// MARK: - The table

private struct CompTable: View {
    @Environment(\.wa) private var wa
    let p: SpCompetitionPageDto
    let go: (SpPage) -> Void
    @State private var mode = "all"

    var body: some View {
        let standings = p.standings ?? []
        if standings.isEmpty {
            StateNote(state: p.standingsState, empty: "sp_no_table")
        } else {
            ChoiceChips(options: [("all", L("spx_all_games")), ("home", L("spx_at_home")), ("away", L("spx_away")), ("form", L("spx_form"))], selected: mode) { mode = $0 }
            ForEach(Array(standings.enumerated()), id: \.offset) { _, g in
                if standings.count > 1, let name = g.group, !name.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: name).font(.dorr(14, .heavy)).foregroundStyle(wa.ink).padding(.top, 14).padding(.bottom, 6)
                } else {
                    Spacer().frame(height: 10)
                }
                FullTable(rows: splitRows(g.rows ?? [], mode), mode: mode) { t in if t.id > 0 { go(.team(t.id)) } }
            }
            // What the colours mean (the provider's own words).
            let zones = standings.flatMap { $0.rows ?? [] }.compactMap { $0.description?.trimmingCharacters(in: .whitespaces) }.filter { !$0.isEmpty }
            let unique = zones.reduce(into: [String]()) { if !$0.contains($1) { $0.append($1) } }
            if !unique.isEmpty {
                VStack(alignment: .leading, spacing: 6) {
                    ForEach(unique, id: \.self) { d in
                        HStack(spacing: 8) {
                            Circle().fill(spZoneColor(d) ?? wa.soft).frame(width: 10, height: 10)
                            Text(verbatim: d).font(.dorr(12)).foregroundStyle(wa.mut)
                        }
                    }
                }
                .padding(12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                .padding(.top, 12)
            }
            if let at = p.standingsUpdatedAt {
                Text(verbatim: L("sp_updated_at", String(at.prefix(16)).replacingOccurrences(of: "T", with: " "))).font(.dorr(11)).foregroundStyle(wa.soft).padding(.top, 8)
            }
        }
    }

    /// Home or away only: the same rows, re-ranked from the provider's split.
    private func splitRows(_ rows: [SpStandingRowDto], _ mode: String) -> [SpStandingRowDto] {
        guard mode == "home" || mode == "away" else { return rows }
        let split: [SpStandingRowDto] = rows.compactMap { r in
            guard let s = mode == "home" ? r.home : r.away else { return nil }
            var c = r
            c.played = s.played; c.win = s.win; c.draw = s.draw; c.lose = s.lose; c.goalsFor = s.goalsFor; c.goalsAgainst = s.goalsAgainst
            c.goalDiff = (s.goalsFor ?? 0) - (s.goalsAgainst ?? 0)
            c.points = (s.win ?? 0) * 3 + (s.draw ?? 0)
            c.trend = nil
            return c
        }
        let sorted = split.sorted { a, b in
            if (a.points ?? 0) != (b.points ?? 0) { return (a.points ?? 0) > (b.points ?? 0) }
            if (a.goalDiff ?? 0) != (b.goalDiff ?? 0) { return (a.goalDiff ?? 0) > (b.goalDiff ?? 0) }
            return (a.goalsFor ?? 0) > (b.goalsFor ?? 0)
        }
        return sorted.enumerated().map { i, r in var c = r; c.rank = i + 1; return c }
    }
}

private struct FullTable: View {
    @Environment(\.wa) private var wa
    let rows: [SpStandingRowDto]
    let mode: String
    let onTeam: (SpTeamDto) -> Void

    var body: some View {
        let cols = mode == "form" ? ["sp_col_pts"] : ["sp_col_p", "spx_col_w", "spx_col_d", "spx_col_l", "sp_col_gd", "sp_col_pts"]
        VStack(spacing: 0) {
            HStack(spacing: 0) {
                Text(verbatim: "#").frame(width: 28, alignment: .leading)
                Text(L("sp_team")).frame(maxWidth: .infinity, alignment: .leading)
                if mode == "form" { Text(L("spx_form")).frame(width: 118) }
                ForEach(cols, id: \.self) { Text(L($0)).frame(width: 27) }
            }
            .font(.dorr(11, .bold)).foregroundStyle(wa.soft)
            .padding(.horizontal, 6).padding(.vertical, 6)
            ForEach(Array(rows.enumerated()), id: \.offset) { i, r in
                Button { if let t = r.team { onTeam(t) } } label: {
                    HStack(spacing: 0) {
                        HStack(spacing: 4) {
                            Capsule().fill(spZoneColor(r.description) ?? .clear).frame(width: 3, height: 18)
                            Text(verbatim: String(r.rank ?? 0)).font(.dorr(12.5, .bold)).foregroundStyle(wa.ink)
                        }
                        .frame(width: 28, alignment: .leading)
                        Crest(team: r.team, size: 22)
                        Text(verbatim: r.team?.code ?? r.team?.name ?? "").font(.dorr(12.5, .semibold)).foregroundStyle(wa.ink).lineLimit(1).padding(.leading, 6)
                        Spacer(minLength: 2)
                        if mode == "form" {
                            FormStrip(form: r.form, size: 19).frame(width: 118)
                            Text(verbatim: String(r.points ?? 0)).font(.dorr(13.5, .black)).foregroundStyle(wa.ink).frame(width: 27)
                        } else {
                            ForEach(Array([r.played, r.win, r.draw, r.lose].enumerated()), id: \.offset) { _, v in
                                Text(verbatim: String(v ?? 0)).font(.dorr(12.5)).foregroundStyle(wa.mut).frame(width: 27)
                            }
                            Text(verbatim: ((r.goalDiff ?? 0) > 0 ? "+" : "") + String(r.goalDiff ?? 0)).font(.dorr(12.5)).foregroundStyle(wa.mut).frame(width: 27)
                            Text(verbatim: String(r.points ?? 0)).font(.dorr(13.5, .black)).foregroundStyle(wa.ink).frame(width: 27)
                        }
                    }
                    .padding(.horizontal, 6)
                    .padding(.vertical, 7)
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .waRise(min(i / 2, 8))
            }
        }
        .padding(6)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
    }
}

// MARK: - Rounds

@MainActor
private struct CompRounds: View {
    @Environment(\.wa) private var wa
    let id: Int
    let p: SpCompetitionPageDto
    @Binding var rounds: SpRoundsDto?
    let now: Date
    let go: (SpPage) -> Void
    @State private var loading = false

    var body: some View {
        let list = rounds?.rounds ?? []
        if list.isEmpty {
            // No rounds from the provider: the matches we know.
            let next = p.next ?? [], last = p.last ?? []
            if !next.isEmpty {
                WaSectionTitle(title: L("sp_next"), topPadding: 0)
                ForEach(next) { m in MatchDayRow(m: m, now: now) { go(.match(m.id)) } }
            }
            if !last.isEmpty {
                WaSectionTitle(title: L("sp_last"))
                ForEach(last) { m in MatchDayRow(m: m, now: now) { go(.match(m.id)) } }
            }
            if next.isEmpty && last.isEmpty { StateNote(state: nil, empty: "sp_no_comp_matches") }
        } else {
            let picked = rounds?.round
            ScrollViewReader { proxy in
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 8) {
                        ForEach(Array(list.enumerated()), id: \.offset) { i, r in
                            let on = r.name == picked
                            let isCurrent = r.name == rounds?.current
                            Button { pick(r.name, on: on) } label: {
                                VStack(spacing: 0) {
                                    Text(verbatim: spRoundLabel(r.name ?? "")).font(.dorr(13, .bold)).foregroundStyle(on ? .white : wa.ink).lineLimit(1)
                                    if let d = r.dates?.first { Text(verbatim: spShortDate(d)).font(.dorr(10.5)).foregroundStyle(on ? .white.opacity(0.8) : wa.soft) }
                                }
                                .padding(.horizontal, 14).padding(.vertical, 8)
                                .background(on ? wa.red : wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                                .overlay { if isCurrent && !on { RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(wa.red, lineWidth: 1.5) } }
                            }
                            .buttonStyle(.plain)
                            .id(i)
                        }
                    }
                }
                .onAppear { if let i = list.firstIndex(where: { $0.name == picked }) { proxy.scrollTo(max(0, i - 1), anchor: .leading) } }
            }
            Spacer().frame(height: 12)
            if loading {
                SkeletonRows(count: 4)
            } else if (rounds?.matches ?? []).isEmpty {
                StateNote(state: nil, empty: "sp_no_comp_matches")
            } else {
                ForEach(rounds?.matches ?? []) { m in MatchDayRow(m: m, now: now) { go(.match(m.id)) } }
            }
        }
    }

    private func pick(_ name: String?, on: Bool) {
        guard !on, !loading else { return }
        loading = true
        Task {
            if let r = try? await SportsApi.rounds(id, timezone: spZone(), round: name).data { rounds = r }
            loading = false
        }
    }
}

// MARK: - Leaders

private struct CompLeaders: View {
    @Environment(\.wa) private var wa
    let leaders: [String: SpLeadersDto]
    let load: (String) -> Void
    let go: (SpPage) -> Void
    @State private var kind = "goals"

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            ChoiceChips(options: [("goals", L("spx_goals")), ("assists", L("spx_assists")), ("yellow", L("spx_yellow")), ("red", L("spx_red"))], selected: kind) {
                kind = $0
                load($0)
            }
            Spacer().frame(height: 12)
            if let l = leaders[kind] {
                let rows = l.rows ?? []
                if rows.isEmpty {
                    StateNote(state: l.state, empty: "sp_no_scorers")
                } else {
                    let emoji = kind == "assists" ? "🎯" : (kind == "yellow" ? "🟨" : (kind == "red" ? "🟥" : "⚽"))
                    Podium(top: Array(rows.prefix(3)), emoji: emoji, go: go)
                    let top = max(rows.first?.value ?? 1, 1)
                    ForEach(Array(rows.dropFirst(3).enumerated()), id: \.offset) { i, r in
                        Button { if let id = r.player?.id { go(.player(id)) } } label: {
                            HStack(spacing: 0) {
                                Text(verbatim: String(r.rank ?? 0)).font(.dorr(14, .black)).foregroundStyle(wa.mut).frame(width: 26)
                                PhotoAvatar(url: r.player?.photo, name: r.player?.name, size: 38)
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(verbatim: r.player?.name ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                                    HStack(spacing: 4) {
                                        Crest(team: r.team, size: 16)
                                        Text(verbatim: r.team?.name ?? "").font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(1)
                                    }
                                    // How close to the leader.
                                    GeometryReader { p in
                                        ZStack(alignment: .leading) {
                                            Capsule().fill(wa.field)
                                            Capsule().fill(wa.red).frame(width: p.size.width * CGFloat(r.value ?? 0) / CGFloat(top))
                                        }
                                    }
                                    .frame(height: 4)
                                    .padding(.top, 5)
                                }
                                .padding(.horizontal, 10)
                                Text(verbatim: String(r.value ?? 0)).font(.dorr(19, .black)).foregroundStyle(wa.ink)
                            }
                            .padding(10)
                            .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        }
                        .buttonStyle(.plain)
                        .padding(.bottom, 6)
                        .waRise(min(i, 8))
                    }
                    if let at = l.fetchedAt {
                        Text(verbatim: L("sp_updated_at", String(at.prefix(16)).replacingOccurrences(of: "T", with: " "))).font(.dorr(11)).foregroundStyle(wa.soft).padding(.top, 6)
                    }
                }
            } else {
                SkeletonRows(count: 5)
            }
        }
        .onAppear { load(kind) }
    }
}

/// The top three on steps: the first in the middle, higher.
private struct Podium: View {
    let top: [SpLeaderDto]
    let emoji: String
    let go: (SpPage) -> Void

    var body: some View {
        let order = [top.count > 1 ? top[1] : nil, top.first, top.count > 2 ? top[2] : nil].compactMap { $0 }
        HStack(alignment: .bottom, spacing: 0) {
            ForEach(Array(order.enumerated()), id: \.offset) { _, r in
                let first = r.rank == 1
                let medal = r.rank == 1 ? Color(hex: 0xFACC15) : (r.rank == 2 ? Color(hex: 0xCBD5E1) : Color(hex: 0xD97706))
                Button { if let id = r.player?.id { go(.player(id)) } } label: {
                    VStack(spacing: 0) {
                        PhotoAvatar(url: r.player?.photo, name: r.player?.name, size: first ? 72 : 56, ring: medal)
                        Text(verbatim: r.player?.name ?? "").font(.dorr(12.5, .heavy)).foregroundStyle(.white).lineLimit(1).padding(.top, 6)
                        Text(verbatim: r.team?.name ?? "").font(.dorr(10.5)).foregroundStyle(.white.opacity(0.6)).lineLimit(1)
                        VStack(spacing: 0) {
                            Text(verbatim: String(r.value ?? 0)).font(.dorr(first ? 24 : 19, .black)).foregroundStyle(medal)
                            Text(verbatim: emoji).font(.system(size: 11))
                        }
                        .frame(maxWidth: .infinity)
                        .frame(height: first ? 62 : 44)
                        .background(medal.opacity(0.22), in: UnevenRoundedRectangleCompat(topRadius: 12))
                        .padding(.horizontal, 10)
                        .padding(.top, 8)
                    }
                    .frame(maxWidth: .infinity)
                }
                .buttonStyle(.plain)
                .waRise(r.rank ?? 0)
            }
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 14)
        .background(LinearGradient(colors: [Color(hex: 0x0B1220), Color(hex: 0x1E3A8A)], startPoint: .top, endPoint: .bottom),
                    in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        .padding(.bottom, 12)
    }
}

// MARK: - Teams

@MainActor
private struct CompTeams: View {
    @Environment(\.wa) private var wa
    let p: SpCompetitionPageDto
    let go: (SpPage) -> Void
    @State private var mine: Set<Int>?

    var body: some View {
        let teams = p.teams ?? []
        let followed = mine ?? Set(teams.filter { $0.following == true }.map(\.id))
        if teams.isEmpty {
            StateNote(state: nil, empty: "sp_no_teams")
        } else {
            LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 8), count: 3), spacing: 8) {
                ForEach(teams) { t in
                    let on = followed.contains(t.id)
                    VStack(spacing: 0) {
                        Button { go(.team(t.id)) } label: {
                            VStack(spacing: 0) {
                                Crest(team: SpTeamDto(id: t.id, name: t.name, logo: t.logo, color: t.color), size: 50)
                                Text(verbatim: t.name ?? "").font(.dorr(12, .bold)).foregroundStyle(wa.ink).multilineTextAlignment(.center).lineLimit(2)
                                    .frame(height: 32).padding(.top, 8)
                            }
                        }
                        .buttonStyle(.plain)
                        Button {
                            var next = followed
                            if on { next.remove(t.id) } else { next.insert(t.id) }
                            mine = next
                            Task { _ = await spToggleFollow("team", t.id, !on) }
                        } label: {
                            Text(verbatim: on ? "★" : "☆").font(.system(size: 18)).foregroundStyle(on ? Color(hex: 0xF59E0B) : wa.soft).padding(.horizontal, 10)
                        }
                        .buttonStyle(.plain)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 14)
                    .padding(.horizontal, 6)
                    .background(wa.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                }
            }
        }
    }
}
