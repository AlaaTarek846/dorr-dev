import SwiftUI
import UIKit

/// androidApp `ui/screens/watchearn/WeTheme.kt` — Watch & Earn's look, on top of the wallet's (WaTheme): the same
/// cards, buttons and page frame, plus the three bucket colours used on every page (rewards / not withdrawable /
/// withdrawable), status badges, chips that animate in and out, numbers that count up, and money in minor units with
/// the currency's own decimals.
enum We {
    /// Rewards bucket (Watch & Earn earnings) — amber everywhere.
    static let reward = Color(hex: 0xD97706)
    /// Not withdrawable (spend-only) bucket — blue everywhere.
    static let spendOnly = Color(hex: 0x2563EB)
    /// Withdrawable bucket — green everywhere.
    static let withdrawable = Color(hex: 0x16A34A)
    static let interaction = Color(hex: 0x7C3AED)
    static let gold = Color(hex: 0xF59E0B)

    /// The two series in the daily chart and the donut slices.
    static let series: [Color] = [Color(hex: 0x2563EB), Color(hex: 0x7C3AED), Color(hex: 0xDB2777), Color(hex: 0x16A34A), Color(hex: 0xD97706), Color(hex: 0x6B7280)]

    static var rewardBrush: LinearGradient {
        LinearGradient(colors: [Color(hex: 0xF59E0B), Color(hex: 0xD97706), Color(hex: 0xB45309)], startPoint: .topLeading, endPoint: .bottomTrailing)
    }
}

extension Error {
    /// The server's own message for a failed call (already in the app's language), or [fallback].
    func weMessage(_ fallback: String) -> String { apiFailure.message ?? fallback }
}

// MARK: - buckets

/// The three wallet buckets, always in this order, with the same label and colour on every page.
enum WeBucket: CaseIterable {
    case reward, spendOnly, withdrawable

    var color: Color {
        switch self {
        case .reward: return We.reward
        case .spendOnly: return We.spendOnly
        case .withdrawable: return We.withdrawable
        }
    }

    var label: String {
        switch self {
        case .reward: return L("we_bucket_reward")
        case .spendOnly: return L("we_bucket_spend_only")
        case .withdrawable: return L("we_bucket_withdrawable")
        }
    }

    func of(_ b: WeBucketsDto) -> Int {
        switch self {
        case .reward: return b.rewardMinor
        case .spendOnly: return b.spendOnlyMinor
        case .withdrawable: return b.withdrawableMinor
        }
    }
}

// MARK: - money

private func weDecimal(_ minor: Int, _ decimals: Int) -> Decimal {
    let d = min(max(decimals, 0), 4)
    return Decimal(sign: minor < 0 ? .minus : .plus, exponent: -d, significand: Decimal(minor.magnitude))
}

/// Minor units → "1,234.50" with the currency's decimals. Decimal only: no float ever touches money.
func weAmount(_ minor: Int, _ decimals: Int) -> String {
    let d = min(max(decimals, 0), 4)
    let f = NumberFormatter()
    f.locale = Locale(identifier: "en_US")
    f.numberStyle = .decimal
    f.usesGroupingSeparator = true
    f.groupingSeparator = ","
    f.decimalSeparator = "."
    f.minimumFractionDigits = d
    f.maximumFractionDigits = d
    return f.string(from: weDecimal(minor, d) as NSDecimalNumber) ?? "\(minor)"
}

/// "1,234.50 SAR".
func weMoney(_ minor: Int, _ currency: WeCurrencyDto?) -> String {
    let text = weAmount(minor, currency?.decimals ?? 2)
    guard let code = currency?.code, !code.trimmingCharacters(in: .whitespaces).isEmpty else { return text }
    return "\(text) \(code)"
}

/// What was typed → minor units, or nil when it isn't a positive amount with at most [decimals] decimals.
func weParseAmount(_ text: String, _ decimals: Int) -> Int? {
    let d = min(max(decimals, 0), 4)
    let t = text.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: ",", with: ".")
    guard t.range(of: #"^[0-9]+(\.[0-9]*)?$|^\.[0-9]+$"#, options: .regularExpression) != nil else { return nil }
    let parts = t.split(separator: ".", omittingEmptySubsequences: false)
    let whole = parts.first.map(String.init) ?? ""
    let fraction = parts.count > 1 ? String(parts[1]) : ""
    guard fraction.count <= d, whole.count + d <= 16 else { return nil }
    let digits = (whole.isEmpty ? "0" : whole) + fraction.padding(toLength: d, withPad: "0", startingAt: 0)
    guard let minor = Int(digits), minor > 0 else { return nil }
    return minor
}

