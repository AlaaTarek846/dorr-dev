import SwiftUI

// androidApp `sports/SportsInsights.kt` — the match page's extras: the poster (stadium, captains, coaches, the
// referee), line-ups on the pitch, the players' sheet, the provider's prediction, head to head, who's missing,
// and odds — information only.

/// "5 Nov" in the app's language.
func spShortDate(_ iso: String) -> String {
    guard let d = evLocalDate(iso) else { return iso }
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current + "@numbers=latn")
    f.dateFormat = "d MMM"
    return f.string(from: d)
}

struct RatingBadge: View {
    let rating: Double
    var small = false

    var body: some View {
        let c: Color = rating >= 8 ? Color(hex: 0x0E9F6E) : (rating >= 7 ? Color(hex: 0x16A34A) : (rating >= 6.5 ? Color(hex: 0xF59E0B) : Color(hex: 0xEA580C)))
        Text(verbatim: String(format: "%.1f", rating)).font(.dorr(small ? 10 : 13, .black)).foregroundStyle(.white)
            .padding(.horizontal, small ? 4 : 8).padding(.vertical, small ? 1 : 4)
            .background(c, in: RoundedRectangle(cornerRadius: small ? 6 : 9, style: .continuous))
    }
}

/// The armband "C".
private struct CaptainMark: View {
    var size: CGFloat = 15
    var body: some View {
        Text(verbatim: "C").font(.dorr(size * 0.56, .black)).foregroundStyle(Color(hex: 0x0B1220))
            .frame(width: size, height: size)
            .background(Color(hex: 0xFACC15), in: Circle())
    }
}

// MARK: - The poster

/// Under the hero: the two captains face each other across the stadium, the referee between them.
struct MatchPoster: View {
    let m: SpMatchDto
    let poster: SpPosterDto?
    let go: (SpPage) -> Void
    @State private var zoom: CGFloat = 1

    var body: some View {
        if let p = poster, p.home?.captain != nil || p.away?.captain != nil || p.home?.coach != nil || p.referee != nil || p.venue?.image != nil {
            let home = spTeamColor(m.home?.color, m.home?.id ?? 0)
            let away = spTeamColor(m.away?.color, m.away?.id ?? 1)
            ZStack {
                Color(hex: 0x0B1220)
                if let url = ApiClient.mediaUrl(p.venue?.image) {
                    // The stadium drifts slowly, like a film poster.
                    AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() }.opacity(0.55).scaleEffect(zoom)
                        .onAppear { withAnimation(.easeInOut(duration: 14).repeatForever(autoreverses: true)) { zoom = 1.09 } }
                }
                LinearGradient(colors: [home.opacity(0.85), .clear, away.opacity(0.85)], startPoint: .leading, endPoint: .trailing)
                LinearGradient(colors: [.clear, .black.opacity(0.75)], startPoint: .top, endPoint: .bottom)
                HStack(alignment: .bottom, spacing: 0) {
                    PosterSide(captain: p.home?.captain, coach: p.home?.coach, alignEnd: false, go: go)
                    VStack(spacing: 0) {
                        if let ref = p.referee?.name {
                            Text(verbatim: "🧑‍⚖️").font(.system(size: 22))
                                .frame(width: 46, height: 46)
                                .background(Color.black.opacity(0.55), in: Circle())
                                .overlay(Circle().stroke(Color(hex: 0xFACC15), lineWidth: 2))
                            Text(verbatim: ref).font(.dorr(12, .heavy)).foregroundStyle(.white).multilineTextAlignment(.center).lineLimit(1).padding(.top, 4)
                            Text(verbatim: L("spx_referee") + (p.referee?.country.map { " · \($0)" } ?? "")).font(.dorr(10)).foregroundStyle(.white.opacity(0.7)).lineLimit(1)
                        }
                        if let venue = p.venue?.name {
                            Text(verbatim: "🏟️ \(venue)").font(.dorr(10.5)).foregroundStyle(.white.opacity(0.85)).multilineTextAlignment(.center).lineLimit(2).padding(.top, 8)
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.bottom, 4)
                    PosterSide(captain: p.away?.captain, coach: p.away?.coach, alignEnd: true, go: go)
                }
                .padding(.horizontal, 14)
                .padding(.vertical, 12)
                .frame(maxHeight: .infinity, alignment: .bottom)
            }
            .frame(height: 210)
            .clipShape(RoundedRectangle(cornerRadius: 26, style: .continuous))
            .padding(.top, 10)
            .waRise(1)
        }
    }
}

