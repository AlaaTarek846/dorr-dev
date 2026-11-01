import SwiftUI

// androidApp `chat/ChTheme.kt` — the chat's look: the app colour carried into a messenger. My bubbles wear the brand
// gradient, theirs are soft white cards; everything that appears, changes or disappears is animated. Every chat
// screen reads its colours, shapes and motion from here. Colours follow the appearance settings (`wa`) and the
// chat's own light / dark choice (ChatStore.themeMode); a conversation's theme (admin-made or mine) colours the
// bubbles while it is on screen.

@MainActor
final class Ch: ObservableObject {
    static let shared = Ch()

    @Published var dark = false
    @Published var accent = Color(hex: 0x001B53)
    @Published var palette: ChPalette?
    /// The theme of the conversation on screen; nil = Dorr's own look.
    @Published var bubbleTheme: ChatThemeDto?

    var red: Color { accent }
    var redDeep: Color { accent.scaled(0.8) }
    var ink: Color { palette?.ink ?? (dark ? Color(hex: 0xF3F4F6) : Color(hex: 0x111928)) }
    var mut: Color { palette?.mut ?? (dark ? Color(hex: 0x9CA3AF) : Color(hex: 0x6B7280)) }
    var soft: Color { palette?.soft ?? (dark ? Color(hex: 0x6B7280) : Color(hex: 0x9CA3AF)) }
    var line: Color { palette?.line ?? (dark ? Color(hex: 0x273244) : Color(hex: 0xEEF0F3)) }
    var bg: Color { palette?.bg ?? (dark ? Color(hex: 0x0B1220) : Color(hex: 0xF7F4F2)) }
    /// Cards, rows, sheets, the incoming bubble.
    var surface: Color { palette?.surface ?? (dark ? Color(hex: 0x172033) : .white) }
    /// Quiet wells inside a surface: quotes, file cards, chips, image placeholders.
    var surfaceMuted: Color { palette.map { $0.surface.mix($0.ink, dark ? 0.08 : 0.045) } ?? (dark ? Color(hex: 0x223047) : Color(hex: 0xF4F4F6)) }
    var danger: Color { palette?.danger ?? Color(hex: 0xDC2626) }
    var success: Color { palette?.success ?? Color(hex: 0x16A34A) }
    /// The system's field look: fill and hairline.
    var fieldFill: Color { dark ? bg : Color(hex: 0xFBF7F8) }
    var fieldLine: Color { dark ? line : Color(hex: 0xF3D5DB) }
    var tint: LinearGradient {
        LinearGradient(colors: dark ? [accent.opacity(0.22), accent.opacity(0.12)] : [accent.opacity(0.07), accent.opacity(0.12)], startPoint: .leading, endPoint: .trailing)
    }
    static let online = Color(hex: 0x22C55E)
    static let readTick = Color(hex: 0x38BDF8)
    static let mention = Color(hex: 0xF59E0B)

    private var themeOut: Color? { bubbleTheme.flatMap { hexColor($0.senderColor) } }
    private var themeIn: Color? { bubbleTheme.flatMap { hexColor($0.receiverColor) } }

    /// My bubble: the app colour (or the theme's) as a gradient.
    var outBubble: LinearGradient {
        if let c = themeOut { return LinearGradient(colors: [c, c.scaled(0.88)], startPoint: .topLeading, endPoint: .bottomTrailing) }
        return LinearGradient(colors: [accent, accent.scaled(0.88), accent.scaled(0.74)], startPoint: .topLeading, endPoint: .bottomTrailing)
    }
    var inBubble: Color { themeIn ?? surface }
    /// Text on my bubble: white on dark colours, near-black on light ones.
    var outText: Color { themeOut.map { $0.isLight ? Color(hex: 0x111928) : .white } ?? .white }
    var inText: Color { themeIn.map { $0.isLight ? Color(hex: 0x111928) : Color(hex: 0xF3F4F6) } ?? ink }

