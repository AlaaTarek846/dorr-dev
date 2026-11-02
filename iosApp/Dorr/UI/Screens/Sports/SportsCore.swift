import Combine
import SwiftUI
import WidgetKit
import UIKit

// androidApp `sports/SportsStore.kt`, `SportsParts.kt`, `SportsKinds.kt`, `SportsWidgets.kt`.
// DORR Sports on the phone: live scores arrive on the realtime connection — the app never asks the provider — the
// minute keeps ticking between updates on the device clock, and a goal for my team brings the goal moment (sound,
// vibration, confetti) unless I turned it off or hid spoilers.

func spZone() -> String { TimeZone.current.identifier }

let spGreen = Color(hex: 0x0E9F6E)
let spLive = Color(hex: 0xE11D48)
let spPitch = Color(hex: 0x15803D)
private let spNight = Color(hex: 0x0B1220)

/// Opening Sports from anywhere: the home card, a notification, the calendar.
@MainActor
final class SportsLink: ObservableObject {
    static let shared = SportsLink()
    @Published var open = false
    @Published var matchId: String?

    func show(_ match: String? = nil) {
        matchId = match
        open = true
    }

    func close() {
        open = false
        matchId = nil
    }
}

/// My sports choices, shared by every page (and the goal moment shown now).
@MainActor
final class SportsLive: ObservableObject {
    static let shared = SportsLive()
    @Published var prefs = SpPrefsDto()
    /// The goal moment / win on screen now.
    @Published var celebration: SportsAlert?
    /// A quiet banner (the other team scored, a red card, full time…).
    @Published var banner: SportsAlert?
    /// The newest version of each match we've heard about live (compact fields).
    @Published var latest: [String: SpMatchDto] = [:]
    /// When each match last changed live — rows flash, scores flip.
    @Published var changedAt: [String: Date] = [:]
    private var started = false
    private var events: AnyCancellable?

    func start() {
        guard !started else { return }
        started = true
        // The home-screen widgets read again now.
        WidgetCenter.shared.reloadAllTimelines()
        ChatRealtime.shared.watchPublic("sports.live", ["sports.match.updated"])
        Task { if let p = try? await SportsApi.preferences().data { prefs = p } }
        events = ChatRealtime.shared.events.receive(on: DispatchQueue.main).sink { [weak self] e in
            guard let self else { return }
            switch e.name {
            case "sports.match.updated": self.onUpdate(e.data)
            case "sports.alert": self.onAlert(e.data)
            // I won a contest: the win celebration, with the prize.
            case "sports.prize":
                self.celebration = SportsAlert(matchId: "", type: "prize", hidden: false, scorer: e.str("title"),
                                               celebrate: self.prefs.celebrationLevel == "off" ? "calm" : self.prefs.celebrationLevel)
                if self.prefs.sounds ?? true { SportsSounds.play(.win) }
            default: break
            }
        }
    }

    /// The freshest copy of a match: what the server sent live, if it's newer than the list's.
    func fresh(_ m: SpMatchDto) -> SpMatchDto {
        guard let l = latest[m.id], (l.version ?? 0) >= (m.version ?? 0) else { return m }
        var out = m
        out.status = l.status
        out.statusCode = l.statusCode
        out.minute = l.minute
        out.minuteExtra = l.minuteExtra
        out.homeScore = l.homeScore
        out.awayScore = l.awayScore
        out.periods = l.periods
        out.winner = l.winner
        out.version = l.version
        out.syncedAt = l.syncedAt
        out.race = l.race ?? m.race
        out.fight = l.fight ?? m.fight
        out.leader = l.leader ?? m.leader
        return out
    }

    private func onUpdate(_ data: JSONAny) {
        guard let m = data.decode(SpMatchDto.self) else { return }
        if let old = latest[m.id], (old.version ?? 0) >= (m.version ?? 0) { return }
        latest[m.id] = m
        changedAt[m.id] = Date()
    }

    private func onAlert(_ d: JSONAny) {
        guard let matchId = d["match_id"]?.str else { return }
        let alert = SportsAlert(matchId: matchId, type: d["type"]?.str ?? "", hidden: d["hidden"]?.bool == true,
                                homeScore: d["home_score"]?.dbl.map { Int($0) }, awayScore: d["away_score"]?.dbl.map { Int($0) },
                                side: d["side"]?.str, scorer: d["scorer"]?.str, minute: d["minute"]?.dbl.map { Int($0) }, celebrate: d["celebrate"]?.str)
        let sounds = prefs.sounds ?? true
        if let c = alert.celebrate, c != "off" {
            celebration = alert
            let win = alert.type == "finished"
            if sounds { SportsSounds.play(win ? .win : .goal) }
            if prefs.vibrate ?? true { SportsHaptics.play(win: win) }
        } else if !alert.hidden || alert.type == "kickoff" {
            banner = alert
            guard sounds else { return }
            switch alert.type {
            case "kickoff", "finished", "half_time": SportsSounds.play(.whistle)
            case "goal", "goal_detail": SportsSounds.play(.tick)
            default: break
            }
        }
    }
}

/// The goal / win buzz (Android's vibration patterns, as taps).
@MainActor
enum SportsHaptics {
    static func play(win: Bool) {
        let gen = UIImpactFeedbackGenerator(style: .heavy)
        let beats: [Double] = win ? [0, 0.2, 0.4] : [0, 0.15, 0.3]
        for t in beats { DispatchQueue.main.asyncAfter(deadline: .now() + t) { gen.impactOccurred() } }
        if !win { DispatchQueue.main.asyncAfter(deadline: .now() + 0.45) { UINotificationFeedbackGenerator().notificationOccurred(.success) } }
    }
}

