import SwiftUI

/// androidApp `ui/screens/marketing/MarketingPlan.kt` — one plan (U04/U05): its current version as the type lays it
/// out, the critic's notes, and the steps its status allows — send for review, approve this exact version (owner or manager),
/// back to draft, edit (a new version), finance, versions, PDF/Word, write again with AI, archive.
struct MarketingPlanPage: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    let planId: String

    @State private var plan: MkPlanDto?
    @State private var project: MkProjectDto?
    @State private var projectId: String?
    @State private var reload = 0
    @State private var busy: String?
    @State private var editing = false
    @State private var showVersions = false
    @State private var viewing: MkVersionDto?

    var body: some View {
        MkPage(title: plan.map { mkPlanLabel($0.type) } ?? L("mktg_plans")) {
            if let p = plan, let version = viewing ?? p.currentVersion {
                content(p, version)
            } else {
                VStack(spacing: 12) { ForEach(0..<4, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 110) } }
            }
        }
        .task(id: "\(planId)|\(reload)|\(net.reconnectTick)|\(host.changes)") {
            do { plan = try await MarketingApi.plan(planId).data } catch { host.fail(error, L("mktg_error_generic")) }
        }
        // The project, for the abilities: the plan page is opened from the project board.
        .task {
            for route in host.stack.reversed() {
                if case .project(let id) = route {
                    projectId = id
                    project = try? await MarketingApi.project(id).data
                    break
                }
            }
        }
        .weSheet(editing, onDismiss: { editing = false }) {
            if let p = plan, let version = p.currentVersion {
                EditPlanSheet(planId: planId, type: p.type, version: version) { updated in
                    editing = false
                    plan = updated
                }
            }
        }
        .weSheet(showVersions, onDismiss: { showVersions = false }) {
            VersionsSheet(planId: planId) { picked in
                showVersions = false
                viewing = picked.version == plan?.currentVersion?.version ? nil : picked
            }
        }
    }

    @ViewBuilder
    private func content(_ p: MkPlanDto, _ version: MkVersionDto) -> some View {
        let isCurrent = viewing == nil
        let abilities = project?.abilities
        let c = version.content ?? .object([:])
        WaCard(padding: 16) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .top) {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: c.text("title") ?? mkPlanLabel(p.type)).font(.dorr(16, .heavy)).foregroundStyle(wa.ink).fixedSize(horizontal: false, vertical: true)
                        let origin = L(version.origin == "edit" ? "mktg_origin_edit" : "mktg_origin_ai")
                        Text(verbatim: L("mktg_version", version.version) + " · \(origin) · " + mkDate(version.createdAt)).font(.dorr(12)).foregroundStyle(wa.mut)
                    }
                    Spacer(minLength: 6)
                    MkStatusBadge(status: isCurrent ? p.status : "SUPERSEDED")
                }
                if let approval = p.approval, isCurrent {
                    HStack(spacing: 6) {
                        Image(systemName: "checkmark.seal.fill").foregroundStyle(wa.green)
                        Text(verbatim: L("mktg_approved_on", approval.version, mkDate(approval.approvedAt))).font(.dorr(12.5, .bold)).foregroundStyle(wa.green)
                    }
                }
                if !isCurrent {
                    WaButton(text: L("mktg_close"), style: .ghost, compact: true) { viewing = nil }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }

        if isCurrent, let abilities {
            VStack(spacing: 8) {
                if p.status == "DRAFT", abilities.edit == true {
                    WaButton(text: L("mktg_submit"), enabled: !(version.critic ?? []).contains { $0.severity == "blocking" }, loading: busy == "submit") {
                        act("submit") { try await MarketingApi.submitPlan(planId, version: version.version).data }
                    }
                }
                if p.status == "READY_FOR_REVIEW", abilities.approve == true {
                    WaButton(text: L("mktg_approve", version.version), icon: "checkmark.seal", loading: busy == "approve") {
                        host.confirm(WeDialogSpec(title: L("mktg_approve_confirm_title"), text: L("mktg_approve_confirm_text"), confirm: L("mktg_approve", version.version)) {
                            act("approve") { try await MarketingApi.approvePlan(planId, version: version.version).data }
                        })
                    }
                    WaButton(text: L("mktg_return"), style: .ghost, loading: busy == "return") {
                        act("return") { try await MarketingApi.returnPlan(planId, version: version.version).data }
                    }
                }
            }
            .padding(.top, 12)
        }

        if let critic = version.critic, !critic.isEmpty {
            CriticCard(critic: critic).padding(.top, 12)
        }

        PlanContent(type: p.type, content: c).padding(.top, 12)

        VStack(spacing: 10) {
            MkNavCard(icon: "function", tone: .green, title: L("mktg_finance"), subtitle: L("mktg_finance_sub")) { host.push(.finance(planId: planId)) }
            HStack(spacing: 10) {
                WaButton(text: L("mktg_export_pdf"), style: .ghost, icon: "doc.richtext", loading: busy == "pdf", compact: true) { export(version.version, "pdf") }
                WaButton(text: L("mktg_export_docx"), style: .ghost, icon: "doc.text", loading: busy == "docx", compact: true) { export(version.version, "docx") }
            }
            WaButton(text: L("mktg_versions") + " (\(p.versionsCount ?? version.version))", style: .ghost, icon: "clock.arrow.circlepath", compact: true) { showVersions = true }
            if isCurrent, abilities?.edit == true, p.status != "ARCHIVED" {
                WaButton(text: L("mktg_edit"), style: .ghost, icon: "pencil", compact: true) { editing = true }
                if let pid = projectId {
                    WaButton(text: L("mktg_plan_rewrite"), style: .quiet, icon: "sparkles", loading: busy == "rewrite", compact: true) {
                        Task {
                            busy = "rewrite"
                            do {
                                _ = try await MarketingApi.generatePlan(pid, MkPlanRequest(type: p.type))
                                host.showToast(L("mktg_plan_started"))
                            } catch {
                                host.fail(error, L("mktg_error_generic"))
                            }
                            busy = nil
                        }
                    }
                }
            }
            if isCurrent, abilities?.manageTeam == true, p.status != "ARCHIVED" {
                WaButton(text: L("mktg_archive_plan"), style: .quiet, icon: "archivebox", loading: busy == "archive", compact: true) {
                    act("archive") { try await MarketingApi.archivePlan(planId).data }
                }
            }
        }
        .padding(.top, 16)
    }

    private func act(_ key: String, _ call: @escaping () async throws -> MkPlanDto?) {
        Task {
            busy = key
            do {
                if let updated = try await call() { plan = updated }
            } catch {
                host.fail(error, L("mktg_error_generic"))
            }
            busy = nil
        }
    }

    /// One version's PDF / Word into the caches' `exports/`, then the system share sheet (Android: FileProvider + ACTION_SEND).
    private func export(_ version: Int, _ format: String) {
        guard busy == nil else { return }
        busy = format
        Task {
            do {
                let (bytes, response) = try await MarketingApi.export(planId, version: version, format: format)
                guard let status = response?.statusCode, (200..<300).contains(status) else { throw URLError(.badServerResponse) }
                let caches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first ?? FileManager.default.temporaryDirectory
                let dir = caches.appendingPathComponent("exports", isDirectory: true)
                try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
                let file = dir.appendingPathComponent("DORR-plan-v\(version).\(format)")
                try bytes.write(to: file, options: .atomic)
                busy = nil
                presentShare(file)
            } catch {
                busy = nil
                host.showToast(L("mktg_export_failed"), error: true)
            }
        }
    }
}

