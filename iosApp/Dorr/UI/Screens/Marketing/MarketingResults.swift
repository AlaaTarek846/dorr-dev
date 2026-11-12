import SwiftUI
import UniformTypeIdentifiers

/// androidApp `ui/screens/marketing/MarketingResults.kt` — phase B: budgets (U08), the results and KPI board with the
/// CSV import (U11), and the executive report (U12).

private func mkLevelColor(_ level: String) -> Color {
    switch level {
    case "exceeded": return Color(hex: 0xDC2626)
    case "warning": return Color(hex: 0xD97706)
    default: return Color(hex: 0x16A34A)
    }
}

private func mkBudgetStatusLabel(_ status: String) -> String {
    switch status {
    case "APPROVED": return L("mktg_bs_APPROVED")
    case "CLOSED": return L("mktg_bs_CLOSED")
    default: return L("mktg_bs_PENDING")
    }
}

private func mkReasonLabel(_ reason: String?) -> String {
    switch reason {
    case "no_impressions": return L("mktg_r_no_impressions")
    case "no_clicks": return L("mktg_r_no_clicks")
    case "no_conversions": return L("mktg_r_no_conversions")
    case "no_spend": return L("mktg_r_no_spend")
    case "no_revenue_data": return L("mktg_r_no_revenue_data")
    default: return L("mktg_r_missing_data")
    }
}

/// "2026-11-03" → "3 Nov" in the app's language.
private func mkShortDate(_ iso: String) -> String {
    calDay(iso).map { mkFormat($0, "d MMM") } ?? iso
}

private func mkRateText(_ rate: MkRateDto?, _ suffix: String) -> String {
    rate?.value.map { mkNum($0) + suffix } ?? "—"
}

// MARK: - budgets (U08)

