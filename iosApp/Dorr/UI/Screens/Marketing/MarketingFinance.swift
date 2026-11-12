import SwiftUI

/// androidApp `ui/screens/marketing/MarketingFinance.kt` — finance (spec 09): the person enters figures, the server
/// computes the three scenarios (the model never does the arithmetic). A metric without what it needs says «N/A»,
/// never a made-up number. Saving is a new plan version. Also the project team (U13).
private let moneyInputs: [(String, String)] = [
    ("price_minor", "mktg_in_price"),
    ("variable_cost_minor", "mktg_in_variable_cost"),
    ("fixed_costs_minor", "mktg_in_fixed_costs"),
    ("marketing_spend_minor", "mktg_in_marketing_spend"),
    ("attributed_revenue_minor", "mktg_in_revenue"),
]
private let countInputs: [(String, String)] = [
    ("expected_new_customers", "mktg_in_new_customers"),
    ("expected_conversions", "mktg_in_conversions"),
]
private let metrics: [(String, String)] = [
    ("contribution_margin_per_unit_minor", "mktg_m_contribution_margin_per_unit_minor"),
    ("break_even_units", "mktg_m_break_even_units"),
    ("cac_minor", "mktg_m_cac_minor"),
    ("cpa_minor", "mktg_m_cpa_minor"),
    ("roas", "mktg_m_roas"),
    ("ltv_minor", "mktg_m_ltv_minor"),
    ("monthly_profit_minor", "mktg_m_monthly_profit_minor"),
]

struct MarketingFinance: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let planId: String

    @State private var plan: MkPlanDto?
    @State private var drafts: [String: String] = [:]
    @State private var busy = false
    @State private var invalid = false

    var body: some View {
        let version = plan?.currentVersion
        let currency = version?.content?["finance"]?.text("currency") ?? ""
        Group {
            if version != nil {
                MkPage(title: L("mktg_finance"), cta: {
                    WaButton(text: L("mktg_save"), loading: busy) { Task { await save() } }
                }, content: { content(version, currency) })
            } else {
                MkPage(title: L("mktg_finance")) { content(version, currency) }
            }
        }
        .task(id: planId) {
            do {
                plan = try await MarketingApi.plan(planId).data
                let inputs = plan?.currentVersion?.scenarios?["base"]?.inputs ?? [:]
                for (key, _) in moneyInputs + countInputs {
                    if let v = inputs[key] ?? nil {
                        drafts[key] = key.hasSuffix("_minor") ? mkMoney(Int(v), "").replacingOccurrences(of: ",", with: "") : String(Int(v))
                    }
                }
                if let churn = inputs["monthly_churn_pct"] ?? nil {
                    drafts["monthly_churn_pct"] = churn == churn.rounded() ? String(Int(churn)) : String(churn)
                }
            } catch {
                host.fail(error, L("mktg_error_generic"))
            }
        }
    }

    @ViewBuilder
    private func content(_ version: MkVersionDto?, _ currency: String) -> some View {
        if let version {
            WaNote(text: L("mktg_finance_note"))
            if invalid {
                Text(verbatim: L("mktg_answer_invalid")).font(.dorr(12.5)).foregroundStyle(wa.danger).padding(.top, 8)
            }
            WaCard(padding: 16) {
                VStack(spacing: 10) {
                    ForEach(moneyInputs, id: \.0) { key, label in
                        WeTextField(value: binding(key, limit: 16), label: L(label), placeholder: "0.00", keyboard: .decimalPad, trailing: currency, ltr: true)
                    }
                    ForEach(countInputs, id: \.0) { key, label in
                        WeTextField(value: binding(key, limit: 9, digitsOnly: true), label: L(label), placeholder: "0", keyboard: .numberPad, ltr: true)
                    }
                    WeTextField(value: binding("monthly_churn_pct", limit: 6), label: L("mktg_in_churn"), placeholder: "0", keyboard: .decimalPad, ltr: true)
                }
            }
            .padding(.top, 12)

            WaSectionTitle(title: L("mktg_finance_sub"))
            if let scenarios = version.scenarios, !scenarios.isEmpty {
                ScenarioTable(outputs: scenarios.mapValues { $0.outputs ?? [:] }, currency: currency)
            } else {
                Text(verbatim: L("mktg_no_finance")).font(.dorr(13)).foregroundStyle(wa.mut).padding(.horizontal, 4)
            }
            // Phase D: which input moves the profit most (shown only when the country switch is on).
            if let scenarios = version.scenarios, !scenarios.isEmpty, host.status?.features?["optimisation"] != false {
                MkSimulationBlock(planId: planId, currency: currency)
            }
        } else {
            VStack(spacing: 12) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 90) } }
        }
    }

    private func binding(_ key: String, limit: Int, digitsOnly: Bool = false) -> Binding<String> {
        Binding(
            get: { drafts[key] ?? "" },
            set: { value in drafts[key] = String((digitsOnly ? value.filter(\.isNumber) : value).prefix(limit)) }
        )
    }

    private func save() async {
        guard let version = plan?.currentVersion else { return }
        var inputs: [String: JSONValue] = [:]
        var bad = false
        for (key, _) in moneyInputs {
            let t = drafts[key] ?? ""
            if t.trimmingCharacters(in: .whitespaces).isEmpty { continue }
            if let minor = mkParseMinor(t) { inputs[key] = .int(minor) } else { bad = true }
        }
        for (key, _) in countInputs {
            let t = (drafts[key] ?? "").trimmingCharacters(in: .whitespaces)
            if t.isEmpty { continue }
            if let n = Int(t), n >= 0 { inputs[key] = .int(n) } else { bad = true }
        }
        let churn = (drafts["monthly_churn_pct"] ?? "").trimmingCharacters(in: .whitespaces)
        if !churn.isEmpty {
            if let v = Double(churn.replacingOccurrences(of: ",", with: ".")), (0...100).contains(v) { inputs["monthly_churn_pct"] = .double(v) } else { bad = true }
        }
        invalid = bad
        guard !bad else { return }
        busy = true
        do {
            if let updated = try await MarketingApi.setFinance(planId, version: version.version, inputs: inputs).data {
                plan = updated
                host.showToast(L("mktg_saved"))
            }
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}

/// Metric × scenario. Wide on a phone, so it scrolls sideways; amounts read left-to-right.
private struct ScenarioTable: View {
    @Environment(\.wa) private var wa
    let outputs: [String: [String: MkMetricDto]]
    let currency: String

    var body: some View {
        let names = [("conservative", "mktg_scenario_conservative"), ("base", "mktg_scenario_base"), ("optimistic", "mktg_scenario_optimistic")]
        WaCard(padding: 12) {
            VStack(alignment: .leading, spacing: 8) {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(alignment: .top, spacing: 0) {
                        VStack(alignment: .leading, spacing: 0) {
                            Color.clear.frame(height: 30)
                            ForEach(metrics, id: \.0) { _, label in
                                Text(verbatim: L(label)).font(.dorr(12.5)).foregroundStyle(wa.mut).frame(width: 130, height: 44, alignment: .leading)
                            }
                        }
                        ForEach(names, id: \.0) { key, label in
                            VStack(spacing: 0) {
                                Text(verbatim: L(label)).font(.dorr(12.5, .heavy)).foregroundStyle(key == "base" ? wa.red : wa.ink).frame(width: 110, height: 30)
                                ForEach(metrics, id: \.0) { metric, _ in
                                    let m = outputs[key]?[metric]
                                    Text(verbatim: "\u{2066}\(text(metric, m))\u{2069}")
                                        .font(.dorr(13, .bold))
                                        .foregroundStyle(m?.value == nil ? wa.soft : ((m?.value ?? 0) < 0 ? wa.danger : wa.ink))
                                        .frame(width: 110, height: 44)
                                }
                            }
                        }
                    }
                }
                WaDivider()
                Text(verbatim: L("mktg_estimated") + (currency.isEmpty ? "" : " · \(currency)")).font(.dorr(11.5)).foregroundStyle(wa.soft)
            }
        }
    }

    private func text(_ metric: String, _ m: MkMetricDto?) -> String {
        guard let value = m?.value else { return L("mktg_unavailable") }
        if metric.hasSuffix("_minor") { return mkMoney(Int(value), "") }
        if metric == "roas" { return "\(value)×" }
        return String(Int(value))
    }
}