    /// Headers: the app colour deepening to dark.
    var headerBrush: LinearGradient {
        LinearGradient(colors: [accent, accent.scaled(0.78), accent.scaled(0.45)], startPoint: .topLeading, endPoint: .bottomTrailing)
    }
    /// The conversation background: the settings' background, warmed by a hint of the app colour.
    var wallpaper: LinearGradient {
        dark ? LinearGradient(colors: [bg.mix(accent, 0.04), bg, bg.scaled(0.9)], startPoint: .top, endPoint: .bottom)
            : LinearGradient(colors: [bg.mix(accent, 0.05), bg.mix(accent, 0.03), bg], startPoint: .top, endPoint: .bottom)
    }

    static let bubbleRadius: CGFloat = 20
    static let tailRadius: CGFloat = 6

    /// Name colours in groups — each member keeps one colour, derived from their key.
    static let namePalette: [Color] = [0xE11D48, 0x7C3AED, 0x0891B2, 0x059669, 0xD97706, 0x2563EB, 0xDB2777, 0x65A30D].map { Color(hex: $0) }
    /// Avatar backgrounds for people without a photo.
    static let avatarGradients: [[Color]] = [
        [0xFB7185, 0xE11D48], [0xA78BFA, 0x7C3AED], [0x22D3EE, 0x0891B2], [0x34D399, 0x059669],
        [0xFBBF24, 0xD97706], [0x60A5FA, 0x2563EB], [0xF472B6, 0xDB2777], [0xF87171, 0xB91C1C],
    ].map { $0.map { Color(hex: $0) } }

    static func colorFor(_ key: String?) -> Color { namePalette[stableHash(key) % namePalette.count] }

    /// Copies the appearance into the chat (androidApp `BindChatAppearance`).
    func bind(_ wa: WaPalette, systemDark: Bool) {
        let chatDark: Bool = {
            switch ChatStore.shared.themeMode {
            case "dark": return true
            case "light": return false
            default: return systemDark
            }
        }()
        let appearance = AppearanceState.shared
        func token(_ key: String, _ light: UInt32, _ night: UInt32) -> Color { appearance.color(key, Color(hex: chatDark ? night : light), dark: chatDark) }
        let p = ChPalette(
            ink: token("textPrimary", 0x111928, 0xF3F4F6), mut: token("textSecondary", 0x6B7280, 0x9CA3AF),
            soft: token("textMuted", 0x9CA3AF, 0x6B7280), line: token("border", 0xEEF0F3, 0x273244),
            bg: token("background", 0xF7F4F2, 0x0B1220), surface: token("surface", 0xFFFFFF, 0x172033),
            danger: token("danger", 0xDC2626, 0xDC2626), success: token("success", 0x16A34A, 0x16A34A)
        )
        let a = chatDark ? wa.darkAccent : appearance.color("primary", Color(hex: 0x001B53), dark: false)
        if dark != chatDark { dark = chatDark }
        if palette != p { palette = p }
        if accent != a { accent = a }
    }
}

/// The appearance-settings colours the chat uses, for one mode.
struct ChPalette: Equatable {
    let ink, mut, soft, line, bg, surface, danger, success: Color
}

/// The same number for the same text on every run (Kotlin's `hashCode().absoluteValue`).
func stableHash(_ s: String?) -> Int {
    var h: Int32 = 0
    for u in (s ?? "").utf16 { h = h &* 31 &+ Int32(u) }
    return Int(h == Int32.min ? 0 : abs(h))
}

/// "#RRGGBB" / "#RRGGBBAA" → Color.
func hexColor(_ hex: String?) -> Color? {
    guard let h = hex?.trimmingCharacters(in: CharacterSet(charactersIn: "#")), let v = UInt64(h, radix: 16) else { return nil }
    switch h.count {
    case 6: return Color(hex: UInt32(v))
    case 8: return Color(.sRGB, red: Double((v >> 24) & 0xFF) / 255, green: Double((v >> 16) & 0xFF) / 255, blue: Double((v >> 8) & 0xFF) / 255, opacity: Double(v & 0xFF) / 255)
    default: return nil
    }
}

