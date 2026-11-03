// freelanceApp `ui/wallet/WaFormat.kt`
import SwiftUI

/// Icon + tint for each ledger type, the same table as the preview's `TX_META` (SF Symbols for the Material icons).
func txMeta(_ type: String) -> (icon: String, tone: Tone) {
    switch type {
    case "topup": return ("plus", .green)
    case "topup_fee": return ("doc.plaintext.fill", .amber)
    case "topup_bonus": return ("gift.fill", .pink)
    case "transfer_out": return ("arrow.up.right", .red)
    case "transfer_in": return ("arrow.down.left", .green)
    case "withdrawal": return ("building.columns.fill", .blue)
    case "refund": return ("arrow.uturn.backward", .blue)
    case "penalty": return ("exclamationmark.circle", .red)
    case "service_payment": return ("bag.fill", .red)
    case "service_earning": return ("bag.fill", .green)
    case "manual_adjustment": return ("slider.horizontal.3", .gray)
    case "reversal": return ("arrow.uturn.backward", .gray)
    default: return ("list.bullet", .gray)
    }
}

/// Java's date formats print ASCII digits; iOS's Arabic locale would print Arabic-Indic.
private var waDateLocale: Locale { Locale(identifier: AppLocale.current == "ar" ? "ar@numbers=latn" : AppLocale.current) }

/// "14:05" in the phone's own time zone.
func timeOf(_ iso: String?) -> String {
    guard let date = parseInstant(iso) else { return "" }
    let formatter = DateFormatter()
    formatter.locale = Locale(identifier: "en_US_POSIX")
    formatter.timeZone = .current
    formatter.dateFormat = "HH:mm"
    return formatter.string(from: date)
}

/// Which calendar day a row belongs to — used to group the statement.
func dayKey(_ iso: String?) -> Date? {
    parseInstant(iso).map { Calendar.current.startOfDay(for: $0) }
}

/// 0 = today, 1 = yesterday, else the number of days (the caller prints a date).
func daysAgo(_ iso: String?) -> Int? {
    guard let day = dayKey(iso) else { return nil }
    return Calendar.current.dateComponents([.day], from: day, to: Calendar.current.startOfDay(for: Date())).day
}

/// "24 سبتمبر 2026" / "24 September 2026" in the app's language.
func longDate(_ iso: String?) -> String {
    guard let day = dayKey(iso) else { return "" }
    let formatter = DateFormatter()
    formatter.locale = waDateLocale
    formatter.dateStyle = .long
    formatter.timeStyle = .none
    return formatter.string(from: day)
}

/// Minor units → "1,234.50" (no currency) — the currency is shown next to it by the caller.
func money(_ minor: Int) -> String { formatMinorGrouped(minor, nil) }

/// "12345678901" → "123 4567 8901".
func groupWalletNumber(_ digits: String) -> String {
    guard digits.count == 11 else { return digits }
    let chars = Array(digits)
    return "\(String(chars[0..<3])) \(String(chars[3..<7])) \(String(chars[7...]))"
}

/// One line of text that shrinks (down to `minSize`) until it fits the width it is given, instead of being cut off — a
/// wallet number must always be read in full, whatever the screen or font size.
struct WaFitText: View {
    let text: String
    let color: Color
    let maxSize: CGFloat
    var minSize: CGFloat = 12
    var weight: Font.Weight = .heavy
    var letterSpacing: CGFloat = 0

    var body: some View {
        Text(verbatim: text)
            .font(.dorr(maxSize, weight))
            .tracking(letterSpacing)
            .foregroundStyle(color)
            .lineLimit(1)
            .minimumScaleFactor(minSize / maxSize)
    }
}

/// Arabic-Indic / Persian digits → ASCII, everything else that isn't a digit dropped (Kotlin's `Char.isDigit` filter).
func asciiDigits(_ text: String) -> String {
    var out = ""
    for scalar in text.unicodeScalars {
        switch scalar.value {
        case 0x30...0x39: out.unicodeScalars.append(scalar)
        case 0x660...0x669: out.unicodeScalars.append(Unicode.Scalar(scalar.value - 0x660 + 0x30)!)
        case 0x6F0...0x6F9: out.unicodeScalars.append(Unicode.Scalar(scalar.value - 0x6F0 + 0x30)!)
        default: break
        }
    }
    return out
}

/// Full month names in the app's language (the date-of-birth preview).
func monthNames() -> [String] {
    let formatter = DateFormatter()
    formatter.locale = waDateLocale
    return formatter.standaloneMonthSymbols ?? formatter.monthSymbols
}
