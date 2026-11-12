import SwiftUI

/// androidApp `ui/screens/marketing/MarketingOptimise.kt` — phase D: suggestions with their numbers, alert rules, and
/// A/B experiments judged in code; plus the finance page's simulation. Nothing here changes a budget or a campaign —
/// every line is for a person to decide.

private let alertMetrics = ["cpa", "roas", "ctr", "spend", "conversions"]

private func mkAlertMetricLabel(_ m: String) -> String {
    switch m {
    case "roas": return L("mktg_k_roas")
    case "ctr": return L("mktg_k_ctr")
    case "spend": return L("mktg_k_spend")
    case "conversions": return L("mktg_k_conversions")
    default: return L("mktg_k_cpa")
    }
}

/// Money metrics are entered in major units and compared in minor units on the server.
private func mkIsMoney(_ metric: String) -> Bool { metric == "cpa" || metric == "spend" }

private func mkInputLabel(_ key: String) -> String {
    switch key {
    case "price_minor": return L("mktg_in_price")
    case "variable_cost_minor": return L("mktg_in_variable_cost")
    case "fixed_costs_minor": return L("mktg_in_fixed_costs")
    case "marketing_spend_minor": return L("mktg_in_marketing_spend")
    case "expected_new_customers": return L("mktg_in_new_customers")
    default: return L("mktg_in_churn")
    }
}