/// Minor units → the text a field starts with ("150.00" → "150").
func weAmountInput(_ minor: Int, _ decimals: Int) -> String {
    let d = min(max(decimals, 0), 4)
    let f = NumberFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.numberStyle = .decimal
    f.usesGroupingSeparator = false
    f.minimumFractionDigits = 0
    f.maximumFractionDigits = d
    return f.string(from: weDecimal(minor, d) as NSDecimalNumber) ?? "\(minor)"
}

/// "1,234" — counts shown with their own word after them.
func weCount(_ n: Int) -> String {
    let f = NumberFormatter()
    f.locale = Locale(identifier: "en_US")
    f.numberStyle = .decimal
    return f.string(from: NSNumber(value: n)) ?? "\(n)"
}

// MARK: - dates

func weInstant(_ iso: String?) -> Date? { parseInstant(iso) }

/// "2 Nov 2026" / "٢ نوفمبر ٢٠٢٦" in the app's language.
func weDate(_ iso: String?) -> String {
    guard let day = weInstant(iso) else { return "" }
    return weDate(day: day)
}

func weDate(day: Date) -> String {
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.dateStyle = .medium
    f.timeStyle = .none
    return f.string(from: day)
}

/// "2 Nov, 14:05".
func weDateTime(_ iso: String?) -> String {
    guard let at = weInstant(iso) else { return "" }
    let f = DateFormatter()
    f.locale = AppLocale.locale
    f.dateStyle = .medium
    f.timeStyle = .short
    return f.string(from: at)
}

/// Today at midnight on the device (Android's `LocalDate.now()`).
func weToday() -> Date { Calendar.current.startOfDay(for: Date()) }

func wePlusDays(_ day: Date, _ n: Int) -> Date { Calendar.current.date(byAdding: .day, value: n, to: Calendar.current.startOfDay(for: day)) ?? day }

/// A day for the server: its start, or its very end (an end date counts the whole day).
func weServerDate(_ day: Date, endOfDay: Bool = false) -> String {
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.calendar = Calendar(identifier: .gregorian)
    f.timeZone = .current
    f.dateFormat = "yyyy-MM-dd"
    return f.string(from: day) + (endOfDay ? " 23:59:59" : " 00:00:00")
}

/// "yyyy-MM-dd" → that day on the device.
func weParseDay(_ text: String?) -> Date? {
    guard let text, !text.isEmpty else { return nil }
    let f = DateFormatter()
    f.locale = Locale(identifier: "en_US_POSIX")
    f.calendar = Calendar(identifier: .gregorian)
    f.timeZone = .current
    f.dateFormat = "yyyy-MM-dd"
    return f.date(from: String(text.prefix(10)))
}

/// "mm:ss" left (device clock).
func weCountdown(_ leftMs: Int) -> String {
    let s = max(leftMs / 1000, 0)
    return String(format: "%02ld:%02ld", s / 60, s % 60)
}

// MARK: - building blocks

extension View {
    /// Numbers and amounts always read left to right, even in an Arabic layout.
    func weLtr() -> some View { environment(\.layoutDirection, .leftToRight) }
}

/// A number that counts up from where it was (from zero the first time) whenever it appears or changes.
struct WeCountUp: View {
    @Environment(\.wa) private var wa
    let target: Int
    let format: (Int) -> String
    var color: Color?
    var size: CGFloat = 22
    var weight: Font.Weight = .heavy
    var duration: Double = 0.9
    @State private var shown: Double = 0

    var body: some View {
        Color.clear.frame(width: 0, height: 0)
            .modifier(WeCounting(value: shown, format: format))
            .font(.dorr(size, weight))
            .foregroundStyle(color ?? wa.ink)
            .lineLimit(1)
            .weLtr()
            .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: duration)) { shown = Double(target) } }
            .onChange(of: target) { t in withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: duration)) { shown = Double(t) } }
    }
}

