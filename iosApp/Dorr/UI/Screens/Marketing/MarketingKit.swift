import SwiftUI

/// androidApp `ui/screens/marketing/MarketingKit.kt` — labels, money, dates and the small building blocks every
/// marketing page shares.

/// Minor units → "1,234.50 SAR". Decimal only: no float touches money.
func mkMoney(_ minor: Int?, _ currency: String, decimals: Int = 2) -> String {
    guard let minor else { return "—" }
    let amount = (Decimal(minor) / pow(10, decimals)) as NSDecimalNumber
    let f = NumberFormatter()
    f.locale = Locale(identifier: "en_US")
    f.numberStyle = .decimal
    f.minimumFractionDigits = decimals
    f.maximumFractionDigits = decimals
    let text = f.string(from: amount) ?? "\(minor)"
    return currency.isEmpty ? text : "\(text) \(currency)"
}

/// "1,234.5" typed by a person (Arabic digits too) → minor units; nil when it isn't a number.
func mkParseMinor(_ text: String, decimals: Int = 2) -> Int? {
    let arabic: [Character: Character] = ["٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4", "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9", "٫": "."]
    let clean = String(text.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: ",", with: "").map { arabic[$0] ?? $0 })
    guard !clean.isEmpty, let value = Decimal(string: clean, locale: Locale(identifier: "en_US")), value >= 0 else { return nil }
    var scaled = value * pow(10, decimals)
    var rounded = Decimal()
    NSDecimalRound(&rounded, &scaled, 0, .plain)
    return (rounded as NSDecimalNumber).intValue
}

func mkDate(_ iso: String?) -> String {
    guard let iso, !iso.isEmpty else { return "" }
    guard let date = parseInstant(iso) else { return String(iso.prefix(10)) }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.dateStyle = .medium
    return f.string(from: date)
}

func mkStatusLabel(_ status: String) -> String {
    switch status {
    case "QUEUED", "RUNNING", "EVIDENCE_CHECK", "COMPLETED", "PARTIAL", "FAILED", "CANCELLED",
         "READY_FOR_REVIEW", "APPROVED", "SUPERSEDED", "ARCHIVED":
        return L("mktg_status_\(status)")
    default:
        return L("mktg_status_DRAFT")
    }
}

func mkStatusColor(_ status: String) -> Color {
    switch status {
    case "COMPLETED", "APPROVED": return Color(hex: 0x16A34A)
    case "PARTIAL", "READY_FOR_REVIEW": return Color(hex: 0xD97706)
    case "FAILED", "CANCELLED": return Color(hex: 0xDC2626)
    case "QUEUED", "RUNNING", "EVIDENCE_CHECK": return Color(hex: 0x2563EB)
    default: return Color(hex: 0x6B7280)
    }
}

func mkPlanLabel(_ type: String) -> String {
    switch type {
    case "business": return L("mktg_plan_business")
    case "marketing": return L("mktg_plan_marketing")
    default: return L("mktg_plan_gtm")
    }
}

func mkKindLabel(_ kind: String) -> String {
    switch kind {
    case "competitor": return L("mktg_research_competitor")
    case "audience": return L("mktg_research_audience")
    default: return L("mktg_research_market")
    }
}

func mkRoleLabel(_ role: String?) -> String {
    switch role {
    case "owner": return L("mktg_role_owner")
    case "manager": return L("mktg_role_manager")
    case "analyst": return L("mktg_role_analyst")
    default: return L("mktg_role_viewer")
    }
}

func mkStageLabel(_ stage: String?) -> String {
    switch stage {
    case "launch": return L("mktg_stage_launch")
    case "growth": return L("mktg_stage_growth")
    default: return L("mktg_stage_idea")
    }
}

let mkAmber = Color(hex: 0xD97706)
let mkBlue = Color(hex: 0x2563EB)

/// A small rounded status pill with a dot.
struct MkBadge: View {
    let text: String
    let color: Color

    var body: some View {
        HStack(spacing: 5) {
            Circle().fill(color).frame(width: 6, height: 6)
            Text(verbatim: text).font(.dorr(11, .bold)).foregroundStyle(color).lineLimit(1)
        }
        .padding(.horizontal, 9)
        .padding(.vertical, 3)
        .background(color.opacity(0.13), in: Capsule())
    }
}

struct MkStatusBadge: View {
    let status: String
    var body: some View { MkBadge(text: mkStatusLabel(status), color: mkStatusColor(status)) }
}

struct MkSmallTag: View {
    let text: String
    let tint: Color

    var body: some View {
        Text(verbatim: text).font(.dorr(10.5, .bold)).foregroundStyle(tint)
            .padding(.horizontal, 7).padding(.vertical, 2)
            .background(tint.opacity(0.12), in: RoundedRectangle(cornerRadius: 8, style: .continuous))
    }
}

/// A thin rounded progress bar (job progress, evidence quality).
struct MkBar: View {
    @Environment(\.wa) private var wa
    let fraction: Double
    let color: Color

    var body: some View {
        GeometryReader { proxy in
            ZStack(alignment: .leading) {
                Capsule().fill(wa.line)
                Capsule().fill(color).frame(width: proxy.size.width * min(max(fraction, 0), 1))
            }
        }
        .frame(height: 6)
        .animation(.easeOut(duration: 0.5), value: fraction)
    }
}

