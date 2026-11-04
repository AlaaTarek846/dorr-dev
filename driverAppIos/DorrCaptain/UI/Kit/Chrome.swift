import SwiftUI
import UIKit

// driverApp `ui/kit/Chrome.kt` — the frame of the screens: the glowing page, the logo, the header with its round back
// button, the sliding message box, the bottom bar and the hero badge. Plus Android's `Dialog` / `DropdownMenu` hosts
// (`dorrDialog`, `dorrDropdown`) the kit and the screens open over everything.

/// The page behind every screen: the page colour with the brand colour glowing in from the top — as in the DORR app.
struct PinkBackdrop: View {
    @Environment(\.pal) private var pal

    var body: some View {
        let glow = pal.accent
        let base = pal.bg
        Canvas { ctx, size in
            ctx.fill(Path(CGRect(origin: .zero, size: size)), with: .color(base))
            func glowCircle(_ center: CGPoint, _ radius: CGFloat, _ colors: [Color]) {
                let rect = CGRect(x: center.x - radius, y: center.y - radius, width: radius * 2, height: radius * 2)
                ctx.fill(Path(ellipseIn: rect), with: .radialGradient(Gradient(colors: colors), center: center, startRadius: 0, endRadius: radius))
            }
            glowCircle(CGPoint(x: size.width * 0.5, y: size.height * -0.08), size.width * 0.85,
                       [glow.opacity(0.35), glow.opacity(0.10), .clear])
            glowCircle(CGPoint(x: size.width * 1.05, y: size.height * 0.02), size.width * 0.55,
                       [glow.opacity(0.20), .clear])
        }
        // Drawn in absolute positions (a Canvas on Android), never mirrored.
        .environment(\.layoutDirection, .leftToRight)
        .ignoresSafeArea()
        .allowsHitTesting(false)
    }
}

/// The DORR wordmark: the navy one on light pages, the white one at night.
struct DorrLogo: View {
    @Environment(\.pal) private var pal
    let width: CGFloat

    init(width: CGFloat) { self.width = width }

    var body: some View {
        Image(pal.night ? "DorrLogoDark" : "DorrLogoLight")
            .resizable()
            .scaledToFit()
            .frame(width: width)
            .accessibilityHidden(true)
    }
}

/// The header of every inner screen, as in the DORR app: the title in the brand colour and a round back
/// button. `actions` sit between them. Sits under the status bar (the safe area does Android's `statusBarsPadding`).
struct ScreenHeader<Actions: View>: View {
    @Environment(\.pal) private var pal
    let title: String
    let onBack: (() -> Void)?
    let actions: Actions

    init(_ title: String, onBack: (() -> Void)?, @ViewBuilder actions: () -> Actions) {
        self.title = title
        self.onBack = onBack
        self.actions = actions()
    }

    var body: some View {
        HStack(spacing: 10) {
            Text(title)
                .font(.dorr(22, .black))
                .foregroundStyle(pal.accent)
                .lineLimit(2)
                .frame(maxWidth: .infinity, alignment: .leading)
            actions
            if let onBack { RoundIconButton("arrow.backward", L("back"), onClick: onBack) }
        }
        .padding(.horizontal, 14)
        .padding(.top, 14)
        .padding(.bottom, 12)
    }
}

extension ScreenHeader where Actions == EmptyView {
    init(_ title: String, onBack: (() -> Void)?) {
        self.init(title, onBack: onBack) { EmptyView() }
    }
}

/// A round white (or night-card) button with an icon in the brand colour.
struct RoundIconButton: View {
    @Environment(\.pal) private var pal
    let icon: String
    let description: String?
    var tint: Color? = nil
    var badge = false
    let onClick: () -> Void

    init(_ icon: String, _ description: String?, tint: Color? = nil, badge: Bool = false, onClick: @escaping () -> Void) {
        self.icon = icon
        self.description = description
        self.tint = tint
        self.badge = badge
        self.onClick = onClick
    }

    var body: some View {
        DorrIcon(icon, size: 18, tint: tint ?? pal.accent)
            .frame(width: 36, height: 36)
            .background(
                Circle().fill(pal.card)
                    .shadow(color: pal.night ? .clear : Color(hex: 0x001B53, alpha: 0x14 / 255.0), radius: 6, y: 3)
            )
            .overlay { if pal.night { Circle().strokeBorder(pal.line, lineWidth: 1) } }
            .overlay(alignment: .topTrailing) {
                if badge { Circle().fill(pal.danger).frame(width: 8, height: 8).padding(7) }
            }
            .bounceClick(onClick: onClick)
            .accessibilityLabel(description ?? "")
    }
}