/// Two caps that never mix: ad spend (money, counted from the results entered) and AI (marketing AI calls, refused
/// by the server once used up). The owner sets or approves; a manager's cap waits for the owner.
struct MarketingBudgets: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let projectId: String

    @State private var project: MkProjectDto?
    @State private var budgets: [MkBudgetDto]?
    @State private var reload = 0
    @State private var adding = false
    @State private var changing: MkBudgetDto?
    @State private var busy: String?

    var body: some View {
        let abilities = project?.abilities
        let currency = project?.currency ?? ""
        MkPage(title: L("mktg_budgets")) {
            WaNote(text: L("mktg_budgets_note"))
            if abilities?.edit == true {
                WaButton(text: L("mktg_budget_add"), icon: "plus", compact: true) { adding = true }.padding(.top, 12)
            }
            list(abilities, currency).padding(.top, 12)
        }
        .task(id: "\(projectId)|\(reload)|\(net.reconnectTick)|\(host.projectChanges(projectId))") { await load() }
        .weSheet(adding || changing != nil, onDismiss: { adding = false; changing = nil }) {
            MkBudgetSheet(projectId: projectId, existing: changing, currency: currency) {
                adding = false
                changing = nil
                reload += 1
            }
            .id(changing?.id ?? "new")
        }
    }

    @ViewBuilder
    private func list(_ abilities: MkAbilitiesDto?, _ currency: String) -> some View {
        if let list = budgets {
            if list.isEmpty {
                WaEmpty(icon: "banknote", tone: .gray, title: L("mktg_budgets_empty"), text: L("mktg_budgets_empty_sub"))
            } else {
                VStack(spacing: 12) {
                    ForEach(Array(list.enumerated()), id: \.element.id) { i, b in
                        MkBudgetCard(
                            b: b, currency: currency,
                            canApprove: abilities?.approveBudget == true, canEdit: abilities?.edit == true, canClose: abilities?.manageTeam == true,
                            busy: busy,
                            onApprove: { Task { await approve(b) } },
                            onChange: { changing = b },
                            onClose: { close(b) }
                        )
                        .waRise(i)
                    }
                }
            }
        } else {
            VStack(spacing: 12) { ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 120) } }
        }
    }

    private func load() async {
        do {
            if project == nil { project = try await MarketingApi.project(projectId).data }
            budgets = try await MarketingApi.budgets(projectId).data ?? []
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }

    private func approve(_ b: MkBudgetDto) async {
        busy = "approve:\(b.id)"
        do {
            _ = try await MarketingApi.approveBudget(b.id, MkLockRequest(lockVersion: b.lockVersion))
            host.showToast(L("mktg_budget_approved"))
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        reload += 1
        busy = nil
    }

    private func close(_ b: MkBudgetDto) {
        host.confirm(WeDialogSpec(title: L("mktg_budget_close"), text: L("mktg_budget_close_text"), confirm: L("mktg_budget_close")) {
            Task {
                do {
                    _ = try await MarketingApi.closeBudget(b.id)
                    reload += 1
                } catch {
                    host.fail(error, L("mktg_error_generic"))
                }
            }
        })
    }
}

private struct MkBudgetCard: View {
    @Environment(\.wa) private var wa
    let b: MkBudgetDto
    let currency: String
    let canApprove: Bool
    let canEdit: Bool
    let canClose: Bool
    let busy: String?
    let onApprove: () -> Void
    let onChange: () -> Void
    let onClose: () -> Void

    var body: some View {
        let ads = b.kind == "ads"
        let money = b.currency ?? currency
        let level = mkLevelColor(b.level)
        WaCard(padding: 16) {
            HStack(spacing: 12) {
                WaIconWell(icon: ads ? "banknote.fill" : "cpu", tone: ads ? .green : .blue, size: 42, iconSize: 21)
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: L(ads ? "mktg_budget_ads" : "mktg_budget_ai")).font(.dorr(14.5, .heavy)).foregroundStyle(wa.ink)
                    Text(verbatim: mkShortDate(b.periodStart) + " – " + mkShortDate(b.periodEnd)).font(.dorr(12.5)).foregroundStyle(wa.mut)
                }
                Spacer(minLength: 6)
                MkBadge(text: mkBudgetStatusLabel(b.status), color: b.status == "APPROVED" ? wa.green : (b.status == "CLOSED" ? wa.mut : mkAmber))
            }
            HStack(alignment: .lastTextBaseline, spacing: 6) {
                Text(verbatim: "\u{2066}\(ads ? mkMoney(b.used, money) : String(b.used))\u{2069}").font(.dorr(20, .black)).foregroundStyle(wa.ink)
                Text(verbatim: L("mktg_of_cap", ads ? mkMoney(b.cap, money) : L("mktg_calls", b.cap))).font(.dorr(12.5)).foregroundStyle(wa.mut).lineLimit(1)
                Spacer(minLength: 6)
                Text(verbatim: "\u{2066}\(mkNum(b.pct ?? 0))%\u{2069}").font(.dorr(14, .heavy)).foregroundStyle(level)
            }
            .padding(.top, 14)
            MkBar(fraction: (b.pct ?? 0) / 100, color: level).padding(.top, 8)
            Text(verbatim: L("mktg_alert_at", b.alertPct)).font(.dorr(11.5)).foregroundStyle(wa.soft).padding(.top, 6)
            notes(ads: ads, money: money, level: level)
            actions
        }
    }

    @ViewBuilder
    private func notes(ads: Bool, money: String, level: Color) -> some View {
        if ads, let estimated = b.estimated, estimated > 0 {
            Text(verbatim: L("mktg_estimated_apart", mkMoney(estimated, money))).font(.dorr(12)).foregroundStyle(wa.mut)
        }
        if b.level != "ok" && b.status == "APPROVED" {
            Text(verbatim: L(b.level == "exceeded" ? "mktg_budget_exceeded" : "mktg_budget_warning")).font(.dorr(12.5, .bold)).foregroundStyle(level)
                .fixedSize(horizontal: false, vertical: true).padding(.top, 8)
        }
        if b.status == "PENDING" {
            Text(verbatim: L("mktg_budget_pending_note")).font(.dorr(12.5)).foregroundStyle(mkAmber).fixedSize(horizontal: false, vertical: true).padding(.top, 8)
        }
    }

    @ViewBuilder
    private var actions: some View {
        if b.status != "CLOSED" && (canApprove || canEdit || canClose) {
            HStack(spacing: 8) {
                if b.status == "PENDING" && canApprove {
                    WaButton(text: L("mktg_approve_budget"), icon: "checkmark.seal", loading: busy == "approve:\(b.id)", compact: true, action: onApprove)
                }
                if canEdit {
                    WaButton(text: L("mktg_budget_change"), style: .ghost, compact: true, action: onChange)
                }
                if canClose {
                    WaButton(text: L("mktg_budget_close"), style: .quiet, icon: "lock", compact: true, action: onClose)
                }
            }
            .padding(.top, 12)
        }
    }
}