/// One alert for a match I follow, as it arrived on my private channel.
struct SportsAlert: Equatable {
    var matchId: String
    var type: String
    var hidden: Bool
    var homeScore: Int?
    var awayScore: Int?
    var side: String?
    var scorer: String?
    var minute: Int?
    /// off / calm / normal / festive — nil: no celebration (not my team, or spoilers hidden).
    var celebrate: String?
    /// When it arrived — two alerts alike are still two moments.
    var at = Date()
}

// MARK: - The minute

/// What the clock shows: football's minute keeps ticking from the last update ("67'", "45+2'", "HT"), other
/// sports show their period, a coming match its local time.
func spClockText(_ m: SpMatchDto, _ now: Date) -> String {
    let ht = L("sp_ht"), ft = L("sp_ft")
    switch m.state {
    case "break": return m.statusCode == "HT" ? ht : (m.statusCode ?? ht)
    case "finished": return m.statusCode == "AET" || m.statusCode == "PEN" ? m.statusCode! : ft
    case "live":
        if m.sport == "formula1" {
            guard let minute = m.minute else { return "LIVE" }
            return "L\(minute)" + (m.race?.laps.map { "/\($0)" } ?? "")
        }
        if m.sport == "mma" { return m.statusCode ?? "LIVE" }
        if m.sport == "football" || m.sport == nil {
            let base = m.minute ?? 0
            let synced = parseInstant(m.syncedAt) ?? now
            let ran = min(max(Int(now.timeIntervalSince(synced) / 60), 0), 10)
            let cap: Int
            switch m.statusCode {
            case "1H": cap = 45
            case "2H": cap = 90
            case "ET": cap = 120
            default: cap = 200
            }
            let minute = base + ran
            if m.statusCode == "P" { return "PEN" }
            if (m.minuteExtra ?? 0) > 0 { return "\(cap)+\(m.minuteExtra!)'" }
            if minute > cap { return "\(cap)+\(minute - cap)'" }
            return "\(minute)'"
        }
        return [m.statusCode, m.minute.map { "\($0)'" }].compactMap { $0 }.joined(separator: " · ")
    default: return m.localTime ?? ""
    }
}

/// 0…1 of the match played (football), for the ring around the minute.
func spProgress(_ m: SpMatchDto, _ now: Date) -> Double {
    if m.state == "finished" { return 1 }
    guard ["live", "break"].contains(m.state) else { return 0 }
    let synced = parseInstant(m.syncedAt) ?? now
    let minute = (m.minute ?? 0) + min(max(Int(now.timeIntervalSince(synced) / 60), 0), 10)
    return min(max(Double(minute) / 90, 0), 1)
}

/// A team's colour: its kit or a steady colour from its id.
func spTeamColor(_ hex: String?, _ id: Int) -> Color {
    if let c = Color(hexString: hex) { return c }
    let palette: [UInt32] = [0x1D4ED8, 0xDC2626, 0x059669, 0x7C3AED, 0xEA580C, 0x0891B2, 0xDB2777, 0x4D7C0F, 0x0F172A, 0xCA8A04]
    return Color(hex: palette[((id % palette.count) + palette.count) % palette.count])
}

/// Re-reads the device clock every 10 s so live minutes move on screen (no server requests).
struct SpTicker<Content: View>: View {
    @ViewBuilder let content: (Date) -> Content
    var body: some View {
        TimelineView(.periodic(from: .now, by: 10)) { context in content(context.date) }
    }
}

// MARK: - Small pieces

/// A team's crest on a soft disc of its colour (or its initials when there's no logo).
struct Crest: View {
    let team: SpTeamDto?
    let size: CGFloat
    var plate: Color?

    var body: some View {
        let color = spTeamColor(team?.color, team?.id ?? 0)
        ZStack {
            if let plate { Circle().fill(plate) }
            Circle().fill(color.opacity(0.12))
            if let url = ApiClient.mediaUrl(team?.logo) {
                AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFit() }.frame(width: size * 0.74, height: size * 0.74)
            } else {
                Text(verbatim: (team?.code ?? String((team?.name ?? "?").prefix(2))).uppercased())
                    .font(.dorr(size * 0.32, .heavy)).foregroundStyle(color)
            }
        }
        .frame(width: size, height: size)
    }
}

/// A score digit that flips up when it changes, with a little bounce.
struct FlipScore: View {
    let value: Int?
    let size: CGFloat
    let color: Color

    var body: some View {
        Text(verbatim: value.map(String.init) ?? "–").font(.dorr(size, .black)).foregroundStyle(color)
            .id(value)
            .transition(.asymmetric(insertion: .move(edge: .bottom).combined(with: .opacity), removal: .move(edge: .top).combined(with: .opacity)))
            .animation(.spring(response: 0.38, dampingFraction: 0.55), value: value)
    }
}

/// The red "live" dot that breathes.
struct LiveDot: View {
    var size: CGFloat = 8
    var color: Color = spLive

    var body: some View {
        TimelineView(.animation) { context in
            let t = context.date.timeIntervalSinceReferenceDate.truncatingRemainder(dividingBy: 1.7) / 0.85
            let phase = t <= 1 ? t : 2 - t
            let a = 1 - 0.75 * phase
            ZStack {
                Circle().fill(color.opacity(0.25 * a)).frame(width: size, height: size).scaleEffect(1 + 0.6 * phase)
                Circle().fill(color.opacity(0.6 + 0.4 * a)).frame(width: size, height: size)
            }
        }
        .frame(width: size, height: size)
    }
}

