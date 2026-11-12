import SwiftUI

// androidApp `sports/SportsPeople.kt` + `SportsSearch.kt` — a player and a coach (who they are, the season's numbers
// by competition, the clubs, trophies, transfers, injuries) and one search for everything.

private let playerTabs = ["spx_tab_stats", "spx_tab_career", "spx_tab_trophies", "spx_tab_transfers", "spx_tab_injuries"]

@MainActor
struct PlayerPage: View {
    @Environment(\.wa) private var wa
    let id: Int
    let onBack: () -> Void
    let go: (SpPage) -> Void
    @State private var player: SpPlayerDto?
    @State private var failed: String?
    @State private var career: SpCareerDto?
    @State private var season: String?
    @State private var tab = 0

    var body: some View {
        WaPage(title: player?.name ?? L("spx_player"), onBack: onBack) {
            if let p = player {
                let color = spTeamColor(p.team?.color, p.team?.id ?? (p.id ?? id))
                PersonHeader(photo: p.photo, name: p.name, sub: [p.firstname.map { [$0, p.lastname].compactMap { $0 }.joined(separator: " ") }, p.nationality].compactMap { $0 }.joined(separator: " · "),
                             color: color, number: p.number) {
                    if let t = p.team { TeamPill(team: t) { if t.id > 0 { go(.team(t.id)) } } }
                }
                // The quick facts.
                let facts: [(String, String)] = [
                    p.age.map { (L("spx_age_short"), String($0)) },
                    p.position.map { (L("spx_position"), positionLabel($0)) },
                    p.height.map { (L("spx_height"), $0.replacingOccurrences(of: " cm", with: "")) },
                    p.weight.map { (L("spx_weight"), $0.replacingOccurrences(of: " kg", with: "")) },
                ].compactMap { $0 }
                HStack(spacing: 8) {
                    ForEach(Array(facts.enumerated()), id: \.offset) { i, f in
                        VStack(spacing: 0) {
                            Text(verbatim: f.1).font(.dorr(15, .black)).foregroundStyle(wa.ink).lineLimit(1)
                            Text(verbatim: f.0).font(.dorr(10.5)).foregroundStyle(wa.soft)
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 10)
                        .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        .waRise(i)
                    }
                }
                .padding(.top, 12)
                if p.injured == true {
                    Text(verbatim: "🤕  " + L("spx_injured_now")).font(.dorr(13, .bold)).foregroundStyle(spLive).padding(.top, 10)
                }
                Spacer().frame(height: 14)
                ScrollTabs(labels: playerTabs.map { L($0) }, selected: tab) { tab = $0 }
                Spacer().frame(height: 12)
                Group {
                    switch playerTabs[tab] {
                    case "spx_tab_stats":
                        let shown = p.season ?? String(Calendar.current.component(.year, from: Date()))
                        let year = Int(shown) ?? Calendar.current.component(.year, from: Date())
                        let seasons = (0...3).map { String(year + 1 - $0) }
                        ChoiceChips(options: seasons.reduce(into: [String]()) { if !$0.contains($1) { $0.append($1) } }.map { ($0, spSeasonLabel($0)) }, selected: shown) { season = $0 }
                        Spacer().frame(height: 10)
                        if (p.seasons ?? []).isEmpty {
                            StateNote(state: p.statsState)
                        } else {
                            ForEach(Array((p.seasons ?? []).enumerated()), id: \.offset) { i, s in SeasonCard(s: s, color: color, go: go).waRise(min(i, 6)) }
                        }
                    case "spx_tab_career": CareerTeams(teams: career?.teams, loaded: career != nil, go: go)
                    case "spx_tab_trophies": Trophies(trophies: career?.trophies, loaded: career != nil)
                    case "spx_tab_transfers":
                        if career == nil { SkeletonRows(count: 6) } else { TransfersList(transfers: career?.transfers ?? SpPart(state: "pending"), go: go) }
                    default: Sidelined(part: career?.sidelined, loaded: career != nil)
                    }
                }
                .task(id: tab) {
                    if playerTabs[tab] != "spx_tab_stats", career == nil {
                        career = (try? await SportsApi.playerCareer(id).data) ?? SpCareerDto()
                    }
                }
                Spacer().frame(height: 24)
            } else if let failed {
                StateNote(state: failed)
            } else {
                WaSkeleton(cornerRadius: 28).frame(height: 220)
                SkeletonRows().padding(.top, 12)
            }
        }
        .task(id: "\(id)|\(season ?? "")") {
            do {
                if let r = try await SportsApi.player(id, season: season).data { player = r }
            } catch {
                if player == nil { failed = error.apiFailure.httpStatus == 503 ? "pending" : "unavailable" }
            }
        }
    }

