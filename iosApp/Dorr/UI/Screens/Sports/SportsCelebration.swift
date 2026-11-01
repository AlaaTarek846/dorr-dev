import AVFoundation
import SwiftUI
import UIKit

// androidApp `sports/SportsCelebration.kt` + `SportsSounds` (SportsStore.kt) — the goal moment and the win
// celebration: calm = a card that bounces in; normal = the whole screen, confetti in the team's colours;
// festive = plus fireworks. Anything else is a quiet banner.

/// Short sounds made for Dorr (Resources/Sounds, the same files as Android's res/raw) — silent with the ring switch.
@MainActor
enum SportsSounds {
    enum Kind: String { case whistle = "sp_whistle", goal = "sp_horn", tick = "sp_tick", win = "sp_win" }

    private static var players: [String: AVAudioPlayer] = [:]

    static func play(_ kind: Kind) {
        try? AVAudioSession.sharedInstance().setCategory(.ambient, options: .mixWithOthers)
        start(kind.rawValue, volume: 0.9)
        // The crowd joins a goal or a win.
        if kind == .goal || kind == .win { start("sp_cheer", volume: 0.55) }
    }

    private static func start(_ name: String, volume: Float) {
        if players[name] == nil, let url = Bundle.main.url(forResource: name, withExtension: "wav") {
            players[name] = try? AVAudioPlayer(contentsOf: url)
        }
        guard let p = players[name] else { return }
        p.volume = volume
        p.currentTime = 0
        p.play()
    }
}

@MainActor
struct SportsCelebrationHost: View {
    @ObservedObject private var live = SportsLive.shared

    var body: some View {
        ZStack(alignment: .top) {
            if let c = live.celebration {
                if c.celebrate == "calm" {
                    CalmCard(a: c).transition(.opacity)
                } else {
                    BigMoment(a: c, festive: c.celebrate == "festive").transition(.opacity)
                }
            } else if let b = live.banner {
                SportsBanner(a: b).transition(.move(edge: .top).combined(with: .opacity))
            }
        }
        .animation(.easeInOut(duration: 0.25), value: live.celebration)
        .animation(.spring(response: 0.4, dampingFraction: 0.7), value: live.banner)
        .task(id: live.banner) {
            guard let b = live.banner else { return }
            try? await Task.sleep(nanoseconds: 3_800_000_000)
            if !Task.isCancelled, live.banner == b { live.banner = nil }
        }
        .task(id: live.celebration) {
            guard let c = live.celebration else { return }
            let delay: UInt64 = c.celebrate == "calm" ? 2_600_000_000 : (c.celebrate == "festive" ? 5_200_000_000 : 3_800_000_000)
            try? await Task.sleep(nanoseconds: delay)
            if !Task.isCancelled, live.celebration == c { live.celebration = nil }
        }
    }
}

private func headline(_ a: SportsAlert) -> String {
    a.type == "finished" ? L("sp_win") : (a.type == "prize" ? L("sp_prize_won") : L("sp_goal"))
}

@MainActor
private func teamsOf(_ a: SportsAlert) -> (Color, Color) {
    let m = SportsLive.shared.latest[a.matchId]
    let home = spTeamColor(m?.home?.color, m?.home?.id ?? 0)
    let away = spTeamColor(m?.away?.color, m?.away?.id ?? 1)
    return a.side == "away" ? (away, home) : (home, away)
}

@MainActor
private func subline(_ a: SportsAlert) -> String {
    let m = SportsLive.shared.latest[a.matchId]
    let team = a.side == "away" ? m?.away?.name : m?.home?.name
    return [team, a.scorer.map { s in a.minute.map { "\(s) \($0)'" } ?? s }].compactMap { $0 }.joined(separator: " · ")
}

@MainActor
private func scoreLine(_ a: SportsAlert) -> String? {
    guard let h = a.homeScore, let w = a.awayScore else { return nil }
    let m = SportsLive.shared.latest[a.matchId]
    return "\(m?.home?.name ?? "")  \(h) – \(w)  \(m?.away?.name ?? "")".trimmingCharacters(in: .whitespaces)
}

@MainActor
private struct BigMoment: View {
    let a: SportsAlert
    let festive: Bool
    @State private var pop: CGFloat = 0.2
    @State private var shake: CGFloat = 0
    @State private var start = Date()