private struct PosterSide: View {
    let captain: SpPersonDto?
    let coach: SpPersonDto?
    let alignEnd: Bool
    let go: (SpPage) -> Void

    var body: some View {
        VStack(alignment: alignEnd ? .trailing : .leading, spacing: 0) {
            if let c = captain {
                Button { if let id = c.id { go(.player(id)) } } label: {
                    PhotoAvatar(url: c.photo, name: c.name, size: 82, ring: .white)
                        .overlay(alignment: .bottomTrailing) { CaptainMark(size: 24) }
                }
                .buttonStyle(.plain)
                Text(verbatim: c.name ?? "").font(.dorr(12.5, .heavy)).foregroundStyle(.white).lineLimit(1).padding(.top, 5)
            }
            if let c = coach {
                Button { if let id = c.id { go(.coach(id)) } } label: {
                    HStack(spacing: 5) {
                        PhotoAvatar(url: c.photo, name: c.name, size: 22)
                        Text(verbatim: c.name ?? "").font(.dorr(10.5, .bold)).foregroundStyle(.white).lineLimit(1)
                    }
                    .padding(.leading, 3).padding(.trailing, 9).padding(.vertical, 3)
                    .background(Color.black.opacity(0.45), in: Capsule())
                }
                .buttonStyle(.plain)
                .padding(.top, 6)
            }
        }
        .frame(maxWidth: .infinity, alignment: alignEnd ? .trailing : .leading)
    }
}

// MARK: - Line-ups on the pitch