    private func positionLabel(_ p: String) -> String {
        switch p {
        case "Goalkeeper", "G": return L("spx_pos_gk")
        case "Defender", "D": return L("spx_pos_df")
        case "Midfielder", "M": return L("spx_pos_mf")
        case "Attacker", "F": return L("spx_pos_fw")
        default: return p
        }
    }
}

private struct TeamPill: View {
    let team: SpTeamDto
    let action: () -> Void
    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                Crest(team: team, size: 22, plate: .white)
                Text(verbatim: team.name ?? "").font(.dorr(12.5, .bold)).foregroundStyle(.white).lineLimit(1)
            }
            .padding(.horizontal, 10).padding(.vertical, 5)
            .background(Color.white.opacity(0.16), in: Capsule())
        }
        .buttonStyle(.plain)
    }
}

/// A big photo on a deep gradient, with a number behind it like a shirt.
private struct PersonHeader<Extra: View>: View {
    let photo: String?
    let name: String?
    let sub: String
    let color: Color
    let number: Int?
    @ViewBuilder let extra: () -> Extra

    var body: some View {
        ZStack(alignment: .trailing) {
            LinearGradient(colors: [color, color.scaled(0.3), Color(hex: 0x0B1220)], startPoint: .topLeading, endPoint: .bottomTrailing)
            if let number {
                Text(verbatim: String(number)).font(.dorr(150, .black)).foregroundStyle(.white.opacity(0.08)).padding(.trailing, 10)
            }
            VStack(spacing: 0) {
                PhotoAvatar(url: photo, name: name, size: 112, ring: .white)
                Text(verbatim: name ?? "").font(.dorr(22, .heavy)).foregroundStyle(.white).multilineTextAlignment(.center).padding(.top, 10)
                if !sub.trimmingCharacters(in: .whitespaces).isEmpty {
                    Text(verbatim: sub).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.78)).multilineTextAlignment(.center)
                }
                extra().padding(.top, 10)
            }
            .padding(20)
            .frame(maxWidth: .infinity)
        }
        .clipShape(RoundedRectangle(cornerRadius: 28, style: .continuous))
        .waRise(0)
    }
}

/// One competition in a season: the big numbers first, the rest under them.
private struct SeasonCard: View {
    @Environment(\.wa) private var wa
    let s: SpPlayerSeasonDto
    let color: Color
    let go: (SpPage) -> Void

    var body: some View {
        let keeper = (s.saves ?? 0) > 0 || ((s.conceded ?? 0) > 0 && (s.goals ?? 0) == 0)
        let decimal: (Int) -> String = { NumberFormatter.localizedString(from: NSNumber(value: $0), number: .decimal) }
        SectionCard(title: nil) {
            Button { if let tid = s.team?.id, tid > 0 { go(.team(tid)) } } label: {
                HStack(spacing: 10) {
                    Crest(team: s.team, size: 34)
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: s.competition?.name ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                        Text(verbatim: [s.team?.name, s.competition?.season.map(spSeasonLabel)].compactMap { $0 }.joined(separator: " · ")).font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(1)
                    }
                    Spacer(minLength: 0)
                    if let r = s.rating { RatingBadge(rating: r) }
                }
            }
            .buttonStyle(.plain)
            .padding(.bottom, 10)
            StatGrid(tiles: [
                (String(s.appearances ?? 0), L("spx_apps"), "👕"),
                keeper ? (String(s.saves ?? 0), L("spx_saves"), "🧤") : (String(s.goals ?? 0), L("spx_goals"), "⚽"),
                keeper ? (String(s.conceded ?? 0), L("spx_conceded"), "🥅") : (String(s.assists ?? 0), L("spx_assists"), "🎯"),
            ], accent: color)
            SpInfoLine(emoji: "⏱️", label: L("spx_minutes_played"), value: s.minutes.map(decimal))
            SpInfoLine(emoji: "🧑‍🤝‍🧑", label: L("spx_starts"), value: s.lineups.map(String.init))
            SpInfoLine(emoji: "🥅", label: L("spx_shots"), value: s.shots.map { "\($0)" + (s.shotsOn.map { " (\($0))" } ?? "") })
            SpInfoLine(emoji: "🅿️", label: L("spx_passes"), value: s.passes.map { decimal($0) + (s.keyPasses.map { " · \($0) 🔑" } ?? "") })
            SpInfoLine(emoji: "✨", label: L("spx_dribbles"), value: s.dribbles.map { "\($0)" + (s.dribblesTried.map { " / \($0)" } ?? "") })
            SpInfoLine(emoji: "🛡️", label: L("spx_tackles"), value: s.tackles.map { "\($0)" + (s.interceptions.map { " · \($0) ✂️" } ?? "") })
            SpInfoLine(emoji: "💪", label: L("spx_duels_won"), value: s.duelsWon.map { "\($0)" + (s.duels.map { " / \($0)" } ?? "") })
            SpInfoLine(emoji: "🎯", label: L("spx_penalties"), value: s.penaltiesScored.map { "\($0)" + ((s.penaltiesMissed ?? 0) > 0 ? " (−\(s.penaltiesMissed!))" : "") })
            SpInfoLine(emoji: "🟨", label: L("spx_cards"), value: [s.yellow.map { "🟨 \($0)" }, (s.red ?? 0) > 0 ? "🟥 \(s.red!)" : nil].compactMap { $0 }.joined(separator: "  "))
        }
    }
}