extension Color {
    var isLight: Bool {
        var r: CGFloat = 0, g: CGFloat = 0, b: CGFloat = 0, a: CGFloat = 0
        UIColor(self).getRed(&r, green: &g, blue: &b, alpha: &a)
        return 0.299 * r + 0.587 * g + 0.114 * b > 0.6
    }

    /// `lerp(self, other, t)`.
    func mix(_ other: Color, _ t: Double) -> Color {
        var r1: CGFloat = 0, g1: CGFloat = 0, b1: CGFloat = 0, a1: CGFloat = 0
        var r2: CGFloat = 0, g2: CGFloat = 0, b2: CGFloat = 0, a2: CGFloat = 0
        UIColor(self).getRed(&r1, green: &g1, blue: &b1, alpha: &a1)
        UIColor(other).getRed(&r2, green: &g2, blue: &b2, alpha: &a2)
        let f = CGFloat(t)
        return Color(.sRGB, red: Double(r1 + (r2 - r1) * f), green: Double(g1 + (g2 - g1) * f), blue: Double(b1 + (b2 - b1) * f), opacity: Double(a1 + (a2 - a1) * f))
    }
}

@MainActor func myKey() -> String { "user:\(AuthSession.shared.user?.id ?? 0)" }

/// Keeps `Ch` in step with the appearance and the system's mode (put once around the chat).
struct ChatAppearanceBinder: ViewModifier {
    @Environment(\.wa) private var wa
    @Environment(\.colorScheme) private var scheme
    @ObservedObject private var store = ChatStore.shared

    func body(content: Content) -> some View {
        content
            .onAppear { Ch.shared.bind(wa, systemDark: wa.night || scheme == .dark) }
            .onChange(of: wa.night) { _ in Ch.shared.bind(wa, systemDark: wa.night || scheme == .dark) }
            .onChange(of: scheme) { _ in Ch.shared.bind(wa, systemDark: wa.night || scheme == .dark) }
            .onChange(of: store.themeMode) { _ in Ch.shared.bind(wa, systemDark: wa.night || scheme == .dark) }
    }
}

extension View {
    func chatAppearance() -> some View { modifier(ChatAppearanceBinder()) }
}

// MARK: - Avatar

/// A round avatar: the photo, else the initials on a gradient that's always the same for the same person. `online`
/// adds a breathing green dot; `ring` a brand ring (unseen story / active call).
struct ChAvatar: View {
    @ObservedObject private var ch = Ch.shared
    let url: String?
    let name: String?
    let key: String?
    var size: CGFloat = 52
    var isGroup = false
    var online = false
    var ring = false

    var body: some View {
        let gradient = Ch.avatarGradients[stableHash(key) % Ch.avatarGradients.count]
        ZStack(alignment: .bottomTrailing) {
            ZStack {
                LinearGradient(colors: gradient, startPoint: .topLeading, endPoint: .bottomTrailing)
                if isGroup && url == nil {
                    Image(systemName: "person.3.fill").font(.system(size: size * 0.32)).foregroundStyle(.white)
                } else {
                    Text(verbatim: initials(name)).font(.dorr(size * 0.36, .heavy)).foregroundStyle(.white)
                }
                if let url {
                    AsyncImage(url: ApiClient.mediaUrl(url)) { phase in
                        if let image = phase.image { image.resizable().scaledToFill() }
                    }
                }
            }
            .clipShape(Circle())
            .padding(ring ? 3 : 0)
            .overlay {
                if ring {
                    Circle().stroke(AngularGradient(colors: [ch.red, ch.redDeep, ch.red.scaled(0.45), ch.red], center: .center), lineWidth: 2.5)
                }
            }
            .frame(width: size, height: size)
            if online { OnlineDot(avatar: size) }
        }
        .frame(width: size, height: size)
    }
}