/// The status line of a match: a live minute with its dot, half time, full time, postponed…
struct StatusChip: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    let now: Date
    var onDark = false

    var body: some View {
        let label: String = {
            switch m.state {
            case "postponed": return L("sp_postponed")
            case "cancelled": return L("sp_cancelled")
            case "suspended": return L("sp_suspended")
            default: return spClockText(m, now)
            }
        }()
        let live = m.state == "live"
        let color: Color = live ? spLive : (m.state == "break" ? Color(hex: 0xD97706)
            : (["postponed", "cancelled", "suspended"].contains(m.state) ? Color(hex: 0x6B7280) : (onDark ? .white.opacity(0.85) : wa.mut)))
        HStack(spacing: 5) {
            if live { LiveDot(size: 7) }
            Text(verbatim: label).font(.dorr(12, live ? .heavy : .bold)).foregroundStyle(color).lineLimit(1)
        }
    }
}

/// A thin ring of the match played, around the minute (football).
struct MinuteRing<Content: View>: View {
    let m: SpMatchDto
    let now: Date
    let size: CGFloat
    let color: Color
    @ViewBuilder let content: () -> Content

    var body: some View {
        let p = spProgress(m, now)
        ZStack {
            Circle().stroke(color.opacity(0.18), lineWidth: 4)
            Circle().trim(from: 0, to: p).stroke(color, style: StrokeStyle(lineWidth: 4, lineCap: .round)).rotationEffect(.degrees(-90))
                .animation(.easeInOut(duration: 0.9), value: p)
            content()
        }
        .padding(2)
        .frame(width: size, height: size)
    }
}

// MARK: - A match in a list

/// One match: crest and name each side, the score in the middle, or the kick-off time. The winner reads bolder.
/// The freshest copy (live updates win over the list's), glowing for a moment when it just changed.
struct MatchRow: View {
    @ObservedObject private var live = SportsLive.shared
    let m: SpMatchDto
    let now: Date
    var mine: Set<Int> = []
    var hideScore = false
    let onClick: () -> Void
    @State private var glow = 0.0

    var body: some View {
        MatchRowBody(m: live.fresh(m), now: now, mine: mine, hideScore: hideScore, onClick: onClick)
            .background(spLive.opacity(0.12 * glow), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .onChange(of: live.changedAt[m.id]) { at in
                guard let at, Date().timeIntervalSince(at) < 4 else { return }
                glow = 1
                withAnimation(.easeOut(duration: 2.2)) { glow = 0 }
            }
    }
}

private struct MatchRowBody: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    let now: Date
    var mine: Set<Int> = []
    var hideScore = false
    let onClick: () -> Void

    var body: some View {
        if m.home == nil {
            RaceRow(m: m, now: now, onClick: onClick)
        } else {
            Button(action: onClick) {
                HStack(spacing: 0) {
                    TeamSide(team: m.home, mine: mine, winner: m.winner == "home", alignEnd: false)
                    VStack(spacing: 2) {
                        if m.fight != nil && m.state == "finished" && !hideScore {
                            // A fight: how it ended, not a score.
                            Text(verbatim: fightResult(m)).font(.dorr(12.5, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center).lineLimit(2)
                        } else if m.fight != nil {
                            Text(verbatim: m.started ? "VS" : (m.localTime ?? "")).font(.dorr(16, .black)).foregroundStyle(m.started ? spLive : wa.ink)
                        } else if m.started && !hideScore {
                            HStack(spacing: 0) {
                                FlipScore(value: m.homeScore, size: 20, color: m.winner == "away" ? wa.mut : wa.ink)
                                Text(verbatim: ":").font(.dorr(15, .bold)).foregroundStyle(wa.soft).padding(.horizontal, 6)
                                FlipScore(value: m.awayScore, size: 20, color: m.winner == "home" ? wa.mut : wa.ink)
                            }
                        } else if m.started {
                            Text(verbatim: "• •").font(.dorr(18, .black)).foregroundStyle(wa.soft)
                        } else {
                            Text(verbatim: m.localTime ?? "").font(.dorr(16, .heavy)).foregroundStyle(wa.ink)
                        }
                        StatusChip(m: m, now: now)
                    }
                    .frame(width: 86)
                    TeamSide(team: m.away, mine: mine, winner: m.winner == "away", alignEnd: true)
                }
                .padding(.horizontal, 12)
                .padding(.vertical, 10)
                .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            }
            .buttonStyle(WaPressStyle(scale: 0.98))
        }
    }
}

private struct TeamSide: View {
    @Environment(\.wa) private var wa
    let team: SpTeamDto?
    let mine: Set<Int>
    let winner: Bool
    let alignEnd: Bool

    var body: some View {
        HStack(spacing: 0) {
            if alignEnd { Spacer(minLength: 0) }
            if !alignEnd { Crest(team: team, size: 34) }
            Text(verbatim: (team.map { mine.contains($0.id) } == true ? "★ " : "") + (team?.name ?? ""))
                .font(.dorr(13.5, winner ? .heavy : .semibold)).foregroundStyle(wa.ink).lineLimit(2)
                .multilineTextAlignment(alignEnd ? .trailing : .leading)
                .padding(.horizontal, 8)
            if alignEnd { Crest(team: team, size: 34) }
            if !alignEnd { Spacer(minLength: 0) }
        }
        .frame(maxWidth: .infinity)
    }
}

/// A big card for my teams' matches: the two kits as a split gradient, large crests, the score, the minute in a ring.
struct LiveCard: View {
    @Environment(\.layoutDirection) private var direction
    let m: SpMatchDto
    let now: Date
    var hideScore = false
    let onClick: () -> Void