private struct CareerTeams: View {
    @Environment(\.wa) private var wa
    let teams: SpPart<[SpCareerTeamDto]>?
    let loaded: Bool
    let go: (SpPage) -> Void

    var body: some View {
        if !loaded {
            SkeletonRows(count: 5)
        } else if let list = teams?.data, !list.isEmpty {
            ForEach(Array(list.enumerated()), id: \.offset) { i, c in
                Button { if let tid = c.team?.id, tid > 0 { go(.team(tid)) } } label: {
                    HStack(spacing: 10) {
                        // A dot down the side, like a timeline.
                        Circle().fill(i == 0 ? wa.red : wa.line).frame(width: 10, height: 10)
                        Crest(team: c.team, size: 36)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: c.team?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                            let years = (c.seasons ?? []).sorted()
                            if let first = years.first { Text(verbatim: years.count == 1 ? String(first) : "\(first) – \(years.last!)").font(.dorr(12)).foregroundStyle(wa.mut) }
                        }
                        Spacer(minLength: 0)
                        Text(verbatim: L("spx_seasons_n", (c.seasons ?? []).count)).font(.dorr(11.5)).foregroundStyle(wa.soft)
                    }
                    .padding(12)
                    .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                }
                .buttonStyle(.plain)
                .padding(.bottom, 8)
                .waRise(min(i, 8))
            }
        } else {
            StateNote(state: teams?.state ?? "pending")
        }
    }
}

private struct Trophies: View {
    @Environment(\.wa) private var wa
    let trophies: SpPart<[SpTrophyDto]>?
    let loaded: Bool

    var body: some View {
        if !loaded {
            SkeletonRows(count: 5)
        } else if let list = trophies?.data, !list.isEmpty {
            let won = list.filter { $0.place == "Winner" }
            if !won.isEmpty {
                HStack(spacing: 12) {
                    Text(verbatim: "🏆").font(.system(size: 34))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: String(won.count)).font(.dorr(28, .black)).foregroundStyle(.white)
                        Text(L("spx_titles_won")).font(.dorr(12.5, .bold)).foregroundStyle(.white.opacity(0.9))
                    }
                    Spacer()
                }
                .padding(16)
                .background(LinearGradient(colors: [Color(hex: 0xB45309), Color(hex: 0xF59E0B)], startPoint: .topLeading, endPoint: .bottomTrailing),
                            in: RoundedRectangle(cornerRadius: 22, style: .continuous))
                .padding(.bottom, 10)
            }
            ForEach(Array(list.enumerated()), id: \.offset) { i, t in
                HStack(spacing: 10) {
                    Text(verbatim: t.place == "Winner" ? "🥇" : ((t.place ?? "").hasPrefix("2") ? "🥈" : "🎖️")).font(.system(size: 20))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: t.competition ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                        Text(verbatim: [t.country, t.season].compactMap { $0 }.joined(separator: " · ")).font(.dorr(11.5)).foregroundStyle(wa.mut)
                    }
                    Spacer(minLength: 0)
                    Text(verbatim: t.place == "Winner" ? L("spx_winner") : (t.place == "2nd Place" ? L("spx_runner_up") : (t.place ?? "")))
                        .font(.dorr(12, .bold)).foregroundStyle(t.place == "Winner" ? Color(hex: 0xD97706) : wa.soft)
                }
                .padding(12)
                .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .padding(.bottom, 6)
                .waRise(min(i, 8))
            }
        } else {
            StateNote(state: trophies?.state ?? "pending")
        }
    }
}

