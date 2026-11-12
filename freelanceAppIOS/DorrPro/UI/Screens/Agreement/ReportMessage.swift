// freelanceApp `ui/screens/ReportMessage.kt`
import SwiftUI

private let messageReportReasons: [(String, String)] = [
    ("abuse", "rm_abuse"),
    ("harassment", "rm_harassment"),
    ("blackmail", "rm_blackmail"),
    ("contact_info", "rm_contact_info"),
    ("spam", "rm_spam"),
    ("other", "rm_other"),
]

/// FL-709: reporting the other person's message (from the menu beside it). One reason and, if wanted, a few words; the team looks at it
/// and the other person is not told. A report that was already sent says so. Shown in `.dorrDialog`.
struct ReportMessageDialog: View {
    @Environment(\.wa) private var wa
    let messageId: Int
    let onDone: (String) -> Void
    let onDismiss: () -> Void
    @State private var code: String?
    @State private var note = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        AlertDialog(
            onDismissRequest: { if !busy { onDismiss() } },
            title: { Text(L("rm_title")) },
            text: {
                VStack(alignment: .leading, spacing: 8) {
                    Text(L("rm_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
                    ForEach(messageReportReasons, id: \.0) { key, label in
                        AgreementPill(text: L(label), on: code == key) { code = key }
                    }
                    DorrTextField(value: agreementLimit($note, 500), placeholder: L("cr_note_hint"), singleLine: false, minLines: 2)
                    if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(Color(hex: 0xDC2626)) }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            },
            confirmButton: {
                DialogButton(onClick: send, enabled: code != nil && !busy) { Text(L("rm_send")).foregroundStyle(Color(hex: 0xDC2626)) }
            },
            dismissButton: { DialogButton(onClick: onDismiss) { Text(L("common_cancel")) } }
        )
    }

    private func send() {
        busy = true
        error = nil
        Task {
            let result = await ChatApi.reportMessage(messageId, ReportMessageRequest(reason: code ?? "", note: note.agreementTrimmedOrNil))
            if case .ok = result { onDone(L("rm_sent")) } else { error = result.errorText(L("login_error_offline")) ?? L("login_error_generic") }
            busy = false
        }
    }
}