// MARK: - team

private let assignable: [(String, String, String)] = [
    ("manager", "mktg_role_manager", "mktg_role_manager_sub"),
    ("analyst", "mktg_role_analyst", "mktg_role_analyst_sub"),
    ("viewer", "mktg_role_viewer", "mktg_role_viewer_sub"),
]

/// The project team (U13): the owner adds people by phone and gives a role; anyone else may leave.
struct MarketingTeam: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let projectId: String

    @State private var project: MkProjectDto?
    @State private var members: [MkMemberDto]?
    @State private var reload = 0
    @State private var adding = false
    @State private var editing: MkMemberDto?

    var body: some View {
        let owner = project?.abilities?.manageTeam == true
        Group {
            if owner {
                MkPage(title: L("mktg_team"), cta: {
                    WaButton(text: L("mktg_add_member"), icon: "person.badge.plus") { adding = true }
                }, content: { content(owner) })
            } else {
                MkPage(title: L("mktg_team")) { content(owner) }
            }
        }
        .task(id: "\(projectId)|\(reload)") {
            do {
                project = try await MarketingApi.project(projectId).data
                members = try await MarketingApi.members(projectId).data ?? []
            } catch {
                host.fail(error, L("mktg_error_generic"))
            }
        }
        .weSheet(adding, onDismiss: { adding = false }) {
            AddMemberSheet(projectId: projectId) {
                adding = false
                reload += 1
            }
        }
        .weSheet(editing != nil, onDismiss: { editing = nil }) {
            if let m = editing {
                RoleSheet(current: m.role) { role in
                    editing = nil
                    Task {
                        do {
                            _ = try await MarketingApi.changeRole(projectId, member: m.id, role: role)
                            host.showToast(L("mktg_role_changed"))
                            reload += 1
                        } catch {
                            host.fail(error, L("mktg_error_generic"))
                        }
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func content(_ owner: Bool) -> some View {
        if let list = members {
            if list.isEmpty {
                WaEmpty(icon: "person.3", tone: .blue, title: L("mktg_team"), text: "")
            } else {
                WaCard(padding: 6) {
                    VStack(spacing: 0) {
                        ForEach(Array(list.enumerated()), id: \.element.id) { i, m in
                            row(m, owner: owner)
                            if i < list.count - 1 { WaDivider() }
                        }
                    }
                }
            }
            if project != nil && !owner {
                WaButton(text: L("mktg_leave"), style: .quiet, icon: "rectangle.portrait.and.arrow.right") {
                    host.confirm(WeDialogSpec(title: L("mktg_leave"), text: project?.name ?? "", confirm: L("mktg_leave"), danger: true) {
                        Task {
                            do {
                                _ = try await MarketingApi.leave(projectId)
                                host.open(.home)
                            } catch {
                                host.fail(error, L("mktg_error_generic"))
                            }
                        }
                    })
                }
                .padding(.top, 16)
            }
        } else {
            VStack(spacing: 10) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 64) } }
        }
    }

    private func row(_ m: MkMemberDto, owner: Bool) -> some View {
        VStack(spacing: 8) {
            HStack(spacing: 10) {
                WaIconWell(icon: "person.fill", tone: m.role == "owner" ? .red : .gray, size: 38, iconSize: 19)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: (m.name ?? m.phone ?? "") + (m.isMe == true ? " · " + L("mktg_you") : "")).font(.dorr(14, .bold)).foregroundStyle(wa.ink)
                    Text(verbatim: "\u{2066}\(m.phone ?? "")\u{2069}").font(.dorr(12)).foregroundStyle(wa.mut)
                }
                Spacer()
                MkSmallTag(text: mkRoleLabel(m.role), tint: m.role == "owner" ? wa.red : wa.mut)
            }
            if owner && m.role != "owner" {
                HStack(spacing: 8) {
                    WaButton(text: L("mktg_role"), style: .ghost, compact: true) { editing = m }
                    WaButton(text: L("mktg_remove"), style: .quiet, icon: "trash", compact: true) {
                        host.confirm(WeDialogSpec(title: L("mktg_remove"), text: m.name ?? m.phone ?? "", confirm: L("mktg_remove"), danger: true) {
                            Task {
                                do {
                                    _ = try await MarketingApi.removeMember(projectId, member: m.id)
                                    host.showToast(L("mktg_member_removed"))
                                    reload += 1
                                } catch {
                                    host.fail(error, L("mktg_error_generic"))
                                }
                            }
                        })
                    }
                }
                .padding(.leading, 48)
            }
        }
        .padding(10)
    }
}

