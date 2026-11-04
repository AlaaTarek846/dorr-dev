import SwiftUI

// driverApp `ui/DatePick.kt`.

/// The app's calendar (the same one as everywhere in the app); answers the picked day as `yyyy-MM-dd` (the server's
/// `from` / `to`). The dialog card: show it with `.dorrDialog(isPresented:) { DatePickDialog(…) }`.
struct DatePickDialog: View {
    let onPicked: (String) -> Void
    let onDismiss: () -> Void

    init(onPicked: @escaping (String) -> Void, onDismiss: @escaping () -> Void) {
        self.onPicked = onPicked
        self.onDismiss = onDismiss
    }

    var body: some View {
        DorrDatePickerDialog(initial: nil, max: LocalDate.now(), onDismiss: onDismiss) {
            onPicked($0.toString())
            onDismiss()
        }
    }
}

/// "Oct 15, 2026" in the app's language; the text as it is if it isn't a date.
func dateLabel(_ iso: String) -> String { prettyDate(iso) }
