// freelanceApp `ui/screens/WalletKit.kt`
import QuickLook
import SwiftUI
import UIKit

/// What the wallet pages share. The PIN the freelancer typed to open the wallet is kept **in memory only**, while the wallet is
/// open, and dropped when they leave it (or sign out) — a money-moving action still asks for it again.
@MainActor
final class WalletSession: ObservableObject {
    static let shared = WalletSession()

    @Published private(set) var pin: String?

    func unlock(_ pin: String) { self.pin = pin }

    func lock() { pin = nil }
}

/// "125.50 SAR" from minor units (two decimals) — the digits stay Latin and left-to-right in Arabic text too.
func formatMinor(_ minor: Int, _ currency: String?) -> String {
    let sign = minor < 0 ? "-" : ""
    let abs = Swift.abs(minor)
    let text = sign + String(format: "%ld.%02ld", abs / 100, abs % 100)
    return "\u{200E}" + [text, currency ?? ""].filter { !$0.trimmingCharacters(in: .whitespaces).isEmpty }.joined(separator: " ")
}

func formatMinor(_ minor: Int64, _ currency: String?) -> String { formatMinor(Int(minor), currency) }

/// "12.5" or "12,5" → 1250 minor units; nil when it is not a positive amount with at most two decimals.
func parseMinor(_ text: String) -> Int? {
    let clean = text.filter { $0 != "\u{200E}" && $0 != "\u{200F}" }.trimmingCharacters(in: .whitespacesAndNewlines).replacingOccurrences(of: ",", with: ".")
    guard clean.range(of: #"^[0-9]{1,9}(\.[0-9]{1,2})?$"#, options: .regularExpression) != nil else { return nil }
    let parts = clean.split(separator: ".", omittingEmptySubsequences: false)
    let whole = Int(parts[0]) ?? 0
    let cents = parts.count > 1 ? Int(String(parts[1]).padding(toLength: 2, withPad: "0", startingAt: 0)) ?? 0 : 0
    let minor = whole * 100 + cents
    return minor > 0 ? minor : nil
}

/// A 4-digit PIN field: dots, a numeric keyboard, nothing but digits.
struct PinField: View {
    @Binding var value: String
    let label: String
    var error = false

    var body: some View {
        DorrTextField(
            value: Binding(get: { value }, set: { value = String($0.filter(\.isWholeNumberDigit).prefix(4)) }),
            label: label,
            placeholder: "••••",
            icon: "lock.fill",
            password: true,
            keyboard: .numberPad,
            error: error
        )
    }
}

extension Character {
    /// Kotlin `Char::isDigit` — any Unicode decimal digit.
    var isWholeNumberDigit: Bool { isNumber && wholeNumberValue != nil && unicodeScalars.count == 1 }
}

/// Opens `url` in the phone's browser (a payment page).
@MainActor
@discardableResult
func openUrl(_ url: String) -> Bool {
    guard let target = URL(string: url), UIApplication.shared.canOpenURL(target) else { return false }
    UIApplication.shared.open(target)
    return true
}

/// Opens a file from the cache in a viewer (a withdrawal receipt) — Quick Look over the top screen.
@MainActor
@discardableResult
func openCachedFile(_ file: URL) -> Bool {
    guard FileManager.default.fileExists(atPath: file.path), let top = WalletTopController.find() else { return false }
    let preview = QLPreviewController()
    let source = WalletPreviewSource(file)
    preview.dataSource = source
    WalletPreviewSource.current = source
    top.present(preview, animated: true)
    return true
}

private final class WalletPreviewSource: NSObject, QLPreviewControllerDataSource {
    /// Quick Look holds its data source weakly — keep the last one alive.
    static var current: WalletPreviewSource?
    let file: URL
    init(_ file: URL) { self.file = file }
    func numberOfPreviewItems(in controller: QLPreviewController) -> Int { 1 }
    func previewController(_ controller: QLPreviewController, previewItemAt index: Int) -> QLPreviewItem { file as NSURL }
}

enum WalletTopController {
    @MainActor
    static func find() -> UIViewController? {
        let scenes = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }
        var top = scenes.flatMap(\.windows).first(where: \.isKeyWindow)?.rootViewController
        while let next = top?.presentedViewController { top = next }
        return top
    }
}
