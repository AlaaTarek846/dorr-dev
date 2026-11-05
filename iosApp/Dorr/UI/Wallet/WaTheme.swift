import SwiftUI
import UIKit

/// androidApp `ui/screens/wallet/WaTheme.kt` — the wallet's look, ported 1:1 from the web preview
/// (public/app/css/wallet.css): brand colour, tinted icon wells, big soft cards, staggered entrances,
/// glass chips. Everything wallet-specific reads its colours and shapes from here.
struct WaPalette {
    let night: Bool
    /// `Wa.Red` — the app's brand colour (settingsAccent): appearance `primary`, the logo navy by default.
    let red: Color
    let ink: Color
    let mut: Color
    let soft: Color
    let line: Color
    let bg: Color
    let field: Color
    let surface: Color
    /// AccountDark.card / well / accent — only used at night.
    let darkCard: Color
    let darkWell: Color
    let darkAccent: Color

    let green = Color(hex: 0x16A34A)
    let danger = Color(hex: 0xDC2626)
    let key = Color(hex: 0xF4F5F7)

    var buttonGradient: LinearGradient {
        LinearGradient(colors: [red, red.scaled(0.72)], startPoint: .topLeading, endPoint: .bottomTrailing)
    }

    var heroGradient: LinearGradient {
        LinearGradient(colors: [red, red.scaled(0.78), red.scaled(0.45)], startPoint: .topLeading, endPoint: .bottomTrailing)
    }

    @MainActor
    static func resolve(_ appearance: AppearanceState, dark: Bool) -> WaPalette {
        func token(_ key: String, _ light: UInt32, _ night: UInt32) -> Color {
            appearance.color(key, Color(hex: dark ? night : light), dark: dark)
        }
        let darkCard = appearance.color("surface", Color(hex: 0x1A1D24), dark: true)
        return WaPalette(
            night: dark,
            red: token("primary", 0x001B53, 0xFA7552),
            ink: token("textPrimary", 0x111928, 0xF4F5F7),
            mut: token("textSecondary", 0x6B7280, 0x9AA1AC),
            soft: dark ? Color(hex: 0x8B93A0) : appearance.color("textMuted", Color(hex: 0x9CA3AF), dark: false),
            line: token("border", 0xEEF0F3, 0x2C313A),
            bg: token("background", 0xF9FAFB, 0x101216),
            field: dark ? darkCard : Color(hex: 0xF5F6F8),
            surface: dark ? darkCard : appearance.color("surface", .white, dark: false),
            darkCard: darkCard,
            darkWell: Color(hex: 0x2C2226),
            darkAccent: appearance.color("primary", Color(hex: 0xFA7552), dark: true)
        )
    }
}

/// The tinted icon wells (background + glyph colour) — `tone-*` in the preview. Icon wells now take the
/// app's colours; the tone only says what an icon means.
enum Tone {
    case red, green, amber, pink, blue, gray
}

extension Color {
    /// Each RGB channel multiplied by [factor] (the `Color(red * 0.72f, …)` gradients on Android).
    func scaled(_ factor: CGFloat) -> Color {
        var r: CGFloat = 0, g: CGFloat = 0, b: CGFloat = 0, a: CGFloat = 0
        UIColor(self).getRed(&r, green: &g, blue: &b, alpha: &a)
        return Color(red: Double(r * factor), green: Double(g * factor), blue: Double(b * factor), opacity: Double(a))
    }

    /// `#rrggbb` (the sandbox bank page reads the app's colours from its query string).
    var hex6: String {
        var r: CGFloat = 0, g: CGFloat = 0, b: CGFloat = 0, a: CGFloat = 0
        UIColor(self).getRed(&r, green: &g, blue: &b, alpha: &a)
        return String(format: "%02x%02x%02x", Int(round(r * 255)), Int(round(g * 255)), Int(round(b * 255)))
    }
}