private struct Sidelined: View {
    @Environment(\.wa) private var wa
    let part: SpPart<[SpSidelinedDto]>?
    let loaded: Bool

    var body: some View {
        if !loaded {
            SkeletonRows(count: 5)
        } else if let list = part?.data, !list.isEmpty {
            ForEach(Array(list.enumerated()), id: \.offset) { i, x in
                let card = (x.type ?? "").localizedCaseInsensitiveContains("Suspend") || (x.type ?? "").localizedCaseInsensitiveContains("Card")
                HStack(spacing: 10) {
                    Text(verbatim: card ? "🟥" : "🩹").font(.system(size: 18))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: x.type ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
                        Text(verbatim: [x.start.map { spShortDate($0) + " " + String($0.prefix(4)) }, x.end.map { spShortDate($0) + " " + String($0.prefix(4)) }].compactMap { $0 }.joined(separator: " → "))
                            .font(.dorr(11.5)).foregroundStyle(wa.mut)
                    }
                    Spacer(minLength: 0)
                }
                .padding(12)
                .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .padding(.bottom, 6)
                .waRise(min(i, 8))
            }
        } else {
            StateNote(state: part?.state ?? "pending", empty: "spx_no_injuries")
        }
    }
}

// MARK: - The coach

@MainActor
struct CoachPage: View {
    @Environment(\.wa) private var wa
    let id: Int
    let onBack: () -> Void
    let go: (SpPage) -> Void
    @State private var coach: SpCoachDto?
    @State private var failed: String?

    var body: some View {
        WaPage(title: coach?.name ?? L("spx_coach"), onBack: onBack) {
            if let c = coach {
                let color = spTeamColor(c.team?.color, c.team?.id ?? (c.id ?? id))
                PersonHeader(photo: c.photo, name: c.name, sub: [c.nationality, c.age.map { L("spx_age", $0) }].compactMap { $0 }.joined(separator: " · "), color: color, number: nil) {
                    if let t = c.team { TeamPill(team: t) { if t.id > 0 { go(.team(t.id)) } } }
                }
                SectionCard(title: L("spx_about")) {
                    SpInfoLine(emoji: "🎂", label: L("spx_born"), value: [c.birth?.date, c.birth?.place, c.birth?.country].compactMap { $0 }.joined(separator: " · "))
                    SpInfoLine(emoji: "🌍", label: L("spx_nationality"), value: c.nationality)
                }
                if let jobs = c.career, !jobs.isEmpty {
                    WaSectionTitle(title: L("spx_tab_career"))
                    ForEach(Array(jobs.enumerated()), id: \.offset) { i, j in
                        WaCard(padding: 12) {
                            Button { if let tid = j.team?.id, tid > 0 { go(.team(tid)) } } label: {
                                HStack(spacing: 10) {
                                    Crest(team: j.team, size: 36)
                                    VStack(alignment: .leading, spacing: 0) {
                                        Text(verbatim: j.team?.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                                        Text(verbatim: "\(j.start.map { String($0.prefix(4)) } ?? "?") – \(j.end.map { String($0.prefix(4)) } ?? L("spx_now"))").font(.dorr(12)).foregroundStyle(wa.mut)
                                    }
                                    Spacer(minLength: 0)
                                    if j.end == nil {
                                        Text(L("spx_now")).font(.dorr(11, .black)).foregroundStyle(.white)
                                            .padding(.horizontal, 9).padding(.vertical, 3)
                                            .background(spGreen, in: Capsule())
                                    }
                                }
                            }
                            .buttonStyle(.plain)
                        }
                        .padding(.bottom, 8)
                        .waRise(min(i, 8))
                    }
                }
                WaSectionTitle(title: L("spx_tab_trophies"))
                Trophies(trophies: c.trophies, loaded: true)
                Spacer().frame(height: 24)
            } else if let failed {
                StateNote(state: failed)
            } else {
                WaSkeleton(cornerRadius: 28).frame(height: 220)
                SkeletonRows().padding(.top, 12)
            }
        }
        .task(id: id) {
            do {
                coach = try await SportsApi.coach(id).data
            } catch {
                failed = error.apiFailure.httpStatus == 503 ? "pending" : "unavailable"
            }
        }
    }
}

// MARK: - Search

/// One search for everything: competitions, teams, players.
@MainActor
struct SportsSearchPage: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    let go: (SpPage) -> Void
    @State private var query = ""
    @State private var result: SpSearchDto?
    @State private var busy = false