/// New budget, or a new cap for one (owner: applies now; manager: waits for the owner).
private struct MkBudgetSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let projectId: String
    let existing: MkBudgetDto?
    let currency: String
    let onSaved: () -> Void

    @State private var kind: String
    @State private var start: Date
    @State private var end: Date
    @State private var cap: String
    @State private var alert: Int
    @State private var picking: String?
    @State private var busy = false

    init(projectId: String, existing: MkBudgetDto?, currency: String, onSaved: @escaping () -> Void) {
        self.projectId = projectId
        self.existing = existing
        self.currency = currency
        self.onSaved = onSaved
        let cal = calCalendar
        let today = calToday()
        let monthStart = cal.date(from: cal.dateComponents([.year, .month], from: today))
            .flatMap { cal.date(bySettingHour: 12, minute: 0, second: 0, of: $0) } ?? today
        let monthEnd = cal.date(byAdding: DateComponents(month: 1, day: -1), to: monthStart) ?? today
        _kind = State(initialValue: existing?.kind ?? "ads")
        _start = State(initialValue: calDay(existing?.periodStart) ?? monthStart)
        _end = State(initialValue: calDay(existing?.periodEnd) ?? monthEnd)
        var text = ""
        if let existing {
            text = existing.kind == "ads" ? mkMoney(existing.cap, "").replacingOccurrences(of: ",", with: "") : String(existing.cap)
        }
        _cap = State(initialValue: text)
        _alert = State(initialValue: existing?.alertPct ?? 80)
    }

    /// Money in minor units for ads, a count of calls for AI (Arabic digits too).
    private var capValue: Int? { kind == "ads" ? mkParseMinor(cap) : mkParseMinor(cap, decimals: 0) }

    var body: some View {
        let value = capValue
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(verbatim: L(existing == nil ? "mktg_budget_add" : "mktg_budget_change")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                if existing == nil { newFields }
                WeTextField(
                    value: Binding(get: { cap }, set: { cap = kind == "ads" ? String($0.prefix(14)) : String($0.filter(\.isNumber).prefix(6)) }),
                    label: L(kind == "ads" ? "mktg_cap_money" : "mktg_cap_calls"),
                    placeholder: kind == "ads" ? "0.00" : "0",
                    keyboard: kind == "ads" ? .decimalPad : .numberPad,
                    error: !cap.isEmpty && (value ?? 0) <= 0,
                    trailing: kind == "ads" ? currency : nil,
                    ltr: true
                )
                Text(verbatim: L("mktg_alert_line")).font(.dorr(12.5, .bold)).foregroundStyle(wa.mut)
                HStack(spacing: 8) {
                    ForEach([70, 80, 90], id: \.self) { p in
                        WeChip(text: "\(p)%", selected: alert == p) { alert = p }
                    }
                }
                WaButton(text: L("mktg_save"), enabled: (value ?? 0) > 0 && !(end < start), loading: busy) { Task { await save() } }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
        }
        .frame(maxHeight: UIScreen.main.bounds.height * 0.8)
        .fixedSize(horizontal: false, vertical: true)
        .dorrDialog(isPresented: Binding(get: { picking != nil }, set: { if !$0 { picking = nil } })) {
            CalDatePickerDialog(initial: picking == "end" ? end : start, onDismiss: { picking = nil }) { d in
                if picking == "end" {
                    end = d < start ? start : d
                } else {
                    start = d
                    if end < d { end = d }
                }
                picking = nil
            }
        }
    }

    @ViewBuilder
    private var newFields: some View {
        HStack(spacing: 8) {
            WeChip(text: L("mktg_budget_ads"), selected: kind == "ads") { kind = "ads"; cap = "" }
            WeChip(text: L("mktg_budget_ai"), selected: kind == "ai") { kind = "ai"; cap = "" }
        }
        Text(verbatim: L(kind == "ads" ? "mktg_budget_ads_sub" : "mktg_budget_ai_sub")).font(.dorr(12.5)).foregroundStyle(wa.mut)
            .lineSpacing(3).fixedSize(horizontal: false, vertical: true)
        HStack(alignment: .top, spacing: 10) {
            MkPickField(label: L("mktg_from"), value: mkShortDate(calKey(start)), icon: "calendar") { picking = "start" }
            MkPickField(label: L("mktg_to"), value: mkShortDate(calKey(end)), icon: "calendar") { picking = "end" }
        }
    }

    private func save() async {
        guard let value = capValue else { return }
        busy = true
        do {
            if let existing {
                _ = try await MarketingApi.updateBudget(existing.id, MkBudgetUpdateRequest(
                    lockVersion: existing.lockVersion, capMinor: kind == "ads" ? value : nil, capCalls: kind == "ai" ? value : nil, alertPct: alert
                ))
            } else {
                _ = try await MarketingApi.createBudget(projectId, MkBudgetRequest(
                    kind: kind, periodStart: calKey(start), periodEnd: calKey(end),
                    capMinor: kind == "ads" ? value : nil, capCalls: kind == "ai" ? value : nil, alertPct: alert
                ))
            }
            host.showToast(L("mktg_saved"))
            onSaved()
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}

// MARK: - results + KPI board (U11)

/// The KPI board: what was measured (copied from the platforms' reports) and what was estimated are never added
/// together; a rate without what it needs says why instead of showing 0. Results come by hand or from a CSV file.
struct MarketingResultsPage: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let projectId: String

    @State private var project: MkProjectDto?
    @State private var days = 30
    @State private var board: MkKpiBoardDto?
    @State private var rows: [MkMetricRowDto] = []
    @State private var reload = 0
    @State private var adding = false
    @State private var picking = false
    @State private var importing = false
    @State private var importErrors: String?

    var body: some View {
        let canEnter = project?.abilities?.results == true
        let currency = board?.currency ?? project?.currency ?? ""
        MkPage(title: L("mktg_results")) {
            HStack(spacing: 8) {
                ForEach([7, 30, 90], id: \.self) { d in
                    WeChip(text: L("mktg_last_n_days", d), selected: days == d) { days = d }
                }
            }
            if canEnter { entryButtons }
            if let importErrors {
                WaCard(padding: 14) {
                    Text(verbatim: L("mktg_file_refused")).font(.dorr(13, .bold)).foregroundStyle(wa.danger)
                    Text(verbatim: importErrors).font(.dorr(12.5)).foregroundStyle(wa.ink).lineSpacing(4).fixedSize(horizontal: false, vertical: true).padding(.top, 4)
                }
                .padding(.top, 10)
            }
            if let b = board {
                boardView(b, currency: currency, canEnter: canEnter)
            } else {
                VStack(spacing: 12) { ForEach(0..<2, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 110) } }.padding(.top, 12)
            }
        }
        .task(id: "\(projectId)|\(days)|\(reload)|\(net.reconnectTick)") { await load() }
        .weSheet(adding, onDismiss: { adding = false }) {
            MkAddResultsSheet(projectId: projectId, currency: currency) {
                adding = false
                reload += 1
            }
        }
        .fileImporter(isPresented: $picking, allowedContentTypes: [.commaSeparatedText, .plainText, .item]) { result in
            guard case .success(let url) = result else { return }
            let scoped = url.startAccessingSecurityScopedResource()
            let bytes = try? Data(contentsOf: url)
            if scoped { url.stopAccessingSecurityScopedResource() }
            Task { await importFile(bytes) }
        }
    }

    private var entryButtons: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 8) {
                WaButton(text: L("mktg_results_add"), icon: "plus", compact: true) { adding = true }
                WaButton(text: L("mktg_results_import"), style: .ghost, icon: "square.and.arrow.down", loading: importing, compact: true) { picking = true }
            }
            Text(verbatim: L("mktg_import_hint")).font(.dorr(11.5)).foregroundStyle(wa.soft).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                .padding(.horizontal, 4)
        }
        .padding(.top, 12)
    }

    @ViewBuilder
    private func boardView(_ b: MkKpiBoardDto, currency: String, canEnter: Bool) -> some View {
        WaSectionTitle(title: L("mktg_measured")) { MkSmallTag(text: L("mktg_basis_imported"), tint: wa.green) }
        if b.gaps.daysWithData == 0 {
            WaEmpty(icon: "chart.line.uptrend.xyaxis", tone: .gray, title: L("mktg_no_results"), text: L("mktg_no_results_sub"))
        } else {
            measured(b, currency: currency)
        }
        if let est = b.estimated {
            WaSectionTitle(title: L("mktg_estimated_section")) { MkSmallTag(text: L("mktg_basis_estimated"), tint: mkAmber) }
            Text(verbatim: L("mktg_estimated_note")).font(.dorr(12.5)).foregroundStyle(wa.mut).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                .padding(.horizontal, 4).padding(.bottom, 8)
            MkKpiGrid(group: est, currency: currency)
        }
        if !b.byChannel.isEmpty {
            WaSectionTitle(title: L("mktg_by_channel"))
            VStack(spacing: 10) {
                ForEach(Array(b.byChannel.enumerated()), id: \.offset) { _, ch in channelCard(ch) }
            }
        }
        if !rows.isEmpty {
            WaSectionTitle(title: L("mktg_entries"))
            entries(canEnter: canEnter)
        }
    }

    @ViewBuilder
    private func measured(_ b: MkKpiBoardDto, currency: String) -> some View {
        MkKpiGrid(group: b.measured, currency: currency)
        MkSpendChart(board: b).padding(.top, 10)
        let updated = b.lastUpdatedAt.map { mkDateTime($0) } ?? ""
        Text(verbatim: L("mktg_coverage", b.gaps.daysWithData, b.gaps.days) + (updated.isEmpty ? "" : " · " + L("mktg_updated", updated)))
            .font(.dorr(12)).foregroundStyle(wa.mut).padding(.top, 8)
        if !b.gaps.channelsWithoutRevenue.isEmpty {
            WaNote(text: L("mktg_no_revenue_for", b.gaps.channelsWithoutRevenue.map { mkChannelLabel($0) }.joined(separator: "، "))).padding(.top, 8)
        }
    }

    private func channelCard(_ ch: MkChannelKpiDto) -> some View {
        WaCard(padding: 14) {
            HStack {
                Text(verbatim: mkChannelLabel(ch.channel)).font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
                Spacer()
                if let last = ch.lastDate { Text(verbatim: mkShortDate(last)).font(.dorr(11.5)).foregroundStyle(wa.soft) }
            }
            HStack(alignment: .top, spacing: 14) {
                MkMiniStat(label: L("mktg_k_spend"), value: mkMoney(ch.measured.total("spend_minor"), ""))
                MkMiniStat(label: L("mktg_k_conversions"), value: ch.measured.total("conversions").map { String($0) } ?? "—")
                MkMiniStat(label: L("mktg_k_roas"), value: mkRateText(ch.measured.rates["roas"], "×"))
            }
            .padding(.top, 8)
        }
    }

    private func entries(canEnter: Bool) -> some View {
        let shown = Array(rows.prefix(30))
        return WaCard(padding: 6) {
            VStack(spacing: 0) {
                ForEach(Array(shown.enumerated()), id: \.element.id) { i, r in
                    if i > 0 { WaDivider() }
                    entryRow(r, canEnter: canEnter)
                }
            }
        }
    }

    private func entryRow(_ r: MkMetricRowDto, canEnter: Bool) -> some View {
        let parts = [
            r.spendMinor.map { L("mktg_k_spend") + " " + mkMoney($0, "") },
            r.clicks.map { L("mktg_k_clicks") + " " + String($0) },
            r.conversions.map { L("mktg_k_conversions") + " " + String($0) },
        ].compactMap { $0 }
        let estimated = r.basis == "estimated"
        return HStack(spacing: 6) {
            VStack(alignment: .leading, spacing: 2) {
                Text(verbatim: mkShortDate(r.date) + " · " + mkChannelLabel(r.channel)).font(.dorr(13, .bold)).foregroundStyle(wa.ink)
                if !parts.isEmpty { Text(verbatim: parts.joined(separator: " · ")).font(.dorr(12)).foregroundStyle(wa.mut).lineLimit(2) }
            }
            Spacer(minLength: 6)
            MkSmallTag(text: L(estimated ? "mktg_basis_estimated" : "mktg_basis_imported"), tint: estimated ? mkAmber : wa.green)
            if canEnter {
                Button { remove(r) } label: {
                    Image(systemName: "trash").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.soft)
                        .frame(width: 32, height: 32)
                        .contentShape(Circle())
                }
                .buttonStyle(WaPressStyle(scale: 0.9))
                .accessibilityLabel(L("mktg_delete"))
            }
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 10)
    }

    private func remove(_ r: MkMetricRowDto) {
        host.confirm(WeDialogSpec(title: L("mktg_delete"), text: mkShortDate(r.date) + " · " + mkChannelLabel(r.channel), confirm: L("mktg_delete"), danger: true) {
            Task {
                do {
                    _ = try await MarketingApi.deleteMetric(r.id)
                    reload += 1
                } catch {
                    host.fail(error, L("mktg_error_generic"))
                }
            }
        })
    }

    private func load() async {
        do {
            if project == nil { project = try await MarketingApi.project(projectId).data }
            let to = calToday()
            let from = calAddDays(to, -(days - 1))
            board = try await MarketingApi.kpis(projectId, from: calKey(from), to: calKey(to)).data
            rows = try await MarketingApi.metrics(projectId).data ?? []
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
    }

    /// The whole file goes up; the server takes all of it or nothing and says which lines it refused.
    private func importFile(_ bytes: Data?) async {
        guard let bytes, bytes.count <= 1_000_000 else {
            host.showToast(L(bytes == nil ? "mktg_error_generic" : "mktg_file_too_big"), error: true)
            return
        }
        importing = true
        importErrors = nil
        do {
            let r = try await MarketingApi.importMetrics(projectId, file: ApiClient.FilePart(field: "file", filename: "results.csv", mimeType: "text/csv", data: bytes)).data
            host.showToast(L("mktg_results_imported") + " (\((r?.created ?? 0) + (r?.updated ?? 0)))")
            reload += 1
        } catch {
            let failure = host.fail(error, L("mktg_error_generic"))
            let errors = failure.data?["errors"]?.arrayValue ?? []
            let lines = errors.prefix(6).map { e in L("mktg_file_line", e["line"]?.int ?? 0, e["field"]?.str ?? "") }
            importErrors = lines.isEmpty ? nil : lines.joined(separator: "\n")
        }
        importing = false
    }
}

