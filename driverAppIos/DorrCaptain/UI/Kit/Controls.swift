import SwiftUI

// driverApp `ui/kit/Controls.kt` — the small pieces every screen is built from: the card surface, the press-in tap, the
// staggered arrival, the buttons, the icon tile, the switch, list rows, chips and spacers.

// MARK: - Motion (Compose's tween / spring presets)

extension Animation {
    /// Compose `tween(ms)` — FastOutSlowIn easing.
    static func tween(_ ms: Int) -> Animation { .timingCurve(0.4, 0, 0.2, 1, duration: Double(ms) / 1000) }
    /// `spring(stiffness = Spring.StiffnessMedium)` (no bounce).
    static let springMedium = Animation.interpolatingSpring(mass: 1, stiffness: 1500, damping: 77.5)
    /// `spring(stiffness = Spring.StiffnessMediumLow)` (no bounce).
    static let springMediumLow = Animation.interpolatingSpring(mass: 1, stiffness: 400, damping: 40)
    /// `spring(dampingRatio = Spring.DampingRatioMediumBouncy)`.
    static let springBouncy = Animation.interpolatingSpring(mass: 1, stiffness: 1500, damping: 38.7)
}

/// A Material icon as the closest SF Symbol, drawn in a `size`×`size` box (Compose `Icon(…, Modifier.size(size))`).
struct DorrIcon: View {
    let name: String
    var size: CGFloat = 24
    var tint: Color

    init(_ name: String, size: CGFloat = 24, tint: Color) {
        self.name = name
        self.size = size
        self.tint = tint
    }

    var body: some View {
        Image(systemName: name)
            .font(.system(size: size * 0.82, weight: .semibold))
            .foregroundStyle(tint)
            .frame(width: size, height: size)
    }
}

/// Material's `CircularProgressIndicator`: a turning arc.
struct CircularProgress: View {
    var color: Color
    var size: CGFloat = 22
    var lineWidth: CGFloat = 2
    @State private var turning = false

    var body: some View {
        Circle()
            .trim(from: 0, to: 0.72)
            .stroke(color, style: StrokeStyle(lineWidth: lineWidth, lineCap: .round))
            .frame(width: size - lineWidth, height: size - lineWidth)
            .rotationEffect(.degrees(turning ? 360 : 0))
            .frame(width: size, height: size)
            .onAppear { withAnimation(.linear(duration: 1).repeatForever(autoreverses: false)) { turning = true } }
    }
}

// MARK: - surface

/// A card surface in the DORR app's way: a soft navy-tinted shadow by day, a hairline border at night
/// (a shadow doesn't show on a dark page).
struct SurfaceModifier<S: InsettableShape>: ViewModifier {
    @Environment(\.pal) private var pal
    let shape: S
    let elevation: CGFloat

    func body(content: Content) -> some View {
        if pal.night {
            content
                .background(shape.fill(pal.card))
                .clipShape(shape)
                .overlay(shape.strokeBorder(pal.line, lineWidth: 1))
        } else {
            content
                .clipShape(shape)
                .background(shape.fill(pal.card).shadow(color: Color(hex: 0x001B53, alpha: 0x12 / 255.0), radius: elevation, y: elevation / 2))
        }
    }
}

extension View {
    /// `Modifier.surface()` — rounded 20, elevation 6 by default.
    func surface(_ radius: CGFloat = 20, elevation: CGFloat = 6) -> some View {
        modifier(SurfaceModifier(shape: RoundedRectangle(cornerRadius: radius), elevation: elevation))
    }

    /// `Modifier.surface(shape, elevation)` with any shape (Circle, Capsule…).
    func surface<S: InsettableShape>(shape: S, elevation: CGFloat = 6) -> some View {
        modifier(SurfaceModifier(shape: shape, elevation: elevation))
    }
}

// MARK: - bounceClick / staggerIn

/// The press-in of `bounceClick`: 0.97 while pressed, on a medium spring.
struct BouncePressStyle: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .scaleEffect(configuration.isPressed ? 0.97 : 1)
            .animation(.springMedium, value: configuration.isPressed)
    }
}

extension View {
    /// A tap that answers with a small press-in, instead of a flat ripple.
    func bounceClick(enabled: Bool = true, onClick: @escaping () -> Void) -> some View {
        Button(action: onClick) { self.contentShape(Rectangle()) }
            .buttonStyle(BouncePressStyle())
            .disabled(!enabled)
    }
}

/// Rises and fades in the first time it is shown, one after another by `index` — lists arrive instead of popping.
struct StaggerIn: ViewModifier {
    let index: Int
    @State private var shown = false

    func body(content: Content) -> some View {
        content
            .opacity(shown ? 1 : 0)
            .offset(y: shown ? 0 : 22)
            .onAppear {
                guard !shown else { return }
                withAnimation(.tween(380).delay(Double(min(index, 8)) * 0.045)) { shown = true }
            }
    }
}

