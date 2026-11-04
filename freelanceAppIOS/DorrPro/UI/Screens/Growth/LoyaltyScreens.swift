import SwiftUI

// freelanceApp `ui/screens/LoyaltyScreens.kt`.
//
// FL-L09 (part B): my loyalty program. A discount for clients who keep coming back, in steps by how many works we finished together —
// I set the steps (within the platform's limits), the discount is taken by itself on a project offer, a service order and a consultation,
// and it comes off my own price. The screen: how the program did, the on / off switch, the steps with ready-made ladders, how a client
// sees it, and my clients on a step. It reloads on open, on a live notice and when the connection returns — never on a timer.

private let LOY_GREEN = Color(hex: 0x16A34A)

/// The SF Symbol standing for Material's `Icons.Rounded.Loyalty`.
let LOYALTY_ICON = "heart.circle.fill"

/// 1500 → "15", 250 → "2.5", 125 → "1.25".
func loyPercent(_ bp: Int) -> String {
    let whole = bp / 100
    let rest = bp % 100
    if rest == 0 { return "\(whole)" }
    if rest % 10 == 0 { return "\(whole).\(rest / 10)" }
    return "\(whole)." + (rest < 10 ? "0\(rest)" : "\(rest)")
}

/// A price after a discount, to the nearest minor unit, as the server works it out (display only: the server decides).
private func loyAfter(_ minor: Int, _ bp: Int) -> Int { max((minor * (10000 - bp) + 5000) / 10000, 1) }

/// What a client earned, on a price already worked out: "Loyalty 10% (− 100 SAR)". Nothing when there is none.
struct LoyaltyOfferLine: View {
    let bp: Int?
    let savingMinor: Int?
    let currency: String?

    var body: some View {
        if let bp {
            HStack(spacing: 6) {
                Image(systemName: LOYALTY_ICON).font(.system(size: 13, weight: .semibold)).foregroundStyle(LOY_GREEN).frame(width: 15, height: 15)
                let saved = savingMinor.map { " (− \u{200E}\(formatMinor($0, nil)) \(currency ?? ""))" } ?? ""
                Text(verbatim: L("loy_offer_line", loyPercent(bp)) + saved).font(.dorr(12, .bold)).foregroundStyle(LOY_GREEN)
            }
        }
    }
}

/// Said before I type a price: this project's client earned a step of my program, so it comes off what I ask.
struct LoyaltyNote: View {
    @Environment(\.wa) private var wa
    let bp: Int
    let works: Int

