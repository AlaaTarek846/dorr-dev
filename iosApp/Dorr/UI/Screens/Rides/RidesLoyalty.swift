import SwiftUI
import UIKit

// androidApp `rides/RidesLoyalty.kt` — DORR points (docs/rides_tasks.md R-146..R-155): the balance and tier with the
// way to the next one, the tiers side by side, rewards to spend the points on, challenges, badges and the history. One
// catch-up on open, and each `loyalty.*` real-time event reloads it.

private let gold = Color(hex: 0xE0A526)
private let goldDeep = Color(hex: 0xB7791F)

private enum LoyaltyTab: CaseIterable {
    case overview, rewards, challenges, badges, history

    var label: String {
        switch self {
        case .overview: return L("loy_tab_overview")
        case .rewards: return L("loy_tab_rewards")
        case .challenges: return L("loy_tab_challenges")
        case .badges: return L("loy_tab_badges")
        case .history: return L("loy_tab_history")
        }
    }
}

struct LoyaltyScreen: View {
    @Environment(\.wa) private var wa
    let onClose: () -> Void
    @State private var data: LoyaltyDashboardDto?
    @State private var rewards: [LoyaltyRewardDto] = []
    @State private var tab = LoyaltyTab.overview
    @State private var error: String?

    private func reload() async {
        do { data = try await LoyaltyApi.dashboard().data } catch { self.error = error.apiFailure.message }
        if let r = try? await LoyaltyApi.rewards().data { rewards = r }
    }

    var body: some View {
        VStack(spacing: 0) {
            LoyaltyHero(d: data, onClose: onClose)
            if let d = data {
                if d.enabled != true {
                    Text(L("loy_off")).font(.dorr(15)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).padding(32)
                    Spacer()
                } else {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 8) {
                            ForEach(LoyaltyTab.allCases, id: \.self) { t in
                                let on = t == tab
                                Button { tab = t } label: {
                                    Text(verbatim: t.label).font(.dorr(13, .bold)).foregroundStyle(on ? .white : wa.settingsInk)
                                        .padding(.horizontal, 16).padding(.vertical, 9).background(on ? wa.accent : wa.settingsCard, in: Capsule())
                                }
                                .buttonStyle(.plain)
                            }
                        }
                        .padding(.horizontal, 14).padding(.vertical, 10)
                    }
                    ScrollView {
                        VStack(alignment: .leading, spacing: 12) {
                            switch tab {
                            case .overview: overview(d)
                            case .rewards: RewardsList(list: rewards, balance: d.balance ?? 0) { Task { await reload() } }
                            case .challenges:
                                if (d.challenges ?? []).isEmpty { empty("loy_no_challenges") } else { ForEach(d.challenges ?? [], id: \.id) { ChallengeCard(c: $0) } }
                            case .badges:
                                if (d.badges ?? []).isEmpty { empty("loy_no_badges") } else { badgeGrid(d.badges ?? []) }
                            case .history: LoyaltyHistory()
                            }
                        }
                        .padding(.horizontal, 16).padding(.bottom, 24)
                        .id(tab)
                        .transition(.opacity)
                    }
                    .animation(.easeInOut(duration: 0.2), value: tab)
                }
            } else {
                Group {
                    if let error { Text(verbatim: error).font(.dorr(14)).foregroundStyle(AppColors.danger) } else { ProgressView().tint(wa.accent) }
                }
                .padding(40)
                Spacer()
            }
        }
        .background(wa.settingsBackground.ignoresSafeArea())
        .task { await reload() }
        // Each move arrives as an event: one reload (nothing on a timer).
        .onReceive(ChatRealtime.shared.events.receive(on: DispatchQueue.main)) { e in if e.name.hasPrefix("loyalty.") { Task { await reload() } } }
    }

    @ViewBuilder
    private func overview(_ d: LoyaltyDashboardDto) -> some View {
        // How points come.
        HStack(spacing: 12) {
            Image(systemName: "star.fill").foregroundStyle(goldDeep).frame(width: 42, height: 42).background(gold.opacity(0.16), in: Circle())
            VStack(alignment: .leading, spacing: 0) {
                Text(L("loy_how_title")).font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                Text(verbatim: L("loy_how_body", d.pointsPerUnit ?? 1)).font(.dorr(12.5)).foregroundStyle(wa.settingsMut)
            }
            Spacer(minLength: 0)
        }
        .padding(14)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        // R-154: the tiers side by side.
        if let tiers = d.tiers, !tiers.isEmpty {
            Text(L("loy_tiers")).font(.dorr(16, .black)).foregroundStyle(wa.settingsInk)
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(alignment: .top, spacing: 10) { ForEach(tiers, id: \.id) { TierCard(t: $0) } }
            }
        }
        if let recent = d.recent, !recent.isEmpty {
            Text(L("loy_recent")).font(.dorr(16, .black)).foregroundStyle(wa.settingsInk)
            VStack(spacing: 0) { ForEach(recent) { EntryRow(e: $0) } }
                .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        }
    }

    private func badgeGrid(_ badges: [LoyaltyBadgeDto]) -> some View {
        LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 10), count: 3), spacing: 10) {
            ForEach(Array(badges.enumerated()), id: \.offset) { i, b in BadgeTile(b: b, index: i) }
        }
    }

    private func empty(_ key: String) -> some View {
        Text(L(key)).font(.dorr(14)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).frame(maxWidth: .infinity).padding(28)
    }
}