/// The animated text behind [WeCountUp] (a Double holds every amount below 2^53 exactly, so the end is exact).
private struct WeCounting: AnimatableModifier {
    var value: Double
    let format: (Int) -> String
    var animatableData: Double {
        get { value }
        set { value = newValue }
    }

    func body(content: Content) -> some View {
        Text(verbatim: format(Int(value.rounded())))
    }
}

/// A small round dot in a bucket's colour.
struct WeDot: View {
    let color: Color
    var size: CGFloat = 9

    var body: some View { Circle().fill(color).frame(width: size, height: size) }
}

/// An amount split by bucket: a stacked bar (its parts grow in) and one row per bucket — the same three colours and
/// labels on every page. [hideZero] leaves out the empty buckets.
struct WeBucketSplit: View {
    @Environment(\.wa) private var wa
    let buckets: WeBucketsDto
    let currency: WeCurrencyDto?
    var hideZero = false
    var bar = true
    @State private var grow: CGFloat = 0

    var body: some View {
        let total = buckets.totalMinor
        VStack(alignment: .leading, spacing: 0) {
            if bar && total > 0 {
                GeometryReader { proxy in
                    HStack(spacing: 0) {
                        ForEach(WeBucket.allCases, id: \.self) { b in
                            let part = CGFloat(b.of(buckets)) / CGFloat(total)
                            if part > 0 { b.color.frame(width: proxy.size.width * part * grow) }
                        }
                        Spacer(minLength: 0)
                    }
                    .frame(width: proxy.size.width, height: 10, alignment: .leading)
                    .background(wa.line)
                    .clipShape(Capsule())
                }
                .frame(height: 10)
                .padding(.bottom, 10)
                .onAppear { animateGrow() }
                .onChange(of: total) { _ in grow = 0; animateGrow() }
            }
            ForEach(WeBucket.allCases, id: \.self) { b in
                let amount = b.of(buckets)
                if !(hideZero && amount == 0) {
                    HStack(spacing: 8) {
                        WeDot(color: b.color)
                        Text(b.label).font(.dorr(13)).foregroundStyle(wa.mut)
                        Spacer(minLength: 6)
                        Text(verbatim: weMoney(amount, currency)).font(.dorr(13, .bold)).foregroundStyle(wa.ink).weLtr()
                    }
                    .padding(.vertical, 5)
                }
            }
        }
    }

    private func animateGrow() {
        withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.8)) { grow = 1 }
    }
}

/// Bucket chips in a row ("● 1.00  ● 2.00"), for statement lines.
struct WeBucketChips: View {
    let buckets: WeBucketsDto
    let currency: WeCurrencyDto?

    var body: some View {
        HStack(spacing: 6) {
            ForEach(WeBucket.allCases, id: \.self) { b in
                let amount = b.of(buckets)
                if amount != 0 {
                    HStack(spacing: 4) {
                        WeDot(color: b.color, size: 6)
                        Text(verbatim: weAmount(amount, currency?.decimals ?? 2)).font(.dorr(10.5, .bold)).foregroundStyle(b.color).weLtr()
                    }
                    .padding(.horizontal, 8)
                    .padding(.vertical, 3)
                    .background(b.color.opacity(0.12), in: Capsule())
                }
            }
        }
    }
}

/// A choice chip: the brand colour when picked, a soft outline when not; colours and size change smoothly.
struct WeChip: View {
    @Environment(\.wa) private var wa
    let text: String
    let selected: Bool
    var icon: String?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack(spacing: 6) {
                if let icon { Image(systemName: icon).font(.system(size: 13, weight: .semibold)) }
                Text(verbatim: text).font(.dorr(13, .bold)).lineLimit(1)
            }
            .foregroundStyle(selected ? Color.white : wa.ink)
            .padding(.horizontal, 14)
            .padding(.vertical, 8)
            .background(selected ? wa.red : wa.surface, in: Capsule())
            .overlay(Capsule().stroke(selected ? Color.clear : wa.line, lineWidth: 1))
            .animation(.easeOut(duration: 0.22), value: selected)
        }
        .buttonStyle(WaPressStyle(scale: 0.94))
    }
}

