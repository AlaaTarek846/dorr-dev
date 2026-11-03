import SwiftUI

// freelanceApp `ui/screens/ServiceKit.kt`.
//
// FL-L04: the shared pieces of the ready-made service screens — money in the currency's own decimals, the status pill, the numbered
// step cards of the form, the − / + stepper and the segmented picker. Colours follow the appearance tokens, light and dark.

let SERVICE_LEVELS = ["basic", "standard", "premium"]

private let svcMoneyFormatters = NSCache<NSNumber, NumberFormatter>()

/// "1,250.00 SAR" — digits stay Latin and left-to-right in Arabic text too.
func svcMoney(_ minor: Int, _ decimals: Int, _ currency: String?) -> String {
    let formatter: NumberFormatter = {
        if let f = svcMoneyFormatters.object(forKey: NSNumber(value: decimals)) { return f }
        let f = NumberFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.numberStyle = .decimal
        f.usesGroupingSeparator = true
        f.groupingSeparator = ","
        f.decimalSeparator = "."
        f.groupingSize = 3
        f.minimumFractionDigits = max(decimals, 0)
        f.maximumFractionDigits = max(decimals, 0)
        f.roundingMode = .halfEven
        svcMoneyFormatters.setObject(f, forKey: NSNumber(value: decimals))
        return f
    }()
    let value = Decimal(minor) / pow(Decimal(10), max(decimals, 0))
    let text = formatter.string(from: value as NSDecimalNumber) ?? "\(value)"
    guard let currency, !currency.trimmingCharacters(in: .whitespaces).isEmpty else { return text }
    return "\(text) \(currency)"
}

/// Minor units → what goes in the price field ("250", "99.5").
func minorToInput(_ minor: Int?, _ decimals: Int) -> String {
    guard let minor, minor > 0 else { return "" }
    let d = max(decimals, 0)
    var p = 1
    for _ in 0..<d { p *= 10 }
    let whole = minor / p
    var frac = d == 0 ? "" : String(minor % p)
    if d > 0 { frac = String(repeating: "0", count: d - frac.count) + frac }
    while frac.hasSuffix("0") { frac.removeLast() }
    return frac.isEmpty ? "\(whole)" : "\(whole).\(frac)"
}

/// What was typed → minor units, or nil when it is not a positive amount in the currency's decimals (never rounded quietly).
func inputToMinor(_ text: String, _ decimals: Int) -> Int? {
    var s = text.trimmingCharacters(in: .whitespacesAndNewlines).replacingOccurrences(of: ",", with: ".").replacingOccurrences(of: "٫", with: ".")
    // BigDecimal reads any Unicode digit (Arabic-Indic too).
    s = String(s.map { ch -> Character in
        if let v = ch.wholeNumberValue, !ch.isASCII { return Character(String(v)) }
        return ch
    })
    if s.hasPrefix("+") { s.removeFirst() }
    let parts = s.split(separator: ".", omittingEmptySubsequences: false)
    guard (1...2).contains(parts.count) else { return nil }
    let wholePart = String(parts[0])
    let fracPart = parts.count == 2 ? String(parts[1]) : ""
    guard !(wholePart.isEmpty && fracPart.isEmpty),
          wholePart.allSatisfy({ $0.isASCII && $0.isNumber }), fracPart.allSatisfy({ $0.isASCII && $0.isNumber }) else { return nil }
    let d = max(decimals, 0)
    guard fracPart.count <= d else { return nil }
    let digits = (wholePart.isEmpty ? "0" : wholePart) + fracPart + String(repeating: "0", count: d - fracPart.count)
    guard let minor = Int(digits), minor > 0 else { return nil }
    return minor
}

/// The time left until `iso` by the phone's clock (read when the screen draws — nothing is asked of the server on a timer).
func timeLeft(_ iso: String?) -> TimeInterval? { parseInstant(iso).map { $0.timeIntervalSinceNow } }

func levelLabel(_ level: String) -> String {
    switch level {
    case "standard": return L("svc_level_standard")
    case "premium": return L("svc_level_premium")
    default: return L("svc_level_basic")
    }
}

func daysLabel(_ days: Int) -> String { days == 1 ? L("svc_day_one") : L("svc_days", days) }

/// A tone: its ink and its soft fill.
struct SvcTone {
    let ink: Color
    let fill: Color
}

func serviceTone(_ status: String, night: Bool) -> SvcTone {
    let c: Color
    switch status {
    case "published", "active", "completed": c = Color(hex: 0x16A34A)
    case "pending_review", "awaiting": c = Color(hex: 0xD97706)
    case "rejected", "declined": c = Color(hex: 0xDC2626)
    case "suspended": c = night ? Color(hex: 0xCBD5E1) : Color(hex: 0x334155)
    default: c = Color(hex: 0x64748B)
    }
    return SvcTone(ink: c, fill: c.opacity(night ? 0.22 : 0.12))
}

func serviceStatusLabel(_ status: String) -> String {
    switch status {
    case "pending_review": return L("svc_status_pending_review")
    case "published": return L("svc_status_published")
    case "paused": return L("svc_status_paused")
    case "rejected": return L("svc_status_rejected")
    case "suspended": return L("svc_status_suspended")
    default: return L("svc_status_draft")
    }
}

/// A small rounded label in the colour of what it says.
struct SvcPill: View {
    let text: String
    let tone: SvcTone
    var solid = false

    var body: some View {
        HStack(spacing: 6) {
            Circle().fill(solid ? Color.white : tone.ink).frame(width: 6, height: 6)
            Text(verbatim: text).font(.dorr(11.5, .bold)).foregroundStyle(solid ? Color.white : tone.ink).lineLimit(1)
        }
        .padding(.horizontal, 10)
        .padding(.vertical, 4)
        .background(solid ? tone.ink : tone.fill, in: Capsule())
    }
}