enum BannerKind { case error, info, success, warning }

/// A message in a tinted box, sliding in and out — instead of a bare red line of text.
struct Banner: View {
    @Environment(\.pal) private var pal
    let message: String?
    var kind: BannerKind = .error

    init(_ message: String?, kind: BannerKind = .error) {
        self.message = message
        self.kind = kind
    }

    var body: some View {
        VStack(spacing: 0) {
            if let message {
                row(message).transition(.asymmetric(
                    insertion: .opacity.animation(.tween(200)).combined(with: .move(edge: .top)),
                    removal: .opacity.animation(.tween(160)).combined(with: .move(edge: .top))
                ))
            }
        }
        .frame(maxWidth: .infinity)
        .clipped()
        .animation(.tween(message == nil ? 200 : 240), value: message)
    }

    private func row(_ text: String) -> some View {
        let tint: Color = {
            switch kind {
            case .error: return pal.danger
            case .info: return pal.accent
            case .success: return pal.success
            case .warning: return pal.warning
            }
        }()
        let icon: String = {
            switch kind {
            case .error: return "exclamationmark.circle"
            case .info: return "info.circle.fill"
            case .success: return "checkmark.circle.fill"
            case .warning: return "exclamationmark.triangle"
            }
        }()
        let shape = RoundedRectangle(cornerRadius: 14)
        return HStack(spacing: 10) {
            DorrIcon(icon, size: 20, tint: tint)
            Text(text)
                .font(.dorr(13, .medium))
                .foregroundStyle(pal.night ? pal.ink : tint)
                .frame(maxWidth: .infinity, alignment: .leading)
                .fixedSize(horizontal: false, vertical: true)
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
        .frame(maxWidth: .infinity)
        .background(shape.fill(tint.opacity(pal.night ? 0.16 : 0.10)))
        .overlay(shape.strokeBorder(tint.opacity(pal.night ? 0.4 : 0.28), lineWidth: 1))
    }
}

/// One tab of the bottom bar. `label` is a string key; the icons are SF Symbols.
struct NavTab {
    let route: String
    let label: String
    let outlined: String
    let filled: String
    var badge: Int = 0

    init(route: String, label: String, outlined: String, filled: String, badge: Int = 0) {
        self.route = route
        self.label = label
        self.outlined = outlined
        self.filled = filled
        self.badge = badge
    }
}

private let InactiveTab = Color(hex: 0x8E9BAE)

/// The bottom bar of the DORR app: a white bar with a hairline on top, outlined icons that fill and rise
/// when chosen, a soft pill behind the chosen one, and the label in the brand colour. The bar's colour runs
/// under the home indicator (Android's `windowInsetsBottomHeight(navigationBars)`).
struct DriverBottomBar: View {
    @Environment(\.pal) private var pal
    let tabs: [NavTab]
    let selected: String
    let onSelect: (String) -> Void

    init(_ tabs: [NavTab], selected: String, onSelect: @escaping (String) -> Void) {
        self.tabs = tabs
        self.selected = selected
        self.onSelect = onSelect
    }

    var body: some View {
        VStack(spacing: 0) {
            Rectangle().fill(pal.line).frame(height: 1)
            HStack(spacing: 0) {
                ForEach(tabs, id: \.route) { tab in
                    tabView(tab)
                }
            }
            .frame(height: 64)
        }
        .background(
            pal.card
                .shadow(color: .black.opacity(0.10), radius: 8, y: -2)
                .ignoresSafeArea(edges: .bottom)
        )
    }