private struct MkMiniStat: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(verbatim: label).font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(1)
            Text(verbatim: "\u{2066}\(value)\u{2069}").font(.dorr(14, .heavy)).foregroundStyle(wa.ink).lineLimit(1).minimumScaleFactor(0.7)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// Six tiles: totals and rates; a rate that can't be computed says why.
struct MkKpiGrid: View {
    @Environment(\.wa) private var wa
    let group: MkKpiGroupDto
    let currency: String

    private struct Tile {
        let label: String
        let value: String
        let reason: String?
    }

    var body: some View {
        let g = group
        let cpa = g.rates["cpa_minor"]
        let tiles = [
            Tile(label: L("mktg_k_spend"), value: mkMoney(g.total("spend_minor"), currency), reason: nil),
            Tile(label: L("mktg_k_revenue"), value: mkMoney(g.total("revenue_minor"), currency), reason: nil),
            Tile(label: L("mktg_k_roas"), value: mkRateText(g.rates["roas"], "×"), reason: missing(g.rates["roas"])),
            Tile(label: L("mktg_k_cpa"), value: cpa?.value.map { mkMoney(Int($0), currency) } ?? "—", reason: missing(cpa)),
            Tile(label: L("mktg_k_ctr"), value: mkRateText(g.rates["ctr_pct"], "%"), reason: missing(g.rates["ctr_pct"])),
            Tile(label: L("mktg_k_conversions"), value: g.total("conversions").map { String($0) } ?? "—", reason: nil),
        ]
        VStack(spacing: 10) {
            ForEach(0..<3, id: \.self) { row in
                HStack(alignment: .top, spacing: 10) {
                    tile(tiles[row * 2])
                    tile(tiles[row * 2 + 1])
                }
            }
        }
    }

