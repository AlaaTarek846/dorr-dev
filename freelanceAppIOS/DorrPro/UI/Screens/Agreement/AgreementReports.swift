// freelanceApp `ui/screens/AgreementReports.kt`
import SwiftUI

/// FL-605: progress reports on an active agreement. The client may have asked for one every few days; I can send one at any time (a
/// note and, if I want, how much is done). It says plainly when one is due or late - nothing is blocked. Read again when the agreement
/// changes or the connection returns; the due reminder comes as a notice, nothing runs on a timer.
struct AgreementReportsCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    let projectUuid: String
    let summary: ReportSummaryDto?
    let canSend: Bool
    let refreshKey: String
    @State private var list: [ReportDto] = []
    @State private var shown: ReportSummaryDto?
    @State private var dialog = false
    @State private var note = ""
    @State private var percent = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        let s = shown ?? summary ?? ReportSummaryDto()
        VStack(spacing: 0) {
            if canSend || !list.isEmpty || s.everyDays != nil { card(s) }
        }
        .task(id: "\(projectUuid)|\(refreshKey)|\(network.reconnectTick)") {
            if let data = await AgreementsApi.reports(projectUuid).value {
                list = data.reports ?? []
                shown = data.summary ?? ReportSummaryDto()
            }
        }
        .formSheet(isPresented: $dialog, icon: "chart.line.uptrend.xyaxis", title: L("rp_send"), text: L("rp_form_intro"), locked: busy) {
            DorrTextField(value: agreementLimit($note, 2000), label: L("rp_note_hint"), icon: "text.alignleft", singleLine: false, minLines: 4)
            DorrTextField(value: agreementLimit($percent, 3, digits: true), label: L("rp_percent_hint"), icon: "percent", keyboard: .numberPad)
            if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(Color(hex: 0xDC2626)) }
            ActionButton(L("rp_send_confirm"), "paperplane.fill", .primary, enabled: note.agreementTrimmed.count >= 3 && !busy, loading: busy) { send() }
        }
    }

    private func card(_ s: ReportSummaryDto) -> some View {
        Card {
            FieldLabel(text: L("rp_title"))
            if let every = s.everyDays { Text(verbatim: L("rp_every", every)).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            if s.late == true {
                Text(L("rp_late")).font(.dorr(13, .bold)).foregroundStyle(Color(hex: 0xD97706))
            } else if let next = s.nextDueAt {
                Text(verbatim: L("rp_next", "\u{200E}" + String(next.prefix(10)))).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            }
            if canSend {
                ActionButton(L("rp_send"), "paperplane.fill", .primary) {
                    error = nil
                    dialog = true
                }
            }
            if list.isEmpty { Text(L("rp_empty")).font(.dorr(13)).foregroundStyle(wa.settingsMut) }
            ForEach(Array(list.enumerated()), id: \.offset) { _, r in
                VStack(alignment: .leading, spacing: 3) {
                    HStack(alignment: .top) {
                        Text(verbatim: "\u{200E}" + agreementStamp(r.createdAt)).font(.dorr(11)).foregroundStyle(wa.settingsMut).frame(maxWidth: .infinity, alignment: .leading)
                        if let p = r.percent { Text(verbatim: L("rp_percent", p)).font(.dorr(12, .bold)).foregroundStyle(wa.settingsInk) }
                    }
                    Text(verbatim: r.note ?? "").font(.dorr(13)).foregroundStyle(wa.settingsInk)
                }
                .padding(12)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(wa.red.opacity(0.06), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
        }
    }

    private func send() {
        busy = true
        Task {
            let value = Int(percent).map { min(max($0, 0), 100) }
            let result = await AgreementsApi.sendReport(projectUuid, ReportRequest(note: note.agreementTrimmed, percent: value))
            if case .ok(let data, _) = result {
                list = data.reports ?? []
                shown = data.summary ?? ReportSummaryDto()
                note = ""
                percent = ""
                dialog = false
            } else {
                error = result.errorText(L("login_error_offline")) ?? L("login_error_generic")
            }
            busy = false
        }
    }
}