/// A picked value with a ×: it pops in when added, and shrinks away before [onRemoved] takes it out of the list
/// (the city chips of the wizard).
struct WeRemovableChip: View {
    @Environment(\.wa) private var wa
    let text: String
    let onRemoved: () -> Void
    @State private var shown = false

    var body: some View {
        HStack(spacing: 4) {
            Text(verbatim: text).font(.dorr(12.5, .bold)).foregroundStyle(wa.red).lineLimit(1)
            Button {
                withAnimation(.easeIn(duration: 0.18)) { shown = false }
                DispatchQueue.main.asyncAfter(deadline: .now() + 0.18) { onRemoved() }
            } label: {
                Image(systemName: "xmark").font(.system(size: 10, weight: .bold)).foregroundStyle(wa.red)
                    .frame(width: 20, height: 20)
                    .background(wa.red.opacity(0.16), in: Circle())
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("we_remove"))
        }
        .padding(.leading, 12)
        .padding(.trailing, 6)
        .padding(.vertical, 5)
        .background(wa.red.opacity(0.12), in: Capsule())
        .overlay(Capsule().stroke(wa.red.opacity(0.3), lineWidth: 1))
        .scaleEffect(shown ? 1 : 0.2)
        .opacity(shown ? 1 : 0)
        .onAppear { withAnimation(.spring(response: 0.35, dampingFraction: 0.5)) { shown = true } }
    }
}

/// Chips that wrap onto new lines (Compose's FlowRow).
struct WeFlow: Layout {
    var spacing: CGFloat = 6

    func sizeThatFits(proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) -> CGSize {
        let width = proposal.width ?? .infinity
        var x: CGFloat = 0, y: CGFloat = 0, row: CGFloat = 0, widest: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > width { x = 0; y += row + spacing; row = 0 }
            x += size.width + spacing
            widest = max(widest, x - spacing)
            row = max(row, size.height)
        }
        return CGSize(width: proposal.width ?? widest, height: y + row)
    }

    func placeSubviews(in bounds: CGRect, proposal: ProposedViewSize, subviews: Subviews, cache: inout ()) {
        var x: CGFloat = 0, y: CGFloat = 0, row: CGFloat = 0
        for s in subviews {
            let size = s.sizeThatFits(.unspecified)
            if x > 0 && x + size.width > bounds.width { x = 0; y += row + spacing; row = 0 }
            s.place(at: CGPoint(x: bounds.minX + x, y: bounds.minY + y), proposal: ProposedViewSize(size))
            x += size.width + spacing
            row = max(row, size.height)
        }
    }
}

// MARK: - statuses

func weStatusLabel(_ status: String, scheduled: Bool = false) -> String {
    if status == "active" && scheduled { return L("we_status_scheduled") }
    switch status {
    case "draft": return L("we_status_draft")
    case "pending_review": return L("we_status_pending_review")
    case "rejected": return L("we_status_rejected")
    case "active": return L("we_status_active")
    case "paused": return L("we_status_paused")
    case "exhausted": return L("we_status_exhausted")
    case "suspended": return L("we_status_suspended")
    case "ended": return L("we_status_ended")
    case "stopped": return L("we_status_stopped")
    default: return L("we_status_unknown")
    }
}

func weStatusColor(_ status: String, scheduled: Bool = false) -> Color {
    if status == "active" && scheduled { return Color(hex: 0x2563EB) }
    switch status {
    case "active": return Color(hex: 0x16A34A)
    case "pending_review": return Color(hex: 0xD97706)
    case "paused": return Color(hex: 0x6B7280)
    case "exhausted": return Color(hex: 0xEA580C)
    case "rejected", "suspended": return Color(hex: 0xDC2626)
    default: return Color(hex: 0x6B7280)
    }
}

/// The ad's status as a tinted pill with a dot.
struct WeStatusBadge: View {
    let status: String
    var scheduled = false

    var body: some View {
        let color = weStatusColor(status, scheduled: scheduled)
        HStack(spacing: 5) {
            WeDot(color: color, size: 6)
            Text(verbatim: weStatusLabel(status, scheduled: scheduled)).font(.dorr(11, .heavy)).foregroundStyle(color).lineLimit(1)
        }
        .padding(.horizontal, 9)
        .padding(.vertical, 3)
        .background(color.opacity(0.13), in: Capsule())
    }
}

