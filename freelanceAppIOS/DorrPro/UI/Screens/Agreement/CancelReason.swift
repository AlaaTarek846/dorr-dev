// freelanceApp `ui/screens/CancelReason.kt`
import SwiftUI

/// FL-607: why I am stepping back from a part. One of a short fixed list (the server turns it into who it points at, for staff only -
/// nothing is charged because of it) and, if I want, a few words; "other" needs the words.
let CANCEL_REASONS: [(String, String)] = [
    ("scope_changed", "cr_scope_changed"),
    ("client_unresponsive", "cr_client_unresponsive"),
    ("not_delivered", "cr_not_delivered"),
    ("quality", "cr_quality"),
    ("mutual", "cr_mutual"),
    ("force_majeure", "cr_force_majeure"),
    ("other", "cr_other"),
]

/// The reason may be sent when one is picked and, for "other", a few words are written.
func cancelReasonReady(_ code: String?, _ note: String) -> Bool { code != nil && (code != "other" || note.agreementTrimmed.count >= 5) }

struct CancelReasonPicker: View {
    @Environment(\.wa) private var wa
    let code: String?
    @Binding var note: String
    let onCode: (String) -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(L("cr_title")).font(.dorr(12)).foregroundStyle(wa.settingsMut)
            ForEach(CANCEL_REASONS, id: \.0) { key, label in
                AgreementPill(text: L(label), on: code == key) { onCode(key) }
            }
            DorrTextField(value: agreementLimit($note, 500), placeholder: L("cr_note_hint"), singleLine: false, minLines: 2)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}