private struct OnlineDot: View {
    let avatar: CGFloat
    @State private var pulse = false

    var body: some View {
        let dot = min(max(avatar * 0.26, 10), 16)
        ZStack {
            Circle().fill(Ch.online).frame(width: dot, height: dot).scaleEffect(pulse ? 1.9 : 1).opacity(pulse ? 0 : 0.45)
            Circle().fill(Ch.online).frame(width: dot - 4, height: dot - 4).overlay(Circle().stroke(.white, lineWidth: 2).frame(width: dot, height: dot))
        }
        .frame(width: dot, height: dot)
        .onAppear { withAnimation(.easeOut(duration: 1.4).repeatForever(autoreverses: false)) { pulse = true } }
    }
}

func initials(_ name: String?) -> String {
    let words = (name ?? "").split(whereSeparator: \.isWhitespace).filter { $0.first?.isLetter == true || $0.first?.isNumber == true }
    if words.isEmpty { return "؟" }
    if words.count == 1 { return String(words[0].prefix(1)).uppercased() }
    return (String(words[0].prefix(1)) + String(words[1].prefix(1))).uppercased()
}

// MARK: - Badges & ticks

// The unread count badge (ChBadge) is shared with the home page (HomeScreen.swift).

/// ⏱ → ✓ → ✓✓ → blue ✓✓ — the icon swaps with a little pop and the colour glides to blue.
struct ChTicks: View {
    @ObservedObject private var ch = Ch.shared
    let status: String?
    let onBubble: Bool
    var size: CGFloat = 15

    var body: some View {
        let onLight = onBubble && ch.outText != .white
        let base: Color = onBubble ? ch.outText.opacity(onLight ? 0.5 : 0.78) : ch.soft
        let read: Color = onLight ? Color(hex: 0x0EA5E9) : onBubble ? Color(hex: 0xBAE6FD) : Ch.readTick
        Group {
            switch status {
            case "pending": Image(systemName: "clock").font(.system(size: size - 4, weight: .semibold))
            case "sent": Image(systemName: "checkmark").font(.system(size: size - 3, weight: .bold))
            default: DoubleTick(size: size)
            }
        }
        .foregroundStyle(status == "read" ? read : base)
        .animation(.easeInOut(duration: 0.45), value: status)
        .transition(.scale.combined(with: .opacity))
        .id(status)
    }
}

private struct DoubleTick: View {
    let size: CGFloat
    var body: some View {
        ZStack {
            Image(systemName: "checkmark").font(.system(size: size - 3, weight: .bold)).offset(x: -size * 0.18)
            Image(systemName: "checkmark").font(.system(size: size - 3, weight: .bold)).offset(x: size * 0.18)
        }
        .frame(width: size * 1.3)
        .environment(\.layoutDirection, .leftToRight)
    }
}

/// Three dots rising one after another — «typing…» / «recording…».
struct TypingDots: View {
    var color: Color?
    var dot: CGFloat = 6
    @ObservedObject private var ch = Ch.shared

    var body: some View {
        TimelineView(.animation) { ctx in
            let t = ctx.date.timeIntervalSinceReferenceDate
            HStack(spacing: dot * 0.6) {
                ForEach(0..<3, id: \.self) { i in
                    let phase = (t / 0.56 - Double(i) * 0.25).truncatingRemainder(dividingBy: 2)
                    let y = phase < 1 ? phase : 2 - phase
                    Circle().fill(color ?? ch.red).frame(width: dot, height: dot).offset(y: -y * dot * 0.9).opacity(0.45 + 0.55 * y)
                }
            }
        }
    }
}

// MARK: - Motion

/// Pop-in for things that just arrived (a new bubble): scale + rise + fade.
struct ChPopIn: ViewModifier {
    let enabled: Bool
    var fromEnd = false
    @State private var p: CGFloat = 0