/// One part of the form: a numbered disc, its title and a line under it, then the fields.
struct StepCard<Content: View>: View {
    @Environment(\.wa) private var wa
    let number: Int
    let title: String
    let subtitle: String
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 12) {
                Text(verbatim: "\(number)").font(.dorr(14, .heavy)).foregroundStyle(.white)
                    .frame(width: 32, height: 32)
                    .background(wa.red, in: Circle())
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: title).font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk)
                    Text(verbatim: subtitle).font(.dorr(12)).foregroundStyle(wa.settingsMut).lineSpacing(2)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            content()
        }
        .padding(16)
        .frame(maxWidth: 520, alignment: .leading)
        .settingsSurface(22)
        .frame(maxWidth: .infinity)
    }
}

/// A white card without the numbered head (the details pages).
struct InfoCard<Content: View>: View {
    @Environment(\.wa) private var wa
    var title: String?
    var icon: String?
    @ViewBuilder let content: () -> Content

    init(title: String? = nil, icon: String? = nil, @ViewBuilder content: @escaping () -> Content) {
        self.title = title
        self.icon = icon
        self.content = content
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            if let title {
                HStack(spacing: 8) {
                    if let icon { Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(wa.red).frame(width: 18, height: 18) }
                    Text(verbatim: title).font(.dorr(14, .heavy)).foregroundStyle(wa.settingsInk)
                }
            }
            content()
        }
        .padding(16)
        .frame(maxWidth: 520, alignment: .leading)
        .settingsSurface(20)
        .frame(maxWidth: .infinity)
    }
}

/// − value + for small whole numbers (days, revisions).
struct Stepper: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: Int
    let range: ClosedRange<Int>
    var enabled = true
    let onChange: (Int) -> Void

    var body: some View {
        let line = wa.night ? wa.line : Color(hex: 0xE5E7EB)
        VStack(alignment: .leading, spacing: 6) {
            Text(verbatim: label).font(.dorr(12, .semibold)).foregroundStyle(wa.settingsMut)
            HStack(spacing: 0) {
                stepButton("minus", enabled && value > range.lowerBound) { onChange(value - 1) }
                Text(verbatim: "\(value)").font(.dorr(16, .heavy)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity)
                stepButton("plus", enabled && value < range.upperBound) { onChange(value + 1) }
            }
            .frame(height: 44)
            .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(line, lineWidth: 1))
        }
    }

    private func stepButton(_ icon: String, _ on: Bool, _ action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Image(systemName: icon).font(.system(size: 16, weight: .semibold))
                .foregroundStyle(on ? wa.red : wa.settingsMut.opacity(0.4))
                .frame(width: 44, height: 44)
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!on)
    }
}

/// A segmented picker: one choice of a few, the chosen one filled. `marks` puts a dot on the choices that are on.
struct Segmented: View {
    @Environment(\.wa) private var wa
    let options: [(String, String)]
    let selected: String
    var marks: Set<String> = []
    let onSelect: (String) -> Void

    var body: some View {
        HStack(spacing: 4) {
            ForEach(options, id: \.0) { option in
                let on = option.0 == selected
                Button { onSelect(option.0) } label: {
                    HStack(spacing: 6) {
                        Text(verbatim: option.1).font(.dorr(13, .bold)).foregroundStyle(on ? Color.white : wa.settingsInk).lineLimit(1)
                        if marks.contains(option.0) {
                            Circle().fill(on ? Color.white : Color(hex: 0x16A34A)).frame(width: 6, height: 6)
                        }
                    }
                    .frame(maxWidth: .infinity, minHeight: 38)
                    .background(on ? wa.red : .clear, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
            }
        }
        .padding(4)
        .background(wa.night ? wa.darkWell : wa.red.opacity(0.08), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
    }
}

/// A row in a money card: the label at the start, the amount at the end (left-to-right digits).
struct MoneyLine: View {
    @Environment(\.wa) private var wa
    let label: String
    let amount: String
    var strong = false
    var muted = false

    var body: some View {
        HStack(spacing: 0) {
            Text(verbatim: label).font(.dorr(strong ? 15 : 13, strong ? .heavy : .medium))
                .foregroundStyle(muted ? wa.settingsMut : wa.settingsInk)
                .frame(maxWidth: .infinity, alignment: .leading)
            Text(verbatim: amount).font(.dorr(strong ? 17 : 13, strong ? .heavy : .semibold))
                .foregroundStyle(strong ? wa.red : muted ? wa.settingsMut : wa.settingsInk)
                .environment(\.layoutDirection, .leftToRight)
        }
    }
}

/// Java's date formats print ASCII digits; iOS's Arabic locale would print Arabic-Indic — the service screens keep them Latin.
var svcDateLocale: Locale { Locale(identifier: AppLocale.current == "ar" ? "ar@numbers=latn" : AppLocale.current) }

/// The first letter of a client's name on a soft disc (`Initial` of ServiceOrderScreens.kt).
struct SvcInitial: View {
    @Environment(\.wa) private var wa
    let name: String?
    var size: CGFloat = 30
    var fontSize: CGFloat = 13

    var body: some View {
        let first = name?.trimmingCharacters(in: .whitespaces).first.map { String($0).uppercased() } ?? "?"
        Text(verbatim: first).font(.dorr(fontSize, .heavy)).foregroundStyle(wa.red)
            .frame(width: size, height: size)
            .background(wa.red.opacity(0.16), in: Circle())
    }
}