private struct WaPaletteKey: EnvironmentKey {
    static let defaultValue = WaPalette(
        night: false, red: AppColors.brandNavy, ink: Color(hex: 0x111928), mut: Color(hex: 0x6B7280),
        soft: Color(hex: 0x9CA3AF), line: Color(hex: 0xEEF0F3), bg: Color(hex: 0xF9FAFB), field: Color(hex: 0xF5F6F8),
        surface: .white, darkCard: Color(hex: 0x1A1D24), darkWell: Color(hex: 0x2C2226), darkAccent: Color(hex: 0xFA7552)
    )
}

private struct WalletHostKey: EnvironmentKey {
    static let defaultValue: WalletHost? = nil
}

extension EnvironmentValues {
    var wa: WaPalette {
        get { self[WaPaletteKey.self] }
        set { self[WaPaletteKey.self] = newValue }
    }

    /// `LocalWalletOrNull` — the wallet visit this page belongs to (nil outside the wallet).
    var walletHost: WalletHost? {
        get { self[WalletHostKey.self] }
        set { self[WalletHostKey.self] = newValue }
    }
}

/// Puts the wallet palette (from the appearance tokens and light / dark) in the environment.
struct WaThemed: ViewModifier {
    @EnvironmentObject private var appearance: AppearanceState
    @Environment(\.colorScheme) private var scheme

    func body(content: Content) -> some View {
        content.environment(\.wa, WaPalette.resolve(appearance, dark: scheme == .dark))
    }
}

extension View {
    func waThemed() -> some View { modifier(WaThemed()) }
}

// MARK: - Motion

/// Staggered entrance: each block fades and rises a little later than the one before.
struct WaRise: ViewModifier {
    let index: Int
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown ? 1 : 0)
            .offset(y: shown ? 0 : 16)
            .onAppear {
                withAnimation(.timingCurve(0.4, 0, 0.2, 1, duration: 0.5).delay(Double(index) * 0.06 + 0.08)) { shown = true }
            }
    }
}

extension View {
    func waRise(_ index: Int = 0) -> some View { modifier(WaRise(index: index)) }
}

/// Presses shrink a little, like the preview's `:active { transform: scale(.97) }`.
struct WaPressStyle: ButtonStyle {
    var scale: CGFloat = 0.96

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? scale : 1)
            .animation(.linear(duration: 0.12), value: configuration.isPressed)
    }
}

/// The loading shimmer (`waShimmerBrush`).
struct WaSkeleton: View {
    @Environment(\.wa) private var wa
    var cornerRadius: CGFloat = 10
    @State private var phase: CGFloat = -1

    var body: some View {
        GeometryReader { proxy in
            let base = wa.night ? Color(hex: 0x2C313A) : Color(hex: 0xEEF0F3)
            let shine = wa.night ? Color(hex: 0x3A404A) : Color(hex: 0xF7F8FA)
            LinearGradient(colors: [base, shine, base], startPoint: .leading, endPoint: .trailing)
                .frame(width: proxy.size.width * 2)
                .offset(x: proxy.size.width * phase)
        }
        .background(wa.night ? Color(hex: 0x2C313A) : Color(hex: 0xEEF0F3))
        .clipShape(RoundedRectangle(cornerRadius: cornerRadius, style: .continuous))
        .onAppear {
            withAnimation(.linear(duration: 1.3).repeatForever(autoreverses: false)) { phase = 1 }
        }
    }
}

// MARK: - Building blocks

/// A card: rounded 22, surface, soft shadow in light, hairline border at night.
struct WaCard<Content: View>: View {
    @Environment(\.wa) private var wa
    var padding: CGFloat = 0
    @ViewBuilder let content: () -> Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0, content: content)
            .padding(padding)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(wa.surface, in: RoundedRectangle(cornerRadius: 22, style: .continuous))
            .overlay {
                if wa.night { RoundedRectangle(cornerRadius: 22, style: .continuous).stroke(wa.line, lineWidth: 1) }
            }
            .shadow(color: wa.night ? .clear : Color(hex: 0x111928).opacity(0.12), radius: 10, y: 4)
    }
}

