import SwiftUI

// androidApp `freelance/FreelanceSanctions.kt`, `FreelanceReports.kt`, `FreelanceReportMessage.kt` — my warnings and restrictions
// (FL-708), the progress reports the freelancer sends (FL-605), and reporting the other person's message (FL-709).

/// FL-708: my warnings and restrictions on freelance work, at the top of my projects while one is active or its appeal waits:
/// what stops (messages, new projects and hiring — never the money or the work already agreed), until when, why, and appealing
/// once within the days. Read on open, when the screen is told something changed, and when the connection returns — nothing on a timer.
@MainActor
struct SanctionsCard: View {
    @EnvironmentObject private var network: NetworkMonitor
    let refreshKey: Int
    @State private var data: FreelanceSanctionsDto?
    @State private var appealFor: FreelanceSanctionDto?

    var body: some View {
        let current = (data?.items ?? []).filter { $0.state == "active" || $0.appeal?.status == "open" }
        Group {
            if !current.isEmpty {
                FlCard(icon: "hammer.fill", title: L("fl_sc_title")) {
                    ForEach(current) { s in SanctionRow(s: s) { appealFor = s } }
                }
            }
        }
        .task(id: "\(refreshKey)|\(network.reconnectTick)") {
            if let fresh = try? await FreelanceApi.sanctions().data { data = fresh }
        }
        .dorrDialog(isPresented: Binding(get: { appealFor != nil }, set: { if !$0 { appealFor = nil } }), bottom: true) {
            if let s = appealFor {
                AppealSheet(s: s, onDone: { appealFor = nil; data = $0 }, onDismiss: { appealFor = nil })
            }
        }
    }
}

