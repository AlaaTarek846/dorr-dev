import SwiftUI

// androidApp `sports/SportsPlay.kt` MatchCardBubble — the card in a chat.

/// A match shared in a chat: both crests, kick-off or the live score (from Pusher), opens the match.
struct MatchCardBubble<Footer: View>: View {
    @ObservedObject private var live = SportsLive.shared
    let dto: MessageDto
    @ViewBuilder let footer: () -> Footer

    var body: some View {
        if let snap = dto.meta?["match"], let id = snap["id"]?.str {
            let home = team(snap["home"])
            let away = team(snap["away"])
            let latest = live.latest[id]
            let started = latest.map { ["live", "break", "finished"].contains($0.state) } ?? false
            let hide = live.prefs.hideScores
            let start = parseRideTime(snap["starts_at"]?.str)
            VStack(spacing: 0) {
                Button { SportsLink.shared.show(id) } label: {
                    VStack(spacing: 0) {
                        Text(verbatim: snap["competition"]?.str ?? "").font(.dorr(11, .bold)).foregroundStyle(.white.opacity(0.8)).lineLimit(1)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        HStack(spacing: 0) {
                            side(home)
                            VStack(spacing: 2) {
                                if started && !hide, let m = latest {
                                    HStack(spacing: 0) {
                                        FlipScore(value: m.homeScore, size: 22, color: .white)
                                        Text(verbatim: " : ").font(.dorr(18)).foregroundStyle(.white.opacity(0.7))
                                        FlipScore(value: m.awayScore, size: 22, color: .white)
                                    }
                                    .environment(\.layoutDirection, .leftToRight)
                                    SpTicker { now in StatusChip(m: m, now: now, onDark: true) }
                                } else {
                                    Text(verbatim: start.map { format($0, "HH:mm") } ?? "").font(.dorr(20, .black)).foregroundStyle(.white)
                                    Text(verbatim: start.map { format($0, "d MMM") } ?? "").font(.dorr(11)).foregroundStyle(.white.opacity(0.75))
                                }
                            }
                            .frame(width: 80)
                            side(away)
                        }
                        .padding(.top, 8)
                        Text(L("sp_view_match")).font(.dorr(12.5, .heavy)).foregroundStyle(.white)
                            .frame(maxWidth: .infinity).padding(.vertical, 7)
                            .background(Color.white.opacity(0.16), in: RoundedRectangle(cornerRadius: 12))
                            .padding(.top, 10)
                    }
                    .padding(14)
                    .background(LinearGradient(colors: [spTeamColor(home?.color, home?.id ?? 0), Color(hex: 0x0B1220), spTeamColor(away?.color, away?.id ?? 1)],
                                               startPoint: .topLeading, endPoint: .bottomTrailing))
                    .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
                }
                .buttonStyle(.plain)
                HStack { Spacer(); footer() }.padding(.horizontal, 6)
            }
            .frame(width: 262)
        }
    }

    private func side(_ t: SpTeamDto?) -> some View {
        VStack(spacing: 2) {
            Crest(team: t, size: 40, plate: .white)
            Text(verbatim: t?.name ?? "").font(.dorr(11.5, .bold)).foregroundStyle(.white).lineLimit(1)
        }
        .frame(maxWidth: .infinity)
    }

    private func team(_ t: JSONAny?) -> SpTeamDto? {
        guard let t, case .object = t else { return nil }
        return SpTeamDto(id: t["id"]?.int ?? 0, name: t["name"]?.str, logo: t["logo"]?.str, color: t["color"]?.str)
    }

    private func format(_ d: Date, _ pattern: String) -> String {
        let f = DateFormatter()
        f.locale = AppLocale.locale
        f.dateFormat = pattern
        return f.string(from: d)
    }
}