    private func missing(_ rate: MkRateDto?) -> String? {
        guard let rate, rate.value == nil else { return nil }
        return rate.reason
    }

    private func tile(_ t: Tile) -> some View {
        WaCard(padding: 14) {
            Text(verbatim: t.label).font(.dorr(12)).foregroundStyle(wa.mut).lineLimit(1)
            Text(verbatim: "\u{2066}\(t.value)\u{2069}").font(.dorr(17, .black)).foregroundStyle(t.value == "—" ? wa.soft : wa.ink)
                .lineLimit(1).minimumScaleFactor(0.6).padding(.top, 4)
            if let reason = t.reason {
                Text(verbatim: mkReasonLabel(reason)).font(.dorr(11)).foregroundStyle(wa.soft).lineLimit(2).fixedSize(horizontal: false, vertical: true)
            }
        }
    }
}

/// Daily measured spend as bars (no axis numbers: the totals above carry the figures). Right-to-left in Arabic.
private struct MkSpendChart: View {
    @Environment(\.wa) private var wa
    let board: MkKpiBoardDto

    var body: some View {
        let points = board.series
        if points.count >= 2 {
            let top = max(points.map { $0.spendMinor ?? 0 }.max() ?? 1, 1)
            WaCard(padding: 14) {
                Text(verbatim: L("mktg_spend_per_day")).font(.dorr(12, .bold)).foregroundStyle(wa.mut)
                HStack(alignment: .bottom, spacing: points.count > 40 ? 1 : 3) {
                    ForEach(Array(points.enumerated()), id: \.offset) { _, p in
                        RoundedRectangle(cornerRadius: 3, style: .continuous)
                            .fill(wa.red)
                            .frame(height: max(2, CGFloat(p.spendMinor ?? 0) / CGFloat(top) * 80))
                            .frame(maxWidth: .infinity)
                    }
                }
                .frame(height: 80, alignment: .bottom)
                .padding(.top, 10)
                HStack {
                    Text(verbatim: mkShortDate(points[0].date)).font(.dorr(10.5)).foregroundStyle(wa.soft)
                    Spacer()
                    Text(verbatim: mkShortDate(points[points.count - 1].date)).font(.dorr(10.5)).foregroundStyle(wa.soft)
                }
                .padding(.top, 4)
            }
        }
    }
}

