import SwiftUI

/// androidApp `ui/screens/marketing/MarketingDiscovery.kt` — discovery (U02): only the missing questions stand out;
/// the budget is never guessed — «I don't know yet» clears it and it is asked again. Lists are one item per line.
/// Only changed answers are sent. Also one research run (U03) with its sources.
struct MarketingDiscovery: View {
    @EnvironmentObject private var host: MkHost
    @Environment(\.wa) private var wa
    let projectId: String

    @State private var state: MkDiscoveryDto?
    @State private var project: MkProjectDto?
    @State private var drafts: [String: String] = [:]
    @State private var cleared: Set<String> = []
    @State private var invalid: Set<String> = []
    @State private var saving = false
    @State private var suggesting = false

    var body: some View {
        let canEdit = project?.abilities?.edit == true
        Group {
            if canEdit {
                MkPage(title: L("mktg_discovery"), cta: {
                    WaButton(text: L("mktg_save"), enabled: !drafts.isEmpty || !cleared.isEmpty, loading: saving) { Task { await save() } }
                }, content: { content(canEdit) })
            } else {
                MkPage(title: L("mktg_discovery")) { content(canEdit) }
            }
        }
        .task(id: projectId) {
            do {
                project = try await MarketingApi.project(projectId).data
                state = try await MarketingApi.discovery(projectId).data
            } catch {
                host.fail(error, L("mktg_error_generic"))
            }
        }
    }