    var body: some View {
        let (main, other) = teamsOf(a)
        ZStack {
            RadialGradient(colors: [main.opacity(0.92), Color(hex: 0x050814).opacity(0.94)], center: .center, startRadius: 0, endRadius: 470)
                .ignoresSafeArea()
                .onTapGesture { SportsLive.shared.celebration = nil }
            Confetti(start: start, colors: [main, other, .white, Color(hex: 0xFACC15)], count: festive ? 170 : 110)
            if festive { Fireworks(start: start, colors: [main, Color(hex: 0xFACC15), .white, other]) }
            VStack(spacing: 0) {
                Text(verbatim: a.type == "finished" || a.type == "prize" ? "🏆" : "⚽").font(.system(size: 64))
                    .scaleEffect(pop).rotationEffect(.degrees(Double(1 - pop) * -40))
                Text(verbatim: headline(a)).font(.dorr(54, .black)).foregroundStyle(.white).multilineTextAlignment(.center).scaleEffect(pop).padding(.top, 6)
                Text(verbatim: subline(a)).font(.dorr(18, .bold)).foregroundStyle(.white.opacity(0.92)).multilineTextAlignment(.center).padding(.horizontal, 24)
                    .opacity(Double(min(max(pop, 0), 1)))
                if let score = scoreLine(a) {
                    Text(verbatim: score).font(.dorr(17, .heavy)).foregroundStyle(.white).lineLimit(1)
                        .padding(.horizontal, 18).padding(.vertical, 8)
                        .background(Color.white.opacity(0.16), in: Capsule())
                        .padding(.top, 14)
                }
                if a.matchId != "preview" {
                    Button {
                        SportsLive.shared.celebration = nil
                        SportsLink.shared.show(a.matchId)
                    } label: {
                        Text(L("sp_view_match")).font(.dorr(14, .heavy)).foregroundStyle(main)
                            .padding(.horizontal, 22).padding(.vertical, 10)
                            .background(Color.white, in: Capsule())
                    }
                    .buttonStyle(.plain)
                    .padding(.top, 22)
                }
            }
            .offset(x: shake)
        }
        .onAppear {
            start = Date()
            withAnimation(.spring(response: 0.5, dampingFraction: 0.32)) { pop = 1 }
            // A short shake.
            for i in 0..<6 {
                withAnimation(.linear(duration: 0.055).delay(Double(i) * 0.055)) { shake = i % 2 == 0 ? 9 : -9 }
            }
            withAnimation(.linear(duration: 0.08).delay(0.33)) { shake = 0 }
        }
    }
}

private struct CalmCard: View {
    let a: SportsAlert
    @State private var pop: CGFloat = 0.6

    var body: some View {
        VStack {
            Button {
                SportsLive.shared.celebration = nil
                if a.matchId != "preview" { SportsLink.shared.show(a.matchId) }
            } label: {
                HStack(spacing: 12) {
                    Text(verbatim: a.type == "finished" ? "🏆" : "⚽").font(.system(size: 30))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: headline(a)).font(.dorr(20, .black)).foregroundStyle(.white)
                        Text(verbatim: [subline(a).isEmpty ? nil : subline(a), scoreLine(a)].compactMap { $0 }.joined(separator: "  ·  "))
                            .font(.dorr(13)).foregroundStyle(.white.opacity(0.92)).lineLimit(2)
                    }
                    Spacer(minLength: 0)
                }
                .padding(16)
                .background(LinearGradient(colors: [teamsOf(a).0, teamsOf(a).1], startPoint: .topLeading, endPoint: .bottomTrailing),
                            in: RoundedRectangle(cornerRadius: 24, style: .continuous))
            }
            .buttonStyle(.plain)
            .scaleEffect(pop)
            .padding(16)
            Spacer()
        }
        .onAppear { withAnimation(.spring(response: 0.45, dampingFraction: 0.45)) { pop = 1 } }
    }
}

/// Confetti bursting up from both bottom corners and fluttering down (the same physics as Android's).
private struct Confetti: View {
    let start: Date
    let colors: [Color]
    let count: Int
    @State private var bits: [(vx: Double, vy: Double, rot: Double, spin: Double, w: CGFloat, h: CGFloat, left: Bool)] = []