struct PitchLineups: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    let go: (SpPage) -> Void

    var body: some View {
        let home = m.lineups?["home"]
        let away = m.lineups?["away"]
        let homeColor = lineColor(home, m.home)
        let awayColor = lineColor(away, m.away, fallback: 1)
        // The best of the match (the provider's ratings).
        let best = bestPlayer(home, away)
        VStack(spacing: 0) {
            if let b = best {
                Button { if let id = b.id { go(.player(id)) } } label: {
                    HStack(spacing: 10) {
                        PhotoAvatar(url: b.photo, name: b.name, size: 46, ring: .white)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: "⭐ " + L("spx_player_of_match")).font(.dorr(11.5, .bold)).foregroundStyle(.white.opacity(0.9))
                            Text(verbatim: b.name ?? "").font(.dorr(15, .heavy)).foregroundStyle(.white)
                        }
                        Spacer()
                        RatingBadge(rating: b.rating ?? 0)
                    }
                    .padding(12)
                    .background(LinearGradient(colors: [Color(hex: 0xB45309), Color(hex: 0xF59E0B)], startPoint: .topLeading, endPoint: .bottomTrailing),
                                in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                }
                .buttonStyle(.plain)
                .padding(.bottom, 10)
            }
            HStack {
                Text(verbatim: [m.home?.name, home?.formation].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12.5, .bold)).foregroundStyle(wa.ink)
                Spacer()
                Text(verbatim: [away?.formation, m.away?.name].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12.5, .bold)).foregroundStyle(wa.ink)
            }
            .padding(.bottom, 8)
            pitch(home: home, away: away, homeColor: homeColor, awayColor: awayColor)
            // Coaches and benches, with photos.
            HStack(alignment: .top, spacing: 10) {
                bench(home, homeColor)
                bench(away, awayColor)
            }
            .padding(.top, 12)
        }
    }

    private func lineColor(_ l: SpLineupDto?, _ t: SpTeamDto?, fallback: Int = 0) -> Color {
        let primary: String? = l?.colors?["primary"] ?? nil
        return spTeamColor(primary ?? t?.color, t?.id ?? fallback)
    }

    private func bestPlayer(_ home: SpLineupDto?, _ away: SpLineupDto?) -> SpLineupPlayerDto? {
        var all: [SpLineupPlayerDto] = []
        for l in [home, away] {
            all += l?.start ?? []
            all += l?.subs ?? []
        }
        return all.filter { $0.rating != nil }.max { ($0.rating ?? 0) < ($1.rating ?? 0) }
    }

    private func pitch(home: SpLineupDto?, away: SpLineupDto?, homeColor: Color, awayColor: Color) -> some View {
        GeometryReader { proxy in
            let w = proxy.size.width, h = proxy.size.height
            ZStack(alignment: .topLeading) {
                LinearGradient(colors: [Color(hex: 0x166534), spPitch, Color(hex: 0x166534)], startPoint: .top, endPoint: .bottom)
                Canvas { gc, size in
                    let pad: CGFloat = 5
                    let line = GraphicsContext.Shading.color(.white.opacity(0.5))
                    for k in stride(from: 0, to: 10, by: 2) {
                        let band = (size.height - 2 * pad) / 10
                        gc.fill(Path(CGRect(x: pad, y: pad + CGFloat(k) * band, width: size.width - 2 * pad, height: band)), with: .color(.white.opacity(0.035)))
                    }
                    gc.stroke(Path(CGRect(x: pad, y: pad, width: size.width - 2 * pad, height: size.height - 2 * pad)), with: line, lineWidth: 1)
                    var mid = Path()
                    mid.move(to: CGPoint(x: pad, y: size.height / 2))
                    mid.addLine(to: CGPoint(x: size.width - pad, y: size.height / 2))
                    gc.stroke(mid, with: line, lineWidth: 1)
                    let r = size.width * 0.14
                    gc.stroke(Path(ellipseIn: CGRect(x: size.width / 2 - r, y: size.height / 2 - r, width: 2 * r, height: 2 * r)), with: line, lineWidth: 1)
                    gc.fill(Path(ellipseIn: CGRect(x: size.width / 2 - 1.5, y: size.height / 2 - 1.5, width: 3, height: 3)), with: line)
                    let boxW = size.width * 0.56, boxH = size.height * 0.12, small = size.width * 0.26
                    for rect in [CGRect(x: (size.width - boxW) / 2, y: pad, width: boxW, height: boxH),
                                 CGRect(x: (size.width - boxW) / 2, y: size.height - pad - boxH, width: boxW, height: boxH),
                                 CGRect(x: (size.width - small) / 2, y: pad, width: small, height: boxH * 0.42),
                                 CGRect(x: (size.width - small) / 2, y: size.height - pad - boxH * 0.42, width: small, height: boxH * 0.42)] {
                        gc.stroke(Path(rect), with: line, lineWidth: 1)
                    }
                }
                if let away { placeSide(away, awayColor, w, h, bottom: false) }
                if let home { placeSide(home, homeColor, w, h, bottom: true) }
            }
            .environment(\.layoutDirection, .leftToRight)
        }
        .aspectRatio(0.62, contentMode: .fit)
        .clipShape(RoundedRectangle(cornerRadius: 24, style: .continuous))
    }

    /// From the goal (row 1) to just before the halfway line; columns spread across.
    @ViewBuilder private func placeSide(_ l: SpLineupDto, _ color: Color, _ w: CGFloat, _ h: CGFloat, bottom: Bool) -> some View {
        let players: [(Int, Int, SpLineupPlayerDto)] = (l.start ?? []).compactMap { p in
            let g = (p.grid ?? "").split(separator: ":").compactMap { Int($0) }
            guard let row = g.first else { return nil }
            return (row, g.count > 1 ? g[1] : 1, p)
        }
        let rows = players.map(\.0).max() ?? 1
        let perRow = Dictionary(grouping: players, by: \.0).mapValues(\.count)
        ForEach(Array(players.enumerated()), id: \.offset) { i, item in
            let (row, col, p) = item
            let count = perRow[row] ?? 1
            let y = 0.055 + 0.39 * CGFloat(row - 1) / CGFloat(max(1, rows - 1))
            let fy = bottom ? 1 - y : y
            let fx = CGFloat(col) / CGFloat(count + 1)
            let x = bottom ? fx : 1 - fx
            Button { if let id = p.id { go(.player(id)) } } label: {
                VStack(spacing: 2) {
                    ZStack {
                        Circle().fill(color)
                        Text(verbatim: p.number.map(String.init) ?? "").font(.dorr(12, .black)).foregroundStyle(.white)
                        if let url = ApiClient.mediaUrl(p.photo) {
                            AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFill() }.clipShape(Circle())
                        }
                    }
                    .frame(width: 38, height: 38)
                    .overlay(Circle().stroke(Color.white, lineWidth: 2))
                    .overlay(alignment: .topTrailing) { if let r = p.rating { RatingBadge(rating: r, small: true).offset(x: 8, y: -4) } }
                    .overlay(alignment: .bottomLeading) { if p.captain == true { CaptainMark().offset(x: -4) } }
                    .overlay(alignment: .bottomTrailing) {
                        let marks = String(repeating: "⚽", count: min(p.goals ?? 0, 3)) + ((p.red ?? 0) > 0 ? "🟥" : ((p.yellow ?? 0) > 0 ? "🟨" : ""))
                        if !marks.isEmpty { Text(verbatim: marks).font(.system(size: 9)).offset(x: 8, y: 2) }
                    }
                    Text(verbatim: String((p.name ?? "").split(separator: " ").last ?? "")).font(.dorr(9.5, .bold)).foregroundStyle(.white).lineLimit(1)
                        .padding(.horizontal, 4).padding(.vertical, 1)
                        .background(Color.black.opacity(0.35), in: RoundedRectangle(cornerRadius: 5, style: .continuous))
                }
                .frame(width: 60)
            }
            .buttonStyle(.plain)
            .position(x: w * x, y: h * fy + 6)
            .waRise(i)
        }
    }

    private func bench(_ l: SpLineupDto?, _ c: Color) -> some View {
        VStack(alignment: .leading, spacing: 0) {
            if let name = l?.coach {
                Button { if let id = l?.coachId { go(.coach(id)) } } label: {
                    HStack(spacing: 8) {
                        PhotoAvatar(url: l?.coachPhoto, name: name, size: 30, ring: c)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: name).font(.dorr(12.5, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                            Text(L("spx_coach")).font(.dorr(10.5)).foregroundStyle(wa.soft)
                        }
                    }
                }
                .buttonStyle(.plain)
            }
            Text(L("sp_bench")).font(.dorr(11, .bold)).foregroundStyle(wa.soft).padding(.top, 10).padding(.bottom, 4)
            ForEach(Array((l?.subs ?? []).prefix(14).enumerated()), id: \.offset) { _, p in
                Button { if let id = p.id { go(.player(id)) } } label: {
                    HStack(spacing: 6) {
                        PhotoAvatar(url: p.photo, name: p.name, size: 24)
                        Text(verbatim: "\(p.number.map(String.init) ?? "") \(p.name ?? "")").font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(1)
                        Spacer(minLength: 0)
                        if let r = p.rating { RatingBadge(rating: r, small: true) }
                    }
                    .padding(.vertical, 3)
                }
                .buttonStyle(.plain)
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

// MARK: - The players' sheet

struct PlayersSheet: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    let go: (SpPage) -> Void
    @State private var side = "home"

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            ChoiceChips(options: [("home", m.home?.name ?? ""), ("away", m.away?.name ?? "")], selected: side) { side = $0 }
            Spacer().frame(height: 10)
            let list = m.players?[side] ?? []
            if list.isEmpty {
                StateNote(state: nil)
            } else {
                ForEach(Array(list.enumerated()), id: \.offset) { i, p in SheetRow(p: p, go: go).waRise(min(i, 8)) }
            }
        }
    }
}