private struct MkAddResultsSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let projectId: String
    let currency: String
    let onSaved: () -> Void

    @State private var date = calAddDays(calToday(), -1)
    @State private var channel = "instagram"
    @State private var basis = "imported"
    @State private var f: [String: String] = [:]
    @State private var pickDate = false
    @State private var busy = false

    private func count(_ key: String) -> Int? {
        guard let t = f[key]?.trimmingCharacters(in: .whitespaces), !t.isEmpty else { return nil }
        return mkParseMinor(t, decimals: 0)
    }

    private func money(_ key: String) -> Int? {
        guard let t = f[key]?.trimmingCharacters(in: .whitespaces), !t.isEmpty else { return nil }
        return mkParseMinor(t)
    }

    private func badMoney(_ key: String) -> Bool { mkNonBlank(f[key]) != nil && money(key) == nil }

    private func binding(_ key: String, limit: Int, digits: Bool) -> Binding<String> {
        Binding(get: { f[key] ?? "" }, set: { f[key] = String((digits ? $0.filter(\.isNumber) : $0).prefix(limit)) })
    }

    var body: some View {
        let clicksTooMany = (count("clicks") ?? 0) > (count("impressions") ?? Int.max)
        let empty = ["impressions", "clicks", "spend", "conversions", "revenue"].allSatisfy { mkNonBlank(f[$0]) == nil }
        let bad = badMoney("spend") || badMoney("revenue")
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(verbatim: L("mktg_results_add")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                MkPickField(label: L("mktg_day"), value: mkFormat(date, "EEE d MMM yyyy"), icon: "calendar") { pickDate = true }
                WeFlow(spacing: 8) {
                    ForEach(mkChannels.filter { $0 != "offline" }, id: \.self) { ch in
                        WeChip(text: mkChannelLabel(ch), selected: channel == ch) { channel = ch }
                    }
                }
                basisPicker
                HStack(alignment: .top, spacing: 10) {
                    WeTextField(value: binding("impressions", limit: 12, digits: true), label: L("mktg_k_impressions"), keyboard: .numberPad, ltr: true)
                    WeTextField(value: binding("clicks", limit: 12, digits: true), label: L("mktg_k_clicks"), keyboard: .numberPad, error: clicksTooMany, ltr: true)
                }
                HStack(alignment: .top, spacing: 10) {
                    WeTextField(value: binding("spend", limit: 14, digits: false), label: L("mktg_k_spend"), placeholder: "0.00", keyboard: .decimalPad, error: badMoney("spend"), ltr: true)
                    WeTextField(value: binding("revenue", limit: 14, digits: false), label: L("mktg_k_revenue"), placeholder: "0.00", keyboard: .decimalPad, error: badMoney("revenue"), ltr: true)
                }
                WeTextField(value: binding("conversions", limit: 9, digits: true), label: L("mktg_k_conversions"), keyboard: .numberPad, ltr: true)
                Text(verbatim: L("mktg_results_blank_hint", currency)).font(.dorr(11.5)).foregroundStyle(wa.soft).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                if clicksTooMany {
                    Text(verbatim: L("mktg_clicks_too_many")).font(.dorr(12)).foregroundStyle(wa.danger)
                }
                WaButton(text: L("mktg_save"), enabled: !empty && !bad && !clicksTooMany, loading: busy) { Task { await save() } }
            }
            .padding(.horizontal, 20)
            .padding(.bottom, 20)
        }
        .frame(maxHeight: UIScreen.main.bounds.height * 0.85)
        .fixedSize(horizontal: false, vertical: true)
        .dorrDialog(isPresented: $pickDate) {
            CalDatePickerDialog(initial: date, onDismiss: { pickDate = false }) { picked in
                let today = calToday()
                date = calKey(picked) > calKey(today) ? today : picked
                pickDate = false
            }
        }
    }

    @ViewBuilder
    private var basisPicker: some View {
        HStack(spacing: 8) {
            WeChip(text: L("mktg_basis_imported"), selected: basis == "imported") { basis = "imported" }
            WeChip(text: L("mktg_basis_estimated"), selected: basis == "estimated") { basis = "estimated" }
        }
        Text(verbatim: L(basis == "imported" ? "mktg_basis_imported_sub" : "mktg_basis_estimated_sub")).font(.dorr(12)).foregroundStyle(wa.mut)
            .lineSpacing(3).fixedSize(horizontal: false, vertical: true)
    }

    private func save() async {
        busy = true
        let row = MkMetricInput(
            date: calKey(date), channel: channel, basis: basis,
            impressions: count("impressions"), clicks: count("clicks"), spendMinor: money("spend"),
            conversions: count("conversions"), revenueMinor: money("revenue")
        )
        do {
            _ = try await MarketingApi.saveMetrics(projectId, MkMetricsRequest(rows: [row]))
            host.showToast(L("mktg_results_saved"))
            onSaved()
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}

// MARK: - executive report (U12)

/// Today's decisions, the period's results against the one before, risks and next steps — built by rules, opens without the AI.
struct MarketingReport: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let projectId: String

    @State private var period = "week"
    @State private var report: MkReportDto?
    @State private var failed = false
    @State private var reload = 0

    var body: some View {
        MkPage(title: L("mktg_report")) {
            HStack(spacing: 8) {
                ForEach([("day", "mktg_period_day"), ("week", "mktg_period_week"), ("month", "mktg_period_month")], id: \.0) { key, label in
                    WeChip(text: L(label), selected: period == key) {
                        if period != key {
                            period = key
                            report = nil
                        }
                    }
                }
            }
            if let r = report {
                reportView(r)
            } else if failed {
                WaEmpty(icon: "chart.line.uptrend.xyaxis", tone: .gray, title: L("mktg_error_generic"), text: "") {
                    WaButton(text: L("mktg_retry"), style: .ghost, compact: true) { reload += 1 }
                }
                .padding(.top, 12)
            } else {
                VStack(spacing: 12) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 96) } }.padding(.top, 12)
            }
        }
        .task(id: "\(projectId)|\(period)|\(reload)|\(net.reconnectTick)|\(host.projectChanges(projectId))") { await load() }
    }

    @ViewBuilder
    private func reportView(_ r: MkReportDto) -> some View {
        Text(verbatim: mkShortDate(r.from) + " – " + mkShortDate(r.to)).font(.dorr(12.5)).foregroundStyle(wa.mut).padding(.horizontal, 4).padding(.top, 6)

        WaSectionTitle(title: L("mktg_decisions"))
        MkReportLines(lines: r.decisions, icon: "checkmark.circle", empty: L("mktg_nothing_to_decide")) { line in
            if let id = line.itemId { host.push(.content(projectId: projectId, itemId: id)) }
        }

        WaSectionTitle(title: L("mktg_results")) { MkSmallTag(text: L("mktg_basis_imported"), tint: wa.green) }
        results(r)

        WaSectionTitle(title: L("mktg_risks"))
        MkReportLines(lines: r.risks, icon: "exclamationmark.triangle", empty: L("mktg_no_risks"))

        WaSectionTitle(title: L("mktg_next_steps"))
        MkReportLines(lines: r.recommendations, icon: "lightbulb", empty: L("mktg_nothing_to_suggest"))

        if let note = r.note {
            Text(verbatim: note).font(.dorr(11.5)).foregroundStyle(wa.soft).lineSpacing(3).fixedSize(horizontal: false, vertical: true)
                .padding(.horizontal, 4).padding(.top, 14)
        }
    }

    @ViewBuilder
    private func results(_ r: MkReportDto) -> some View {
        if r.results.daysWithData == 0 {
            Text(verbatim: L("mktg_no_results_sub")).font(.dorr(13)).foregroundStyle(wa.mut).padding(.horizontal, 4)
        } else {
            let keys = [("spend_minor", "mktg_k_spend"), ("revenue_minor", "mktg_k_revenue"), ("conversions", "mktg_k_conversions"), ("clicks", "mktg_k_clicks")]
            WaCard(padding: 14) {
                VStack(spacing: 0) {
                    ForEach(Array(keys.enumerated()), id: \.offset) { i, entry in
                        if i > 0 { WaDivider() }
                        resultRow(r, key: entry.0, label: entry.1)
                    }
                }
            }
            Text(verbatim: L("mktg_vs_previous")).font(.dorr(11.5)).foregroundStyle(wa.soft).padding(.horizontal, 4).padding(.top, 4)
        }
    }

    private func resultRow(_ r: MkReportDto, key: String, label: String) -> some View {
        let value = r.results.current.total(key)
        let change = r.results.change(key)
        let text = key.hasSuffix("_minor") ? mkMoney(value, r.currency) : (value.map { String($0) } ?? "—")
        return HStack(spacing: 8) {
            Text(verbatim: L(label)).font(.dorr(13)).foregroundStyle(wa.mut)
            Spacer(minLength: 6)
            Text(verbatim: "\u{2066}\(text)\u{2069}").font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
            if let change {
                let up = change >= 0
                MkSmallTag(text: (up ? "▲ " : "▼ ") + "\(mkNum(abs(change)))%", tint: up ? wa.green : wa.danger)
            }
        }
        .padding(.vertical, 10)
    }

    private func load() async {
        do {
            report = try await MarketingApi.report(projectId, period: period).data
            failed = false
        } catch {
            failed = report == nil
            host.fail(error, L("mktg_error_generic"))
        }
    }
}