    private func tabView(_ tab: NavTab) -> some View {
        let active = tab.route == selected
        let tint = active ? pal.accent : InactiveTab
        return VStack(spacing: 0) {
            ZStack {
                Capsule()
                    .fill(pal.accent.opacity(pal.night ? 0.2 : 0.12))
                    .frame(width: 52, height: 28)
                    .scaleEffect(x: active ? 1 : 0.5, y: 1)
                    .opacity(active ? 1 : 0)
                    .animation(.tween(240), value: active)
                DorrIcon(active ? tab.filled : tab.outlined, size: 22, tint: tint)
                    .animation(.tween(200), value: active)
                if tab.badge > 0 {
                    Text(tab.badge > 9 ? "9+" : "\(tab.badge)")
                        .font(.system(size: 9, weight: .bold))
                        .foregroundStyle(.white)
                        .padding(.horizontal, 5)
                        .padding(.vertical, 1)
                        .background(Capsule().fill(pal.danger))
                        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topTrailing)
                }
            }
            .frame(width: 52, height: 28)
            .offset(y: active ? -2 : 0)
            .animation(.springMedium, value: active)
            .scaleEffect(active ? 1.08 : 1)
            .animation(.springBouncy, value: active)
            Text(L(tab.label))
                .font(.dorr(11, active ? .bold : .medium))
                .foregroundStyle(tint)
                .lineLimit(1)
                .animation(.tween(200), value: active)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .bounceClick { onSelect(tab.route) }
    }
}

/// A small soft circle behind a decorative icon (empty states, hero spots).
struct HeroBadge: View {
    @Environment(\.pal) private var pal
    let icon: String
    var tint: Color? = nil
    var size: CGFloat = 72
    var scale: CGFloat = 1

    init(_ icon: String, tint: Color? = nil, size: CGFloat = 72, scale: CGFloat = 1) {
        self.icon = icon
        self.tint = tint
        self.size = size
        self.scale = scale
    }

    var body: some View {
        let t = tint ?? pal.accent
        DorrIcon(icon, size: size * 0.48, tint: t)
            .frame(width: size, height: size)
            .background(Circle().fill(t.opacity(pal.night ? 0.18 : 0.12)))
            .scaleEffect(scale)
    }
}

/// The frame of an inner screen: the glowing page, the header with its back button, then the content under it
/// (a column, top-leading, no spacing — Compose's `ColumnScope`).
struct ScreenScaffold<Content: View, Actions: View>: View {
    let title: String
    let onBack: (() -> Void)?
    let actions: Actions
    let content: Content

    init(_ title: String, onBack: (() -> Void)?, @ViewBuilder actions: () -> Actions, @ViewBuilder content: () -> Content) {
        self.title = title
        self.onBack = onBack
        self.actions = actions()
        self.content = content()
    }

    var body: some View {
        ZStack {
            PinkBackdrop()
            VStack(alignment: .leading, spacing: 0) {
                ScreenHeader(title, onBack: onBack) { actions }
                content
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
        }
    }
}

extension ScreenScaffold where Actions == EmptyView {
    init(_ title: String, onBack: (() -> Void)?, @ViewBuilder content: () -> Content) {
        self.init(title, onBack: onBack, actions: { EmptyView() }, content: content)
    }
}

// MARK: - Dialog / dropdown hosts

extension View {
    /// Android's `Dialog` / `AlertDialog`: over everything (the tab bar too), a dimmed scrim that closes it when
    /// tapped (`dismissOnClickOutside`). `bottom`: a panel rising from the bottom edge instead of a centred card.
    func dorrDialog<Content: View>(isPresented: Binding<Bool>, bottom: Bool = false, dismissible: Bool = true,
                                   @ViewBuilder content: @escaping () -> Content) -> some View {
        modifier(DialogHost(isPresented: isPresented, bottom: bottom, dismissible: dismissible, dialog: content))
    }

    /// Android's `DropdownMenu`: a card under `anchor` (a frame in the `.global` space — read it with
    /// `.background(GeometryReader { … .frame(in: .global) })`), closed by a tap anywhere else.
    func dorrDropdown<Content: View>(isPresented: Binding<Bool>, anchor: CGRect, minWidth: CGFloat = 112, maxHeight: CGFloat? = nil,
                                     @ViewBuilder content: @escaping () -> Content) -> some View {
        modifier(DropdownHost(isPresented: isPresented, anchor: anchor, minWidth: minWidth, maxHeight: maxHeight, menu: content))
    }
}

private struct DialogHost<Dialog: View>: ViewModifier {
    @Environment(\.pal) private var pal
    @Environment(\.layoutDirection) private var direction
    @Binding var isPresented: Bool
    let bottom: Bool
    let dismissible: Bool
    let dialog: () -> Dialog

    func body(content: Content) -> some View {
        content
            .fullScreenCover(isPresented: $isPresented) {
                DialogScrim(onDismiss: { if dismissible { isPresented = false } }, bottom: bottom, content: dialog)
                    .environment(\.pal, pal)
                    .environment(\.layoutDirection, direction)
            }
            .transaction { $0.disablesAnimations = true }
    }
}

private struct DialogScrim<Content: View>: View {
    let onDismiss: () -> Void
    let bottom: Bool
    @ViewBuilder let content: () -> Content
    @State private var shown = false

