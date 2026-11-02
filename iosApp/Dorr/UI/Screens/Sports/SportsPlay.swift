import SwiftUI

// androidApp `sports/SportsPlay.kt` — predictions, contests and prizes, the community rating. Predicting is free;
// prizes reach only where they're allowed. (Sharing a match into a chat and watching together join with Chat.)

let spGold = Color(hex: 0xF59E0B)
let spGoldGradient = LinearGradient(colors: [Color(hex: 0xF59E0B), Color(hex: 0xB45309), Color(hex: 0x78350F)], startPoint: .topLeading, endPoint: .bottomTrailing)

func spMoney(_ minor: Int?, _ currency: String?) -> String {
    guard let minor else { return "" }
    return String(format: "%.2f", Double(minor) / 100) + (currency.map { " \($0)" } ?? "")
}

func spPrizeLine(_ c: SpContestDto) -> String {
    switch c.prizeType {
    case "badge": return L("sp_prize_badge")
    case "coupon": return c.prizePercent.map { L("sp_prize_coupon_pct", $0) } ?? L("sp_prize_coupon", spMoney(c.prizeAmountMinor, c.currencyCode))
    default: return L("sp_prize_wallet", spMoney(c.prizeAmountMinor, c.currencyCode))
    }
}

/// On the match page: predict (until kick-off), what the crowd thinks, the contests on it, the rating after.
@MainActor
struct PlayCard: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    let onContest: (String) -> Void
    @State private var play: SpPlayDto?
    @State private var home = 1
    @State private var away = 0
    @State private var busy = false

    var body: some View {
        Group {
            if let p = play, p.enabled ?? true {
                let hColor = spTeamColor(m.home?.color, m.home?.id ?? 0)
                let aColor = spTeamColor(m.away?.color, m.away?.id ?? 1)
                WaCard(padding: 14) {
                    HStack(spacing: 8) {
                        Text(verbatim: "🔮").font(.system(size: 20))
                        Text(L("sp_predict_title")).font(.dorr(16, .heavy)).foregroundStyle(wa.ink)
                        Spacer()
                        Text(L("sp_free")).font(.dorr(11, .heavy)).foregroundStyle(spGreen)
                            .padding(.horizontal, 8).padding(.vertical, 3)
                            .background(spGreen.opacity(0.12), in: Capsule())
                    }
                    if !(p.locked ?? false) {
                        HStack(spacing: 0) {
                            Stepper(team: m.home, value: home, color: hColor) { home = $0 }
                            Text(verbatim: "–").font(.dorr(26, .black)).foregroundStyle(wa.soft).padding(.horizontal, 6)
                            Stepper(team: m.away, value: away, color: aColor) { away = $0 }
                        }
                        .padding(.top, 12)
                        WaButton(text: L(p.mine == nil ? "sp_predict_save" : "sp_predict_update"), loading: busy, action: predict).padding(.top, 12)
                        Text(L("sp_predict_locks")).font(.dorr(11.5)).foregroundStyle(wa.soft).padding(.top, 6)
                    } else if let mine = p.mine {
                        HStack(spacing: 0) {
                            Text(L("sp_my_prediction")).font(.dorr(13)).foregroundStyle(wa.mut)
                            Spacer()
                            Text(verbatim: mine.homeScore != nil ? "\(mine.homeScore!) – \(mine.awayScore ?? 0)"
                                 : L(mine.winner == "home" ? "sp_pick_home" : (mine.winner == "away" ? "sp_pick_away" : "sp_pick_draw")))
                                .font(.dorr(16, .black)).foregroundStyle(wa.ink)
                            if let pts = mine.points {
                                Text(verbatim: mine.result == "void" ? L("sp_void") : "+\(pts)").font(.dorr(13, .black)).foregroundStyle(.white)
                                    .padding(.horizontal, 10).padding(.vertical, 4)
                                    .background(pts >= 3 ? spGold : (pts > 0 ? spGreen : wa.soft), in: Capsule())
                                    .padding(.leading, 10)
                            }
                        }
                        .padding(12)
                        .background(wa.field, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        .padding(.top, 10)
                    }
                    if let c = p.crowd, (c.count ?? 0) > 0 {
                        Text(verbatim: L("sp_crowd", c.count ?? 0)).font(.dorr(12, .bold)).foregroundStyle(wa.mut).padding(.top, 12).padding(.bottom, 6)
                        CrowdBar(home: c.home ?? 0, draw: c.draw ?? 0, away: c.away ?? 0, hColor: hColor, aColor: aColor)
                        HStack {
                            Text(verbatim: "\(c.home ?? 0)%").font(.dorr(12, .heavy)).foregroundStyle(hColor).frame(maxWidth: .infinity, alignment: .leading)
                            Text(verbatim: L("sp_pick_draw") + " \(c.draw ?? 0)%").font(.dorr(12, .bold)).foregroundStyle(wa.mut).frame(maxWidth: .infinity)
                            Text(verbatim: "\(c.away ?? 0)%").font(.dorr(12, .heavy)).foregroundStyle(aColor).frame(maxWidth: .infinity, alignment: .trailing)
                        }
                        .padding(.top, 4)
                        if let top = c.topScore { Text(verbatim: L("sp_top_score", top)).font(.dorr(11.5)).foregroundStyle(wa.soft).padding(.top, 4) }
                    }
                    ForEach(p.contests ?? []) { c in
                        Button { onContest(c.id) } label: {
                            HStack(spacing: 10) {
                                Text(verbatim: "🏆").font(.system(size: 22))
                                VStack(alignment: .leading, spacing: 0) {
                                    Text(verbatim: c.name ?? "").font(.dorr(14, .heavy)).foregroundStyle(.white).lineLimit(1)
                                    Text(verbatim: c.prizesHere == true ? spPrizeLine(c) : L("sp_prize_not_here")).font(.dorr(12)).foregroundStyle(.white.opacity(0.9))
                                }
                                Spacer(minLength: 0)
                            }
                            .padding(12)
                            .background(spGoldGradient, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                        }
                        .buttonStyle(.plain)
                        .padding(.top, 10)
                    }
                    if m.state == "finished" { RatingRow(m: m, p: p) { play = $0 } }
                }
                .padding(.top, 12)
            }
        }
        .task(id: "\(m.id)|\(m.state)") {
            play = try? await SportsApi.play(m.id).data
            if let mine = play?.mine {
                if let h = mine.homeScore { home = h }
                if let a = mine.awayScore { away = a }
            }
        }
    }

    private func predict() {
        busy = true
        Task {
            do {
                play = try await SportsApi.predict(m.id, ["home_score": .int(home), "away_score": .int(away)]).data
                AppToast.show(L("sp_predicted"))
            } catch {
                AppToast.show(error.apiFailure.message ?? L("wa_error_generic"), long: true)
            }
            busy = false
        }
    }
}

private struct Stepper: View {
    @Environment(\.wa) private var wa
    let team: SpTeamDto?
    let value: Int
    let color: Color
    let onChange: (Int) -> Void

    var body: some View {
        VStack(spacing: 0) {
            Crest(team: team, size: 34)
            Text(verbatim: team?.name ?? "").font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(1).padding(.vertical, 4)
            HStack(spacing: 0) {
                step("−", enabled: value > 0) { onChange(value - 1) }
                FlipScore(value: value, size: 30, color: color).padding(.horizontal, 12)
                step("+", enabled: value < 20) { onChange(value + 1) }
            }
            .environment(\.layoutDirection, .leftToRight)
        }
        .frame(maxWidth: .infinity)
    }

    private func step(_ label: String, enabled: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(18, .black)).foregroundStyle(enabled ? wa.ink : wa.soft)
                .frame(width: 34, height: 34)
                .background(enabled ? wa.field : wa.field.opacity(0.4), in: Circle())
        }
        .buttonStyle(.plain)
        .allowsHitTesting(enabled)
    }
}

