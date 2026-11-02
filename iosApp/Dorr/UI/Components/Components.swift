import SwiftUI
import UIKit

// The design pieces every settings-style screen shares — androidApp `ui/components/*` and the helpers of
// `ui/screens/profile/NotificationSettingsScreen.kt` (settingsAccent, AccountDark, PinkBackdrop, SubHeader…).
// Colours come from [WaPalette] (`\.wa`), which is the same palette: settingsAccent = `wa.red`,
// AccountDark.* = `wa.darkCard` / `wa.darkWell` / …

extension WaPalette {
    /// `settingsMut()` — textMuted in light, AccountDark.mut at night.
    var settingsMut: Color { night ? mut : soft }
    /// `settingsCard()` — the card fill behind a settings row.
    var settingsCard: Color { night ? darkCard : surface }
    /// `AccountDark.accent` at night, `settingsAccent()` in light (the same colour on both sides today).
    var accent: Color { night ? darkAccent : red }
    /// `settingsInk()` — textPrimary in light, AccountDark.ink at night.
    var settingsInk: Color { night ? ink : AppColors.textPrimary }
}

/// androidApp `profile/PinkBackdrop` — the page base with two soft brand glows at the top.
struct PinkBackdrop: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState

    var body: some View {
        GeometryReader { proxy in
            let size = proxy.size
            let base = wa.night ? wa.bg : appearance.color("background", .white, dark: false)
            let glow = wa.night ? wa.darkAccent : wa.red
            ZStack {
                base
                RadialGradient(colors: [glow.opacity(0.35), glow.opacity(0.10), .clear],
                               center: UnitPoint(x: 0.5, y: -0.08 * size.height / max(size.height, 1)),
                               startRadius: 0, endRadius: size.width * 0.85)
                    .frame(width: size.width, height: size.height)
                    .offset(y: 0)
                RadialGradient(colors: [glow.opacity(0.20), .clear],
                               center: UnitPoint(x: 1.05, y: 0.02),
                               startRadius: 0, endRadius: size.width * 0.55)
            }
            // The glows are drawn in absolute positions (Canvas on Android), never mirrored.
            .environment(\.layoutDirection, .leftToRight)
        }
        .ignoresSafeArea()
        .allowsHitTesting(false)
    }
}

/// `Modifier.settingsSurface(shape, elevation)` — the card of a settings row.
struct SettingsSurface: ViewModifier {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    let radius: CGFloat
    var elevation: CGFloat = 6

    func body(content: Content) -> some View {
        content
            .background(wa.night ? wa.darkCard : appearance.color("surface", .white, dark: false),
                        in: RoundedRectangle(cornerRadius: radius, style: .continuous))
            .overlay { if wa.night { RoundedRectangle(cornerRadius: radius, style: .continuous).stroke(wa.line, lineWidth: 1) } }
            .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.07), radius: elevation, y: elevation / 3)
    }
}

extension View {
    func settingsSurface(_ radius: CGFloat, elevation: CGFloat = 6) -> some View {
        modifier(SettingsSurface(radius: radius, elevation: elevation))
    }
}

/// `SettingsScreenTitle` — the big brand-coloured title of a settings screen.
struct SettingsScreenTitle: View {
    @Environment(\.wa) private var wa
    let title: String

    var body: some View {
        Text(title).font(.dorr(22, .black)).foregroundStyle(wa.accent).lineLimit(2)
    }
}

/// `SubHeader` — the title at the start and a small round back button at the end.
struct SubHeader: View {
    @Environment(\.wa) private var wa
    let title: String
    let onBack: () -> Void
    /// Beside the back button (an action of the page).
    var trailing: AnyView?

    var body: some View {
        HStack(spacing: 0) {
            SettingsScreenTitle(title: title)
            Spacer(minLength: 8)
            if let trailing { trailing.padding(.trailing, 6) }
            Button(action: onBack) {
                // ArrowBack mirrored (scaleX = -1): it points to the edge it sits on.
                Image(systemName: "arrow.forward")
                    .font(.system(size: 13, weight: .bold))
                    .foregroundStyle(wa.red)
                    .frame(width: 34, height: 34)
                    .background(wa.settingsCard, in: Circle())
                    .overlay { if wa.night { Circle().stroke(wa.line, lineWidth: 1) } }
                    .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.08), radius: 6, y: 2)
            }
            .buttonStyle(.plain)
            .accessibilityLabel(L("common_back"))
        }
        .padding(.horizontal, 14)
        .padding(.top, 14)
        .padding(.bottom, 12)
    }
}

/// `PinkIcon` — a brand-tinted rounded well with an icon.
struct PinkIcon: View {
    @Environment(\.wa) private var wa
    let icon: String
    var box: CGFloat = 34

    var body: some View {
        Image(systemName: icon)
            .font(.system(size: 15, weight: .semibold))
            .foregroundStyle(wa.red)
            .frame(width: box, height: box)
            .background(wa.night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 10, style: .continuous))
    }
}

/// androidApp `components/DotIndicator` — the active dot is a ring with a filled centre; others small grey.
struct DotIndicator: View {
    @Environment(\.wa) private var wa
    let count: Int
    let activeIndex: Int