/// The balance (counting up), the tier and the bar to the next one, the committed rider's bonus.
private struct LoyaltyHero: View {
    @Environment(\.wa) private var wa
    let d: LoyaltyDashboardDto?
    let onClose: () -> Void
    @State private var counted: Double = 0
    @State private var bar: Double = 0

    var body: some View {
        let tierColor = hexColor(d?.tier?.color) ?? gold
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                Button(action: onClose) {
                    Image(systemName: "arrow.backward").font(.system(size: 17, weight: .semibold)).foregroundStyle(.white)
                        .frame(width: 40, height: 40).background(Color.white.opacity(0.18), in: Circle())
                }
                .buttonStyle(.plain)
                Text(L("loy_title")).font(.dorr(20, .black)).foregroundStyle(.white)
                Spacer()
                if let tier = d?.tier {
                    HStack(spacing: 4) {
                        Image(systemName: "rosette").font(.system(size: 14)).foregroundStyle(tierColor)
                        Text(verbatim: tier.name ?? "").font(.dorr(13, .bold)).foregroundStyle(.white)
                    }
                    .padding(.horizontal, 12).padding(.vertical, 6).background(Color.white.opacity(0.2), in: Capsule())
                }
            }
            Text(L("loy_balance")).font(.dorr(13)).foregroundStyle(.white.opacity(0.8)).padding(.top, 18)
            HStack(alignment: .lastTextBaseline, spacing: 6) {
                Text(verbatim: Int(counted).formatted()).font(.dorr(40, .black)).foregroundStyle(.white)
                    .modifier(CountingText(value: counted))
                Text(L("loy_points")).font(.dorr(15)).foregroundStyle(.white.opacity(0.85))
            }
            if let dd = d, dd.enabled == true {
                GeometryReader { proxy in
                    ZStack(alignment: .leading) {
                        Capsule().fill(Color.white.opacity(0.22))
                        Capsule().fill(LinearGradient(colors: [gold, .white], startPoint: .leading, endPoint: .trailing))
                            .frame(width: proxy.size.width * CGFloat(min(max(bar, 0.02), 1)))
                    }
                }
                .frame(height: 10)
                .padding(.top, 10)
                Text(verbatim: dd.nextTier.map { L("loy_to_next", $0.pointsToGo ?? 0, $0.name ?? "") } ?? L("loy_top_tier"))
                    .font(.dorr(12.5)).foregroundStyle(.white.opacity(0.9)).padding(.top, 6)
                if dd.committed?.active == true, let bp = dd.committed?.bonusBp, bp > 0 {
                    HStack(spacing: 6) {
                        Image(systemName: "checkmark.seal.fill").font(.system(size: 14))
                        Text(verbatim: L("loy_committed", bp / 100)).font(.dorr(12, .bold))
                    }
                    .foregroundStyle(.white)
                    .padding(.horizontal, 12).padding(.vertical, 6).background(AppColors.success.opacity(0.9), in: Capsule())
                    .padding(.top, 10)
                }
            }
        }
        .padding(.horizontal, 18).padding(.top, 10).padding(.bottom, 22)
        .background(LinearGradient(colors: [wa.accent, Color(hex: 0x2B4C9B), tierColor.opacity(0.95)], startPoint: .topLeading, endPoint: .bottomTrailing).ignoresSafeArea(edges: .top))
        .clipShape(BottomRoundedRectangle(radius: 34))
        .onChange(of: d?.balance) { b in withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 1.1)) { counted = Double(b ?? 0) } }
        .onChange(of: d?.progress) { p in withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 1.2)) { bar = p ?? 0 } }
        .onAppear {
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 1.1)) { counted = Double(d?.balance ?? 0) }
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 1.2)) { bar = d?.progress ?? 0 }
        }
    }
}

