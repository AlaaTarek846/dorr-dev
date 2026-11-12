import Foundation

// androidApp `ui/screens/wallet/WalletFormat.kt` + `WaFormat.kt` + the small helpers of WalletTransfer.kt.

private let moneyFormatter: NumberFormatter = {
    let formatter = NumberFormatter()
    formatter.locale = Locale(identifier: "en_US_POSIX")
    formatter.numberStyle = .decimal
    formatter.usesGroupingSeparator = true
    formatter.groupingSeparator = ","
    formatter.groupingSize = 3
    formatter.decimalSeparator = "."
    formatter.minimumFractionDigits = 2
    formatter.maximumFractionDigits = 2
    formatter.minimumIntegerDigits = 1
    return formatter
}()

/// Minor units (halalas/piastres) → "1,234.50 SAR". Pure integer→decimal conversion through Decimal:
/// no Float/Double, so a balance can never show a rounding artefact. The backend uses 2 decimals for
/// every currency today.
func formatMinor(_ minor: Int, _ currency: String?) -> String {
    let value = Decimal(minor) / Decimal(100)
    let text = moneyFormatter.string(from: value as NSDecimalNumber) ?? "0.00"
    guard let currency, !currency.trimmingCharacters(in: .whitespaces).isEmpty else { return text }
    return "\(text) \(currency)"
}

/// Minor units → "1,234.50" (no currency) — the currency is shown next to it by the caller.
func money(_ minor: Int) -> String { formatMinor(minor, nil) }

/// What the user typed ("100", "100.5", "100.50") → minor units, or nil when it isn't a positive amount
/// with at most 2 decimals. Never rounds silently: "10.555" is rejected instead of quietly becoming 10.56.
func parseAmountToMinor(_ text: String) -> Int? {
    let value = text.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: ",", with: ".")
    // The shapes Java's BigDecimal accepts from these characters: "5", "5.", "5.5", "5.55", ".5", ".55".
    guard value.range(of: #"^(\d+\.?\d{0,2}|\.\d{1,2})$"#, options: .regularExpression) != nil else { return nil }
    let parts = value.split(separator: ".", omittingEmptySubsequences: false)
    let whole = parts[0].isEmpty ? "0" : String(parts[0])
    let fraction = parts.count > 1 ? String(parts[1]) : ""
    let cents = (fraction + "00").prefix(2)
    guard let wholeValue = Int(whole), let centsValue = Int(cents) else { return nil }
    let (scaled, overflow) = wholeValue.multipliedReportingOverflow(by: 100)
    guard !overflow else { return nil }
    let minor = scaled + centsValue
    return minor > 0 ? minor : nil
}

/// "30" for 3000, "12.5" for 1250 — how a top-up amount is typed ([parseAmountToMinor] reads it back).
func minorToInput(_ minor: Int) -> String {
    if minor <= 0 { return "" }
    let whole = minor / 100
    let cents = minor % 100
    if cents == 0 { return String(whole) }
    var fraction = String(format: "%02d", cents)
    while fraction.hasSuffix("0") { fraction.removeLast() }
    return "\(whole).\(fraction)"
}

/// "2.5000" → "2.5", "10.0000" → "10" — trims the trailing zeros a fixed-point percent snapshot carries.
func fmtPercent(_ value: Double) -> String {
    if value == value.rounded() { return String(Int(value)) }
    var text = String(value)
    while text.hasSuffix("0") { text.removeLast() }
    if text.hasSuffix(".") { text.removeLast() }
    return text
}

/// "12345678901" → "123 4567 8901".
func groupWalletNumber(_ digits: String) -> String {
    guard digits.count == 11 else { return digits }
    let chars = Array(digits)
    return "\(String(chars[0..<3])) \(String(chars[3..<7])) \(String(chars[7...]))"
}

/// Arabic-Indic / Persian digits → ASCII, everything else that isn't a digit dropped.
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

/// 11 digits, the last one a Luhn check digit — catches a mistyped number before asking the server.
func isValidWalletNumber(_ digits: String) -> Bool {
    guard digits.count == 11, digits.allSatisfy(\.isASCII), digits.allSatisfy(\.isNumber) else { return false }
    var sum = 0
    for (index, char) in digits.reversed().enumerated() {
        var n = Int(String(char)) ?? 0
        if index % 2 == 1 {
            n *= 2
            if n > 9 { n -= 9 }
        }
        sum += n
    }
    return sum % 10 == 0
}

