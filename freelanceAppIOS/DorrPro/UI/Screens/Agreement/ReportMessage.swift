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
        AgreementAlertCard(
            title: L("rm_title"), confirmLabel: L("rm_send"), confirmColor: Color(hex: 0xDC2626), confirmEnabled: code != nil && !busy,
            onConfirm: send, dismissLabel: L("common_cancel"), onDismiss: onDismiss
        ) {
            Text(L("rm_hint")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            ForEach(messageReportReasons, id: \.0) { key, label in
                AgreementPill(text: L(label), on: code == key) { code = key }
            }
            DorrTextField(value: agreementLimit($note, 500), placeholder: L("cr_note_hint"), singleLine: false, minLines: 2)
            if let error { Text(verbatim: error).font(.dorr(12)).foregroundStyle(Color(hex: 0xDC2626)) }
        }
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