    func body(content: Content) -> some View {
        content
            .opacity(enabled ? p : 1)
            .scaleEffect(enabled ? 0.82 + 0.18 * p : 1, anchor: fromEnd ? .bottomTrailing : .bottomLeading)
            .offset(y: enabled ? (1 - p) * 22 : 0)
            .task { if p < 1 { withAnimation(.spring(response: 0.4, dampingFraction: 0.62)) { p = 1 } } }
    }
}

/// Staggered entrance for list rows (first page only).
struct ChStagger: ViewModifier {
    let index: Int
    var enabled = true
    @Environment(\.layoutDirection) private var direction
    @State private var p: CGFloat = 0

    func body(content: Content) -> some View {
        let on = enabled && index < 14
        content
            .opacity(on ? p : 1)
            .offset(x: on ? (1 - p) * 28 * (direction == .rightToLeft ? -1 : 1) : 0)
            .task {
                guard on, p < 1 else { return }
                try? await Task.sleep(nanoseconds: UInt64((0.04 + Double(index) * 0.045) * 1_000_000_000))
                withAnimation(.easeOut(duration: 0.42)) { p = 1 }
            }
    }
}

extension View {
    func chPopIn(_ enabled: Bool, fromEnd: Bool = false) -> some View { modifier(ChPopIn(enabled: enabled, fromEnd: fromEnd)) }
    func chStagger(_ index: Int, enabled: Bool = true) -> some View { modifier(ChStagger(index: index, enabled: enabled)) }
}

// MARK: - Wallpaper

/// The conversation background: a warm wash with a faint doodle pattern (bubbles, hearts, sparkles, dots).
struct ChWallpaper: View {
    @ObservedObject private var ch = Ch.shared
    var tint: Color?
    var wash = true

    var body: some View {
        Canvas { gc, size in
            let step: CGFloat = 92
            let c = (tint ?? ch.red).opacity(0.07)
            let stroke = StrokeStyle(lineWidth: 1.4)
            var row = 0
            var y = -step / 2
            while y < size.height + step {
                var x: CGFloat = row % 2 == 0 ? 0 : step / 2
                var i = row
                while x < size.width + step {
                    let s: CGFloat = 11
                    var p = Path()
                    switch i % 4 {
                    case 0:
                        p.addRoundedRect(in: CGRect(x: x - s, y: y - s * 0.7, width: s * 2, height: s * 1.4), cornerSize: CGSize(width: s * 0.6, height: s * 0.6))
                        p.move(to: CGPoint(x: x - s * 0.4, y: y + s * 0.7))
                        p.addLine(to: CGPoint(x: x - s * 0.8, y: y + s * 1.2))
                    case 1:
                        p.move(to: CGPoint(x: x, y: y + s * 0.8))
                        p.addCurve(to: CGPoint(x: x, y: y - s * 0.3), control1: CGPoint(x: x - s * 1.4, y: y - s * 0.1), control2: CGPoint(x: x - s * 0.6, y: y - s * 1.1))
                        p.addCurve(to: CGPoint(x: x, y: y + s * 0.8), control1: CGPoint(x: x + s * 0.6, y: y - s * 1.1), control2: CGPoint(x: x + s * 1.4, y: y - s * 0.1))
                    case 2:
                        p.move(to: CGPoint(x: x, y: y - s)); p.addLine(to: CGPoint(x: x, y: y + s))
                        p.move(to: CGPoint(x: x - s, y: y)); p.addLine(to: CGPoint(x: x + s, y: y))
                        p.addEllipse(in: CGRect(x: x - s * 0.28, y: y - s * 0.28, width: s * 0.56, height: s * 0.56))
                    default:
                        p.addEllipse(in: CGRect(x: x - s * 0.75, y: y - s * 0.75, width: s * 1.5, height: s * 1.5))
                    }
                    gc.stroke(p, with: .color(c), style: stroke)
                    x += step
                    i += 1
                }
                y += step * 0.72
                row += 1
            }
        }
        .background { if wash { ch.wallpaper } }
        .environment(\.layoutDirection, .leftToRight)
        .allowsHitTesting(false)
    }
}