/// A tappable card row: icon well, title, subtitle, trailing content, and the reading-direction chevron.
struct MkNavCard<Trailing: View>: View {
    @Environment(\.wa) private var wa
    let icon: String
    var tone: Tone = .red
    let title: String
    var subtitle: String?
    @ViewBuilder var trailing: () -> Trailing
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            WaCard(padding: 14) {
                HStack(spacing: 12) {
                    WaIconWell(icon: icon, tone: tone, size: 42, iconSize: 21)
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verbatim: title).font(.dorr(14.5, .heavy)).foregroundStyle(wa.ink).lineLimit(2).multilineTextAlignment(.leading)
                        if let subtitle, !subtitle.isEmpty {
                            Text(verbatim: subtitle).font(.dorr(12.5)).foregroundStyle(wa.mut).lineLimit(2).multilineTextAlignment(.leading)
                        }
                    }
                    Spacer(minLength: 6)
                    trailing()
                    // SF "chevron.forward" mirrors itself in right-to-left layouts.
                    Image(systemName: "chevron.forward").font(.system(size: 14, weight: .semibold)).foregroundStyle(wa.soft)
                }
            }
        }
        .buttonStyle(WaPressStyle(scale: 0.97))
    }
}

extension MkNavCard where Trailing == EmptyView {
    init(icon: String, tone: Tone = .red, title: String, subtitle: String?, action: @escaping () -> Void) {
        self.init(icon: icon, tone: tone, title: title, subtitle: subtitle, trailing: { EmptyView() }, action: action)
    }
}

/// A titled block inside a card.
struct MkBlock<Content: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    @ViewBuilder var content: () -> Content

    var body: some View {
        WaCard(padding: 16) {
            VStack(alignment: .leading, spacing: 8) {
                Text(verbatim: title).font(.dorr(14.5, .heavy)).foregroundStyle(wa.ink).padding(.bottom, 2)
                content()
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
    }
}

/// "• text" lines.
struct MkBullets: View {
    @Environment(\.wa) private var wa
    let items: [String]
    var muted = false

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            ForEach(Array(items.enumerated()), id: \.offset) { _, item in
                HStack(alignment: .top, spacing: 8) {
                    Text(verbatim: "•").font(.dorr(14, .black)).foregroundStyle(wa.red)
                    Text(verbatim: item).font(.dorr(13.5)).foregroundStyle(muted ? wa.mut : wa.ink).lineSpacing(4)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
        }
    }
}

/// The page frame every marketing page uses (the wallet's page; back walks the section's stack).
struct MkPage<Content: View, Cta: View>: View {
    @EnvironmentObject private var host: MkHost
    let title: String
    var hasCta = false
    @ViewBuilder var cta: () -> Cta
    @ViewBuilder var content: () -> Content

    var body: some View {
        if hasCta {
            WaPage(title: title, onBack: { host.pop() }, cta: cta, content: content)
        } else {
            WaPage(title: title, onBack: { host.pop() }, content: content)
        }
    }
}

extension MkPage where Cta == EmptyView {
    init(title: String, @ViewBuilder content: @escaping () -> Content) {
        self.init(title: title, hasCta: false, cta: { EmptyView() }, content: content)
    }
}

extension MkPage {
    init(title: String, @ViewBuilder cta: @escaping () -> Cta, @ViewBuilder content: @escaping () -> Content) {
        self.init(title: title, hasCta: true, cta: cta, content: content)
    }
}

// MARK: - plan JSON

extension JSONAny {
    var stringValue: String? {
        if case .string(let v) = self, !v.trimmingCharacters(in: .whitespaces).isEmpty { return v }
        return nil
    }

    var arrayValue: [JSONAny] {
        if case .array(let v) = self { return v }
        return []
    }

    func text(_ key: String) -> String? { self[key]?.stringValue }

    func number(_ key: String) -> Int? {
        if case .number(let v)? = self[key] { return Int(v) }
        return nil
    }

    func strings(_ key: String) -> [String] { (self[key]?.arrayValue ?? []).compactMap { $0.stringValue } }

    func objects(_ key: String) -> [JSONAny] {
        (self[key]?.arrayValue ?? []).filter { if case .object = $0 { return true } else { return false } }
    }

    /// A copy with [key] set (objects only; anything else comes back unchanged).
    func setting(_ key: String, _ value: JSONAny) -> JSONAny {
        guard case .object(var map) = self else { return self }
        map[key] = value
        return .object(map)
    }

    static func lines(_ text: String) -> JSONAny {
        var seen = Set<String>()
        let items = text.split(whereSeparator: \.isNewline).map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { !$0.isEmpty && seen.insert($0).inserted }
        return .array(items.map { .string($0) })
    }
}

/// A discovery answer as the text field shows it (lists one per line).
func mkAnswerText(_ value: JSONAny?, type: String?) -> String {
    guard let value else { return "" }
    switch value {
    case .array(let items): return items.compactMap { $0.str }.joined(separator: "\n")
    case .number(let v) where type == "money": return mkMoney(Int(v), "").replacingOccurrences(of: ",", with: "")
    case .null: return ""
    default: return value.str ?? ""
    }
}