private struct CrowdBar: View {
    @Environment(\.wa) private var wa
    let home: Int
    let draw: Int
    let away: Int
    let hColor: Color
    let aColor: Color
    @State private var grow: CGFloat = 0

    var body: some View {
        GeometryReader { p in
            HStack(spacing: 0) {
                ForEach(Array([(home, hColor), (draw, Color(hex: 0x94A3B8)), (away, aColor)].enumerated()), id: \.offset) { _, item in
                    item.1.frame(width: p.size.width * CGFloat(item.0) / 100 * grow)
                }
                Spacer(minLength: 0)
            }
        }
        .frame(height: 10)
        .background(wa.field)
        .clipShape(Capsule())
        .onAppear { withAnimation(.easeOut(duration: 0.8)) { grow = 1 } }
    }
}

/// After the whistle: how fun, how exciting — a community rating, not an official one.
@MainActor
private struct RatingRow: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    let p: SpPlayDto
    let onSaved: (SpPlayDto) -> Void
    @State private var fun = 0
    @State private var ex = 0

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(L("sp_rate_title")).font(.dorr(14.5, .heavy)).foregroundStyle(wa.ink)
            stars(L("sp_rate_fun"), fun) { fun = $0; save() }
            stars(L("sp_rate_ex"), ex) { ex = $0; save() }
            if let r = p.rating, (r.count ?? 0) > 0 {
                Text(verbatim: L("sp_rate_community", r.fun ?? 0, r.excitement ?? 0, r.count ?? 0)).font(.dorr(11.5)).foregroundStyle(wa.soft).padding(.top, 4)
            }
        }
        .padding(.top, 14)
        .onAppear {
            fun = p.rating?.mine?.fun ?? 0
            ex = p.rating?.mine?.excitement ?? 0
        }
    }

    private func stars(_ label: String, _ value: Int, _ pick: @escaping (Int) -> Void) -> some View {
        HStack(spacing: 0) {
            Text(verbatim: label).font(.dorr(13)).foregroundStyle(wa.mut).frame(width: 90, alignment: .leading)
            ForEach(1...5, id: \.self) { i in
                Button { pick(i) } label: {
                    Text(verbatim: "★").font(.system(size: 26)).foregroundStyle(i <= value ? spGold : wa.line).padding(.horizontal, 2)
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.top, 6)
        .animation(.easeInOut(duration: 0.2), value: value)
    }

    private func save() {
        guard fun > 0, ex > 0 else { return }
        Task { if let saved = try? await SportsApi.rate(m.id, ["fun": .int(fun), "excitement": .int(ex)]).data { onSaved(saved) } }
    }
}

// MARK: - Contests & prizes

@MainActor
struct ContestsPage: View {
    let onBack: () -> Void
    let onOpen: (String) -> Void
    let onPrizes: () -> Void
    @State private var rows: [SpContestDto]?

    var body: some View {
        WaPage(title: L("sp_contests"), onBack: onBack, actions: {
            Button(action: onPrizes) { Text(verbatim: "🎁").font(.system(size: 22)).padding(8) }.buttonStyle(.plain)
        }) {
            WaNote(text: L("sp_contests_note")).waRise(0)
            Spacer().frame(height: 12)
            if let list = rows {
                if list.isEmpty {
                    WaCard { WaEmpty(icon: "trophy.fill", tone: .amber, title: L("sp_no_contests"), text: L("sp_no_contests_text")) }
                } else {
                    ForEach(Array(list.enumerated()), id: \.element.id) { i, c in
                        ContestCard(c: c) { onOpen(c.id) }.padding(.bottom, 10).waRise(min(i, 6))
                    }
                }
            } else {
                ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 86).padding(.bottom, 10) }
            }
            Spacer().frame(height: 24)
        }
        .task { rows = (try? await SportsApi.contests().data) ?? [] }
    }
}