private struct MkReportLines: View {
    @Environment(\.wa) private var wa
    let lines: [MkReportLineDto]
    let icon: String
    let empty: String
    var onTap: ((MkReportLineDto) -> Void)?

    var body: some View {
        if lines.isEmpty {
            Text(verbatim: empty).font(.dorr(13)).foregroundStyle(wa.mut).padding(.horizontal, 4)
        } else {
            WaCard(padding: 6) {
                VStack(spacing: 0) {
                    ForEach(Array(lines.enumerated()), id: \.offset) { i, line in
                        if i > 0 { WaDivider() }
                        row(line)
                    }
                }
            }
        }
    }

    private func color(_ level: String) -> Color {
        switch level {
        case "high": return wa.danger
        case "medium", "action": return mkAmber
        case "low": return wa.mut
        default: return mkBlue
        }
    }

    @ViewBuilder
    private func row(_ line: MkReportLineDto) -> some View {
        let content = HStack(alignment: .top, spacing: 10) {
            Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(color(line.level)).padding(.top, 1)
            Text(verbatim: line.text).font(.dorr(13.5)).foregroundStyle(wa.ink).lineSpacing(4).fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 12)
        if let onTap, line.itemId != nil {
            content
                .contentShape(Rectangle())
                .onTapGesture { onTap(line) }
        } else {
            content
        }
    }
}