extension View {
    func staggerIn(_ index: Int = 0) -> some View { modifier(StaggerIn(index: index)) }
}

// MARK: - Buttons

/// The main action: rounded, in the brand colour, with a glow of its own colour by day. Fills the width.
struct PrimaryButton: View {
    @Environment(\.pal) private var pal
    let text: String
    var enabled = true
    var busy = false
    /// nil = `Pal.accent`.
    var color: Color? = nil
    /// An SF Symbol.
    var icon: String? = nil
    let onClick: () -> Void

    init(_ text: String, enabled: Bool = true, busy: Bool = false, color: Color? = nil, icon: String? = nil, onClick: @escaping () -> Void) {
        self.text = text
        self.enabled = enabled
        self.busy = busy
        self.color = color
        self.icon = icon
        self.onClick = onClick
    }

    var body: some View {
        let tint = color ?? pal.accent
        let glow = enabled && !busy && !pal.night
        ZStack {
            if busy {
                CircularProgress(color: .white, size: 22, lineWidth: 2)
            } else {
                HStack(spacing: 8) {
                    if let icon { DorrIcon(icon, size: 20, tint: .white) }
                    Text(text).font(.dorr(16, .bold)).foregroundStyle(.white).lineLimit(1)
                }
            }
        }
        .frame(maxWidth: .infinity)
        .frame(height: 54)
        .background(
            RoundedRectangle(cornerRadius: 18)
                .fill(enabled ? tint : tint.opacity(0.4))
                .shadow(color: glow ? tint.opacity(0.38) : .clear, radius: 10, y: 5)
                .animation(.tween(200), value: enabled)
        )
        .bounceClick(enabled: enabled && !busy, onClick: onClick)
    }
}

/// The quieter action: an outline in the brand colour. As wide as its words; `fillMaxWidth: true` stretches it
/// (Android `OutlineButton(…, Modifier.fillMaxWidth())` / `Modifier.weight(1f)`).
struct OutlineButton: View {
    @Environment(\.pal) private var pal
    let text: String
    var enabled = true
    var color: Color? = nil
    var fillMaxWidth = false
    let onClick: () -> Void

    init(_ text: String, enabled: Bool = true, color: Color? = nil, fillMaxWidth: Bool = false, onClick: @escaping () -> Void) {
        self.text = text
        self.enabled = enabled
        self.color = color
        self.fillMaxWidth = fillMaxWidth
        self.onClick = onClick
    }

    var body: some View {
        let tint = color ?? pal.accent
        Text(text)
            .font(.dorr(14, .bold))
            .foregroundStyle(enabled ? tint : tint.opacity(0.4))
            .lineLimit(1)
            .padding(.horizontal, 18)
            .frame(maxWidth: fillMaxWidth ? .infinity : nil)
            .frame(height: 46)
            .overlay(RoundedRectangle(cornerRadius: 18).strokeBorder(enabled ? tint : tint.opacity(0.4), lineWidth: 1.5))
            .bounceClick(enabled: enabled, onClick: onClick)
    }
}

/// An icon on a soft brand-coloured tile — the icon of every list row and card heading.
struct TileIcon: View {
    @Environment(\.pal) private var pal
    let icon: String
    var tint: Color? = nil
    var box: CGFloat = 40

    init(_ icon: String, tint: Color? = nil, box: CGFloat = 40) {
        self.icon = icon
        self.tint = tint
        self.box = box
    }

    var body: some View {
        let t = tint ?? pal.accent
        DorrIcon(icon, size: box * 0.5, tint: t)
            .frame(width: box, height: box)
            .background(RoundedRectangle(cornerRadius: 12).fill(t.opacity(pal.night ? 0.16 : 0.12)))
    }
}

/// The on / off switch of the DORR app's settings.
struct DorrToggle: View {
    @Environment(\.pal) private var pal
    let on: Bool
    let onChange: (Bool) -> Void

    init(on: Bool, onChange: @escaping (Bool) -> Void) {
        self.on = on
        self.onChange = onChange
    }

    var body: some View {
        let track = on ? pal.accent : (pal.night ? Color(hex: 0x3A3F48) : Color(hex: 0xE5E7EB))
        ZStack(alignment: .leading) {
            RoundedRectangle(cornerRadius: 12).fill(track).animation(.tween(200), value: on)
            Circle()
                .fill(Color.white)
                .shadow(color: .black.opacity(0.2), radius: 1, y: 0.5)
                .frame(width: 20, height: 20)
                .padding(2)
                .offset(x: on ? 18 : 0)
                .animation(.springMediumLow, value: on)
        }
        .frame(width: 42, height: 24)
        .contentShape(Rectangle())
        .onTapGesture { onChange(!on) }
        // The thumb slides the same way in Arabic and English.
        .environment(\.layoutDirection, .leftToRight)
    }
}