private struct RolePicker: View {
    @Environment(\.wa) private var wa
    @Binding var selected: String

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 8) {
                ForEach(assignable, id: \.0) { key, label, _ in
                    WeChip(text: L(label), selected: selected == key) { selected = key }
                }
            }
            if let sub = assignable.first(where: { $0.0 == selected })?.2 {
                Text(verbatim: L(sub)).font(.dorr(12.5)).foregroundStyle(wa.mut)
            }
        }
    }
}

private struct AddMemberSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let projectId: String
    let onAdded: () -> Void
    @State private var phone = ""
    @State private var role = "viewer"
    @State private var busy = false

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(verbatim: L("mktg_add_member")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
            WeTextField(value: Binding(get: { phone }, set: { phone = String($0.filter { $0.isNumber || $0 == "+" }.prefix(16)) }),
                        label: L("mktg_phone"), placeholder: "+9665XXXXXXXX", icon: "phone", keyboard: .phonePad, ltr: true)
            Text(verbatim: L("mktg_role")).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut)
            RolePicker(selected: $role)
            WaButton(text: L("mktg_add_member"), enabled: phone.count >= 8, loading: busy) {
                Task {
                    busy = true
                    do {
                        _ = try await MarketingApi.addMember(projectId, MkMemberRequest(phone: phone, role: role))
                        host.showToast(L("mktg_member_added"))
                        onAdded()
                    } catch {
                        host.fail(error, L("mktg_error_generic"))
                    }
                    busy = false
                }
            }
        }
    }
}

private struct RoleSheet: View {
    @Environment(\.wa) private var wa
    let current: String
    let onPick: (String) -> Void
    @State private var role = ""

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(verbatim: L("mktg_role")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
            RolePicker(selected: $role)
            WaButton(text: L("mktg_save"), enabled: !role.isEmpty && role != current) { onPick(role) }
        }
        .onAppear { if role.isEmpty { role = current } }
    }
}