struct WaIconWell: View {
    @Environment(\.wa) private var wa
    let icon: String
    var tone: Tone = .red
    var size: CGFloat = 44
    var iconSize: CGFloat = 22

    var body: some View {
        // Every icon well takes the app's colours — the tone only says what the icon means, never its colour.
        Image(systemName: icon)
            .font(.system(size: iconSize * 0.86, weight: .semibold))
            .foregroundStyle(wa.night ? wa.darkAccent : wa.red)
            .frame(width: size, height: size)
            .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: size * 0.34, style: .continuous))
    }
}

struct WaCircleButton: View {
    @Environment(\.wa) private var wa
    let icon: String
    var tint: Color?
    var label: String?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon)
                .font(.system(size: 16, weight: .semibold))
                .foregroundStyle(tint ?? wa.ink)
                .frame(width: 38, height: 38)
                .background(wa.surface, in: Circle())
                .shadow(color: wa.red.opacity(0.18), radius: 6, y: 2)
        }
        .buttonStyle(WaPressStyle(scale: 0.9))
        .accessibilityLabel(label ?? "")
    }
}

/// Header circle matching settings: filled 34pt surface, 16pt icon.
struct WaOutlineCircleButton: View {
    @Environment(\.wa) private var wa
    let icon: String
    var tint: Color?
    var label: String?
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: icon)
                .font(.system(size: 13, weight: .bold))
                .foregroundStyle(tint ?? wa.red)
                .frame(width: 34, height: 34)
                .background(wa.night ? wa.darkCard : wa.surface, in: Circle())
                .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
                .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.08), radius: 6, y: 2)
        }
        .buttonStyle(WaPressStyle(scale: 0.9))
        .accessibilityLabel(label ?? "")
    }
}

enum WaButtonStyle { case primary, ghost, quiet }

struct WaButton: View {
    @Environment(\.wa) private var wa
    let text: String
    var style: WaButtonStyle = .primary
    var icon: String?
    var enabled = true
    var loading = false
    var iconAfter = false
    var spread = false
    var compact = false
    let action: () -> Void

    var body: some View {
        let height: CGFloat = compact ? 40 : (style == .quiet ? 44 : 54)
        let content: Color = style == .primary ? .white : (style == .ghost ? wa.red : wa.mut)
        let size: CGFloat = compact ? 13 : (style == .quiet ? 14 : 16)
        Button(action: action) {
            HStack(spacing: 8) {
                if loading {
                    ProgressView().tint(content)
                } else {
                    if let icon, !iconAfter { Image(systemName: icon).font(.system(size: 15, weight: .bold)) }
                    if spread && iconAfter { Text(text).font(.dorr(size, .heavy)).lineLimit(1); Spacer(minLength: 0) } else { Text(text).font(.dorr(size, .heavy)).lineLimit(1) }
                    if let icon, iconAfter { Image(systemName: icon).font(.system(size: 15, weight: .bold)) }
                }
            }
            .foregroundStyle(content)
            .padding(.horizontal, spread ? 22 : 12)
            .frame(maxWidth: .infinity, minHeight: height, maxHeight: height)
            .background {
                switch style {
                case .primary:
                    RoundedRectangle(cornerRadius: 18, style: .continuous)
                        .fill(enabled ? AnyShapeStyle(wa.buttonGradient) : AnyShapeStyle(Color(hex: 0x9CA3AF)))
                        .shadow(color: enabled ? wa.red.opacity(0.45) : .clear, radius: 12, y: 6)
                case .ghost:
                    RoundedRectangle(cornerRadius: 18, style: .continuous).fill(wa.surface)
                        .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.red.opacity(0.4), lineWidth: 1))
                case .quiet:
                    Color.clear
                }
            }
            .contentShape(Rectangle())
            .opacity(enabled ? 1 : 0.4)
        }
        .buttonStyle(WaPressStyle(scale: 0.97))
        .disabled(!enabled || loading)
    }
}

