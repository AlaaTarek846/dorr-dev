// freelanceApp `ui/screens/SanctionsCard.kt`
import SwiftUI

private let Danger = Color(hex: 0xDC2626)
private let Warn = Color(hex: 0xD97706)

/// FL-708: my warnings and restrictions, at the top of Home while one is active or its appeal waits. It says what stops (messages, new
/// work - never the money or the work already agreed), until when, why, and lets me appeal once within the days. Read on open, when the
/// connection returns, and on any live notice (a new one, a lift, an appeal decision) - nothing on a timer.
@MainActor
struct SanctionsCard: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var network: NetworkMonitor
    @State private var data: SanctionsDto?
    @State private var appealFor: SanctionDto?

    var body: some View {
        let current = (data?.items ?? []).filter { $0.state == "active" || $0.appeal?.status == "open" }
        Group {
            if !current.isEmpty {
                VStack(alignment: .leading, spacing: 12) {
                    Text(L("sc_title")).font(.dorr(15, .heavy)).foregroundStyle(wa.settingsInk)
                    ForEach(current) { s in SanctionRow(s: s) { appealFor = s } }
                }
                .padding(16)
                .frame(maxWidth: .infinity, alignment: .leading)
                .settingsSurface(20)
                .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(Danger.opacity(0.35), lineWidth: 1))
            }
        }
        .task(id: network.reconnectTick) { await load() }
        .onReceive(Realtime.shared.changed) { _ in Task { await load() } }
        .dorrDialog(isPresented: Binding(get: { appealFor != nil }, set: { if !$0 { appealFor = nil } })) {
            if let s = appealFor {
                AppealDialog(s: s, onDone: { appealFor = nil; data = $0 }, onDismiss: { appealFor = nil })
            }
        }
    }

    private func load() async {
        if let d = await AccountApi.sanctions().value { data = d }
    }
}

private struct SanctionRow: View {
    @Environment(\.wa) private var wa
    let s: SanctionDto
    let onAppeal: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 3) {
            let key: String = {
                switch s.type { case "chat_limit": return "sc_chat_limit"; case "work_limit": return "sc_work_limit"; case "suspension": return "sc_suspension"; default: return "sc_warning" }
            }()
            Text(L(key)).font(.dorr(14, .bold)).foregroundStyle(s.type == "warning" ? Warn : Danger)
            let blocks = s.blocks ?? []
            if blocks.isEmpty { Text(L("sc_blocks_none")).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            if blocks.contains("chat") { Text(L("sc_blocks_chat")).font(.dorr(12)).foregroundStyle(wa.settingsInk) }
            if blocks.contains("work") { Text(L("sc_blocks_work")).font(.dorr(12)).foregroundStyle(wa.settingsInk) }
            Text(s.endsAt.map { L("sc_until", "\u{200E}" + String($0.prefix(10))) } ?? L("sc_until_lifted")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            if let reason = s.reason { Text(L("sc_reason", reason)).font(.dorr(13)).foregroundStyle(wa.settingsInk) }
            if let a = s.appeal {
                let appealKey: String = {
                    switch a.status { case "upheld": return "sc_appeal_upheld"; case "reduced": return "sc_appeal_reduced"; case "lifted": return "sc_appeal_lifted"; default: return "sc_appeal_open" }
                }()
                Text(L(appealKey)).font(.dorr(12, .bold)).foregroundStyle(wa.settingsMut)
                if let note = a.decisionNote { Text(verbatim: note).font(.dorr(12)).foregroundStyle(wa.settingsMut) }
            }
            if s.canAppeal == true {
                Button(action: onAppeal) {
                    Text(L("sc_appeal")).font(.dorr(13, .bold)).foregroundStyle(wa.red).padding(.vertical, 4)
                }
                .buttonStyle(.plain)
                if let until = s.appealUntil { Text(L("sc_appeal_until", "\u{200E}" + String(until.prefix(10)))).font(.dorr(11)).foregroundStyle(wa.settingsMut) }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

private struct AppealDialog: View {
    @Environment(\.wa) private var wa
    let s: SanctionDto
    let onDone: (SanctionsDto) -> Void
    let onDismiss: () -> Void
    @State private var note = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        let ready = note.trimmingCharacters(in: .whitespacesAndNewlines).count >= 5 && !busy
        AlertDialog(
            onDismissRequest: { if !busy { onDismiss() } },
            title: { Text(L("sc_appeal_title")) },
            text: {
                VStack(alignment: .leading, spacing: 8) {
                    Text(L("sc_appeal_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    DorrTextField(value: Binding(get: { note }, set: { note = String($0.prefix(1000)) }), placeholder: L("sc_appeal_note"), singleLine: false, minLines: 3)
                    if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(Danger) }
                }
            },
            confirmButton: { DialogButton(onClick: { send() }, enabled: ready) { Text(L("sc_appeal_send")) } },
            dismissButton: { DialogButton(onClick: onDismiss) { Text(L("common_cancel")) } }
        )
    }

    private func send() {
        busy = true
        error = nil
        Task {
            let r = await AccountApi.appealSanction(s.uuid, note: note.trimmingCharacters(in: .whitespacesAndNewlines))
            if let done = r.value { onDone(done) } else { error = r.errorText(L("login_error_offline")) ?? L("login_error_generic") }
            busy = false
        }
    }
}