// MARK: - Time

private func chFormatter(_ pattern: String) -> DateFormatter {
    let f = DateFormatter()
    f.locale = Locale(identifier: AppLocale.current)
    f.dateFormat = pattern
    return f
}

/// "14:05"
func clockTime(_ iso: String?) -> String {
    guard let d = parseRideTime(iso) else { return "" }
    return chFormatter("HH:mm").string(from: d)
}

/// Chat list: today → time, yesterday → «Yesterday», this week → weekday, else the date.
func listTime(_ iso: String?) -> String {
    guard let d = parseRideTime(iso) else { return "" }
    let cal = Calendar.current
    if cal.isDateInToday(d) { return clockTime(iso) }
    if cal.isDateInYesterday(d) { return L("ch_yesterday") }
    let days = cal.dateComponents([.day], from: cal.startOfDay(for: d), to: cal.startOfDay(for: Date())).day ?? 99
    if days < 7 { return chFormatter("EEEE").string(from: d) }
    return chFormatter("d/M/yyyy").string(from: d)
}

/// "Monday 2026-11-30 00:00" in the phone's language.
func taskDueStamp(_ iso: String?) -> String {
    guard let d = parseRideTime(iso) else { return "" }
    let rest = DateFormatter()
    rest.locale = Locale(identifier: "en_US_POSIX")
    rest.dateFormat = "yyyy-MM-dd HH:mm"
    return "\(chFormatter("EEEE").string(from: d)) \(rest.string(from: d))"
}

func dayOf(_ iso: String?) -> Date? { parseRideTime(iso).map { Calendar.current.startOfDay(for: $0) } }

/// The floating date chip in a conversation.
func dayLabel(_ day: Date) -> String {
    let cal = Calendar.current
    if cal.isDateInToday(day) { return L("ch_today") }
    if cal.isDateInYesterday(day) { return L("ch_yesterday") }
    return chFormatter("d MMMM yyyy").string(from: day)
}

func durationText(_ ms: Int64) -> String {
    let total = max(ms / 1000, 0)
    return String(format: "%d:%02d", total / 60, total % 60)
}

func fileSizeText(_ bytes: Int64?) -> String {
    guard let b = bytes else { return "" }
    if b >= 1_048_576 { return String(format: "%.1f MB", Double(b) / 1_048_576) }
    if b >= 1024 { return "\(b / 1024) KB" }
    return "\(b) B"
}

/// A small rounded chip (date separators, system lines).
struct ChChip: View {
    @ObservedObject private var ch = Ch.shared
    let text: String
    var dark = false

    var body: some View {
        Text(verbatim: text).font(.dorr(12, .semibold)).foregroundStyle(dark ? .white : ch.mut)
            .padding(.horizontal, 12).padding(.vertical, 5)
            .background(dark ? Color(hex: 0x111928).opacity(0.8) : Color.white.opacity(0.92), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
    }
}

/// A thin brand line under headers while something loads.
struct ChLoadingBar: View {
    @ObservedObject private var ch = Ch.shared
    let visible: Bool

    var body: some View {
        if visible {
            TimelineView(.animation) { ctx in
                GeometryReader { g in
                    let t = ctx.date.timeIntervalSinceReferenceDate.truncatingRemainder(dividingBy: 1.1) / 1.1
                    let x = (-0.4 + 1.5 * t) * g.size.width
                    LinearGradient(colors: [.clear, ch.red, .clear], startPoint: .leading, endPoint: .trailing)
                        .frame(width: g.size.width * 0.35, height: 2)
                        .offset(x: x)
                }
            }
            .frame(height: 2)
            .environment(\.layoutDirection, .leftToRight)
            .clipped()
        }
    }
}