/// Amber heads-up box (`wa-note`).
struct WaNote: View {
    @Environment(\.wa) private var wa
    let text: String
    var icon = "info.circle.fill"

    var body: some View {
        let ink = wa.night ? Color(hex: 0xF6C98A) : Color(hex: 0x92590B)
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: icon).font(.system(size: 14, weight: .semibold)).foregroundStyle(ink).padding(.top, 3)
            Text(text).font(.dorr(12.5)).foregroundStyle(ink).lineSpacing(4).fixedSize(horizontal: false, vertical: true)
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(wa.night ? wa.darkWell : Color(hex: 0xFEF6E7), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
    }
}

/// Reports an error as a notification (the wallet toast, in red) instead of drawing text inside the page.
/// Put it where the message used to be shown: it shows each new message once and draws nothing.
struct WaError: View {
    @Environment(\.walletHost) private var host
    let message: String?

    var body: some View {
        Color.clear.frame(width: 0, height: 0)
            .onAppear { report(message) }
            .onChange(of: message) { report($0) }
    }

    @MainActor
    private func report(_ text: String?) {
        guard let text, !text.trimmingCharacters(in: .whitespaces).isEmpty else { return }
        // Outside the wallet there is no wallet toast: the app-wide one, like Android's system toast.
        if let host { host.showError(text) } else { AppToast.show(text) }
    }
}

/// Empty / failure block with a floating icon well.
struct WaEmpty<Action: View>: View {
    @Environment(\.wa) private var wa
    let icon: String
    var tone: Tone = .gray
    let title: String
    let text: String
    @ViewBuilder var action: () -> Action
    @State private var float = false

    var body: some View {
        VStack(spacing: 0) {
            WaIconWell(icon: icon, tone: tone, size: 74, iconSize: 30)
                .offset(y: float ? -7 : 0)
                .onAppear { withAnimation(.easeInOut(duration: 1.7).repeatForever(autoreverses: true)) { float = true } }
            Text(title).font(.dorr(16, .heavy)).foregroundStyle(wa.ink).multilineTextAlignment(.center).padding(.top, 14)
            Text(text).font(.dorr(13)).foregroundStyle(wa.mut).multilineTextAlignment(.center).lineSpacing(5).padding(.top, 4)
            action().padding(.top, 14)
        }
        .padding(.horizontal, 20)
        .padding(.vertical, 30)
        .frame(maxWidth: .infinity)
    }
}

extension WaEmpty where Action == EmptyView {
    init(icon: String, tone: Tone = .gray, title: String, text: String) {
        self.init(icon: icon, tone: tone, title: title, text: text, action: { EmptyView() })
    }
}

/// The glass pills on the hero card and confirmation card.
struct WaGlassChip: View {
    @Environment(\.wa) private var wa
    let text: String
    let icon: String
    var dark = false

    var body: some View {
        let night = wa.night && dark
        let background: Color = night ? wa.darkWell : (dark ? Color(hex: 0xF3F4F6) : Color.white.opacity(0.17))
        let foreground: Color = night ? wa.ink : (dark ? wa.mut : .white)
        HStack(spacing: 6) {
            Image(systemName: icon).font(.system(size: 12, weight: .semibold))
            Text(text).font(.dorr(12, .bold))
        }
        .foregroundStyle(foreground)
        .padding(.horizontal, 11)
        .padding(.vertical, 5)
        .background(background, in: Capsule())
    }
}

/// Section title row (`wa-sec`).
struct WaSectionTitle<Trailing: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    var topPadding: CGFloat = 22
    @ViewBuilder var trailing: () -> Trailing

    var body: some View {
        HStack {
            Text(title).font(.dorr(15, .heavy)).foregroundStyle(wa.ink)
            Spacer()
            trailing()
        }
        .padding(.horizontal, 2)
        .padding(.top, topPadding)
        .padding(.bottom, 10)
    }
}