/// A number that counts up as it animates.
private struct CountingText: AnimatableModifier {
    var value: Double
    var animatableData: Double {
        get { value }
        set { value = newValue }
    }

    func body(content: Content) -> some View {
        Text(verbatim: Int(value).formatted()).font(.dorr(40, .black)).foregroundStyle(.white)
    }
}

private struct TierCard: View {
    @Environment(\.wa) private var wa
    let t: LoyaltyTierDto
    @State private var glow = false

    var body: some View {
        let color = hexColor(t.color) ?? gold
        let current = t.current == true
        let mult = String(format: "%.2f", Double(t.multiplierBp ?? 10000) / 10000).replacingOccurrences(of: #"\.?0+$"#, with: "", options: .regularExpression)
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 6) {
                Image(systemName: "rosette").font(.system(size: 22)).foregroundStyle(color)
                Text(verbatim: t.name ?? "").font(.dorr(16, .black)).foregroundStyle(wa.settingsInk)
                Spacer(minLength: 0)
                if current {
                    Text(L("loy_you")).font(.dorr(10, .bold)).foregroundStyle(.white).padding(.horizontal, 8).padding(.vertical, 2).background(color, in: Capsule())
                }
            }
            Text(verbatim: L("loy_from_points", t.minPoints ?? 0)).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            Text(verbatim: "×" + mult).font(.dorr(18, .black)).foregroundStyle(color).padding(.vertical, 4)
            ForEach(Array((t.benefits ?? []).enumerated()), id: \.offset) { _, b in
                HStack(alignment: .top, spacing: 4) {
                    Image(systemName: "checkmark.circle.fill").font(.system(size: 12)).foregroundStyle(AppColors.success).padding(.top, 2)
                    Text(verbatim: b).font(.dorr(12)).foregroundStyle(wa.settingsInk)
                }
                .padding(.top, 2)
            }
        }
        .padding(14)
        .frame(width: 200, alignment: .leading)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(current ? color.opacity(glow ? 1 : 0.5) : color.opacity(0.25), lineWidth: current ? 2 : 1))
        .onAppear { if current { withAnimation(.easeInOut(duration: 1.4).repeatForever()) { glow = true } } }
    }
}