private struct SheetRow: View {
    @Environment(\.wa) private var wa
    let p: SpSheetPlayerDto
    let go: (SpPage) -> Void
    @State private var open = false

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                PhotoAvatar(url: p.photo, name: p.name, size: 40)
                    .overlay(alignment: .bottomTrailing) { if p.captain == true { CaptainMark() } }
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: p.name ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                    Text(verbatim: [p.number.map { "#\($0)" }, p.pos, p.minutes.map { "\($0)'" },
                                    (p.goals ?? 0) > 0 ? String(repeating: "⚽", count: min(p.goals!, 4)) : nil,
                                    (p.assists ?? 0) > 0 ? String(repeating: "🎯", count: min(p.assists!, 4)) : nil,
                                    (p.red ?? 0) > 0 ? "🟥" : ((p.yellow ?? 0) > 0 ? "🟨" : nil)].compactMap { $0 }.joined(separator: "  "))
                        .font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(1)
                }
                Spacer(minLength: 0)
                if let r = p.rating { RatingBadge(rating: r) }
            }
            if open {
                VStack(spacing: 0) {
                    SpInfoLine(emoji: "🥅", label: L("spx_shots"), value: p.shots.map { "\($0)" + (p.shotsOn.map { " (\($0))" } ?? "") })
                    SpInfoLine(emoji: "🅿️", label: L("spx_passes"), value: p.passes.map { "\($0)" + (p.keyPasses.map { " · \($0) 🔑" } ?? "") + (spText(p.passAccuracy).map { " · \($0)%" } ?? "") })
                    SpInfoLine(emoji: "✨", label: L("spx_dribbles"), value: p.dribbles.map(String.init))
                    SpInfoLine(emoji: "🛡️", label: L("spx_tackles"), value: p.tackles.map(String.init))
                    SpInfoLine(emoji: "💪", label: L("spx_duels_won"), value: p.duelsWon.map(String.init))
                    SpInfoLine(emoji: "🧤", label: L("spx_saves"), value: (p.saves ?? 0) > 0 ? String(p.saves!) : nil)
                    SpInfoLine(emoji: "⚠️", label: L("spx_fouls"), value: p.fouls.map(String.init))
                    if let id = p.id {
                        Button { go(.player(id)) } label: {
                            Text(L("spx_open_profile")).font(.dorr(12.5, .bold)).foregroundStyle(wa.red).frame(maxWidth: .infinity, alignment: .leading)
                        }
                        .buttonStyle(.plain)
                        .padding(.top, 6)
                    }
                }
                .padding(.top, 8)
            }
        }
        .padding(10)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .contentShape(Rectangle())
        .onTapGesture { withAnimation(.easeInOut(duration: 0.2)) { open.toggle() } }
        .padding(.bottom, 6)
    }
}