extension WaSectionTitle where Trailing == EmptyView {
    init(title: String, topPadding: CGFloat = 22) {
        self.init(title: title, topPadding: topPadding, trailing: { EmptyView() })
    }
}

/// The frame of every wallet page: soft brand glow at the top, a round back button, a brand-coloured
/// title, scrolling content and — when given — a pinned call-to-action over the content.
struct WaPage<Content: View, Actions: View, Cta: View>: View {
    @Environment(\.wa) private var wa
    let title: String
    let onBack: () -> Void
    var scroll = true
    /// Extra space under the scroll so a taller pinned bar does not cover the last cards.
    var ctaReserve: CGFloat = 110
    /// Solid bottom bar with a top shadow (summary + action).
    var ctaDock = false
    /// When false the title row is omitted (the screen draws its own header).
    var showHeader = true
    var hasCta = false
    @ViewBuilder var actions: () -> Actions
    @ViewBuilder var cta: () -> Cta
    @ViewBuilder var content: () -> Content

    var body: some View {
        ZStack(alignment: .bottom) {
            wa.bg.ignoresSafeArea()
            GeometryReader { proxy in
                let width = proxy.size.width
                RadialGradient(
                    colors: [wa.red.opacity(0.35), wa.red.opacity(0.10), .clear],
                    center: UnitPoint(x: 0.5, y: -0.08), startRadius: 0, endRadius: width * 0.85
                )
                .frame(width: width, height: proxy.size.height)
            }
            .ignoresSafeArea()
            .allowsHitTesting(false)

            VStack(spacing: 0) {
                if showHeader {
                    HStack(spacing: 10) {
                        Text(title).font(.dorr(22, .black)).foregroundStyle(wa.red).lineLimit(1)
                        Spacer(minLength: 0)
                        actions()
                        // Points to the edge it sits on: → in English, ← in Arabic.
                        WaOutlineCircleButton(icon: "arrow.forward", label: L("common_back"), action: onBack)
                    }
                    .padding(.horizontal, 14)
                    .padding(.top, 14)
                    .padding(.bottom, 8)
                }
                if scroll {
                    ScrollView {
                        VStack(alignment: .leading, spacing: 0, content: content)
                            .padding(.horizontal, 16)
                            .padding(.top, showHeader ? 6 : 0)
                            .padding(.bottom, hasCta ? ctaReserve : 28)
                    }
                    .scrollDismissesKeyboard(.interactively)
                } else {
                    VStack(alignment: .leading, spacing: 0, content: content)
                        .padding(.horizontal, 16)
                        .padding(.top, showHeader ? 6 : 0)
                        .padding(.bottom, hasCta ? ctaReserve : 0)
                        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
                }
            }

            if hasCta {
                VStack(spacing: 0, content: cta)
                    .padding(.horizontal, 16)
                    .padding(.top, ctaDock ? 14 : 26)
                    .padding(.bottom, 16)
                    .background {
                        if ctaDock {
                            UnevenRoundedRectangleCompat(topRadius: 22)
                                .fill(wa.night ? wa.darkCard : wa.surface)
                                .shadow(color: Color(hex: 0x111928).opacity(0.2), radius: 22, y: -2)
                                .ignoresSafeArea(edges: .bottom)
                        } else {
                            LinearGradient(colors: [wa.bg.opacity(0), wa.bg], startPoint: .top, endPoint: UnitPoint(x: 0.5, y: 0.35))
                                .ignoresSafeArea(edges: .bottom)
                        }
                    }
            }
        }
    }
}

extension WaPage where Actions == EmptyView, Cta == EmptyView {
    init(title: String, onBack: @escaping () -> Void, scroll: Bool = true, showHeader: Bool = true, @ViewBuilder content: @escaping () -> Content) {
        self.init(title: title, onBack: onBack, scroll: scroll, showHeader: showHeader, hasCta: false, actions: { EmptyView() }, cta: { EmptyView() }, content: content)
    }
}