    var body: some View {
        HStack(spacing: 10) {
            Image(systemName: LOYALTY_ICON).font(.system(size: 17, weight: .semibold)).foregroundStyle(LOY_GREEN).frame(width: 20, height: 20)
            Text(verbatim: L("loy_offer_note", works, loyPercent(bp))).font(.dorr(12.5)).foregroundStyle(wa.settingsInk).lineSpacing(3)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(12)
        .frame(maxWidth: .infinity)
        .background(LOY_GREEN.opacity(0.10), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
    }
}

struct LoyaltyScreen: View {
    let onBack: () -> Void
    var onSwitchStore: ((String) -> Void)? = nil

    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var dash: LoyaltyDashDto?
    @State private var closed = false
    @State private var error: String?
    @State private var busy = false
    // What I am editing: copied from the saved program once, and again after each save (a live reload never overwrites it).
    @State private var tiers: [LoyaltyTierDto] = []
    @State private var seeded = false
    @State private var enabled = true

    private func seed(_ d: LoyaltyDashDto) {
        let presets = d.presets ?? []
        let source = d.program?.tiers ?? presets.first(where: { $0.key == "balanced" })?.tiers ?? presets.first?.tiers ?? [LoyaltyTierDto(after: 2, discountBp: 500)]
        tiers = source.map { LoyaltyTierDto(after: $0.after ?? 1, discountBp: $0.discountBp ?? 0) }
        enabled = d.program?.enabled ?? true
        seeded = true
    }

    private func load() async {
        let r = await LoyaltyApi.show()
        switch r {
        case .ok(let d, _):
            dash = d
            closed = false
            error = nil
            if !seeded { seed(d) }
        case .failed(let e):
            if e.errorCode == "freelance_feature_off" { closed = true } else { error = r.errorText(L("login_error_offline")) ?? L("login_error_generic") }
        case .offline:
            break
        }
    }

    private func save() {
        busy = true
        error = nil
        Task {
            let r = await LoyaltyApi.save(LoyaltyRequest(enabled: enabled, tiers: tiers.map { LoyaltyStepRequest(after: $0.after ?? 1, discountBp: $0.discountBp ?? 0) }))
            busy = false
            if let d = r.value {
                dash = d
                seed(d)
                await AppToast.show(L("loy_saved"))
            } else {
                error = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    private func toggle(_ on: Bool) {
        busy = true
        error = nil
        Task {
            let r = await LoyaltyApi.toggle(on ? "enable" : "disable")
            busy = false
            if let d = r.value {
                dash = d
                enabled = d.program?.enabled ?? on
            } else {
                error = r.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
        }
    }

    var body: some View {
        Page(title: L("loy_title"), onBack: onBack) {
            if closed {
                EmptyState(icon: "nosign", title: L("loy_closed_title"), body: L("loy_closed_body")).padding(.top, 70)
            } else if let d = dash {
                content(d)
            } else if error == nil {
                SkeletonDetail()
            }
        }
        .messageToast(error)
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { Task { await load() } }
    }

    @ViewBuilder
    private func content(_ d: LoyaltyDashDto) -> some View {
        let saved = d.program
        let rules = d.rules ?? LoyaltyRulesDto()
        let max = rules.maxDiscountBp ?? 3000
        let maxTiers = rules.maxTiers ?? 4
        let maxAfter = rules.maxAfter ?? 50
        let rising = zip(tiers, tiers.dropFirst()).allSatisfy { a, b in (b.after ?? 1) > (a.after ?? 1) && (b.discountBp ?? 0) > (a.discountBp ?? 0) }
        let problem: String? = rising ? nil : L("loy_problem_order")
        let pairs = tiers.map { [$0.after ?? 1, $0.discountBp ?? 0] }
        let savedPairs = (saved?.tiers ?? []).map { [$0.after ?? 1, $0.discountBp ?? 0] }
        let dirty = saved == nil || enabled != (saved?.enabled ?? true) || pairs != savedPairs

        if let onSwitchStore { StoreSwitcher(current: "loyalty", onOpen: onSwitchStore) }
        LoyHero(d: d)
        // On / off: instant once a program is saved.
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text(L("loy_switch_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                Text(L(saved == nil ? "loy_switch_save_first" : (saved?.enabled ?? true) ? "loy_switch_on" : "loy_switch_off"))
                    .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Toggle("", isOn: Binding(get: { saved?.enabled ?? false }, set: { toggle($0) }))
                .labelsHidden().tint(wa.red)
                .disabled(saved == nil || busy)
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 14)
        .frame(maxWidth: 520)
        .settingsSurface(20)
        .frame(maxWidth: .infinity)

        StepCard(number: 1, title: L("loy_steps_title"), subtitle: L("loy_steps_sub")) {
            let presets = d.presets ?? []
            if !presets.isEmpty {
                Text(L("loy_presets")).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
                ChipRow(options: presets.map { (value: $0.key ?? "", label: $0.name ?? "") }, selected: []) { key in
                    if let p = presets.first(where: { $0.key == key }) {
                        tiers = (p.tiers ?? []).map { LoyaltyTierDto(after: $0.after ?? 1, discountBp: $0.discountBp ?? 0) }
                    }
                }
            }
            ForEach(Array(tiers.enumerated()), id: \.offset) { i, t in
                LoyTierRow(number: i + 1, tier: t, d: d, canRemove: tiers.count > 1,
                           onChange: { if i < tiers.count { tiers[i] = $0 } },
                           onRemove: { if i < tiers.count { tiers.remove(at: i) } })
            }
            let last = tiers.last
            let canAdd = tiers.count < maxTiers && last != nil && (last?.after ?? 1) < maxAfter && (last?.discountBp ?? 0) < max
            let tint = canAdd ? wa.red : wa.settingsMut.opacity(0.5)
            Button {
                if let l = tiers.last {
                    tiers.append(LoyaltyTierDto(after: min((l.after ?? 1) + 2, maxAfter), discountBp: min((l.discountBp ?? 0) + 500, max)))
                }
            } label: {
                HStack(spacing: 6) {
                    Image(systemName: "plus").font(.system(size: 15, weight: .semibold)).frame(width: 18, height: 18)
                    Text(L("loy_add")).font(.dorr(14, .bold))
                }
                .foregroundStyle(tint)
                .frame(maxWidth: .infinity)
                .frame(height: 46)
                .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(canAdd ? wa.red : wa.settingsMut.opacity(0.3), lineWidth: 1))
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)
            .disabled(!canAdd)
            Text(L("loy_limit", maxTiers, loyPercent(max))).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            if let problem {
                Text(verbatim: problem).font(.dorr(12, .semibold)).foregroundStyle(serviceTone("awaiting", night: wa.night).ink)
            }
        }

        LoyPreview(tiers: tiers, d: d)
        SettingsPrimaryButton(text: L("loy_save"), enabled: dirty && problem == nil && !tiers.isEmpty, loading: busy) { save() }
            .frame(maxWidth: 520)
            .frame(maxWidth: .infinity)

        if saved != nil { LoyClients(d: d) }
        LoyHow()
    }
}

/// The head: what this is for and how it did.
private struct LoyHero: View {
    let d: LoyaltyDashDto

    var body: some View {
        let st = d.stats ?? LoyaltyStatsDto()
        let uses = st.uses ?? 0
        StoreHero(
            icon: LOYALTY_ICON, title: L("loy_hero_title"), body: L("loy_hero_body"),
            numbers: [
                HeroNumber(value: "\(st.pastClients ?? 0)", label: L("loy_stat_clients")),
                HeroNumber(value: "\(st.inProgram ?? 0)", label: L("loy_stat_in_program")),
                HeroNumber(value: "\(uses)", label: L("loy_stat_uses")),
            ],
            footnote: uses > 0 ? L("loy_saved_line", svcMoney(st.savedMinor ?? 0, d.decimals ?? 2, d.currency)) : nil
        )
    }
}

/// One step of the ladder: how many works, how much off.
private struct LoyTierRow: View {
    @Environment(\.wa) private var wa
    let number: Int
    let tier: LoyaltyTierDto
    let d: LoyaltyDashDto
    let canRemove: Bool
    let onChange: (LoyaltyTierDto) -> Void
    let onRemove: () -> Void

    var body: some View {
        let rules = d.rules ?? LoyaltyRulesDto()
        let minPct = max(((rules.minDiscountBp ?? 100) + 99) / 100, 1)
        let maxPct = max((rules.maxDiscountBp ?? 3000) / 100, minPct)
        let bp = tier.discountBp ?? 0
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 0) {
                Text(L("loy_step_n", number)).font(.dorr(13, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                Text(verbatim: "\(loyPercent(bp))%").font(.dorr(14, .heavy)).foregroundStyle(LOY_GREEN)
                if canRemove {
                    Button(action: onRemove) {
                        Image(systemName: "trash").font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.settingsMut)
                            .frame(width: 30, height: 30).contentShape(Circle())
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(L("loy_remove"))
                    .padding(.leading, 8)
                }
            }
            HStack(spacing: 10) {
                Stepper(label: L("loy_after"), value: tier.after ?? 1, range: 1...max(rules.maxAfter ?? 50, 1)) { v in
                    var t = tier
                    t.after = v
                    onChange(t)
                }
                .frame(maxWidth: .infinity)
                Stepper(label: L("loy_discount"), value: min(max(bp / 100, minPct), maxPct), range: minPct...maxPct) { v in
                    var t = tier
                    t.discountBp = v * 100
                    onChange(t)
                }
                .frame(maxWidth: .infinity)
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.night ? wa.darkWell : wa.red.opacity(0.05), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
    }
}

/// How a client reads it, with one worked example at the first step.
private struct LoyPreview: View {
    @Environment(\.wa) private var wa
    let tiers: [LoyaltyTierDto]
    let d: LoyaltyDashDto

    var body: some View {
        InfoCard(title: L("loy_preview_title"), icon: "eye.fill") {
            Text(L("loy_preview_head")).font(.dorr(13.5, .bold)).foregroundStyle(wa.settingsInk).lineSpacing(3)
            HStack(spacing: 8) {
                ForEach(Array(tiers.enumerated()), id: \.offset) { _, t in
                    VStack(spacing: 2) {
                        Text(verbatim: "\(loyPercent(t.discountBp ?? 0))%").font(.dorr(17, .heavy)).foregroundStyle(LOY_GREEN)
                        Text(L("loy_tier_after", t.after ?? 1)).font(.dorr(10.5)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center)
                    }
                    .padding(.vertical, 10)
                    .padding(.horizontal, 4)
                    .frame(maxWidth: .infinity)
                    .background(LOY_GREEN.opacity(0.10), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                }
            }
            if let t = tiers.first {
                let decimals = d.decimals ?? 2
                let example = 1000 * loyPow10(decimals)
                Text(L("loy_example", svcMoney(example, decimals, d.currency), svcMoney(loyAfter(example, t.discountBp ?? 0), decimals, d.currency)))
                    .font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            }
        }
    }
}

private func loyPow10(_ n: Int) -> Int {
    var v = 1
    for _ in 0..<min(max(n, 0), 6) { v *= 10 }
    return v
}

/// My clients on a step, and how many stand on each.
private struct LoyClients: View {
    @Environment(\.wa) private var wa
    let d: LoyaltyDashDto

    var body: some View {
        InfoCard(title: L("loy_clients_title"), icon: "person.3.fill") {
            let tiers = d.stats?.byTier ?? []
            if !tiers.isEmpty {
                HStack(spacing: 8) {
                    ForEach(Array(tiers.enumerated()), id: \.offset) { _, t in
                        VStack(spacing: 0) {
                            Text(verbatim: "\(loyPercent(t.discountBp ?? 0))%").font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                            Text(L("loy_tier_clients", t.clients ?? 0)).font(.dorr(10.5)).foregroundStyle(wa.settingsMut)
                        }
                        .padding(.vertical, 8)
                        .frame(maxWidth: .infinity)
                        .background(wa.red.opacity(0.07), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                }
            }
            let clients = d.clients ?? []
            if clients.isEmpty {
                Text(L("loy_clients_empty")).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
            } else {
                ForEach(Array(clients.enumerated()), id: \.offset) { _, c in LoyClientRow(c: c) }
            }
        }
    }
}

private struct LoyClientRow: View {
    @Environment(\.wa) private var wa
    let c: LoyaltyClientDto

    var body: some View {
        HStack(spacing: 10) {
            AvatarImage(url: c.avatarUrl, size: 36)
            VStack(alignment: .leading, spacing: 0) {
                Text(verbatim: c.name ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.settingsInk).lineLimit(1).truncationMode(.tail)
                Text(verbatim: c.next.map { L("loy_client_next", $0.remaining ?? 0, loyPercent($0.discountBp ?? 0)) } ?? L("loy_client_works", c.works ?? 0))
                    .font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if let now = c.current {
                Text(verbatim: "\(loyPercent(now.discountBp ?? 0))%").font(.dorr(12.5, .heavy)).foregroundStyle(LOY_GREEN)
                    .padding(.horizontal, 10)
                    .padding(.vertical, 4)
                    .background(LOY_GREEN.opacity(0.12), in: Capsule())
            } else {
                Text(L("loy_client_none")).font(.dorr(11.5)).foregroundStyle(wa.settingsMut)
            }
        }
        .padding(.vertical, 4)
    }
}

private struct LoyHow: View {
    @Environment(\.wa) private var wa

    var body: some View {
        InfoCard(title: L("loy_how_title"), icon: "checkmark.circle.fill") {
            ForEach(["loy_how_1", "loy_how_2", "loy_how_3", "loy_how_4"], id: \.self) { key in
                HStack(alignment: .top, spacing: 8) {
                    Circle().fill(wa.red).frame(width: 6, height: 6).padding(.top, 6)
                    Text(L(key)).font(.dorr(12.5)).foregroundStyle(wa.settingsMut).lineSpacing(3)
                }
            }
        }
    }
}
