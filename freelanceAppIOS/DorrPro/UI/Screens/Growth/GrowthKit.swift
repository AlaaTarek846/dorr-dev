import SwiftUI

// Small helpers shared by the growth screens (plans, insights, coach, skill tests) — freelanceApp `ui/wallet/WaFormat.kt` `longDate`.

/// "24 سبتمبر 2026" / "24 September 2026" in the app's language (`longDate` of WaFormat.kt); "" when there is no date.
func growthLongDate(_ iso: String?) -> String {
    guard let date = parseInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.timeZone = .current
    f.dateStyle = .long
    f.timeStyle = .none
    return f.string(from: date)
}

/// A soft pill on the accent colour (the «current» / status / quota chips of the plans page).
struct GrowthChip: View {
    @Environment(\.wa) private var wa
    let text: String
    var size: CGFloat = 11
    var hPad: CGFloat = 10
    var vPad: CGFloat = 4

    var body: some View {
        Text(verbatim: text).font(.dorr(size, .bold)).foregroundStyle(wa.red)
            .padding(.horizontal, hPad).padding(.vertical, vPad)
            .background(wa.red.opacity(0.10), in: Capsule())
    }
}