    var body: some View {
        ZStack(alignment: bottom ? .bottom : .center) {
            Color.black.opacity(shown ? 0.45 : 0).ignoresSafeArea().onTapGesture(perform: onDismiss)
            if bottom {
                content().offset(y: shown ? 0 : 800)
            } else {
                content().opacity(shown ? 1 : 0).scaleEffect(shown ? 1 : 0.94)
            }
        }
        .background(ClearPresentationBackground())
        .onAppear { withAnimation(bottom ? .tween(300) : .easeOut(duration: 0.2)) { shown = true } }
    }
}

private struct DropdownHost<Menu: View>: ViewModifier {
    @Environment(\.pal) private var pal
    @Environment(\.layoutDirection) private var direction
    @Binding var isPresented: Bool
    let anchor: CGRect
    let minWidth: CGFloat
    let maxHeight: CGFloat?
    let menu: () -> Menu

    func body(content: Content) -> some View {
        content
            .fullScreenCover(isPresented: $isPresented) {
                DropdownLayer(anchor: anchor, minWidth: minWidth, maxHeight: maxHeight, direction: direction,
                              onDismiss: { isPresented = false }, menu: menu)
                    .environment(\.pal, pal)
            }
            .transaction { $0.disablesAnimations = true }
    }
}

private struct DropdownLayer<Menu: View>: View {
    @Environment(\.pal) private var pal
    let anchor: CGRect
    let minWidth: CGFloat
    let maxHeight: CGFloat?
    let direction: LayoutDirection
    let onDismiss: () -> Void
    @ViewBuilder let menu: () -> Menu
    @State private var shown = false
    @State private var size: CGSize = .zero

    var body: some View {
        GeometryReader { proxy in
            let screen = proxy.size
            let width = max(size.width, minWidth)
            // Starts under the anchor (at its start edge in the reading direction), kept on the screen.
            let rawX = direction == .rightToLeft ? anchor.maxX - width : anchor.minX
            let x = min(max(8, rawX), screen.width - width - 8)
            let below = anchor.maxY + 4
            let y = below + size.height > screen.height - 8 ? max(8, anchor.minY - 4 - size.height) : below
            ZStack(alignment: .topLeading) {
                Color.black.opacity(0.001).onTapGesture(perform: onDismiss)
                VStack(alignment: .leading, spacing: 0) { menu() }
                    .padding(.vertical, 8)
                    .frame(minWidth: minWidth, alignment: .leading)
                    .frame(maxHeight: maxHeight)
                    .fixedSize(horizontal: true, vertical: maxHeight == nil)
                    .background(RoundedRectangle(cornerRadius: 16).fill(pal.card)
                        .shadow(color: .black.opacity(0.14), radius: 6, y: 2))
                    .clipShape(RoundedRectangle(cornerRadius: 16))
                    .environment(\.layoutDirection, direction)
                    .background(GeometryReader { g in Color.clear.onAppear { size = g.size }.onChange(of: g.size) { size = $0 } })
                    .scaleEffect(shown ? 1 : 0.8, anchor: .top)
                    .opacity(shown ? 1 : 0)
                    .offset(x: x, y: y)
            }
        }
        .ignoresSafeArea()
        .environment(\.layoutDirection, .leftToRight)
        .background(ClearPresentationBackground())
        .onAppear { withAnimation(.tween(150)) { shown = true } }
    }
}

/// One row of a `dorrDropdown` — Material's `DropdownMenuItem` (48 tall, 12 at the sides).
struct DropdownItem<Label: View>: View {
    var selected = false
    let onClick: () -> Void
    @ViewBuilder let label: () -> Label
    @Environment(\.pal) private var pal

    init(selected: Bool = false, onClick: @escaping () -> Void, @ViewBuilder label: @escaping () -> Label) {
        self.selected = selected
        self.onClick = onClick
        self.label = label
    }

    var body: some View {
        Button(action: onClick) {
            label()
                .padding(.horizontal, 12)
                .frame(maxWidth: .infinity, minHeight: 48, alignment: .leading)
                .background(selected ? pal.well : .clear)
                .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

/// A full-screen cover's own background made clear (iOS 16.0 has no `presentationBackground`).
struct ClearPresentationBackground: UIViewRepresentable {
    func makeUIView(context: Context) -> UIView {
        let view = UIView()
        DispatchQueue.main.async { view.superview?.superview?.backgroundColor = .clear }
        return view
    }

    func updateUIView(_ view: UIView, context: Context) {}
}
