import SwiftUI

/// One plan as a column of the comparison: the free baseline first, `mine` marks the plan I (or my team) am on.
struct PlanColumn {
    var slug: String
    var name: String
    var mine: Bool
    var perks: [String: PlanPerk]
}

/// FS-705c: the plans side by side — every feature the app checks today, in the server's order and groups, one column per plan, my plan
/// marked. Only what works is listed (the server leaves out what is "coming"), so a tick or a number here is always something the plan
/// really gives.
struct PlanComparison: View {
    @Environment(\.wa) private var wa
    let features: [PlanFeatureDto]
    let plans: [PlanColumn]

    /// The decoder turns the dictionary keys camel-case too (`offers_monthly` → `offersMonthly`), so look for both spellings.
    private func perk(_ plan: PlanColumn, _ key: String) -> PlanPerk {
        plan.perks[key] ?? plan.perks[camel(key)] ?? .none
    }

    private func camel(_ key: String) -> String {
        let parts = key.split(separator: "_").map(String.init)
        guard let first = parts.first else { return key }
        return first + parts.dropFirst().map { $0.prefix(1).uppercased() + $0.dropFirst() }.joined()
    }

    private func groupName(_ group: String) -> String {
        switch group {
        case "money": return L("plans_group_money")
        case "offers": return L("plans_group_offers")
        case "visibility": return L("plans_group_visibility")
        case "selling": return L("plans_group_selling")
        case "tools": return L("plans_group_tools")
        case "team": return L("plans_group_team")
        case "support": return L("plans_group_support")
        default: return L("plans_group_other")
        }
    }

    /// The groups in the order they first appear, each with its rows.
    private var groups: [(group: String, rows: [PlanFeatureDto])] {
        var order: [String] = []
        var rows: [String: [PlanFeatureDto]] = [:]
        for f in features {
            let g = f.group ?? "other"
            if rows[g] == nil { order.append(g) }
            rows[g, default: []].append(f)
        }
        return order.map { (group: $0, rows: rows[$0] ?? []) }
    }

    var body: some View {
        if !features.isEmpty && plans.count >= 2 {
            Card {
                Text(L("plans_compare_title")).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
                HStack(alignment: .bottom, spacing: 0) {
                    Spacer().frame(maxWidth: .infinity)
                    ForEach(plans, id: \.slug) { p in
                        VStack(spacing: 2) {
                            Text(verbatim: p.name).font(.dorr(12, .heavy)).foregroundStyle(p.mine ? wa.red : wa.settingsInk)
                                .multilineTextAlignment(.center).lineLimit(2)
                            if p.mine { Text(L("plans_compare_mine")).font(.dorr(10, .bold)).foregroundStyle(wa.red) }
                        }
                        .frame(width: 64)
                    }
                }
                ForEach(groups, id: \.group) { g in
                    Text(groupName(g.group)).font(.dorr(11, .bold)).foregroundStyle(wa.settingsMut)
                    VStack(spacing: 8) {
                        ForEach(g.rows, id: \.key) { f in
                            HStack(spacing: 0) {
                                VStack(alignment: .leading, spacing: 1) {
                                    Text(verbatim: f.name ?? "").font(.dorr(12)).foregroundStyle(wa.settingsInk)
                                    if let hint = f.hint, !hint.isEmpty {
                                        Text(verbatim: hint).font(.dorr(10)).foregroundStyle(wa.settingsMut)
                                    }
                                }
                                .frame(maxWidth: .infinity, alignment: .leading)
                                ForEach(plans, id: \.slug) { p in
                                    cell(f, perk(p, f.key ?? ""), mine: p.mine).frame(width: 64)
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /// A tick, a dash (nothing), "no limit", or the number — by the feature's type.
    @ViewBuilder
    private func cell(_ f: PlanFeatureDto, _ value: PlanPerk, mine: Bool) -> some View {
        let ink = mine ? wa.red : wa.settingsInk
        switch f.type ?? "switch" {
        case "switch":
            if case .on(true) = value { Image(systemName: "checkmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.red) }
            else if case .number(let n) = value, n != 0 { Image(systemName: "checkmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.red) }
            else { dash }
        case "level":
            if case .number(let n) = value, n > 0 {
                Text(verbatim: (f.max ?? 1) > 1 ? "\(n)/\(f.max ?? 1)" : "\(n)").font(.dorr(12, .bold)).foregroundStyle(ink)
            } else { dash }
        case "fee_bp":
            if case .number(let n) = value {
                Text(verbatim: trimmedPercent(n)).font(.dorr(12, .bold)).foregroundStyle(ink)
            } else { dash }
        default:
            if case .number(let n) = value {
                Text(verbatim: n.formatted(.number.locale(Locale(identifier: "en_US")))).font(.dorr(12, .bold)).foregroundStyle(ink)
            } else {
                Text(L("plans_compare_unlimited")).font(.dorr(11, .bold)).foregroundStyle(wa.red).multilineTextAlignment(.center)
            }
        }
    }

    private var dash: some View { Text(verbatim: "—").font(.dorr(12)).foregroundStyle(wa.settingsMut) }

    /// 800 → "8%", 750 → "7.5%" (hundredths of a percent).
    private func trimmedPercent(_ bp: Int) -> String {
        let v = Double(bp) / 100
        return (v == v.rounded() ? String(Int(v)) : String(v)) + "%"
    }
}