extension WaPage where Cta == EmptyView {
    init(title: String, onBack: @escaping () -> Void, scroll: Bool = true, @ViewBuilder actions: @escaping () -> Actions, @ViewBuilder content: @escaping () -> Content) {
        self.init(title: title, onBack: onBack, scroll: scroll, hasCta: false, actions: actions, cta: { EmptyView() }, content: content)
    }
}

extension WaPage where Actions == EmptyView {
    init(title: String, onBack: @escaping () -> Void, scroll: Bool = true, ctaReserve: CGFloat = 110, ctaDock: Bool = false, @ViewBuilder cta: @escaping () -> Cta, @ViewBuilder content: @escaping () -> Content) {
        self.init(title: title, onBack: onBack, scroll: scroll, ctaReserve: ctaReserve, ctaDock: ctaDock, hasCta: true, actions: { EmptyView() }, cta: cta, content: content)
    }
}

/// Top-rounded rectangle for iOS 16 (UnevenRoundedRectangle is iOS 17+).
struct UnevenRoundedRectangleCompat: Shape {
    let topRadius: CGFloat

    func path(in rect: CGRect) -> Path {
        Path(UIBezierPath(roundedRect: rect, byRoundingCorners: [.topLeft, .topRight], cornerRadii: CGSize(width: topRadius, height: topRadius)).cgPath)
    }
}

/// Small grey caption under a CTA ("محمي بالرقم السري").
struct WaCtaHint: View {
    @Environment(\.wa) private var wa
    let text: String
    let icon: String

    var body: some View {
        HStack(spacing: 5) {
            Image(systemName: icon).font(.system(size: 12, weight: .semibold))
            Text(text).font(.dorr(11.5))
        }
        .foregroundStyle(wa.soft)
        .frame(maxWidth: .infinity)
        .padding(.top, 8)
    }
}

struct WaKeyValue: View {
    @Environment(\.wa) private var wa
    let label: String
    let value: String
    var bold = false
    var valueColor: Color?
    /// Amounts read left-to-right ("− 1.00 SAR") even inside an Arabic layout, like the preview's dir="ltr".
    var ltr = false
    /// Optional icon shown in a small brand-tinted well before the label.
    var icon: String?

    var body: some View {
        HStack(spacing: 0) {
            if let icon {
                WaIconWell(icon: icon, size: 34, iconSize: 18).padding(.trailing, 12)
            }
            Text(label).font(.dorr(13.5)).foregroundStyle(wa.mut)
            Spacer(minLength: 8)
            Text(ltr ? "\u{2066}\(value)\u{2069}" : value)
                .font(.dorr(13.5, bold ? .heavy : .bold))
                .foregroundStyle(valueColor ?? wa.ink)
                .multilineTextAlignment(.trailing)
        }
        .padding(.vertical, icon != nil ? 9 : 11)
    }
}

struct WaDivider: View {
    @Environment(\.wa) private var wa

    var body: some View {
        wa.line.frame(height: 1).frame(maxWidth: .infinity)
    }
}

/// A country's flag (the same CDN the phone fields use).
struct WalletFlag: View {
    let code: String?
    var width: CGFloat = 26
    var height: CGFloat = 19

    var body: some View {
        if let code, code.caseInsensitiveCompare("INT") == .orderedSame {
            // The freelance international wallet has no flag: a globe.
            Image(systemName: "globe").font(.system(size: min(width, height) * 0.9, weight: .semibold)).foregroundStyle(.white)
                .frame(width: width, height: height)
        } else if let code, !code.isEmpty {
            AsyncImage(url: URL(string: "https://flagcdn.com/w80/\(code.lowercased()).png")) { phase in
                if let image = phase.image { image.resizable().scaledToFill() } else { Color.gray.opacity(0.15) }
            }
            .frame(width: width, height: height)
            .clipShape(RoundedRectangle(cornerRadius: 4, style: .continuous))
        }
    }
}