    var body: some View {
        WaPage(title: L("spx_search"), onBack: onBack) {
            DorrTextArea(value: Binding(get: { query }, set: { query = String($0.prefix(60)) }), placeholder: L("spx_search_hint"), icon: "magnifyingglass", singleLine: true)
                .waRise(0)
            let q = query.trimmingCharacters(in: .whitespaces)
            if q.count < 2 {
                WaEmpty(icon: "globe", tone: .blue, title: L("spx_search"), text: L("spx_search_sub"))
            } else if busy && result == nil {
                SkeletonRows(count: 5).padding(.top, 12)
            } else if let r = result, !(r.competitions ?? []).isEmpty || !(r.teams ?? []).isEmpty || !(r.players ?? []).isEmpty {
                if let teams = r.teams, !teams.isEmpty {
                    WaSectionTitle(title: L("sp_tab_teams"))
                    ForEach(Array(teams.enumerated()), id: \.offset) { i, t in
                        ResultRow(index: i, title: t.name ?? "", sub: t.country, action: { go(.team(t.id)) }) { Crest(team: t, size: 40) }
                    }
                }
                if let comps = r.competitions, !comps.isEmpty {
                    WaSectionTitle(title: L("spx_competitions"))
                    ForEach(Array(comps.enumerated()), id: \.offset) { i, c in
                        ResultRow(index: i, title: c.name ?? "", sub: c.country, action: { go(.competition(c.id)) }) {
                            ZStack {
                                if let url = ApiClient.mediaUrl(c.logo) {
                                    AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFit() }.frame(width: 30, height: 30)
                                } else {
                                    Text(verbatim: "🏆")
                                }
                            }
                            .frame(width: 40, height: 40)
                            .background(wa.field, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        }
                    }
                }
                if let players = r.players, !players.isEmpty {
                    WaSectionTitle(title: L("spx_players"))
                    ForEach(Array(players.enumerated()), id: \.offset) { i, p in
                        ResultRow(index: i, title: p.name ?? "", sub: [p.position, p.nationality, p.age.map { L("spx_age", $0) }].compactMap { $0 }.joined(separator: " · "),
                                  action: { if let pid = p.id { go(.player(pid)) } }) { PhotoAvatar(url: p.photo, name: p.name, size: 40) }
                    }
                }
            } else {
                StateNote(state: nil, empty: "spx_nothing_found").padding(.top, 12)
            }
            Spacer().frame(height: 24)
        }
        .task(id: query) {
            let q = query.trimmingCharacters(in: .whitespaces)
            guard q.count >= 2 else { result = nil; return }
            try? await Task.sleep(nanoseconds: 450_000_000)
            guard !Task.isCancelled else { return }
            busy = true
            result = try? await SportsApi.search(q).data
            busy = false
        }
    }
}

private struct ResultRow<Icon: View>: View {
    @Environment(\.wa) private var wa
    let index: Int
    let title: String
    let sub: String?
    let action: () -> Void
    @ViewBuilder let icon: () -> Icon

    var body: some View {
        Button(action: action) {
            HStack(spacing: 12) {
                icon()
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                    if let sub, !sub.isEmpty { Text(verbatim: sub).font(.dorr(12)).foregroundStyle(wa.mut).lineLimit(1) }
                }
                Spacer(minLength: 0)
                Image(systemName: "chevron.forward").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.soft)
            }
            .padding(10)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(.plain)
        .padding(.bottom, 6)
        .waRise(min(index, 8))
    }
}