    var body: some View {
        HStack(spacing: 0) {
            ForEach(0..<count, id: \.self) { index in
                Group {
                    if index == activeIndex {
                        Circle().stroke(wa.red, lineWidth: 1.6).frame(width: 12, height: 12)
                            .overlay(Circle().fill(wa.red).frame(width: 6, height: 6))
                    } else {
                        Circle().fill(Color(hex: 0xD1D5DB)).frame(width: 6, height: 6)
                    }
                }
                .padding(.horizontal, 5)
            }
        }
        .animation(.easeOut(duration: 0.2), value: activeIndex)
    }
}

/// A country flag from flagcdn (20×15, 2pt corners), nothing for a code that isn't two letters.
struct FlagImage: View {
    let code: String?

    var body: some View {
        let iso = (code ?? "").trimmingCharacters(in: .whitespaces).lowercased()
        if iso.count == 2 {
            AsyncImage(url: URL(string: "https://flagcdn.com/w40/\(iso).png")) { phase in
                if let image = phase.image { image.resizable().scaledToFill() } else { Color.gray.opacity(0.12) }
            }
            .frame(width: 20, height: 15)
            .clipShape(RoundedRectangle(cornerRadius: 2))
        }
    }
}

/// androidApp `components/DorrLogo` — the bundled logo at a given width, the light or dark version.
struct DorrLogo: View {
    let width: CGFloat
    var onDark = false

    var body: some View {
        Image(onDark ? "DorrLogoDark" : "DorrLogoLight")
            .resizable()
            .scaledToFit()
            .frame(width: width)
            .accessibilityHidden(true)
    }
}

/// Admin-authored HTML (p, h3, ul, strong, links…) as formatted text (`HtmlContent` / `HtmlText` on Android).
struct HtmlContent: UIViewRepresentable {
    let html: String
    let textColor: Color
    var accentColor: Color
    var fontSize: CGFloat = 13.5
    var lineSpacing: CGFloat = 1.45

    func makeUIView(context: Context) -> UITextView {
        let view = UITextView()
        view.isEditable = false
        view.isScrollEnabled = false
        view.backgroundColor = .clear
        view.textContainerInset = .zero
        view.textContainer.lineFragmentPadding = 0
        view.setContentCompressionResistancePriority(.defaultLow, for: .horizontal)
        return view
    }

    func updateUIView(_ view: UITextView, context: Context) {
        view.attributedText = attributed(rtl: context.environment.layoutDirection == .rightToLeft)
        view.linkTextAttributes = [.foregroundColor: UIColor(accentColor)]
    }

    func sizeThatFits(_ proposal: ProposedViewSize, uiView: UITextView, context: Context) -> CGSize? {
        let width = proposal.width ?? UIScreen.main.bounds.width
        let size = uiView.sizeThatFits(CGSize(width: width, height: .greatestFiniteMagnitude))
        return CGSize(width: width, height: size.height)
    }

    private func attributed(rtl: Bool) -> NSAttributedString {
        let font = DorrFont.uiFont(size: fontSize, weight: .regular)
        guard let data = html.data(using: .utf8),
              let parsed = try? NSMutableAttributedString(data: data, options: [
                  .documentType: NSAttributedString.DocumentType.html, .characterEncoding: String.Encoding.utf8.rawValue,
              ], documentAttributes: nil) else {
            return NSAttributedString(string: html, attributes: [.font: font, .foregroundColor: UIColor(textColor)])
        }
        let whole = NSRange(location: 0, length: parsed.length)
        // Keep the HTML's bold / italic, but in the app font, the screen's colour and a roomy line height.
        parsed.enumerateAttribute(.font, in: whole) { value, range, _ in
            let traits = (value as? UIFont)?.fontDescriptor.symbolicTraits ?? []
            let weight: Font.Weight = traits.contains(.traitBold) ? .bold : .regular
            let scale = ((value as? UIFont)?.pointSize ?? 12) / 12
            parsed.addAttribute(.font, value: DorrFont.uiFont(size: fontSize * max(scale, 1), weight: weight), range: range)
        }
        parsed.addAttribute(.foregroundColor, value: UIColor(textColor), range: whole)
        let paragraph = NSMutableParagraphStyle()
        paragraph.lineHeightMultiple = lineSpacing
        paragraph.alignment = .natural
        paragraph.baseWritingDirection = rtl ? .rightToLeft : .leftToRight
        parsed.addAttribute(.paragraphStyle, value: paragraph, range: whole)
        while parsed.string.hasSuffix("\n") { parsed.deleteCharacters(in: NSRange(location: parsed.length - 1, length: 1)) }
        return parsed
    }
}

/// Network image through [ApiClient.mediaUrl] (Coil's `AsyncImage` on Android).
struct RemoteImage: View {
    let url: String?
    var placeholder = "square.grid.2x2"

    var body: some View {
        AsyncImage(url: ApiClient.mediaUrl(url)) { phase in
            if let image = phase.image {
                image.resizable().scaledToFill()
            } else {
                Image(systemName: placeholder).foregroundStyle(.secondary)
            }
        }
    }
}