    @ViewBuilder
    private func content(_ canEdit: Bool) -> some View {
        if let s = state {
            let total = s.total ?? 0
            let complete = s.complete == true
            WaCard(padding: 16) {
                VStack(alignment: .leading, spacing: 8) {
                    Text(verbatim: L("mktg_discovery_sub", s.answered ?? 0, total)).font(.dorr(14, .heavy)).foregroundStyle(wa.ink)
                    MkBar(fraction: total == 0 ? 0 : Double(s.answered ?? 0) / Double(total), color: complete ? wa.green : wa.red)
                    Text(verbatim: L(complete ? "mktg_discovery_done" : "mktg_discovery_missing")).font(.dorr(12.5)).foregroundStyle(wa.mut)
                        .fixedSize(horizontal: false, vertical: true)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            VStack(spacing: 12) {
                ForEach(Array((s.questions ?? []).enumerated()), id: \.element.id) { i, q in
                    question(q, canEdit: canEdit).waRise(min(i, 8))
                }
            }
            .padding(.top, 12)
            if canEdit {
                WaButton(text: L("mktg_suggest"), style: .ghost, icon: "sparkles", loading: suggesting) { Task { await suggest() } }
                    .padding(.top, 14)
            }
        } else {
            VStack(spacing: 12) { ForEach(0..<4, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 96) } }
        }
    }

    private func question(_ q: MkQuestionDto, canEdit: Bool) -> some View {
        let type = q.type ?? "text"
        let currency = project?.currency ?? ""
        let shown = cleared.contains(q.key) ? "" : (drafts[q.key] ?? mkAnswerText(q.value, type: type))
        let binding = Binding<String>(
            get: { shown },
            set: { value in
                drafts[q.key] = value
                cleared.remove(q.key)
                invalid.remove(q.key)
            }
        )
        return WaCard(padding: 14) {
            VStack(alignment: .leading, spacing: 10) {
                HStack(alignment: .top) {
                    Text(verbatim: q.text ?? "").font(.dorr(14, .bold)).foregroundStyle(wa.ink).fixedSize(horizontal: false, vertical: true)
                    Spacer(minLength: 6)
                    if q.required == true && q.answered != true {
                        MkSmallTag(text: L("mktg_required"), tint: wa.danger)
                    } else if q.origin == "ai" {
                        MkSmallTag(text: L("mktg_from_ai"), tint: wa.red)
                    }
                }
                if canEdit {
                    WeTextField(
                        value: binding,
                        placeholder: type == "list" ? L("mktg_one_per_line") : (type == "money" ? "0.00" : (type == "number" ? "1" : nil)),
                        minLines: type == "list" || type == "text" ? 2 : 1,
                        keyboard: type == "money" ? .decimalPad : (type == "number" ? .numberPad : .default),
                        error: invalid.contains(q.key),
                        trailing: type == "money" ? currency : nil,
                        capitalize: type == "text" || type == "list"
                    )
                } else {
                    Text(verbatim: shown.isEmpty ? "—" : shown).font(.dorr(13.5)).foregroundStyle(wa.mut)
                }
                if invalid.contains(q.key) {
                    Text(verbatim: L("mktg_answer_invalid")).font(.dorr(12)).foregroundStyle(wa.danger)
                }
                if type == "money", canEdit, q.answered == true || !shown.isEmpty, !cleared.contains(q.key) {
                    Button {
                        cleared.insert(q.key)
                        drafts.removeValue(forKey: q.key)
                    } label: {
                        Text(verbatim: L("mktg_dont_know")).font(.dorr(12.5, .bold)).foregroundStyle(wa.red)
                    }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }

    private func save() async {
        var answers: [String: JSONValue] = [:]
        var clear: [String] = []
        var bad: Set<String> = []
        for q in state?.questions ?? [] {
            if cleared.contains(q.key) {
                clear.append(q.key)
                continue
            }
            guard let text = drafts[q.key] else { continue }
            let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
            switch q.type ?? "text" {
            case "list":
                var seen = Set<String>()
                let items = text.split(whereSeparator: \.isNewline).map { $0.trimmingCharacters(in: .whitespaces) }
                    .filter { !$0.isEmpty && seen.insert($0).inserted }.prefix(20)
                if items.isEmpty { clear.append(q.key) } else { answers[q.key] = .array(items.map { .string($0) }) }
            case "money":
                if trimmed.isEmpty { clear.append(q.key) } else if let minor = mkParseMinor(trimmed) { answers[q.key] = .int(minor) } else { bad.insert(q.key) }
            case "number":
                if trimmed.isEmpty { clear.append(q.key) } else if let n = Int(trimmed), n >= 1 { answers[q.key] = .int(n) } else { bad.insert(q.key) }
            default:
                if trimmed.isEmpty { clear.append(q.key) } else { answers[q.key] = .string(trimmed) }
            }
        }
        invalid = bad
        guard bad.isEmpty else { return }
        saving = true
        do {
            state = try await MarketingApi.answer(projectId, answers: answers, clear: clear).data
            drafts = [:]
            cleared = []
            host.showToast(L("mktg_saved"))
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        saving = false
    }

    private func suggest() async {
        suggesting = true
        do {
            state = try await MarketingApi.suggestQuestions(projectId).data
        } catch {
            host.fail(error, L("mktg_error_generic"))
        }
        suggesting = false
    }
}

// MARK: - one research run

/// A run (U03): its summary, evidence quality, what the sources don't cover, the findings with their sources.
struct MarketingResearchPage: View {
    @EnvironmentObject private var host: MkHost
    @ObservedObject private var net = NetworkMonitor.shared
    @Environment(\.wa) private var wa
    @Environment(\.openURL) private var openURL
    let runId: String
    @State private var run: MkRunDto?

    var body: some View {
        MkPage(title: run.map { mkKindLabel($0.kind) } ?? L("mktg_research")) {
            if let r = run {
                content(r)
            } else {
                VStack(spacing: 12) { ForEach(0..<3, id: \.self) { _ in WaSkeleton(cornerRadius: 22).frame(height: 110) } }
            }
        }
        .task(id: "\(runId)|\(net.reconnectTick)|\(host.changes)") {
            do { run = try await MarketingApi.researchRun(runId).data } catch { host.fail(error, L("mktg_error_generic")) }
        }
    }

    @ViewBuilder
    private func content(_ r: MkRunDto) -> some View {
        WaCard(padding: 16) {
            VStack(alignment: .leading, spacing: 10) {
                HStack {
                    Text(verbatim: mkDate(r.createdAt)).font(.dorr(12.5)).foregroundStyle(wa.mut)
                    Spacer()
                    MkStatusBadge(status: r.status)
                }
                if let summary = r.summary, !summary.isEmpty {
                    Text(verbatim: summary).font(.dorr(14)).foregroundStyle(wa.ink).lineSpacing(5).fixedSize(horizontal: false, vertical: true)
                }
                if let q = r.qualityScore {
                    HStack {
                        Text(verbatim: L("mktg_quality")).font(.dorr(12.5)).foregroundStyle(wa.mut)
                        Spacer()
                        Text(verbatim: "\(q)%").font(.dorr(12.5, .heavy)).foregroundStyle(wa.ink)
                    }
                    MkBar(fraction: Double(q) / 100, color: q >= 70 ? wa.green : (q >= 40 ? mkAmber : wa.danger))
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        if let gaps = r.gaps, !gaps.isEmpty {
            WaNote(text: L("mktg_gaps") + "\n" + gaps.map { "• \($0)" }.joined(separator: "\n")).padding(.top, 12)
        }

        WaSectionTitle(title: L("mktg_findings"))
        let findings = r.findings ?? []
        if findings.isEmpty {
            if r.finished { WaEmpty(icon: "chart.bar.xaxis", tone: .gray, title: L("mktg_findings"), text: L("mktg_no_findings")) }
        } else {
            VStack(spacing: 10) {
                ForEach(Array(findings.enumerated()), id: \.element.id) { i, f in
                    WaCard(padding: 14) {
                        VStack(alignment: .leading, spacing: 8) {
                            Text(verbatim: f.claim).font(.dorr(13.5)).foregroundStyle(wa.ink).lineSpacing(4).fixedSize(horizontal: false, vertical: true)
                            HStack(spacing: 6) {
                                if f.isAssumption == true { MkSmallTag(text: L("mktg_assumption"), tint: mkAmber) }
                                MkSmallTag(text: L(f.confidence == "high" ? "mktg_confidence_high" : (f.confidence == "medium" ? "mktg_confidence_medium" : "mktg_confidence_low")), tint: wa.mut)
                                ForEach(f.sources ?? [], id: \.self) { ref in MkSmallTag(text: ref, tint: wa.red) }
                            }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                    }
                    .waRise(min(i, 6))
                }
            }
        }

        let sources = r.sources ?? []
        if !sources.isEmpty {
            WaSectionTitle(title: L("mktg_sources"))
            WaCard(padding: 6) {
                VStack(spacing: 0) {
                    ForEach(Array(sources.enumerated()), id: \.element.id) { i, s in
                        Button {
                            if let url = URL(string: s.url) { openURL(url) }
                        } label: {
                            HStack(spacing: 10) {
                                MkSmallTag(text: s.ref, tint: wa.red)
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(verbatim: s.title ?? s.publisher ?? s.url).font(.dorr(13.5, .bold)).foregroundStyle(wa.ink).lineLimit(2).multilineTextAlignment(.leading)
                                    let meta = [s.publisher, s.publishedAt.map { L("mktg_published", $0) }, s.retrievedAt.map { L("mktg_retrieved", mkDate($0)) }]
                                        .compactMap { $0 }.joined(separator: " · ")
                                    Text(verbatim: meta).font(.dorr(11.5)).foregroundStyle(wa.mut).lineLimit(2)
                                }
                                Spacer(minLength: 4)
                                Image(systemName: "arrow.up.forward.square").font(.system(size: 15)).foregroundStyle(wa.soft)
                                    .accessibilityLabel(L("mktg_open_source"))
                            }
                            .padding(10)
                        }
                        .buttonStyle(.plain)
                        if i < sources.count - 1 { WaDivider() }
                    }
                }
            }
        }
    }
}