struct MarketingOptimise: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let projectId: String

    @State private var project: MkProjectDto?
    @State private var advice: MkOptimisationsDto?
    @State private var rules: [MkAlertRuleDto] = []
    @State private var experiments: [MkExperimentDto] = []
    @State private var reload = 0
    @State private var addingRule = false
    @State private var addingTest = false
    @State private var recording: MkExperimentDto?

    private var canEdit: Bool { project?.abilities?.edit == true }
    private var canResults: Bool { project?.abilities?.results == true }
    private var currency: String { project?.currency ?? "" }

    var body: some View {
        MkPage(title: L("mktg_optimise")) {
            if let a = advice {
                if let note = a.note { WaNote(text: note) }
                suggestions(a)
                rulesSection
                experimentsSection
            } else {
                VStack(spacing: 12) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 80) } }
            }
        }
        .task(id: "\(projectId)|\(reload)|\(net.reconnectTick)") { await load() }
        .weSheet(addingRule, onDismiss: { addingRule = false }) {
            MkAlertRuleSheet(projectId: projectId, currency: currency) {
                addingRule = false
                reload += 1
            }
        }
        .weSheet(addingTest, onDismiss: { addingTest = false }) {
            MkExperimentSheet(projectId: projectId) {
                addingTest = false
                reload += 1
            }
        }
        .weSheet(recording != nil, onDismiss: { recording = nil }) {
            if let e = recording {
                MkExperimentResultSheet(experiment: e) {
                    recording = nil
                    reload += 1
                }
                .id(e.id)
            }
        }
    }

    @ViewBuilder
    private func suggestions(_ a: MkOptimisationsDto) -> some View {
        WaSectionTitle(title: L("mktg_suggestions"))
        WaCard(padding: 6) {
            VStack(spacing: 0) {
                ForEach(Array(a.suggestions.enumerated()), id: \.offset) { i, s in
                    if i > 0 { WaDivider() }
                    HStack(alignment: .top, spacing: 10) {
                        Image(systemName: "lightbulb.fill").font(.system(size: 15, weight: .semibold))
                            .foregroundStyle(s.priority == "high" ? wa.danger : (s.priority == "medium" ? mkAmber : mkBlue)).padding(.top, 1)
                        Text(verbatim: s.text).font(.dorr(13.5)).foregroundStyle(wa.ink).lineSpacing(4).fixedSize(horizontal: false, vertical: true)
                        Spacer(minLength: 0)
                    }
                    .padding(10)
                }
            }
        }
    }

    private func addButton(_ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(verbatim: L("mktg_add")).font(.dorr(13, .heavy)).foregroundStyle(wa.red).padding(6)
        }
        .buttonStyle(WaPressStyle(scale: 0.94))
    }

    @ViewBuilder
    private var rulesSection: some View {
        WaSectionTitle(title: L("mktg_alert_rules")) {
            if canEdit { addButton { addingRule = true } }
        }
        if rules.isEmpty {
            Text(verbatim: L("mktg_no_alert_rules")).font(.dorr(12.5)).foregroundStyle(wa.soft).padding(.horizontal, 4)
        } else {
            VStack(spacing: 10) {
                ForEach(rules) { r in ruleCard(r) }
            }
        }
    }

    private func amount(_ value: Double, metric: String) -> String {
        mkIsMoney(metric) ? mkMoney(Int(value), currency) : mkNum(value)
    }

    private func ruleCard(_ r: MkAlertRuleDto) -> some View {
        let title = mkAlertMetricLabel(r.metric) + " " + L(r.comparison == "above" ? "mktg_above" : "mktg_below") + " " + amount(r.threshold, metric: r.metric)
        let now = r.current?.value.map { amount($0, metric: r.metric) } ?? L("mktg_r_missing_data")
        return WaCard(padding: 14) {
            HStack(spacing: 10) {
                WaIconWell(icon: "bell.badge.fill", tone: r.active ? .amber : .gray, size: 38, iconSize: 19)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: title).font(.dorr(13.5, .heavy)).foregroundStyle(wa.ink).fixedSize(horizontal: false, vertical: true)
                    Text(verbatim: L("mktg_last_n_days", r.windowDays) + " · " + L("mktg_now_value", now)).font(.dorr(12)).foregroundStyle(wa.mut)
                }
                Spacer(minLength: 6)
                if canEdit {
                    Toggle("", isOn: Binding(get: { r.active }, set: { on in Task { await toggle(r, on) } }))
                        .labelsHidden().tint(wa.red)
                    Button { Task { await remove(r) } } label: {
                        Image(systemName: "trash").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.soft)
                            .frame(width: 34, height: 34)
                            .contentShape(Circle())
                    }
                    .buttonStyle(WaPressStyle(scale: 0.9))
                    .accessibilityLabel(L("mktg_delete"))
                }
            }
        }
    }

    @ViewBuilder
    private var experimentsSection: some View {
        WaSectionTitle(title: L("mktg_experiments")) {
            if canEdit { addButton { addingTest = true } }
        }
        if experiments.isEmpty {
            Text(verbatim: L("mktg_no_experiments")).font(.dorr(12.5)).foregroundStyle(wa.soft).padding(.horizontal, 4)
        } else {
            VStack(spacing: 10) {
                ForEach(experiments) { e in experimentCard(e) }
            }
        }
    }

    private func experimentCard(_ e: MkExperimentDto) -> some View {
        let verdict = e.conclusion ?? e.preview
        let concluded = e.status == "CONCLUDED"
        let top = verdict.map { v in v.rates.values.compactMap { $0 }.max() ?? 0 } ?? 0
        return WaCard(padding: 14) {
            HStack(spacing: 10) {
                WaIconWell(icon: "flask.fill", tone: .blue, size: 38, iconSize: 19)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: e.name).font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
                    Text(verbatim: e.hypothesis).font(.dorr(12)).foregroundStyle(wa.mut).lineSpacing(2).fixedSize(horizontal: false, vertical: true)
                }
                Spacer(minLength: 6)
                MkBadge(text: L(concluded ? "mktg_concluded" : "mktg_running"), color: concluded ? wa.green : mkBlue)
            }
            VStack(spacing: 6) {
                ForEach(Array(e.variants.enumerated()), id: \.offset) { _, v in
                    rateRow(v.key, rate: verdict?.rate(v.key), top: top, winner: verdict?.winner == v.key)
                }
            }
            .padding(.top, 10)
            if let text = mkNonBlank(verdict?.text) {
                Text(verbatim: text).font(.dorr(12.5, .bold)).foregroundStyle(verdict?.verdict == "winner" ? wa.green : wa.mut)
                    .lineSpacing(3).fixedSize(horizontal: false, vertical: true).padding(.top, 8)
            }
            if !concluded && (canResults || canEdit) {
                HStack(spacing: 8) {
                    if canResults {
                        WaButton(text: L("mktg_results_add"), style: .ghost, compact: true) { recording = e }
                    }
                    if canEdit {
                        WaButton(text: L("mktg_conclude"), style: .quiet, compact: true) { Task { await conclude(e) } }
                    }
                }
                .padding(.top, 10)
            }
        }
    }

    private func rateRow(_ key: String, rate: Double?, top: Double, winner: Bool) -> some View {
        let fraction = top > 0 ? min(max((rate ?? 0) / top, 0), 1) : 0
        let tint = winner ? wa.green : mkBlue
        return HStack(spacing: 8) {
            Text(verbatim: key).font(.dorr(13, .black)).foregroundStyle(winner ? wa.green : wa.ink).frame(width: 22, alignment: .leading)
            MkBar(fraction: fraction, color: tint)
            Text(verbatim: rate.map { "\u{2066}\(mkNum($0))%\u{2069}" } ?? "—").font(.dorr(12.5, .bold)).foregroundStyle(wa.ink)
                .frame(width: 56, alignment: .trailing)
        }
    }

    private func load() async {
        do {
            if project == nil { project = try await MarketingApi.project(projectId).data }
            advice = try await MarketingApi.optimisations(projectId).data
            rules = try await MarketingApi.alertRules(projectId).data ?? []
            experiments = try await MarketingApi.experiments(projectId).data ?? []
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }

    private func toggle(_ r: MkAlertRuleDto, _ on: Bool) async {
        do {
            _ = try await MarketingApi.toggleAlertRule(r.id, MkActiveRequest(active: on))
            reload += 1
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }

    private func remove(_ r: MkAlertRuleDto) async {
        do {
            _ = try await MarketingApi.deleteAlertRule(r.id)
            reload += 1
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }

    private func conclude(_ e: MkExperimentDto) async {
        do {
            _ = try await MarketingApi.concludeExperiment(e.id)
            reload += 1
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }
}

private struct MkAlertRuleSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let projectId: String
    let currency: String
    let onSaved: () -> Void

    @State private var metric = "cpa"
    @State private var above = true
    @State private var value = ""
    @State private var window = 7
    @State private var busy = false

    private var parsed: Double? {
        if mkIsMoney(metric) { return mkParseMinor(value).map { Double($0) } }
        guard let v = Double(value.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: ",", with: ".")), v >= 0 else { return nil }
        return v
    }

    var body: some View {
        let unit = mkIsMoney(metric) ? currency : (metric == "ctr" ? "%" : (metric == "roas" ? "×" : ""))
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(verbatim: L("mktg_alert_new")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                WeFlow(spacing: 8) {
                    ForEach(alertMetrics, id: \.self) { m in
                        WeChip(text: mkAlertMetricLabel(m), selected: metric == m) {
                            metric = m
                            value = ""
                        }
                    }
                }
                HStack(spacing: 8) {
                    WeChip(text: L("mktg_above"), selected: above) { above = true }
                    WeChip(text: L("mktg_below"), selected: !above) { above = false }
                }
                WeTextField(value: Binding(get: { value }, set: { value = String($0.prefix(14)) }), label: L("mktg_threshold"),
                            keyboard: .decimalPad, error: !value.isEmpty && parsed == nil, trailing: unit, ltr: true)
                HStack(spacing: 8) {
                    ForEach([1, 7, 14, 30], id: \.self) { d in
                        WeChip(text: L("mktg_last_n_days", d), selected: window == d) { window = d }
                    }
                }
                WaButton(text: L("mktg_save"), enabled: parsed != nil, loading: busy) { Task { await save() } }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
        }
        .frame(maxHeight: UIScreen.main.bounds.height * 0.8)
        .fixedSize(horizontal: false, vertical: true)
    }

    private func save() async {
        busy = true
        do {
            _ = try await MarketingApi.createAlertRule(projectId, MkAlertRuleRequest(
                metric: metric, comparison: above ? "above" : "below", threshold: parsed ?? 0, windowDays: window
            ))
            onSaved()
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}

private struct MkExperimentSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let projectId: String
    let onSaved: () -> Void

    @State private var name = ""
    @State private var hypothesis = ""
    @State private var metric = "ctr"
    @State private var busy = false

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(verbatim: L("mktg_experiment_new")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
            WeTextField(value: $name, label: L("mktg_item_title"), limit: 160, capitalize: true)
            WeTextField(value: $hypothesis, label: L("mktg_hypothesis"), placeholder: L("mktg_hypothesis_hint"), minLines: 2, limit: 500, capitalize: true)
            HStack(spacing: 8) {
                WeChip(text: L("mktg_k_ctr"), selected: metric == "ctr") { metric = "ctr" }
                WeChip(text: L("mktg_conversion_rate"), selected: metric == "conversion_rate") { metric = "conversion_rate" }
            }
            WaButton(text: L("mktg_save"),
                     enabled: name.trimmingCharacters(in: .whitespacesAndNewlines).count >= 2 && hypothesis.trimmingCharacters(in: .whitespacesAndNewlines).count >= 5,
                     loading: busy) { Task { await save() } }
        }
        .padding(.horizontal, 20)
        .padding(.bottom, 20)
    }

    private func save() async {
        busy = true
        do {
            _ = try await MarketingApi.createExperiment(projectId, MkExperimentRequest(
                name: name.trimmingCharacters(in: .whitespacesAndNewlines),
                hypothesis: hypothesis.trimmingCharacters(in: .whitespacesAndNewlines),
                successMetric: metric
            ))
            onSaved()
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}

private struct MkExperimentResultSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let experiment: MkExperimentDto
    let onSaved: () -> Void

    @State private var variant = ""
    @State private var f: [String: String] = [:]
    @State private var busy = false

    private static let fields = [("impressions", "mktg_k_impressions"), ("clicks", "mktg_k_clicks"), ("conversions", "mktg_k_conversions")]

    private func n(_ key: String) -> Int? {
        guard let t = f[key]?.trimmingCharacters(in: .whitespaces), !t.isEmpty else { return nil }
        return mkParseMinor(t, decimals: 0)
    }

    var body: some View {
        let bad = (n("clicks") ?? 0) > (n("impressions") ?? Int.max) || (n("conversions") ?? 0) > (n("clicks") ?? Int.max)
        let any = Self.fields.contains { mkNonBlank(f[$0.0]) != nil }
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(verbatim: experiment.name).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                HStack(spacing: 8) {
                    ForEach(Array(experiment.variants.enumerated()), id: \.offset) { _, v in
                        WeChip(text: L("mktg_variant", v.key), selected: variant == v.key) { pick(v.key) }
                    }
                }
                ForEach(Self.fields, id: \.0) { key, label in
                    WeTextField(value: Binding(get: { f[key] ?? "" }, set: { f[key] = String($0.filter(\.isNumber).prefix(12)) }),
                                label: L(label), keyboard: .numberPad, ltr: true)
                }
                if bad {
                    Text(verbatim: L("mktg_clicks_too_many")).font(.dorr(12)).foregroundStyle(wa.danger)
                }
                Text(verbatim: L("mktg_experiment_min")).font(.dorr(11.5)).foregroundStyle(wa.soft).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                WaButton(text: L("mktg_save"), enabled: !bad && any, loading: busy) { Task { await save() } }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
        }
        .frame(maxHeight: UIScreen.main.bounds.height * 0.8)
        .fixedSize(horizontal: false, vertical: true)
        .onAppear { if variant.isEmpty { pick(experiment.variants.first?.key ?? "A") } }
    }

    /// The chosen variant's numbers so far fill the fields.
    private func pick(_ key: String) {
        variant = key
        let r = experiment.results[key] ?? [:]
        for (k, _) in Self.fields {
            f[k] = (r[k] ?? nil).map { String($0) } ?? ""
        }
    }

    private func save() async {
        busy = true
        do {
            _ = try await MarketingApi.experimentResults(experiment.id, MkExperimentResultRequest(
                variant: variant, impressions: n("impressions"), clicks: n("clicks"), conversions: n("conversions")
            ))
            onSaved()
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}

/// On the finance page: which input moves the monthly profit most (estimates from the person's own inputs).
struct MkSimulationBlock: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let planId: String
    let currency: String

    @State private var swing = 20
    @State private var sim: MkSimulationDto?
    @State private var busy = false

    var body: some View {
        WaSectionTitle(title: L("mktg_simulation"))
        WaCard(padding: 14) {
            Text(verbatim: L("mktg_simulation_note")).font(.dorr(12.5)).foregroundStyle(wa.mut).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
            HStack(spacing: 8) {
                ForEach([10, 20, 30], id: \.self) { s in
                    WeChip(text: "±\(s)%", selected: swing == s) { swing = s }
                }
                Spacer(minLength: 4)
                WaButton(text: L("mktg_simulate"), loading: busy, compact: true) { Task { await run() } }
                    .fixedSize()
            }
            .padding(.top, 10)
            if let s = sim {
                result(s).padding(.top, 12)
            }
        }
    }

    @ViewBuilder
    private func result(_ s: MkSimulationDto) -> some View {
        if s.tornado.isEmpty {
            Text(verbatim: L("mktg_no_finance")).font(.dorr(12.5)).foregroundStyle(wa.mut)
        } else {
            let top = max(s.tornado.map(\.swingMinor).max() ?? 1, 1)
            VStack(alignment: .leading, spacing: 8) {
                Text(verbatim: L("mktg_base_profit", mkMoney(s.baseProfitMinor, currency))).font(.dorr(13, .bold)).foregroundStyle(wa.ink)
                ForEach(Array(s.tornado.enumerated()), id: \.offset) { _, t in
                    VStack(alignment: .leading, spacing: 4) {
                        Text(verbatim: mkInputLabel(t.input)).font(.dorr(12.5, .bold)).foregroundStyle(wa.ink)
                        MkBar(fraction: Double(t.swingMinor) / Double(top), color: mkAmber)
                        Text(verbatim: "\u{2066}\(mkMoney(t.lowProfitMinor, ""))  …  \(mkMoney(t.highProfitMinor, ""))\u{2069}").font(.dorr(11.5)).foregroundStyle(wa.mut)
                    }
                }
            }
        }
    }

    private func run() async {
        busy = true
        do {
            sim = try await MarketingApi.simulate(planId, MkSimulateRequest(swingPct: swing)).data
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}