// MARK: - Dates

/// Android's Java date formats always print ASCII digits; iOS's Arabic locale would print Arabic-Indic.
private var dateLocale: Locale { Locale(identifier: AppLocale.current == "ar" ? "ar@numbers=latn" : AppLocale.current) }

private func instantOf(_ iso: String?) -> Date? { parseInstant(iso) }

/// "14:05" in the phone's own time zone.
func timeOf(_ iso: String?) -> String {
    guard let date = instantOf(iso) else { return "" }
    let formatter = DateFormatter()
    formatter.locale = Locale(identifier: "en_US_POSIX")
    formatter.timeZone = .current
    formatter.dateFormat = "HH:mm"
    return formatter.string(from: date)
}

/// 0 = today, 1 = yesterday, else the number of days (the caller prints a date for anything else).
func daysAgo(_ iso: String?) -> Int? {
    guard let date = instantOf(iso) else { return nil }
    let calendar = Calendar.current
    return calendar.dateComponents([.day], from: calendar.startOfDay(for: date), to: calendar.startOfDay(for: Date())).day
}

/// "24 سبتمبر 2026" / "24 September 2026" in the app's language.
func longDate(_ iso: String?) -> String {
    guard let date = instantOf(iso) else { return "" }
    let formatter = DateFormatter()
    formatter.locale = dateLocale
    formatter.dateStyle = .long
    formatter.timeStyle = .none
    return formatter.string(from: date)
}

/// "اليوم" / "أمس" / a long date.
func dayText(_ iso: String?) -> String {
    switch daysAgo(iso) {
    case 0: return L("wa_today")
    case 1: return L("wa_yesterday")
    default: return longDate(iso)
    }
}

/// Full month names in the app's language (the date-of-birth preview).
func monthNames() -> [String] {
    let formatter = DateFormatter()
    formatter.locale = dateLocale
    return formatter.standaloneMonthSymbols ?? formatter.monthSymbols
}

// MARK: - Countries

/// The short names people actually say ("السعودية", not "المملكة العربية السعودية").
private let arabicCountryNames: [String: String] = [
    "SA": "السعودية", "EG": "مصر", "AE": "الإمارات", "KW": "الكويت", "QA": "قطر", "BH": "البحرين", "OM": "عُمان",
    "JO": "الأردن", "IQ": "العراق", "LB": "لبنان", "MA": "المغرب", "DZ": "الجزائر", "TN": "تونس", "LY": "ليبيا",
    "SD": "السودان", "YE": "اليمن", "SY": "سوريا", "PS": "فلسطين",
]

/// English/Arabic name of a wallet's country from its ISO code.
func countryName(_ code: String?) -> String {
    guard let code, !code.trimmingCharacters(in: .whitespaces).isEmpty else { return "" }
    if AppLocale.isArabic, let name = arabicCountryNames[code.uppercased()] { return name }
    return Locale(identifier: AppLocale.current).localizedString(forRegionCode: code.uppercased()) ?? code
}

// MARK: - Ledger types

/// Icon + meaning for each ledger type, the same table as the preview's `TX_META` (SF Symbols for Material icons).
func txMeta(_ type: String) -> (icon: String, tone: Tone) {
    switch type {
    case "topup": return ("creditcard.and.123", .green) // the home «Top up» button's icon
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
    // Watch & Earn: money moved into the ad wallet, back out of it, and the rewards for watching.
    case "ad_funding": return ("megaphone.fill", .red)
    case "ad_return": return ("arrow.uturn.backward", .green)
    case "reward": return ("trophy.fill", .amber)
    default: return ("list.bullet", .gray)
    }
}

/// Payment gateway → icon + meaning (`methodLook` on Android).
func methodLook(_ gateway: String) -> (icon: String, tone: Tone) {
    switch gateway {
    case "myfatoorah": return ("creditcard.fill", .blue)
    case "arb": return ("building.columns.fill", .green)
    case "urpay": return ("phone.fill", .pink)
    case "sandbox": return ("testtube.2", .amber)
    default: return ("creditcard.fill", .gray)
    }
}