private struct SanctionRow: View {
    @Environment(\.wa) private var wa
    let s: FreelanceSanctionDto
    let onAppeal: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(verbatim: title).font(.dorr(14, .bold)).foregroundStyle(s.type == "warning" || s.type == nil ? flAmber : flRed)
            let blocks = s.blocks ?? []
            if blocks.isEmpty { Text(L("fl_sc_blocks_none")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            if blocks.contains("chat") { Text(L("fl_sc_blocks_chat")).font(.dorr(12)).foregroundStyle(wa.settingsInk) }
            if blocks.contains("work") { Text(L("fl_sc_blocks_work")).font(.dorr(12)).foregroundStyle(wa.settingsInk) }
            Text(verbatim: s.endsAt.map { L("fl_sc_until", "\u{200E}" + String($0.prefix(10))) } ?? L("fl_sc_until_lifted")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            if let reason = s.reason { Text(verbatim: L("fl_sc_reason", reason)).font(.dorr(13)).foregroundStyle(wa.settingsInk) }
            if let a = s.appeal {
                Text(verbatim: appealLabel(a.status)).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                if let note = a.decisionNote { Text(verbatim: note).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            }
            if s.canAppeal == true {
                FlTextLink(text: L("fl_sc_appeal"), action: onAppeal)
                if let until = s.appealUntil {
                    Text(verbatim: L("fl_sc_appeal_until", "\u{200E}" + String(until.prefix(10)))).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private var title: String {
        switch s.type {
        case "chat_limit": return L("fl_sc_chat_limit")
        case "work_limit": return L("fl_sc_work_limit")
        case "suspension": return L("fl_sc_suspension")
        default: return L("fl_sc_warning")
        }
    }

    private func appealLabel(_ status: String?) -> String {
        switch status {
        case "upheld": return L("fl_sc_appeal_upheld")
        case "reduced": return L("fl_sc_appeal_reduced")
        case "lifted": return L("fl_sc_appeal_lifted")
        default: return L("fl_sc_appeal_open")
        }
    }
}

@MainActor
private struct AppealSheet: View {
    let s: FreelanceSanctionDto
    let onDone: (FreelanceSanctionsDto) -> Void
    let onDismiss: () -> Void
    @State private var note = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        FlSheet(icon: "hammer.fill", title: L("fl_sc_appeal_title"), text: L("fl_sc_appeal_hint")) {
            DorrTextArea(value: Binding(get: { note }, set: { note = String($0.prefix(1000)) }), placeholder: L("fl_sc_appeal_note"), icon: "text.alignleft", minHeight: 90)
            if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
            FlAction(text: L("fl_sc_appeal_send"), icon: "paperplane.fill", tone: .primary, enabled: note.trimmingCharacters(in: .whitespacesAndNewlines).count >= 5, loading: busy) {
                busy = true
                error = nil
                Task {
                    do {
                        if let fresh = try await FreelanceApi.appealSanction(s.uuid, note: note.trimmingCharacters(in: .whitespacesAndNewlines)).data { onDone(fresh) }
                    } catch {
                        self.error = flError(error)
                    }
                    busy = false
                }
            }
            FlAction(text: L("fl_not_now"), action: onDismiss)
        }
    }
}

/// FL-605: the progress reports the freelancer sent on this agreement, newest first. The client only reads them; how often one
/// is asked for is set while writing the agreement. A late report is said plainly (nothing is blocked). Read again when the
/// agreement changes or the connection returns — the "a report arrived" notice reloads the screen, nothing runs on a timer.
@MainActor
struct ProgressReportsCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let summary: FreelanceReportSummaryDto
    let refreshKey: String
    @State private var list: [FreelanceReportDto] = []

    var body: some View {
        Group {
            if !list.isEmpty || summary.everyDays != nil {
                FlCard(icon: "calendar.badge.clock", title: L("fl_rp_title")) {
                    if let every = summary.everyDays { Text(verbatim: L("fl_rp_every", every)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
                    if summary.late == true {
                        FlNotice(text: L("fl_rp_late"), icon: "exclamationmark.triangle.fill", color: flAmber)
                    } else if let next = summary.nextDueAt {
                        Text(verbatim: L("fl_rp_next", "\u{200E}" + String(next.prefix(10)))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    }
                    if list.isEmpty { Text(L("fl_rp_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut) }
                    ForEach(list) { r in
                        VStack(alignment: .leading, spacing: 3) {
                            HStack {
                                Text(verbatim: "\u{200E}" + String((r.createdAt ?? "").prefix(16)).replacingOccurrences(of: "T", with: " ")).font(.dorr(11)).foregroundStyle(wa.settingsMut)
                                Spacer(minLength: 0)
                                if let percent = r.percent { Text(verbatim: L("fl_rp_percent", percent)).font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk) }
                            }
                            Text(verbatim: r.note ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk)
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(12)
                        .background(wa.accent.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                    }
                }
            }
        }
        .task(id: "\(projectUuid)|\(refreshKey)|\(network.reconnectTick)|\(summary.lastAt ?? "")") {
            if let fresh = try? await FreelanceApi.reports(projectUuid).data { list = fresh.reports ?? [] }
        }
    }
}

private let messageReportReasons: [(String, String)] = [
    ("abuse", "fl_rm_abuse"),
    ("harassment", "fl_rm_harassment"),
    ("blackmail", "fl_rm_blackmail"),
    ("contact_info", "fl_rm_contact_info"),
    ("spam", "fl_rm_spam"),
    ("other", "fl_rm_other"),
]

/// FL-709: reporting the other person's message (long press on it). One reason and, if wanted, a few words; the team looks at it
/// and the other person is not told. A report that was already sent says so.
@MainActor
struct ReportMessageDialog: View {
    let messageId: Int
    let onDone: (String) -> Void
    let onDismiss: () -> Void
    @State private var code: String?
    @State private var note = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        FlSheet(icon: "flag.fill", title: L("fl_rm_title"), text: L("fl_rm_hint")) {
            ForEach(messageReportReasons, id: \.0) { key, label in
                FlChip(text: L(label), selected: code == key, icon: "flag.fill") { code = key }
            }
            DorrTextArea(value: Binding(get: { note }, set: { note = String($0.prefix(500)) }), placeholder: L("fl_cr_note_hint"), icon: "text.alignleft", minHeight: 70)
            if let error { FlNotice(text: error, icon: "exclamationmark.circle", color: flRed) }
            FlAction(text: L("fl_rm_send"), icon: "paperplane.fill", tone: .danger, enabled: code != nil, loading: busy) {
                busy = true
                error = nil
                Task {
                    do {
                        let n = note.trimmingCharacters(in: .whitespacesAndNewlines)
                        _ = try await FreelanceApi.reportMessage(messageId, reason: code ?? "", note: n.isEmpty ? nil : n)
                        onDone(L("fl_rm_sent"))
                    } catch {
                        self.error = flError(error)
                    }
                    busy = false
                }
            }
            FlAction(text: L("fl_not_now"), action: onDismiss)
        }
    }
}