    var body: some View {
        TimelineView(.animation) { context in
            Canvas { gc, size in
                let t = context.date.timeIntervalSince(start)
                for (i, b) in bits.enumerated() {
                    // vy' = 1.1 (gravity), vx' = −0.6 vx (drag): integrated in closed form.
                    let decay = exp(-0.6 * t)
                    let x = (b.left ? 0 : 1) + b.vx * (1 - decay) / 0.6
                    let y = 1.02 + b.vy * t + 0.55 * t * t
                    guard y < 1.1 else { continue }
                    let cx = x * size.width, cy = y * size.height
                    var g = gc
                    g.translateBy(x: cx, y: cy)
                    g.rotate(by: .degrees(b.rot + b.spin * t))
                    g.fill(Path(CGRect(x: -b.w / 2, y: -b.h / 2, width: b.w, height: b.h)), with: .color(colors[i % colors.count]))
                }
            }
        }
        .allowsHitTesting(false)
        .ignoresSafeArea()
        .onAppear {
            bits = (0..<count).map { i in
                let left = i % 2 == 0
                return ((left ? 1 : -1) * Double.random(in: 0...0.55) + (left ? 0.08 : -0.08), -(0.9 + Double.random(in: 0...0.75)),
                        Double.random(in: 0...360), Double.random(in: -360...360), CGFloat.random(in: 2...5), CGFloat.random(in: 3.5...8), left)
            }
        }
    }
}

/// A few bursts of sparks (festive).
private struct Fireworks: View {
    let start: Date
    let colors: [Color]
    @State private var bursts: [(x: CGFloat, y: CGFloat, start: Double)] = []

    var body: some View {
        TimelineView(.animation) { context in
            Canvas { gc, size in
                // FastOutSlowIn over 4.2 s, like the Android burst clock.
                let raw = min(context.date.timeIntervalSince(start) / 4.2, 1)
                let t = fastOutSlowIn(raw)
                for (i, b) in bursts.enumerated() {
                    let p = min(max((t - b.start) / 0.45, 0), 1)
                    guard p > 0, p < 1 else { continue }
                    let r = p * min(size.width, size.height) * 0.22
                    for k in 0..<18 {
                        let a = Double(k) / 18 * 2 * .pi
                        let cx = b.x * size.width + cos(a) * r
                        let cy = b.y * size.height + sin(a) * r + p * p * 22
                        let dot = 2 * (1 - p * 0.5)
                        gc.fill(Path(ellipseIn: CGRect(x: cx - dot, y: cy - dot, width: dot * 2, height: dot * 2)), with: .color(colors[i % colors.count].opacity(1 - p)))
                    }
                }
            }
        }
        .allowsHitTesting(false)
        .ignoresSafeArea()
        .onAppear { bursts = (0..<5).map { (CGFloat.random(in: 0.15...0.85), CGFloat.random(in: 0.12...0.47), Double($0) * 0.16) } }
    }
}

/// A quiet line for a match I follow (the other team scored, a red card, full time…); a tap opens the match.
private struct SportsBanner: View {
    let a: SportsAlert

    var body: some View {
        let m = SportsLive.shared.latest[a.matchId]
        let title = [m?.home?.name, m?.away?.name].compactMap { $0 }.joined(separator: " – ")
        let text: String = {
            switch a.type {
            case "goal", "goal_detail": return L("sp_banner_goal", [a.scorer, a.minute.map { "\($0)'" }].compactMap { $0 }.joined(separator: " "))
            case "red_card": return L("sp_banner_red", a.scorer ?? "")
            case "kickoff": return L("sp_banner_kickoff")
            case "half_time": return L("sp_ht")
            case "finished": return L("sp_ft")
            case "lineups": return L("sp_banner_lineups")
            case "reminder": return L("sp_banner_reminder")
            default: return L("sp_banner_update")
            }
        }()
        let icon: String = {
            switch a.type {
            case "red_card": return "🟥"
            case "kickoff", "half_time", "finished": return "⏱️"
            case "lineups": return "📋"
            case "reminder": return "⏰"
            default: return "⚽"
            }
        }()
        Button {
            SportsLive.shared.banner = nil
            SportsLink.shared.show(a.matchId)
        } label: {
            HStack(spacing: 10) {
                Text(verbatim: icon).font(.system(size: 22))
                VStack(alignment: .leading, spacing: 1) {
                    Text(verbatim: title.isEmpty ? L("sp_title") : title).font(.dorr(14, .heavy)).foregroundStyle(.white).lineLimit(1)
                    Text(verbatim: text).font(.dorr(12.5)).foregroundStyle(.white.opacity(0.85)).lineLimit(1)
                }
                Spacer(minLength: 0)
                if !a.hidden, let h = a.homeScore, let w = a.awayScore {
                    Text(verbatim: "\(h) – \(w)").font(.dorr(18, .black)).foregroundStyle(.white).environment(\.layoutDirection, .leftToRight)
                }
            }
            .padding(.horizontal, 14).padding(.vertical, 12)
            .background(Color(hex: 0x0B1220).opacity(0.94), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        }
        .buttonStyle(.plain)
        .padding(.horizontal, 14).padding(.vertical, 8)
    }
}