// MARK: - Prediction, head to head, missing, odds

/// Win · draw · win as one bar in three colours, growing in.
struct ProbBar: View {
    @Environment(\.wa) private var wa
    let home: Double
    let draw: Double
    let away: Double
    let homeColor: Color
    let awayColor: Color
    @State private var grow: CGFloat = 0

    var body: some View {
        let total = home + draw + away
        if total > 0 {
            VStack(spacing: 6) {
                GeometryReader { p in
                    HStack(spacing: 0) {
                        ForEach(Array([(home, homeColor), (draw, Color(hex: 0x9CA3AF)), (away, awayColor)].enumerated()), id: \.offset) { _, item in
                            item.1.frame(width: p.size.width * CGFloat(item.0 / total) * grow)
                        }
                        Spacer(minLength: 0)
                    }
                }
                .frame(height: 14)
                .background(wa.field)
                .clipShape(Capsule())
                HStack {
                    Text(verbatim: "\(Int(home))%").font(.dorr(13, .black)).foregroundStyle(homeColor).frame(maxWidth: .infinity, alignment: .leading)
                    Text(verbatim: "\(Int(draw))%").font(.dorr(13, .black)).foregroundStyle(wa.mut).frame(maxWidth: .infinity)
                    Text(verbatim: "\(Int(away))%").font(.dorr(13, .black)).foregroundStyle(awayColor).frame(maxWidth: .infinity, alignment: .trailing)
                }
            }
            .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.9)) { grow = 1 } }
        }
    }
}