// MARK: - Rows

/// One row of a list on a card: icon tile, title, a line under it, and a chevron — or `trailing` instead.
struct ListRow<Trailing: View>: View {
    @Environment(\.pal) private var pal
    let icon: String
    let title: String
    var subtitle: String? = nil
    var tint: Color? = nil
    var badge: String? = nil
    var trailing: Trailing?
    var onClick: (() -> Void)? = nil

    init(icon: String, title: String, subtitle: String? = nil, tint: Color? = nil, badge: String? = nil,
         onClick: (() -> Void)? = nil, @ViewBuilder trailing: () -> Trailing) {
        self.icon = icon
        self.title = title
        self.subtitle = subtitle
        self.tint = tint
        self.badge = badge
        self.trailing = trailing()
        self.onClick = onClick
    }

    var body: some View {
        let row = HStack(spacing: 12) {
            TileIcon(icon, tint: tint ?? pal.accent)
            VStack(alignment: .leading, spacing: 0) {
                Text(title).font(.dorr(14, .bold)).foregroundStyle(pal.ink).lineLimit(1)
                if let subtitle {
                    Text(subtitle).font(.dorr(12)).foregroundStyle(pal.mut).padding(.top, 2)
                        .fixedSize(horizontal: false, vertical: true)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if let badge {
                Text(badge).font(.dorr(11, .bold)).foregroundStyle(.white)
                    .padding(.horizontal, 7).padding(.vertical, 2)
                    .background(Capsule().fill(pal.danger))
            }
            if let trailing {
                trailing
            } else if onClick != nil {
                DorrIcon("chevron.forward", size: 22, tint: pal.faint)
            }
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
        .frame(maxWidth: .infinity)
        if let onClick {
            row.bounceClick(onClick: onClick)
        } else {
            row
        }
    }
}

extension ListRow where Trailing == EmptyView {
    init(icon: String, title: String, subtitle: String? = nil, tint: Color? = nil, badge: String? = nil, onClick: (() -> Void)? = nil) {
        self.icon = icon
        self.title = title
        self.subtitle = subtitle
        self.tint = tint
        self.badge = badge
        self.trailing = nil
        self.onClick = onClick
    }
}

/// A thin rule between rows.
struct RowDivider: View {
    @Environment(\.pal) private var pal

    var body: some View {
        Rectangle().fill(pal.line.opacity(pal.night ? 1 : 0.6)).frame(height: 1)
            .padding(.leading, 66)
    }
}

/// A small caption above a group of rows.
struct SectionLabel: View {
    @Environment(\.pal) private var pal
    let text: String

    init(_ text: String) { self.text = text }

    var body: some View {
        Text(text).font(.dorr(12, .bold)).foregroundStyle(pal.mut)
            .padding(.horizontal, 6)
            .padding(.top, 6)
    }
}

/// A rounded chip (a filter, a duration, a status).
struct Chip: View {
    @Environment(\.pal) private var pal
    let text: String
    var selected = false
    var tint: Color? = nil
    var onClick: (() -> Void)? = nil

    init(_ text: String, selected: Bool = false, tint: Color? = nil, onClick: (() -> Void)? = nil) {
        self.text = text
        self.selected = selected
        self.tint = tint
        self.onClick = onClick
    }

    var body: some View {
        let t = tint ?? pal.accent
        let chip = Text(text)
            .font(.dorr(13, .semibold))
            .foregroundStyle(selected ? Color.white : t)
            .padding(.horizontal, 16)
            .padding(.vertical, 8)
            .background(Capsule().fill(selected ? t : t.opacity(pal.night ? 0.16 : 0.08)))
            .animation(.tween(200), value: selected)
        if let onClick {
            chip.bounceClick(onClick: onClick)
        } else {
            chip
        }
    }
}

/// `Spacer(Modifier.width(…))`.
struct HSpace: View {
    let width: CGFloat
    init(_ width: CGFloat) { self.width = width }
    var body: some View { Color.clear.frame(width: width, height: 0) }
}

/// `Spacer(Modifier.height(…))`.
struct VSpace: View {
    let height: CGFloat
    init(_ height: CGFloat) { self.height = height }
    var body: some View { Color.clear.frame(width: 0, height: height) }
}

/// The same card the whole app uses, usable where a `Column` scope isn't wanted.
struct SurfaceColumn<Content: View>: View {
    @ViewBuilder let content: () -> Content

    init(@ViewBuilder content: @escaping () -> Content) { self.content = content }

    var body: some View {
        VStack(alignment: .leading, spacing: 0) { content() }
            .frame(maxWidth: .infinity, alignment: .leading)
            .surface()
    }
}