// MARK: - cards

/// A number card that counts up when it appears: icon, value, label.
struct WeNumberCard: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: Int
    let icon: String
    var suffix = ""
    var tint: Color?

    var body: some View {
        let t = tint ?? wa.red
        WaCard(padding: 14) {
            Image(systemName: icon).font(.system(size: 15, weight: .semibold)).foregroundStyle(t)
                .frame(width: 32, height: 32)
                .background(t.opacity(0.13), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            WeCountUp(target: value, format: { "\($0)" + suffix }, size: 20).padding(.top, 10)
            Text(verbatim: label).font(.dorr(12)).foregroundStyle(wa.mut).lineLimit(2)
        }
    }
}

/// A tile on the landing page (my rewards / my ads / the ad wallet).
struct WeTile: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    var subtitle: String?
    let tint: Color
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            WaCard(padding: 14) {
                Image(systemName: icon).font(.system(size: 19, weight: .semibold)).foregroundStyle(tint)
                    .frame(width: 40, height: 40)
                    .background(tint.opacity(0.14), in: RoundedRectangle(cornerRadius: 13, style: .continuous))
                Text(verbatim: title).font(.dorr(13.5, .heavy)).foregroundStyle(wa.ink).lineLimit(1).padding(.top, 10)
                if let subtitle, !subtitle.isEmpty { Text(verbatim: subtitle).font(.dorr(11)).foregroundStyle(wa.mut).lineLimit(1) }
            }
        }
        .buttonStyle(WaPressStyle(scale: 0.95))
    }
}

/// A thin rounded progress bar that fills in when it appears.
struct WeProgressBar: View {
    @Environment(\.wa) private var wa
    let fraction: Double
    var color: Color?
    var height: CGFloat = 7
    @State private var shown: Double = 0

    var body: some View {
        GeometryReader { proxy in
            ZStack(alignment: .leading) {
                Capsule().fill(wa.line)
                Capsule().fill(color ?? wa.red).frame(width: proxy.size.width * shown)
            }
        }
        .frame(height: height)
        .onAppear { withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.9)) { shown = min(max(fraction, 0), 1) } }
        .onChange(of: fraction) { f in withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.9)) { shown = min(max(f, 0), 1) } }
    }
}

/// The check's stroke (drawn by `trim`).
private struct WeCheckShape: Shape {
    func path(in rect: CGRect) -> Path {
        var p = Path()
        p.move(to: CGPoint(x: rect.width * 0.28, y: rect.height * 0.52))
        p.addLine(to: CGPoint(x: rect.width * 0.44, y: rect.height * 0.67))
        p.addLine(to: CGPoint(x: rect.width * 0.73, y: rect.height * 0.36))
        return p
    }
}

/// The success mark: a circle draws itself, then the check, with a little bounce.
struct WeSuccessCheck: View {
    var size: CGFloat = 110
    var color: Color = We.withdrawable
    var onDone: () -> Void = {}
    @State private var ring: CGFloat = 0
    @State private var tick: CGFloat = 0
    @State private var pop: CGFloat = 0.6

    var body: some View {
        let stroke = size * 0.07
        ZStack {
            Circle().fill(color.opacity(0.12 * ring))
            Circle().trim(from: 0, to: ring)
                .stroke(color, style: StrokeStyle(lineWidth: stroke, lineCap: .round))
                .rotationEffect(.degrees(-90))
                .padding(stroke / 2)
            WeCheckShape().trim(from: 0, to: tick)
                .stroke(color, style: StrokeStyle(lineWidth: stroke * 1.1, lineCap: .round, lineJoin: .round))
        }
        .frame(width: size, height: size)
        .scaleEffect(0.85 + 0.15 * pop)
        .task {
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.52)) { ring = 1 }
            try? await Task.sleep(nanoseconds: 520_000_000)
            withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.38)) { tick = 1 }
            try? await Task.sleep(nanoseconds: 380_000_000)
            withAnimation(.spring(response: 0.4, dampingFraction: 0.4)) { pop = 1 }
            try? await Task.sleep(nanoseconds: 400_000_000)
            onDone()
        }
    }
}