    var body: some View {
        if m.home == nil {
            RaceCard(m: m, now: now, onClick: onClick)
        } else {
            let home = spTeamColor(m.home?.color, m.home?.id ?? 0)
            let away = spTeamColor(m.away?.color, m.away?.id ?? 1)
            Button(action: onClick) {
                VStack(spacing: 0) {
                    HStack {
                        Text(verbatim: m.competition?.name ?? "").font(.dorr(11.5, .bold)).foregroundStyle(.white.opacity(0.85)).lineLimit(1)
                        Spacer(minLength: 6)
                        StatusChip(m: m, now: now, onDark: true)
                    }
                    HStack(spacing: 0) {
                        side(m.home)
                        if m.started && !hideScore {
                            MinuteRing(m: m, now: now, size: 92, color: .white) {
                                HStack(spacing: 4) {
                                    FlipScore(value: m.homeScore, size: 28, color: .white)
                                    Text(verbatim: ":").font(.dorr(22, .black)).foregroundStyle(.white.opacity(0.7))
                                    FlipScore(value: m.awayScore, size: 28, color: .white)
                                }
                            }
                        } else {
                            VStack(spacing: 0) {
                                Image(systemName: "soccerball").font(.system(size: 17)).foregroundStyle(.white.opacity(0.8))
                                Text(verbatim: m.started ? "• •" : (m.localTime ?? "")).font(.dorr(22, .black)).foregroundStyle(.white)
                            }
                            .frame(width: 92)
                        }
                        side(m.away)
                    }
                    .padding(.top, 12)
                    if !m.allPeriods.isEmpty && m.sport != "football" && m.started && !hideScore {
                        PeriodStrip(m: m, onDark: true).padding(.top, 10)
                    }
                }
                .padding(16)
                .frame(width: 300)
                .background(LinearGradient(colors: [home, spNight, away], startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 26, style: .continuous))
            }
            .buttonStyle(WaPressStyle(scale: 0.97))
        }
    }

    private func side(_ team: SpTeamDto?) -> some View {
        VStack(spacing: 6) {
            Crest(team: team, size: 54, plate: .white.opacity(0.9))
            Text(verbatim: team?.name ?? "").font(.dorr(12.5, .bold)).foregroundStyle(.white).lineLimit(1)
        }
        .frame(maxWidth: .infinity)
    }
}

/// Scores per period (quarters, sets, periods) — for the sports that have them.
struct PeriodStrip: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    var onDark = false

    var body: some View {
        let ink = onDark ? Color.white : wa.ink
        HStack(spacing: 6) {
            ForEach(Array(m.allPeriods.enumerated()), id: \.offset) { _, p in
                VStack(spacing: 0) {
                    Text(verbatim: p.label ?? "").font(.dorr(10, .bold)).foregroundStyle(ink.opacity(0.7))
                    Text(verbatim: "\(p.home.map(String.init) ?? "-")–\(p.away.map(String.init) ?? "-")").font(.dorr(12, .heavy)).foregroundStyle(ink)
                }
                .padding(.horizontal, 8).padding(.vertical, 4)
                .background(onDark ? Color.white.opacity(0.14) : wa.field, in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            }
        }
        .frame(maxWidth: .infinity)
    }
}

/// A competition's heading in a list: logo, name, country, and a star to follow it.
struct CompetitionHeading: View {
    @Environment(\.wa) private var wa
    let name: String?
    let logo: String?
    let country: String?
    let followed: Bool
    var onStar: (() -> Void)?
    let onClick: () -> Void