private struct CriticCard: View {
    @Environment(\.wa) private var wa
    let critic: [MkCriticDto]

    var body: some View {
        var seen = Set<String>()
        let unique = critic.filter { seen.insert($0.code).inserted }
        return WaCard(padding: 14) {
            VStack(alignment: .leading, spacing: 8) {
                HStack(spacing: 8) {
                    Image(systemName: "exclamationmark.triangle.fill").foregroundStyle(mkAmber)
                    Text(verbatim: L("mktg_issues")).font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
                }
                ForEach(unique, id: \.code) { c in
                    HStack(alignment: .top, spacing: 8) {
                        Text(verbatim: "•").font(.dorr(14, .black)).foregroundStyle(c.severity == "blocking" ? wa.danger : mkAmber)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(verbatim: criticText(c.code)).font(.dorr(13)).foregroundStyle(wa.ink).fixedSize(horizontal: false, vertical: true)
                            if c.severity == "blocking" {
                                Text(verbatim: L("mktg_issue_blocking")).font(.dorr(11.5, .bold)).foregroundStyle(wa.danger)
                            }
                        }
                    }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }

    private func criticText(_ code: String) -> String {
        switch code {
        case "budget_missing", "invented_budget_removed", "unknown_sources_removed", "budget_share_over_100",
             "invalid_phase_range", "overlapping_phases", "phase_without_go_no_go":
            return L("mktg_issue_\(code)")
        default:
            return L("mktg_issue_numbers_without_sources")
        }
    }
}

/// The version's content, laid out by plan type; every list is shown only when it has items.
private struct PlanContent: View {
    @Environment(\.wa) private var wa
    let type: String
    let content: JSONAny

    var body: some View {
        let c = content
        VStack(alignment: .leading, spacing: 12) {
            if let summary = c.text("summary") {
                MkBlock(title: L("mktg_summary")) { prose(summary) }
            }
            switch type {
            case "business":
                ForEach(Array(c.objects("sections").enumerated()), id: \.offset) { _, s in
                    MkBlock(title: s.text("title") ?? s.text("key") ?? "") { prose(s.text("body") ?? "") }
                }
            case "marketing":
                if let positioning = c.text("positioning") { MkBlock(title: L("mktg_positioning")) { prose(positioning) } }
                let segments = c.objects("segments")
                if !segments.isEmpty {
                    MkBlock(title: L("mktg_segments")) {
                        ForEach(Array(segments.enumerated()), id: \.offset) { _, r in
                            Text(verbatim: r.text("name") ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
                            if let d = r.text("description") { Text(verbatim: d).font(.dorr(13)).foregroundStyle(wa.mut).fixedSize(horizontal: false, vertical: true) }
                        }
                    }
                }
                let channels = c.objects("channels")
                if !channels.isEmpty {
                    MkBlock(title: L("mktg_channels")) {
                        ForEach(Array(channels.enumerated()), id: \.offset) { i, r in
                            HStack {
                                Text(verbatim: r.text("channel") ?? "").font(.dorr(13.5, .bold)).foregroundStyle(wa.ink)
                                Spacer()
                                if let share = r.number("budget_share_pct") { MkSmallTag(text: "\(share)%", tint: wa.red) }
                            }
                            if let why = r.text("rationale") { Text(verbatim: why).font(.dorr(13)).foregroundStyle(wa.mut).fixedSize(horizontal: false, vertical: true) }
                            if i < channels.count - 1 { WaDivider() }
                        }
                    }
                }
                let messages = c.strings("messages")
                if !messages.isEmpty { MkBlock(title: L("mktg_messages")) { MkBullets(items: messages) } }
                let kpis = c.objects("kpis")
                if !kpis.isEmpty {
                    MkBlock(title: L("mktg_kpis")) {
                        ForEach(Array(kpis.enumerated()), id: \.offset) { _, r in WaKeyValue(label: r.text("name") ?? "", value: r.text("target") ?? "") }
                    }
                }
            default:
                if let days = c.number("horizon_days") {
                    HStack(spacing: 6) {
                        MkSmallTag(text: L("mktg_horizon", days), tint: wa.red)
                        ForEach(Array(c.strings("channels").prefix(4)), id: \.self) { MkSmallTag(text: $0, tint: wa.mut) }
                    }
                }
                ForEach(Array(c.objects("phases").enumerated()), id: \.offset) { _, phase in
                    let days = phase.number("day_from").flatMap { from in phase.number("day_to").map { to in L("mktg_phase_days", from, to) } }
                    MkBlock(title: [days, phase.text("title")].compactMap { $0 }.joined(separator: " · ")) {
                        phaseList(L("mktg_tasks"), phase.strings("tasks"))
                        phaseList(L("mktg_kpis"), phase.strings("kpis"))
                        phaseList(L("mktg_go_no_go"), phase.strings("go_no_go_criteria"))
                    }
                }
            }
            let assumptions = c.strings("assumptions")
            if !assumptions.isEmpty { MkBlock(title: L("mktg_assumptions")) { MkBullets(items: assumptions) } }
            let risks = c.strings("risks")
            if !risks.isEmpty { MkBlock(title: L("mktg_risks")) { MkBullets(items: risks) } }
            let notes = c.strings("confidence_notes")
            if !notes.isEmpty { MkBlock(title: L("mktg_confidence_notes")) { MkBullets(items: notes, muted: true) } }
            let sources = c.strings("sources")
            if !sources.isEmpty {
                Text(verbatim: L("mktg_cited_sources", sources.joined(separator: ", "))).font(.dorr(12)).foregroundStyle(wa.mut).padding(.horizontal, 4)
            }
        }
    }

    private func prose(_ text: String) -> some View {
        Text(verbatim: text).font(.dorr(13.5)).foregroundStyle(wa.ink).lineSpacing(5).fixedSize(horizontal: false, vertical: true)
    }

    @ViewBuilder
    private func phaseList(_ title: String, _ items: [String]) -> some View {
        if !items.isEmpty {
            Text(verbatim: title).font(.dorr(12, .bold)).foregroundStyle(wa.mut)
            MkBullets(items: items)
        }
    }
}

/// Edits what a person writes on a phone: title, summary, assumptions, risks (one per line) — and for a GTM plan each
/// phase's tasks. The rest of the content goes back unchanged. Saving is a new version.
private struct EditPlanSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let planId: String
    let type: String
    let version: MkVersionDto
    let onSaved: (MkPlanDto) -> Void

    @State private var title = ""
    @State private var summary = ""
    @State private var assumptions = ""
    @State private var risks = ""
    @State private var phaseTasks: [String] = []
    @State private var busy = false
    @State private var filled = false

    var body: some View {
        let c = version.content ?? .object([:])
        let phases = c.objects("phases")
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(verbatim: L("mktg_edit_plan")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink)
                WaNote(text: L("mktg_edit_note"))
                WeTextField(value: $title, label: L("mktg_title_field"), limit: 200, capitalize: true)
                WeTextField(value: $summary, label: L("mktg_summary"), minLines: 3, limit: 4000, capitalize: true)
                if type == "gtm" {
                    ForEach(Array(phases.enumerated()), id: \.offset) { i, phase in
                        if i < phaseTasks.count {
                            WeTextField(value: $phaseTasks[i], label: (phase.text("title") ?? "") + " · " + L("mktg_tasks"),
                                        placeholder: L("mktg_one_per_line"), minLines: 2, capitalize: true)
                        }
                    }
                }
                WeTextField(value: $assumptions, label: L("mktg_assumptions"), placeholder: L("mktg_one_per_line"), minLines: 2, capitalize: true)
                WeTextField(value: $risks, label: L("mktg_risks"), placeholder: L("mktg_one_per_line"), minLines: 2, capitalize: true)
                WaButton(text: L("mktg_save"), enabled: !title.trimmingCharacters(in: .whitespaces).isEmpty && !summary.trimmingCharacters(in: .whitespaces).isEmpty,
                         loading: busy) { Task { await save(c, phases) } }
            }
        }
        .frame(maxHeight: UIScreen.main.bounds.height * 0.8)
        .onAppear {
            guard !filled else { return }
            filled = true
            title = c.text("title") ?? ""
            summary = c.text("summary") ?? ""
            assumptions = c.strings("assumptions").joined(separator: "\n")
            risks = c.strings("risks").joined(separator: "\n")
            phaseTasks = phases.map { $0.strings("tasks").joined(separator: "\n") }
        }
    }