// MARK: - fields, sheets and dialogs (the iOS side of DorrTextField, ChSheet and LocaleAwareDialog)

/// androidApp `DorrTextField` as Watch & Earn uses it: a label, a rounded field with an optional icon, an error
/// outline, a trailing word (the currency), one line or several.
struct WeTextField: View {
    @Environment(\.wa) private var wa
    @Binding var value: String
    var label: String?
    var placeholder: String?
    var icon: String?
    var minLines = 1
    /// Grows to more lines as it is typed (Android's `singleLine = false`); implied by [minLines] > 1.
    var multiline = false
    var keyboard: UIKeyboardType = .default
    var error = false
    var trailing: String?
    var limit = 2000
    var ltr = false
    var capitalize = false
    @FocusState private var focused: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            if let label { Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.ink).padding(.bottom, 8) }
            let grows = multiline || minLines > 1
            HStack(alignment: grows ? .top : .center, spacing: 8) {
                if let icon {
                    Image(systemName: icon).font(.system(size: 15, weight: .semibold))
                        .foregroundStyle(error ? wa.danger : (focused ? wa.red : wa.red.opacity(0.8)))
                        .padding(.top, grows ? 3 : 0)
                }
                Group {
                    if grows {
                        TextField(placeholder ?? "", text: limited, axis: .vertical).lineLimit(max(minLines, 1)...8)
                    } else {
                        TextField(placeholder ?? "", text: limited)
                    }
                }
                .font(.dorr(15))
                .foregroundStyle(wa.ink)
                .tint(wa.red)
                .keyboardType(keyboard)
                .textInputAutocapitalization(capitalize ? .sentences : .never)
                .autocorrectionDisabled(!capitalize)
                .focused($focused)
                .environment(\.layoutDirection, ltr ? .leftToRight : layoutDirection)
                if let trailing, !trailing.isEmpty { Text(verbatim: trailing).font(.dorr(13, .bold)).foregroundStyle(wa.mut) }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, grows ? 12 : 8)
            .frame(minHeight: 48)
            .background(wa.night ? wa.bg : (focused ? wa.surface : wa.field), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: 16, style: .continuous)
                    .stroke(error ? wa.danger : (focused ? wa.red : wa.line), lineWidth: focused || error ? 1.5 : 1)
            )
            .animation(.easeOut(duration: 0.2), value: focused)
        }
    }

    @Environment(\.layoutDirection) private var layoutDirection

    private var limited: Binding<String> {
        Binding(get: { value }, set: { value = String($0.prefix(limit)) })
    }
}

/// Keeps only what an amount can hold (digits, a point or a comma).
func weAmountChars(_ text: String) -> String { text.filter { $0.isNumber && $0.isASCII || $0 == "." || $0 == "," } }

extension View {
    /// A bottom sheet over this page (Android's `ChSheet`): a dim scrim that closes it, a surface card rounded on top
    /// that slides up with its handle. Drawn in the page itself, so it follows the keyboard and the page's state.
    func weSheet<C: View>(_ shown: Bool, onDismiss: @escaping () -> Void, @ViewBuilder content: @escaping () -> C) -> some View {
        overlay { WeSheetLayer(shown: shown, onDismiss: onDismiss, content: content) }
    }
}

private struct WeSheetLayer<C: View>: View {
    @Environment(\.wa) private var wa
    let shown: Bool
    let onDismiss: () -> Void
    @ViewBuilder let content: () -> C

    var body: some View {
        ZStack(alignment: .bottom) {
            if shown {
                Color.black.opacity(0.45).ignoresSafeArea().onTapGesture(perform: onDismiss).transition(.opacity)
                VStack(alignment: .leading, spacing: 0) {
                    Capsule().fill(wa.line).frame(width: 40, height: 4).frame(maxWidth: .infinity).padding(.top, 10).padding(.bottom, 12)
                    content()
                }
                .padding(.bottom, 8)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(
                    UnevenRoundedRectangleCompat(topRadius: 30).fill(wa.surface)
                        .shadow(color: .black.opacity(0.18), radius: 20, y: -2)
                        .ignoresSafeArea(.container, edges: .bottom)
                )
                .contentShape(Rectangle())
                .onTapGesture {}
                .transition(.move(edge: .bottom))
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottom)
        .animation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.32), value: shown)
    }
}