    var body: some View {
        HStack(spacing: 10) {
            Button(action: onClick) {
                HStack(spacing: 10) {
                    ZStack {
                        if let url = ApiClient.mediaUrl(logo) {
                            AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFit() }.frame(width: 20, height: 20)
                        } else {
                            Text(verbatim: "🏆").font(.system(size: 14))
                        }
                    }
                    .frame(width: 28, height: 28)
                    .background(wa.surface, in: RoundedRectangle(cornerRadius: 8, style: .continuous))
                    .overlay(RoundedRectangle(cornerRadius: 8, style: .continuous).stroke(wa.line, lineWidth: 1))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: name ?? "").font(.dorr(14.5, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                        if let country { Text(verbatim: country).font(.dorr(11.5)).foregroundStyle(wa.soft).lineLimit(1) }
                    }
                    Spacer(minLength: 0)
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            if let onStar {
                Button(action: onStar) {
                    Text(verbatim: followed ? "★" : "☆").font(.system(size: 22)).foregroundStyle(followed ? Color(hex: 0xF59E0B) : wa.soft).padding(.horizontal, 8)
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.vertical, 6)
    }
}

// MARK: - Home card

/// On the home page: live now, or my team's next match — a tap opens Sports.
@MainActor
struct SportsHomeCard: View {
    @ObservedObject private var live = SportsLive.shared
    let onOpen: () -> Void
    @State private var home: SpHomeDto?

    var body: some View {
        SpTicker { now in
            let next = home?.mine?.first
            let liveCount = home?.liveCount ?? 0
            Button(action: onOpen) {
                HStack(spacing: 14) {
                    Text(verbatim: "⚽").font(.system(size: 26))
                        .frame(width: 52, height: 52)
                        .background(Color.white.opacity(0.16), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    VStack(alignment: .leading, spacing: 0) {
                        HStack(spacing: 4) {
                            Text(L("sp_title")).font(.dorr(16, .heavy)).foregroundStyle(.white)
                            if liveCount > 0 {
                                LiveDot(size: 7, color: .white).padding(.leading, 4)
                                Text(verbatim: L("sp_live_now", liveCount)).font(.dorr(12, .bold)).foregroundStyle(.white)
                            }
                        }
                        Text(verbatim: next.map { m in
                            let score = m.started && !live.prefs.hideScores ? "\(m.homeScore ?? 0)–\(m.awayScore ?? 0)" : (m.localTime ?? "")
                            return "\(m.home?.name ?? "") \(score) \(m.away?.name ?? "")" + (m.state == "live" ? " · " + spClockText(m, now) : "")
                        } ?? L("sp_home_sub"))
                            .font(.dorr(13)).foregroundStyle(.white.opacity(0.9)).lineLimit(1)
                    }
                    Spacer(minLength: 0)
                }
                .padding(16)
                .background(LinearGradient(colors: [Color(hex: 0x065F46), spGreen, spNight], startPoint: .topLeading, endPoint: .bottomTrailing),
                            in: RoundedRectangle(cornerRadius: 24, style: .continuous))
            }
            .buttonStyle(WaPressStyle(scale: 0.98))
        }
        .task {
            live.start()
            home = try? await SportsApi.home(timezone: spZone()).data
        }
    }
}

// MARK: - Each sport in its own way

private let raceDark = LinearGradient(colors: [Color(hex: 0x111827), Color(hex: 0x7F1D1D), Color(hex: 0x111827)], startPoint: .topLeading, endPoint: .bottomTrailing)

/// A race in a list: the Grand Prix, its circuit, who leads.
struct RaceRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var live = SportsLive.shared
    let m: SpMatchDto
    let now: Date
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 10) {
                Text(verbatim: "🏎️").font(.system(size: 20))
                    .frame(width: 42, height: 42)
                    .background(raceDark, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: m.title ?? m.round ?? "").font(.dorr(14, .heavy)).foregroundStyle(wa.ink).lineLimit(1)
                    Text(verbatim: [m.race?.type, m.race?.circuit].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.mut).lineLimit(1)
                }
                Spacer(minLength: 0)
                VStack(alignment: .trailing, spacing: 0) {
                    if m.state == "scheduled" { Text(verbatim: m.localTime ?? "").font(.dorr(15, .heavy)).foregroundStyle(wa.ink) }
                    if let leader = m.leader, ["live", "finished"].contains(m.state), !live.prefs.hideScores {
                        Text(verbatim: "P1 \(leader)").font(.dorr(13, .black)).foregroundStyle(wa.ink)
                    }
                    StatusChip(m: m, now: now)
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

/// A race on my teams' row: the Grand Prix with the lap bar.
struct RaceCard: View {
    let m: SpMatchDto
    let now: Date
    let onClick: () -> Void

    var body: some View {
        Button(action: onClick) {
            VStack(alignment: .leading, spacing: 0) {
                HStack {
                    Text(verbatim: "🏁 " + (m.race?.type ?? "")).font(.dorr(11.5, .bold)).foregroundStyle(.white.opacity(0.8))
                    Spacer()
                    StatusChip(m: m, now: now, onDark: true)
                }
                Text(verbatim: m.title ?? m.round ?? "").font(.dorr(20, .black)).foregroundStyle(.white).lineLimit(2).padding(.top, 8)
                Text(verbatim: m.race?.circuit ?? "").font(.dorr(12)).foregroundStyle(.white.opacity(0.75)).lineLimit(1)
                LapBar(m: m).padding(.top, 12)
            }
            .padding(16)
            .frame(width: 300, alignment: .leading)
            .background(raceDark, in: RoundedRectangle(cornerRadius: 26, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

private struct LapBar: View {
    let m: SpMatchDto
    var body: some View {
        if let total = m.race?.laps, total > 0 {
            let lap = m.state == "finished" ? total : (m.minute ?? m.race?.lap ?? 0)
            let p = min(max(Double(lap) / Double(total), 0), 1)
            VStack(alignment: .leading, spacing: 4) {
                GeometryReader { proxy in
                    ZStack(alignment: .leading) {
                        Capsule().fill(Color.white.opacity(0.15))
                        Capsule().fill(LinearGradient(colors: [Color(hex: 0xEF4444), Color(hex: 0xFACC15)], startPoint: .leading, endPoint: .trailing))
                            .frame(width: proxy.size.width * p)
                            .animation(.easeInOut(duration: 0.9), value: p)
                    }
                }
                .frame(height: 8)
                Text(verbatim: L("sp_laps", lap, total)).font(.dorr(11, .bold)).foregroundStyle(.white.opacity(0.8))
            }
        }
    }
}

/// The top of a race's page.
struct RaceHero: View {
    let m: SpMatchDto
    let now: Date

    var body: some View {
        ZStack {
            raceDark
            if let url = ApiClient.mediaUrl(m.race?.circuitImage) {
                AsyncImage(url: url) { phase in phase.image?.resizable().scaledToFit() }.frame(height: 150).padding(16).opacity(0.18)
                    .frame(maxHeight: .infinity, alignment: .top)
            }
            VStack(alignment: .leading, spacing: 0) {
                HStack {
                    Text(verbatim: "🏎️  " + (m.race?.type ?? "")).font(.dorr(12, .bold)).foregroundStyle(.white.opacity(0.8))
                    Spacer()
                    StatusChip(m: m, now: now, onDark: true)
                }
                Text(verbatim: m.title ?? m.round ?? "").font(.dorr(24, .black)).foregroundStyle(.white).padding(.top, 10)
                Text(verbatim: [m.race?.circuit, m.city, m.race?.country].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.78))
                if m.state == "scheduled" {
                    Text(verbatim: [m.localDate, m.localTime].compactMap { $0 }.joined(separator: "  ")).font(.dorr(18, .heavy)).foregroundStyle(.white).padding(.top, 10)
                }
                LapBar(m: m).padding(.top, 14)
                HStack(spacing: 8) {
                    ForEach([m.race?.distance.map { "📏 \($0)" }, m.race?.fastestLap.map { "⚡ \($0)" }].compactMap { $0 }, id: \.self) {
                        Text(verbatim: $0).font(.dorr(12, .bold)).foregroundStyle(.white)
                            .padding(.horizontal, 10).padding(.vertical, 5)
                            .background(Color.white.opacity(0.14), in: Capsule())
                    }
                }
                .padding(.top, 10)
            }
            .padding(18)
        }
        .frame(maxWidth: .infinity)
        .clipShape(RoundedRectangle(cornerRadius: 28, style: .continuous))
    }
}

/// The order: podium colours, team, time or gap, pit stops, places gained since the grid.
struct RaceResults: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto

    var body: some View {
        let rows = m.results ?? []
        if rows.isEmpty {
            Text(L(m.state == "scheduled" ? "sp_not_started" : "sp_no_events")).font(.dorr(13)).foregroundStyle(wa.mut)
        } else {
            WaCard(padding: 8) {
                ForEach(Array(rows.enumerated()), id: \.offset) { i, r in
                    let podium: Color? = r.position == 1 ? Color(hex: 0xF59E0B) : (r.position == 2 ? Color(hex: 0x94A3B8) : (r.position == 3 ? Color(hex: 0xB45309) : nil))
                    let gained = Int(r.grid ?? "").flatMap { g in r.position.map { g - $0 } }
                    HStack(spacing: 0) {
                        Text(verbatim: r.position.map(String.init) ?? "–").font(.dorr(13, .black)).foregroundStyle(podium != nil ? .white : wa.ink)
                            .frame(width: 28, height: 28)
                            .background(podium ?? wa.field, in: RoundedRectangle(cornerRadius: 8, style: .continuous))
                        Text(verbatim: r.abbr ?? "").font(.system(size: 14, weight: .black, design: .monospaced)).foregroundStyle(wa.ink)
                            .frame(width: 44, alignment: .leading).padding(.leading, 10)
                        VStack(alignment: .leading, spacing: 0) {
                            Text(verbatim: r.driver ?? "").font(.dorr(13, .semibold)).foregroundStyle(wa.ink).lineLimit(1)
                            Text(verbatim: [r.team, r.pits.map { "🛞 \($0)" }].compactMap { $0 }.joined(separator: " · ")).font(.dorr(11)).foregroundStyle(wa.soft).lineLimit(1)
                        }
                        Spacer(minLength: 6)
                        VStack(alignment: .trailing, spacing: 0) {
                            Text(verbatim: r.position == 1 ? (r.time ?? "") : (r.gap ?? r.time ?? "")).font(.system(size: 12.5, weight: .bold, design: .monospaced)).foregroundStyle(wa.ink)
                            if let g = gained, g != 0 {
                                Text(verbatim: g > 0 ? "▲\(g)" : "▼\(-g)").font(.dorr(10.5, .bold)).foregroundStyle(g > 0 ? spGreen : spLive)
                            }
                        }
                    }
                    .padding(.horizontal, 6)
                    .padding(.vertical, 6)
                    .waRise(min(i, 14))
                }
            }
        }
    }
}

/// How a fight ended: "KO/TKO · R2 3:05".
func fightResult(_ m: SpMatchDto) -> String {
    guard let f = m.fight else { return "" }
    let parts = [f.wonBy, f.round.map { L("sp_round_n", $0) + (f.time.map { " \($0)" } ?? "") }].compactMap { $0 }
    return parts.isEmpty ? L("sp_ft") : parts.joined(separator: " · ")
}

/// Under a fight's hero: the weight class, main event, and how it was won.
struct FightBanner: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    let f: SpFightDto
    let hide: Bool

    var body: some View {
        let items = [f.category.map { "🥊 \($0)" }, f.main == true ? L("sp_main_event") : nil, m.state == "finished" && !hide ? "🏆 " + fightResult(m) : nil].compactMap { $0 }
        FlowLayout(spacing: 8) {
            ForEach(items, id: \.self) {
                Text(verbatim: $0).font(.dorr(12.5, .bold)).foregroundStyle(wa.ink)
                    .padding(.horizontal, 12).padding(.vertical, 7)
                    .background(wa.surface, in: Capsule())
            }
        }
        .padding(.top, 10)
    }
}

/// Quarters, sets, periods, innings: a row per side, a column per period, the total last.
struct PeriodsTable: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto

    var body: some View {
        if m.allPeriods.isEmpty {
            Text(L(m.state == "scheduled" ? "sp_not_started" : "sp_no_events")).font(.dorr(13)).foregroundStyle(wa.mut)
        } else {
            WaCard(padding: 12) {
                HStack(spacing: 0) {
                    Spacer()
                    ForEach(Array(m.allPeriods.enumerated()), id: \.offset) { _, p in
                        Text(verbatim: p.label ?? "").font(.dorr(11, .bold)).foregroundStyle(wa.soft).frame(width: 34)
                    }
                    Text(verbatim: "T").font(.dorr(11, .black)).foregroundStyle(wa.soft).frame(width: 40)
                }
                ForEach([(m.home, true, m.homeScore), (m.away, false, m.awayScore)], id: \.1) { team, isHome, total in
                    HStack(spacing: 0) {
                        HStack(spacing: 8) {
                            Crest(team: team, size: 26)
                            Text(verbatim: team?.name ?? "").font(.dorr(13, .bold)).foregroundStyle(wa.ink).lineLimit(1)
                        }
                        Spacer(minLength: 4)
                        ForEach(Array(m.allPeriods.enumerated()), id: \.offset) { _, p in
                            let mine = isHome ? p.home : p.away
                            let other = isHome ? p.away : p.home
                            let won = mine != nil && other != nil && mine! > other!
                            Text(verbatim: mine.map(String.init) ?? "-").font(.dorr(13, won ? .heavy : .regular)).foregroundStyle(won ? wa.ink : wa.mut).frame(width: 34)
                        }
                        FlipScore(value: total, size: 17, color: wa.ink).frame(width: 40)
                    }
                    .padding(.top, 8)
                }
            }
        }
    }
}

/// The table, with qualification / relegation colours from the provider's description, and the trend.
struct StandingsTable: View {
    @Environment(\.wa) private var wa
    let rows: [SpStandingRowDto]
    var highlight: Set<Int> = []
    let onTeam: (SpTeamDto) -> Void

    var body: some View {
        WaCard(padding: 6) {
            HStack(spacing: 0) {
                Text(verbatim: "#").frame(width: 30, alignment: .leading)
                Text(L("sp_team")).frame(maxWidth: .infinity, alignment: .leading)
                ForEach(["sp_col_p", "sp_col_gd", "sp_col_pts"], id: \.self) { Text(L($0)).frame(width: 36) }
            }
            .font(.dorr(11, .bold)).foregroundStyle(wa.soft)
            .padding(.horizontal, 8).padding(.vertical, 6)
            ForEach(Array(rows.enumerated()), id: \.offset) { _, r in
                let me = r.team.map { highlight.contains($0.id) } ?? false
                Button { if let t = r.team { onTeam(t) } } label: {
                    HStack(spacing: 0) {
                        HStack(spacing: 4) {
                            Capsule().fill(spZoneColor(r.description) ?? .clear).frame(width: 3, height: 18)
                            Text(verbatim: String(r.rank ?? 0)).font(.dorr(13, .bold)).foregroundStyle(wa.ink)
                        }
                        .frame(width: 30, alignment: .leading)
                        Crest(team: r.team, size: 24)
                        Text(verbatim: r.team?.name ?? "").font(.dorr(13, me ? .heavy : .semibold)).foregroundStyle(wa.ink).lineLimit(1)
                            .padding(.leading, 8)
                        Spacer(minLength: 2)
                        Text(verbatim: r.trend == "up" ? "▲" : (r.trend == "down" ? "▼" : "")).font(.system(size: 9))
                            .foregroundStyle(r.trend == "up" ? spGreen : spLive).padding(.horizontal, 2)
                        Text(verbatim: String(r.played ?? 0)).font(.dorr(13)).foregroundStyle(wa.mut).frame(width: 36)
                        Text(verbatim: ((r.goalDiff ?? 0) > 0 ? "+" : "") + String(r.goalDiff ?? 0)).font(.dorr(13)).foregroundStyle(wa.mut).frame(width: 36)
                        Text(verbatim: String(r.points ?? 0)).font(.dorr(14, .black)).foregroundStyle(wa.ink).frame(width: 36)
                    }
                    .padding(.horizontal, 8)
                    .padding(.vertical, 7)
                    .background(me ? wa.red.opacity(0.08) : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
    }
}

func spZoneColor(_ description: String?) -> Color? {
    guard let d = description?.lowercased() else { return nil }
    if d.contains("relegation") { return Color(hex: 0xDC2626) }
    if d.contains("champions") || (d.contains("promotion") && !d.contains("play")) { return Color(hex: 0x2563EB) }
    if d.contains("europa") || d.contains("conference") || d.contains("afc") || d.contains("caf") { return Color(hex: 0xF59E0B) }
    if d.contains("play") { return Color(hex: 0x7C3AED) }
    return Color(hex: 0x16A34A)
}

/// A match with its date, for team and competition lists.
struct MatchDayRow: View {
    @Environment(\.wa) private var wa
    @ObservedObject private var live = SportsLive.shared
    let m: SpMatchDto
    let now: Date
    let onClick: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            if let d = evLocalDate(m.localDate) {
                Text(verbatim: dayText(d)).font(.dorr(11, .bold)).foregroundStyle(wa.soft).padding(.leading, 6)
            }
            MatchRow(m: m, now: now, hideScore: live.prefs.hideScores, onClick: onClick)
        }
        .padding(.bottom, 6)
    }
}

/// Fixed tabs on one row; the picked one is a filled pill.
struct SpTabs: View {
    @Environment(\.wa) private var wa
    let labels: [String]
    let selected: Int
    let onPick: (Int) -> Void

    var body: some View {
        HStack(spacing: 0) {
            ForEach(Array(labels.enumerated()), id: \.offset) { i, label in
                Button { onPick(i) } label: {
                    Text(verbatim: label).font(.dorr(13.5, .bold)).foregroundStyle(i == selected ? .white : wa.ink).lineLimit(1)
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 10)
                        .background(i == selected ? wa.red : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(4)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .animation(.easeInOut(duration: 0.2), value: selected)
    }
}

/// Tabs that scroll sideways (pages with many of them); the picked one is a filled pill.
struct ScrollTabs: View {
    @Environment(\.wa) private var wa
    let labels: [String]
    let selected: Int
    let onPick: (Int) -> Void

    var body: some View {
        ScrollViewReader { proxy in
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 6) {
                    ForEach(Array(labels.enumerated()), id: \.offset) { i, label in
                        Button { onPick(i) } label: {
                            Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(i == selected ? .white : wa.ink).lineLimit(1)
                                .padding(.horizontal, 16).padding(.vertical, 9)
                                .background(i == selected ? wa.red : wa.surface, in: Capsule())
                        }
                        .buttonStyle(.plain)
                        .id(i)
                    }
                }
            }
            .onChange(of: selected) { s in withAnimation { proxy.scrollTo(max(0, s - 1), anchor: .leading) } }
        }
        .animation(.easeInOut(duration: 0.2), value: selected)
    }
}

/// Why a part is empty: still on its way, or the data provider has none for it.
struct StateNote: View {
    @Environment(\.wa) private var wa
    let state: String?
    var empty = "spx_empty"

    var body: some View {
        HStack(spacing: 10) {
            Text(verbatim: state == "unavailable" ? "🔒" : (state == "pending" ? "⏳" : "ℹ️")).font(.system(size: 18))
            Text(L(state == "unavailable" ? "spx_unavailable" : (state == "pending" ? "spx_pending" : empty))).font(.dorr(13)).lineSpacing(4).foregroundStyle(wa.mut)
            Spacer(minLength: 0)
        }
        .padding(16)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

struct SkeletonRows: View {
    var count = 4
    var height: CGFloat = 56
    var body: some View {
        ForEach(0..<count, id: \.self) { _ in WaSkeleton(cornerRadius: 16).frame(height: height - 8).padding(.bottom, 8) }
    }
}

/// A round photo (player, coach) with initials while it loads or when there's none.
struct PhotoAvatar: View {
    @Environment(\.wa) private var wa
    let url: String?
    let name: String?
    let size: CGFloat
    var ring: Color?

    var body: some View {
        ZStack {
            wa.field
            Text(verbatim: spInitials(name)).font(.dorr(size * 0.34, .heavy)).foregroundStyle(wa.mut)
            if let link = ApiClient.mediaUrl(url) {
                AsyncImage(url: link) { phase in phase.image?.resizable().scaledToFill() }
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
        .overlay { if let ring { Circle().stroke(ring, lineWidth: 2) } }
    }
}

func spInitials(_ name: String?) -> String {
    let parts = (name ?? "").split(whereSeparator: { $0 == " " || $0 == "." }).prefix(2)
    let s = parts.map { String($0.prefix(1)) }.joined().uppercased()
    return s.isEmpty ? "?" : s
}

/// W · D · L squares from a form string ("WWDLW"), newest last.
struct FormStrip: View {
    let form: String?
    var size: CGFloat = 22
    var last = 5

    var body: some View {
        let letters = Array((form ?? "").filter { "WDL".contains($0) }.suffix(last))
        if !letters.isEmpty {
            HStack(spacing: 4) {
                ForEach(Array(letters.enumerated()), id: \.offset) { i, c in
                    Text(verbatim: spFormLetter(c)).font(.dorr(size * 0.5, .black)).foregroundStyle(.white)
                        .frame(width: size, height: size)
                        .background(spFormColor(c), in: RoundedRectangle(cornerRadius: size * 0.3, style: .continuous))
                        .waRise(i)
                }
            }
        }
    }
}

func spFormColor(_ c: Character) -> Color { c == "W" ? spGreen : (c == "L" ? spLive : Color(hex: 0x9CA3AF)) }

func spFormLetter(_ c: Character) -> String { c == "W" ? L("spx_w") : (c == "L" ? L("spx_l") : L("spx_d")) }

/// A white card with a title.
struct SectionCard<Content: View, Trailing: View>: View {
    @Environment(\.wa) private var wa
    let title: String?
    @ViewBuilder var trailing: () -> Trailing
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            if let title {
                HStack {
                    Text(verbatim: title).font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
                    Spacer()
                    trailing()
                }
                .padding(.bottom, 8)
            }
            content()
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        .padding(.top, 12)
    }
}

extension SectionCard where Trailing == EmptyView {
    init(title: String?, @ViewBuilder content: @escaping () -> Content) {
        self.init(title: title, trailing: { EmptyView() }, content: content)
    }
}

/// A label and its value on one line.
struct SpInfoLine: View {
    @Environment(\.wa) private var wa
    let emoji: String
    let label: String
    let value: String?

    var body: some View {
        if let value, !value.trimmingCharacters(in: .whitespaces).isEmpty {
            HStack(spacing: 0) {
                Text(verbatim: emoji).font(.system(size: 15)).frame(width: 28, alignment: .leading)
                Text(verbatim: label).font(.dorr(13)).foregroundStyle(wa.mut)
                Spacer(minLength: 8)
                Text(verbatim: value).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink).multilineTextAlignment(.trailing).lineLimit(2)
            }
            .padding(.vertical, 5)
        }
    }
}

/// A chip row to pick one of a few (a season, a competition, a kind of leader).
struct ChoiceChips: View {
    @Environment(\.wa) private var wa
    let options: [(String, String)]
    let selected: String?
    let onPick: (String) -> Void

    var body: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 6) {
                ForEach(options, id: \.0) { key, label in
                    let on = key == selected
                    Button { onPick(key) } label: {
                        Text(verbatim: label).font(.dorr(12.5, .bold)).foregroundStyle(on ? wa.red : wa.mut).lineLimit(1)
                            .padding(.horizontal, 13).padding(.vertical, 7)
                            .background(on ? wa.red.opacity(0.08) : .clear, in: Capsule())
                            .overlay(Capsule().stroke(on ? wa.red : wa.line, lineWidth: 1.5))
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }
}

/// A switch line (Material's Switch in the brand colour on Android).
struct SpToggleLine: View {
    @Environment(\.wa) private var wa
    let title: String
    let on: Bool
    var sub: String?
    let onChange: (Bool) -> Void

    var body: some View {
        HStack {
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: title).font(.dorr(14.5, .bold)).foregroundStyle(wa.ink)
                if let sub { Text(verbatim: sub).font(.dorr(12)).foregroundStyle(wa.mut) }
            }
            Spacer(minLength: 8)
            RedToggle(on: on) { onChange(!on) }
        }
        .padding(.vertical, 6)
    }
}

/// Follows or unfollows a team / competition; answers my follows after.
@MainActor
func spToggleFollow(_ kind: String, _ id: Int, _ on: Bool) async -> [SpFollowDto]? {
    if on {
        return try? await SportsApi.follow(["kind": .string(kind), "target_id": .int(id)]).data
    }
    let list = (try? await SportsApi.follows().data) ?? []
    guard let f = list.first(where: { $0.kind == kind && $0.target?.id == id }) else { return nil }
    return try? await SportsApi.unfollow(f.id).data
}