private struct RewardsList: View {
    @Environment(\.wa) private var wa
    let list: [LoyaltyRewardDto]
    let balance: Int
    let onDone: () -> Void
    @State private var confirm: LoyaltyRewardDto?
    @State private var result: LoyaltyRedemptionDto?
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        if list.isEmpty {
            Text(L("loy_no_rewards")).font(.dorr(14)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity).padding(28)
        }
        if let error { Text(verbatim: error).font(.dorr(13)).foregroundStyle(AppColors.danger) }
        ForEach(Array(list.enumerated()), id: \.offset) { i, r in RewardCard(r: r) { confirm = r }.staggerIn(i) }
            .dorrDialog(isPresented: Binding(get: { confirm != nil }, set: { if !$0 && !busy { confirm = nil } })) {
                if let r = confirm {
                    FlAlert(title: r.title ?? "", content: {
                        Text(verbatim: L("loy_confirm", r.costPoints ?? 0, balance - (r.costPoints ?? 0))).font(.dorr(14)).foregroundStyle(wa.settingsInk)
                    }, confirm: L("loy_redeem"), confirmEnabled: !busy, onConfirm: { redeem(r) }, dismiss: L("loy_back"), onDismiss: { confirm = nil })
                }
            }
            .dorrDialog(isPresented: Binding(get: { result != nil }, set: { if !$0 { result = nil } })) {
                if let res = result { RedeemedDialog(res: res) { result = nil } }
            }
    }

    private func redeem(_ r: LoyaltyRewardDto) {
        busy = true
        error = nil
        Task {
            do {
                result = try await LoyaltyApi.redeem(r.id, key: RidesApi.key()).data
                onDone()
            } catch {
                self.error = error.apiFailure.message
            }
            busy = false
            confirm = nil
        }
    }
}

private struct RewardCard: View {
    @Environment(\.wa) private var wa
    let r: LoyaltyRewardDto
    let onRedeem: () -> Void

