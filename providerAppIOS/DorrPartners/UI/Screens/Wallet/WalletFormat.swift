// providerApp `ui/wallet/WalletFormat.kt`
import Foundation

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

/// `ui.wallet.formatMinor` (Swift has one namespace, so it is named apart from `ui.screens.formatMinor` in WalletKit.swift):
/// minor units (halalas/piastres) → "1,234.50 SAR". Pure integer→decimal conversion through Decimal: no Float/Double, so a
/// balance can never show a rounding artefact. The backend uses 2 decimals for every currency today.
func formatMinorGrouped(_ minor: Int, _ currency: String?) -> String {
    let value = Decimal(minor) / Decimal(100)
    let text = moneyFormatter.string(from: value as NSDecimalNumber) ?? "0.00"
    guard let currency, !currency.trimmingCharacters(in: .whitespaces).isEmpty else { return text }
    return "\(text) \(currency)"
}

/// What the user typed ("100", "100.5", "100.50") → minor units, or nil when it isn't a positive amount with at most
/// 2 decimals. Never rounds silently: "10.555" is rejected instead of quietly becoming 10.56.
func parseAmountToMinor(_ text: String) -> Int? {
    let value = text.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: ",", with: ".")
    // The shapes Java's BigDecimal accepts from these characters: "5", "5.", "5.5", "5.55", ".5", ".55".
    guard value.range(of: #"^([0-9]+\.?[0-9]{0,2}|\.[0-9]{1,2})$"#, options: .regularExpression) != nil else { return nil }
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