    private func save(_ c: JSONAny, _ phases: [JSONAny]) async {
        var content = c
            .setting("title", .string(title.trimmingCharacters(in: .whitespaces)))
            .setting("summary", .string(summary.trimmingCharacters(in: .whitespacesAndNewlines)))
            .setting("assumptions", JSONAny.lines(assumptions))
            .setting("risks", JSONAny.lines(risks))
        if type == "gtm" {
            let updated = phases.enumerated().map { i, phase in phase.setting("tasks", JSONAny.lines(i < phaseTasks.count ? phaseTasks[i] : "")) }
            content = content.setting("phases", .array(updated))
        }
        busy = true
        do {
            if let plan = try await MarketingApi.editPlan(planId, version: version.version, content: content).data {
                host.showToast(L("mktg_saved"))
                onSaved(plan)
            }
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        busy = false
    }
}

private struct VersionsSheet: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let planId: String
    let onPick: (MkVersionDto) -> Void
    @State private var rows: [MkVersionRowDto]?

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 8) {
                Text(verbatim: L("mktg_versions")).font(.dorr(18, .heavy)).foregroundStyle(wa.ink).padding(.bottom, 4)
                if let rows {
                    ForEach(rows) { v in
                        let origin = L(v.origin == "edit" ? "mktg_origin_edit" : "mktg_origin_ai")
                        MkNavCard(icon: "clock.arrow.circlepath", tone: .gray, title: L("mktg_version", v.version), subtitle: "\(origin) · \(mkDate(v.createdAt))") {
                            Task {
                                do {
                                    if let picked = try await MarketingApi.version(planId, v.version).data { onPick(picked) }
                                } catch {
                                    host.fail(error, L("mktg_error_generic"))
                                }
                            }
                        }
                    }
                } else {
                    ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 16).frame(height: 52) }
                }
            }
        }
        .frame(maxHeight: UIScreen.main.bounds.height * 0.7)
        .task {
            do { rows = (try await MarketingApi.versions(planId).data ?? []).reversed() } catch { host.fail(error, L("mktg_error_generic")) }
        }
    }
}