    var body: some View {
        let coupon = r.kind == "coupon"
        HStack(spacing: 12) {
            Image(systemName: coupon ? "tag.fill" : "wallet.pass.fill").font(.system(size: 20)).foregroundStyle(.white)
                .frame(width: 50, height: 50)
                .background(LinearGradient(colors: [gold, goldDeep], startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            VStack(alignment: .leading, spacing: 1) {
                Text(verbatim: r.title ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk).lineLimit(2)
                Text(verbatim: coupon && r.couponPercent != nil ? L("loy_coupon_percent", r.couponPercent!)
                     : (coupon ? L("loy_coupon_fixed", rideMoney(r.valueMinor, r.currency)) : L("loy_credit", rideMoney(r.valueMinor, r.currency))))
                    .font(.dorr(12)).foregroundStyle(wa.settingsMut)
                if let d = r.description, !d.isEmpty { Text(verbatim: d).font(.dorr(11.5)).foregroundStyle(wa.settingsMut).lineLimit(2) }
                Text(verbatim: L("loy_cost", r.costPoints ?? 0)).font(.dorr(14, .black)).foregroundStyle(goldDeep).padding(.top, 4)
            }
            Spacer(minLength: 0)
            if r.available == true {
                Button(action: onRedeem) {
                    Text(L("loy_redeem")).font(.dorr(13, .bold)).foregroundStyle(.white).padding(.horizontal, 14).padding(.vertical, 9).background(wa.accent, in: Capsule())
                }
                .buttonStyle(WaPressStyle())
            } else {
                let why: String = {
                    switch r.reason {
                    case "out_of_stock": return L("loy_why_stock", r.minTier ?? "")
                    case "limit": return L("loy_why_limit", r.minTier ?? "")
                    case "tier": return L("loy_why_tier", r.minTier ?? "")
                    default: return L("loy_why_points", r.minTier ?? "")
                    }
                }()
                VStack(spacing: 2) {
                    Image(systemName: "lock.fill").font(.system(size: 15)).foregroundStyle(wa.settingsMut)
                    Text(verbatim: why).font(.dorr(10.5)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).frame(width: 80)
                }
            }
        }
        .padding(14)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
    }
}

private struct RedeemedDialog: View {
    @Environment(\.wa) private var wa
    let res: LoyaltyRedemptionDto
    let onClose: () -> Void
    @State private var pop: CGFloat = 0.6

    var body: some View {
        FlAlert(title: L("loy_redeemed"), content: {
            VStack(spacing: 6) {
                Image(systemName: "trophy.fill").font(.system(size: 44)).foregroundStyle(gold).scaleEffect(pop)
                    .onAppear { withAnimation(.spring(response: 0.5, dampingFraction: 0.45)) { pop = 1 } }
                Text(verbatim: res.title ?? "").font(.dorr(14)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center)
                if let code = res.result?.couponCode {
                    Button {
                        copyToClipboard(code)
                        AppToast.show(L("loy_coupon_hint"))
                    } label: {
                        HStack(spacing: 8) {
                            Text(verbatim: code).font(.dorr(18, .black)).foregroundStyle(goldDeep)
                            Image(systemName: "doc.on.doc").font(.system(size: 15)).foregroundStyle(goldDeep)
                        }
                        .padding(.horizontal, 14).padding(.vertical, 10)
                        .overlay(RoundedRectangle(cornerRadius: 12, style: .continuous).stroke(gold, lineWidth: 1.5))
                    }
                    .buttonStyle(.plain)
                    .padding(.top, 4)
                    Text(L("loy_coupon_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
                }
                if res.result?.creditMinor != nil { Text(L("loy_credit_added")).font(.dorr(12)).foregroundStyle(AppColors.success) }
            }
            .frame(maxWidth: .infinity)
        }, confirm: L("rd_done"), onConfirm: onClose, dismiss: nil, onDismiss: onClose)
    }
}

private struct ChallengeCard: View {
    @Environment(\.wa) private var wa
    let c: LoyaltyChallengeDto
    @State private var bar: Double = 0

    var body: some View {
        let done = c.completedAt != nil
        let target = max(c.target ?? 1, 1)
        let tint = done ? AppColors.success : (c.failed == true ? AppColors.danger : wa.accent)
        // The time left, from the phone's clock (a minute's step is plenty).
        TimelineView(.periodic(from: .now, by: 60)) { context in
            let left = parseRideTime(c.endsAt).map { $0.timeIntervalSince(context.date) }
            VStack(alignment: .leading, spacing: 0) {
                HStack(spacing: 10) {
                    Image(systemName: done ? "trophy.fill" : "bolt.fill").foregroundStyle(tint).frame(width: 42, height: 42).background(tint.opacity(0.14), in: Circle())
                    VStack(alignment: .leading, spacing: 0) {
                        Text(verbatim: c.title ?? "").font(.dorr(15, .bold)).foregroundStyle(wa.settingsInk)
                        if let d = c.description, !d.isEmpty { Text(verbatim: d).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    }
                    Spacer(minLength: 0)
                    Text(verbatim: "+\(c.rewardPoints ?? 0)").font(.dorr(15, .black)).foregroundStyle(goldDeep)
                }
                GeometryReader { proxy in
                    ZStack(alignment: .leading) {
                        Capsule().fill(tint.opacity(0.14))
                        Capsule().fill(tint).frame(width: proxy.size.width * CGFloat(max(bar, 0.02)))
                    }
                }
                .frame(height: 8)
                .padding(.top, 10)
                HStack {
                    Text(verbatim: "\(min(c.progress ?? 0, target)) / \(target)").font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    Spacer()
                    Text(verbatim: done ? L("loy_challenge_done") : (c.failed == true ? L("loy_challenge_failed")
                        : (left.map { $0 > 0 ? L("loy_ends_in", Int($0) / 86_400, (Int($0) % 86_400) / 3600) : L("loy_challenge_over") } ?? L("loy_challenge_over"))))
                        .font(.dorr(12, .semibold)).foregroundStyle(tint)
                }
                .padding(.top, 6)
            }
            .padding(14)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        }
        .onAppear {
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.9)) { bar = min(max(Double(c.progress ?? 0) / Double(target), 0), 1) }
        }
    }
}

private func badgeIcon(_ key: String?) -> String {
    switch key {
    case "rocket": return "airplane.departure"
    case "crown": return "crown.fill"
    case "heart": return "heart.fill"
    case "leaf": return "leaf.fill"
    case "moon": return "moon.fill"
    case "bolt": return "bolt.fill"
    case "trophy": return "trophy.fill"
    case "map": return "map.fill"
    case "car": return "car.fill"
    case "bus": return "bus.fill"
    case "boat": return "ferry.fill"
    default: return "star.fill"
    }
}

private struct BadgeTile: View {
    @Environment(\.wa) private var wa
    let b: LoyaltyBadgeDto
    let index: Int
    @State private var appear = false
    @State private var ring: Double = 0

    var body: some View {
        let earned = b.earnedAt != nil
        let tint = earned ? gold : wa.settingsMut.opacity(0.5)
        VStack(spacing: 0) {
            ZStack {
                Circle().stroke(tint.opacity(0.15), lineWidth: 3).padding(2)
                Circle().trim(from: 0, to: ring).stroke(tint, style: StrokeStyle(lineWidth: 3, lineCap: .round)).rotationEffect(.degrees(-90)).padding(2)
                Image(systemName: badgeIcon(b.icon)).font(.system(size: 20)).foregroundStyle(earned ? .white : tint)
                    .frame(width: 44, height: 44)
                    .background(earned ? AnyShapeStyle(LinearGradient(colors: [gold, goldDeep], startPoint: .topLeading, endPoint: .bottomTrailing)) : AnyShapeStyle(tint.opacity(0.15)), in: Circle())
            }
            .frame(width: 62, height: 62)
            Text(verbatim: b.name ?? "").font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk).multilineTextAlignment(.center).lineLimit(2).padding(.top, 6)
            Text(verbatim: earned ? L("loy_badge_earned") : "\(b.progress ?? 0) / \(b.threshold ?? 1)").font(.dorr(10.5)).foregroundStyle(earned ? AppColors.success : wa.settingsMut)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 12).padding(.horizontal, 6)
        .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
        .scaleEffect(appear ? 1 : 0.6).opacity(appear ? 1 : 0)
        .onAppear {
            withAnimation(.spring(response: 0.5, dampingFraction: 0.55).delay(Double(index) * 0.05)) { appear = true }
            withAnimation(.easeOut(duration: 0.9)) { ring = min(max(Double(b.progress ?? 0) / Double(max(b.threshold ?? 1, 1)), 0), 1) }
        }
    }
}

private struct LoyaltyHistory: View {
    @Environment(\.wa) private var wa
    @State private var filter: String?
    @State private var list: [LoyaltyEntryDto]?

    var body: some View {
        HStack(spacing: 8) {
            ForEach([(nil, "loy_all"), ("earned", "loy_earned"), ("spent", "loy_spent")], id: \.1) { k, label in
                let on = filter == k
                Button { filter = k } label: {
                    Text(L(label)).font(.dorr(12.5, .semibold)).foregroundStyle(on ? .white : wa.settingsInk)
                        .padding(.horizontal, 14).padding(.vertical, 7).background(on ? wa.accent : wa.settingsCard, in: Capsule())
                }
                .buttonStyle(.plain)
            }
        }
        .task(id: filter) {
            list = nil
            list = (try? await LoyaltyApi.history(kind: filter).data) ?? []
        }
        if let rows = list {
            if rows.isEmpty {
                Text(L("loy_no_history")).font(.dorr(14)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity).padding(28)
            } else {
                VStack(spacing: 0) { ForEach(rows) { EntryRow(e: $0) } }
                    .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            }
        } else {
            ProgressView().tint(wa.accent).frame(maxWidth: .infinity).padding(24)
        }
    }
}

private struct EntryRow: View {
    @Environment(\.wa) private var wa
    let e: LoyaltyEntryDto

    var body: some View {
        let plus = e.points > 0
        let icon: String = {
            switch e.kind {
            case "badge": return "trophy.fill"
            case "challenge": return "bolt.fill"
            case "redeem": return "tag.fill"
            case "service": return "bus.fill"
            default: return "car.fill"
            }
        }()
        HStack(spacing: 10) {
            Image(systemName: icon).font(.system(size: 16)).foregroundStyle(plus ? AppColors.success : AppColors.danger).frame(width: 22)
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: e.title ?? "").font(.dorr(13.5)).foregroundStyle(wa.settingsInk).lineLimit(1)
                Text(verbatim: String((e.createdAt ?? "").prefix(16)).replacingOccurrences(of: "T", with: " ")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
            }
            Spacer(minLength: 0)
            Text(verbatim: (plus ? "+" : "") + "\(e.points)").font(.dorr(15, .black)).foregroundStyle(plus ? AppColors.success : AppColors.danger)
        }
        .padding(.horizontal, 14).padding(.vertical, 11)
    }
}