private struct ContestCard: View {
    let c: SpContestDto
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(spacing: 12) {
                    Text(verbatim: "🏆").font(.system(size: 28))
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: c.name ?? "").font(.dorr(16, .heavy)).foregroundStyle(.white)
                        Text(verbatim: [c.match, c.competition, c.round].compactMap { $0 }.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(.white.opacity(0.85)).lineLimit(1)
                    }
                    Spacer(minLength: 0)
                }
                HStack(spacing: 6) {
                    Text(verbatim: c.prizesHere == true ? spPrizeLine(c) : L("sp_prize_not_here")).font(.dorr(12, .heavy)).foregroundStyle(Color(hex: 0x78350F))
                        .padding(.horizontal, 10).padding(.vertical, 5)
                        .background(Color.white, in: Capsule())
                    Text(L(c.rule == "exact" || c.rule == nil ? "sp_rule_exact" : (c.rule == "winner" ? "sp_rule_winner" : "sp_rule_points"))).font(.dorr(12, .bold)).foregroundStyle(.white)
                        .padding(.horizontal, 10).padding(.vertical, 5)
                        .background(Color.white.opacity(0.18), in: Capsule())
                }
            }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(spGoldGradient, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

@MainActor
struct ContestPage: View {
    @Environment(\.wa) private var wa
    let id: String
    let onBack: () -> Void
    let onMatch: (String) -> Void
    @State private var c: SpContestDto?

    var body: some View {
        WaPage(title: c?.name ?? L("sp_contests"), onBack: onBack) {
            if let x = c {
                ContestCard(c: x) {}.waRise(0)
                if let prize = x.myPrize {
                    let amount = spMoney(prize.amountMinor, prize.currencyCode)
                    WaNote(text: L("sp_you_won", amount.isEmpty ? "🏅" : amount)).padding(.top, 10)
                }
                HStack {
                    Text(L("sp_my_points")).font(.dorr(13)).foregroundStyle(wa.mut)
                    Spacer()
                    Text(verbatim: String(x.myPoints ?? 0)).font(.dorr(22, .black)).foregroundStyle(wa.ink)
                }
                .padding(14)
                .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                .padding(.top, 12)
                if let board = x.leaderboard, !board.isEmpty {
                    WaSectionTitle(title: L("sp_leaderboard"))
                    WaCard(padding: 8) {
                        ForEach(Array(board.enumerated()), id: \.offset) { _, r in
                            HStack(spacing: 0) {
                                Text(verbatim: r.rank == 1 ? "🥇" : (r.rank == 2 ? "🥈" : (r.rank == 3 ? "🥉" : String(r.rank ?? 0)))).font(.dorr(15)).frame(width: 32)
                                Text(verbatim: r.name ?? "").font(.dorr(14, r.me == true ? .heavy : .semibold)).foregroundStyle(wa.ink).lineLimit(1)
                                Spacer()
                                Text(verbatim: String(r.points ?? 0)).font(.dorr(15, .black)).foregroundStyle(wa.ink)
                            }
                            .padding(.horizontal, 8).padding(.vertical, 7)
                            .background(r.me == true ? wa.red.opacity(0.08) : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                        }
                    }
                }
                if let matches = x.matches, !matches.isEmpty {
                    WaSectionTitle(title: L("sp_contest_matches"))
                    SpTicker { now in
                        VStack(spacing: 0) { ForEach(matches) { m in MatchDayRow(m: m, now: now) { onMatch(m.id) } } }
                    }
                }
                if let terms = x.terms, !terms.trimmingCharacters(in: .whitespaces).isEmpty {
                    WaSectionTitle(title: L("sp_terms"))
                    Text(verbatim: terms).font(.dorr(12.5)).foregroundStyle(wa.mut)
                }
                Spacer().frame(height: 24)
            } else {
                WaSkeleton(cornerRadius: 22).frame(height: 140)
            }
        }
        .task(id: id) { c = try? await SportsApi.contest(id, timezone: spZone()).data }
    }
}

@MainActor
struct PrizesPage: View {
    @Environment(\.wa) private var wa
    let onBack: () -> Void
    @State private var rows: [SpPrizeDto]?

    var body: some View {
        WaPage(title: L("sp_my_prizes"), onBack: onBack) {
            if let list = rows {
                if list.isEmpty {
                    WaCard { WaEmpty(icon: "trophy.fill", tone: .amber, title: L("sp_no_prizes"), text: L("sp_no_prizes_text")) }
                } else {
                    ForEach(Array(list.enumerated()), id: \.offset) { _, w in
                        HStack(spacing: 12) {
                            Text(verbatim: w.prizeType == "coupon" ? "🎟️" : (w.prizeType == "badge" ? "🏅" : "💰")).font(.system(size: 26))
                            VStack(alignment: .leading, spacing: 0) {
                                Text(verbatim: w.name ?? "").font(.dorr(14.5, .bold)).foregroundStyle(wa.ink)
                                Text(L(w.status == "paid" ? "sp_prize_paid" : (w.status == "review" ? "sp_prize_review" : (w.status == "rejected" ? "sp_prize_rejected" : "sp_prize_pending"))))
                                    .font(.dorr(12, .bold)).foregroundStyle(w.status == "paid" ? spGreen : wa.mut)
                            }
                            Spacer()
                            Text(verbatim: spMoney(w.amountMinor, w.currencyCode)).font(.dorr(15, .black)).foregroundStyle(wa.ink)
                        }
                        .padding(14)
                        .background(wa.surface, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                        .padding(.bottom, 8)
                    }
                }
            } else {
                WaSkeleton(cornerRadius: 18).frame(height: 80)
            }
            Spacer().frame(height: 24)
        }
        .task { rows = (try? await SportsApi.prizes().data) ?? [] }
    }
}

// MARK: - Share & watch together (195)

@MainActor
struct ShareMatchSheet: View {
    @Environment(\.wa) private var wa
    let m: SpMatchDto
    let onDismiss: () -> Void
    @State private var chats: [ConversationDto]?
    @State private var picked: String?
    @State private var poll: Bool
    @State private var busy = false

    init(m: SpMatchDto, onDismiss: @escaping () -> Void) {
        self.m = m
        self.onDismiss = onDismiss
        _poll = State(initialValue: m.status == "scheduled")
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(L("sp_share_title")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink).padding(.bottom, 10)
            if let list = chats {
                ScrollView {
                    LazyVStack(spacing: 0) {
                        ForEach(list) { c in
                            let on = picked == c.id
                            Button { picked = c.id } label: {
                                HStack(spacing: 10) {
                                    ChAvatar(url: c.avatar, name: c.title, key: c.peer?.key ?? c.id, size: 40, isGroup: c.isGroup)
                                    Text(verbatim: c.title ?? "").font(.dorr(14.5, .bold)).foregroundStyle(wa.ink).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                                    Image(systemName: on ? "checkmark.circle.fill" : "circle").font(.system(size: 22)).foregroundStyle(on ? wa.red : wa.soft)
                                }
                                .padding(.vertical, 8).padding(.horizontal, 4).contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
                .frame(maxHeight: 320)
            } else {
                WaSkeleton(cornerRadius: 16).frame(height: 120)
            }
            if m.status == "scheduled" { SpToggleLine(title: L("sp_share_poll"), on: poll) { poll = $0 } }
            WaButton(text: L("sp_share_send"), icon: "paperplane.fill", enabled: picked != nil, loading: busy, action: send).padding(.top, 10)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 20).padding(.top, 24).padding(.bottom, 24)
        .task { chats = ((try? await ChatApi.conversations(perPage: 60).data) ?? []).filter { $0.canSend != false } }
    }

    private func send() {
        guard let target = picked else { return }
        busy = true
        Task {
            do {
                _ = try await SportsApi.share(m.id, ["conversation_id": .string(target), "poll": .bool(poll)])
                AppToast.show(L("sp_shared"))
                onDismiss()
            } catch { if let message = error.apiFailure.message { AppToast.show(message, long: true) } }
            busy = false
        }
    }
}

func makeRoom(_ m: SpMatchDto, _ members: [Int]) async -> String? {
    (try? await SportsApi.room(m.id, ["members": .array(members.map { .int($0) })]).data)??["conversation"]?["id"]?.str
}