/// A yes / no question shown over the whole section (`WeConfirmDialog` on Android).
struct WeDialogSpec {
    let title: String
    let text: String
    let confirm: String
    var danger = false
    let onConfirm: () -> Void
}

/// A day to pick, shown over the whole section (`WeDatePicker` on Android): from [min] on, up to two years ahead.
struct WeDatePickerSpec {
    let initial: Date
    let min: Date
    let onPick: (Date) -> Void
}

struct WeConfirmDialog: View {
    @Environment(\.wa) private var wa
    let spec: WeDialogSpec
    let onDismiss: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(verbatim: spec.title).font(.dorr(17, .heavy)).foregroundStyle(wa.ink)
            Text(verbatim: spec.text).font(.dorr(13.5)).foregroundStyle(wa.mut).lineSpacing(5)
                .fixedSize(horizontal: false, vertical: true)
                .padding(.top, 8).padding(.bottom, 18)
            HStack(spacing: 10) {
                WaButton(text: L("we_cancel"), style: .ghost, compact: true, action: onDismiss)
                if spec.danger {
                    Button {
                        onDismiss()
                        spec.onConfirm()
                    } label: {
                        HStack(spacing: 6) {
                            Image(systemName: "trash.fill").font(.system(size: 14, weight: .bold))
                            Text(verbatim: spec.confirm).font(.dorr(13, .heavy))
                        }
                        .foregroundStyle(.white)
                        .frame(maxWidth: .infinity, minHeight: 40, maxHeight: 40)
                        .background(wa.danger, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    }
                    .buttonStyle(WaPressStyle(scale: 0.97))
                } else {
                    WaButton(text: spec.confirm, compact: true) {
                        onDismiss()
                        spec.onConfirm()
                    }
                }
            }
        }
        .padding(22)
        .frame(maxWidth: 340)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        .padding(.horizontal, 24)
    }
}

struct WeDatePickerCard: View {
    @Environment(\.wa) private var wa
    let spec: WeDatePickerSpec
    let onDismiss: () -> Void
    @State private var picked: Date

    init(spec: WeDatePickerSpec, onDismiss: @escaping () -> Void) {
        self.spec = spec
        self.onDismiss = onDismiss
        _picked = State(initialValue: max(spec.initial, spec.min))
    }

    var body: some View {
        let upper = Calendar.current.date(byAdding: .year, value: 2, to: weToday()) ?? spec.min
        VStack(spacing: 0) {
            DatePicker("", selection: $picked, in: spec.min...max(upper, spec.min), displayedComponents: .date)
                .datePickerStyle(.graphical)
                .labelsHidden()
                .tint(wa.red)
                .environment(\.locale, AppLocale.locale)
            HStack(spacing: 10) {
                Spacer(minLength: 0)
                Button(action: onDismiss) {
                    Text(L("we_cancel")).font(.dorr(14, .bold)).foregroundStyle(wa.ink).padding(.horizontal, 14).padding(.vertical, 10)
                }
                .buttonStyle(.plain)
                Button {
                    onDismiss()
                    spec.onPick(Calendar.current.startOfDay(for: picked))
                } label: {
                    Text(L("we_ok")).font(.dorr(14, .heavy)).foregroundStyle(.white).padding(.horizontal, 18).padding(.vertical, 10)
                        .background(wa.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                }
                .buttonStyle(WaPressStyle(scale: 0.95))
            }
            .padding(.top, 4)
        }
        .padding(16)
        .frame(maxWidth: 380)
        .background(wa.surface, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        .padding(.horizontal, 16)
    }
}

/// A picture from our server (Coil's `AsyncImage`), cropped to fill.
struct WeRemoteImage: View {
    let url: String?
    var fit = false

    var body: some View {
        AsyncImage(url: ApiClient.mediaUrl(url)) { phase in
            if let image = phase.image {
                if fit { image.resizable().scaledToFit() } else { image.resizable().scaledToFill() }
            } else {
                Color.clear
            }
        }
    }
}