struct ForecastTab: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    let insights: SpInsightsDto?

    var body: some View {
        if let insights {
            if let f = insights.prediction?.data {
                let home = spTeamColor(m.home?.color, m.home?.id ?? 0)
                let away = spTeamColor(m.away?.color, m.away?.id ?? 1)
                VStack(alignment: .leading, spacing: 0) {
                    VStack(alignment: .leading, spacing: 0) {
                        Text(L("spx_win_chances")).font(.dorr(15, .heavy)).foregroundStyle(wa.ink).padding(.bottom, 8)
                        HStack {
                            Text(verbatim: m.home?.name ?? "").font(.dorr(12.5, .bold)).foregroundStyle(wa.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                            Text(L("spx_draw")).font(.dorr(12.5)).foregroundStyle(wa.mut).frame(maxWidth: .infinity)
                            Text(verbatim: m.away?.name ?? "").font(.dorr(12.5, .bold)).foregroundStyle(wa.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .trailing)
                        }
                        .padding(.bottom, 8)
                        ProbBar(home: f.percent?.home ?? 0, draw: f.percent?.draw ?? 0, away: f.percent?.away ?? 0, homeColor: home, awayColor: away)
                        if let advice = f.advice {
                            HStack(spacing: 8) {
                                Text(verbatim: "💡").font(.system(size: 18))
                                Text(verbatim: advice).font(.dorr(13, .semibold)).foregroundStyle(wa.ink)
                                Spacer(minLength: 0)
                            }
                            .padding(12)
                            .background(wa.field, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            .padding(.top, 12)
                        }
                    }
                    .padding(14)
                    .background(wa.surface, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
                    if let comparison = f.comparison, !comparison.isEmpty {
                        SectionCard(title: L("spx_comparison")) {
                            ForEach(Array(comparison.enumerated()), id: \.offset) { i, c in
                                CompareRow(label: compareLabel(c.type ?? ""), home: c.home ?? 0, away: c.away ?? 0, homeText: "\(Int(c.home ?? 0))%",
                                           awayText: "\(Int(c.away ?? 0))%", homeColor: home, awayColor: away, index: i)
                            }
                        }
                    }
                    let hf = f.home?.leagueForm, af = f.away?.leagueForm
                    if !(hf ?? "").isEmpty || !(af ?? "").isEmpty {
                        SectionCard(title: L("spx_form")) {
                            HStack(spacing: 8) { Crest(team: m.home, size: 26); FormStrip(form: hf, size: 20) }
                            HStack(spacing: 8) { Crest(team: m.away, size: 26); FormStrip(form: af, size: 20) }.padding(.top, 8)
                        }
                    }
                    Text(L("spx_forecast_source")).font(.dorr(11)).foregroundStyle(wa.soft).padding(.top, 8)
                    if let odds = insights.odds { OddsCard(odds: odds.data, state: odds.state) }
                }
            } else {
                StateNote(state: insights.prediction?.state)
            }
        } else {
            SkeletonRows(count: 4)
        }
    }

    private func compareLabel(_ type: String) -> String {
        switch type {
        case "form": return L("spx_form")
        case "att": return L("spx_attack")
        case "def": return L("spx_defence")
        case "poisson_distribution": return L("spx_poisson")
        case "h2h": return L("spx_h2h")
        case "goals": return L("spx_goals")
        case "total": return L("spx_total")
        default: return type
        }
    }
}

private struct OddsCard: View {
    @Environment(\.wa) private var wa
    let odds: SpOddsDto?
    let state: String?

    var body: some View {
        SectionCard(title: L("spx_odds"), trailing: { Text(L("spx_info_only")).font(.dorr(10.5, .bold)).foregroundStyle(wa.soft) }) {
            if let odds, let bets = odds.bets, !bets.isEmpty {
                ForEach(Array(bets.enumerated()), id: \.offset) { _, b in
                    Text(verbatim: b.name ?? "").font(.dorr(12, .bold)).foregroundStyle(wa.mut).padding(.top, 8).padding(.bottom, 4)
                    HStack(spacing: 6) {
                        ForEach(Array((b.values ?? []).prefix(3).enumerated()), id: \.offset) { _, v in
                            VStack(spacing: 0) {
                                Text(verbatim: v.value ?? "").font(.dorr(11)).foregroundStyle(wa.mut).lineLimit(1)
                                Text(verbatim: v.odd ?? "").font(.dorr(14, .black)).foregroundStyle(wa.ink)
                            }
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 8)
                            .background(wa.field, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        }
                    }
                }
                if let b = odds.bookmaker { Text(verbatim: b).font(.dorr(10.5)).foregroundStyle(wa.soft).padding(.top, 8) }
            } else {
                StateNote(state: state)
            }
        }
    }
}

struct H2hTab: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    let insights: SpInsightsDto?
    let go: (SpPage) -> Void

    var body: some View {
        if let insights {
            if let h = insights.h2h?.data, let matches = h.matches, !matches.isEmpty {
                let home = spTeamColor(m.home?.color, m.home?.id ?? 0)
                let away = spTeamColor(m.away?.color, m.away?.id ?? 1)
                VStack(alignment: .leading, spacing: 0) {
                    if let s = h.summary {
                        HStack(spacing: 0) {
                            summaryColumn(s.home ?? 0, m.home?.name, team: m.home, index: 0)
                            summaryColumn(s.draw ?? 0, L("spx_draws"), team: nil, index: 1)
                            summaryColumn(s.away ?? 0, m.away?.name, team: m.away, index: 2)
                        }
                        .padding(16)
                        .background(LinearGradient(colors: [home, Color(hex: 0x0B1220), away], startPoint: .topLeading, endPoint: .bottomTrailing),
                                    in: RoundedRectangle(cornerRadius: 24, style: .continuous))
                        Text(verbatim: L("spx_h2h_goals", s.played ?? 0, s.homeGoals ?? 0, s.awayGoals ?? 0)).font(.dorr(12)).foregroundStyle(wa.mut).padding(.top, 8)
                    }
                    WaSectionTitle(title: L("spx_last_meetings"))
                    ForEach(Array(matches.enumerated()), id: \.offset) { i, x in
                        HStack(spacing: 0) {
                            VStack(alignment: .leading, spacing: 0) {
                                Text(verbatim: x.date.map(spShortDate) ?? "").font(.dorr(11.5, .bold)).foregroundStyle(wa.ink)
                                Text(verbatim: String((x.date ?? "").prefix(4))).font(.dorr(10.5)).foregroundStyle(wa.soft)
                            }
                            .frame(width: 62, alignment: .leading)
                            HStack(spacing: 6) {
                                Text(verbatim: x.home?.name ?? "").font(.dorr(12.5, .semibold)).foregroundStyle(wa.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .trailing)
                                Crest(team: x.home, size: 20)
                                Text(verbatim: "\(x.homeScore.map(String.init) ?? "-") : \(x.awayScore.map(String.init) ?? "-")").font(.dorr(14, .black)).foregroundStyle(wa.ink)
                                    .padding(.horizontal, 2)
                                Crest(team: x.away, size: 20)
                                Text(verbatim: x.away?.name ?? "").font(.dorr(12.5, .semibold)).foregroundStyle(wa.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                            }
                        }
                        .padding(10)
                        .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        .padding(.bottom, 6)
                        .waRise(min(i, 8))
                    }
                }
            } else {
                StateNote(state: insights.h2h?.state, empty: "spx_no_h2h")
            }
        } else {
            SkeletonRows(count: 4)
        }
    }

    private func summaryColumn(_ n: Int, _ label: String?, team: SpTeamDto?, index: Int) -> some View {
        VStack(spacing: 0) {
            if let team { Crest(team: team, size: 40, plate: .white) } else { Text(verbatim: "🤝").font(.system(size: 26)) }
            Text(verbatim: String(n)).font(.dorr(28, .black)).foregroundStyle(.white).padding(.top, 4)
            Text(verbatim: label ?? "").font(.dorr(11)).foregroundStyle(.white.opacity(0.8)).lineLimit(1)
        }
        .frame(maxWidth: .infinity)
        .waRise(index)
    }
}

struct MissingTab: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    let insights: SpInsightsDto?
    let go: (SpPage) -> Void

    var body: some View {
        if let insights {
            let list = insights.injuries?.data ?? []
            if list.isEmpty {
                StateNote(state: insights.injuries?.state, empty: "spx_no_missing")
            } else {
                HStack(alignment: .top, spacing: 10) {
                    ForEach([("home", m.home), ("away", m.away)], id: \.0) { side, team in
                        VStack(spacing: 0) {
                            HStack(spacing: 6) {
                                Crest(team: team, size: 24)
                                Text(verbatim: team?.name ?? "").font(.dorr(12.5, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                                Spacer(minLength: 0)
                            }
                            .padding(.bottom, 8)
                            ForEach(Array(list.filter { $0.side == side }.enumerated()), id: \.offset) { i, x in
                                let doubtful = x.type == "Questionable"
                                Button { if let id = x.player?.id { go(.player(id)) } } label: {
                                    VStack(spacing: 0) {
                                        PhotoAvatar(url: x.player?.photo, name: x.player?.name, size: 44, ring: doubtful ? Color(hex: 0xF59E0B) : spLive)
                                        Text(verbatim: x.player?.name ?? "").font(.dorr(12, .bold)).foregroundStyle(wa.ink).lineLimit(1).padding(.top, 4)
                                        Text(verbatim: x.reason ?? "").font(.dorr(10.5)).foregroundStyle(wa.mut).multilineTextAlignment(.center).lineLimit(2)
                                        Text(L(doubtful ? "spx_doubtful" : "spx_out_of_match")).font(.dorr(10.5, .black))
                                            .foregroundStyle(doubtful ? Color(hex: 0xD97706) : spLive).padding(.top, 3)
                                    }
                                    .frame(maxWidth: .infinity)
                                    .padding(10)
                                    .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                                }
                                .buttonStyle(.plain)
                                .padding(.bottom, 6)
                                .waRise(i)
                            }
                        }
                        .frame(maxWidth: .infinity)
                    }
                }
            }
        } else {
            SkeletonRows(count: 4)
        }
    }
}
