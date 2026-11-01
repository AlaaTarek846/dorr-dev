import SwiftUI

// androidApp `aichat/AiSiteUi.kt` — the shared look of DORR AI's studio pages (websites, World, AR): the palette,
// cards, section titles, the top bar, the two buttons, the outlined field, the status chip and the letter avatar —
// plus the wallet PIN dialog (androidApp `AiWalletPinSheet`) and the info dialog the paid steps use.

struct SitePalette {
    let night: Bool
    var bg: Color { night ? Color(hex: 0x0B1220) : Color(hex: 0xF7F4F2) }
    var surface: Color { night ? Color(hex: 0x172033) : .white }
    var ink: Color { night ? Color(hex: 0xF3F4F6) : Color(hex: 0x111928) }
    var mut: Color { night ? Color(hex: 0x9CA3AF) : Color(hex: 0x6B7280) }
    var line: Color { night ? Color(hex: 0x273244) : Color(hex: 0xEEF0F3) }
    var field: Color { night ? Color(hex: 0x111A2B) : Color(hex: 0xF9FAFB) }
    var fieldBorder: Color { night ? Color(hex: 0x334155) : Color(hex: 0xE2E8F0) }
    let success = Color(hex: 0x16A34A)
    let warn = Color(hex: 0xF59E0B)
    let danger = Color(hex: 0xDC2626)
}

extension WaPalette {
    var site: SitePalette { SitePalette(night: night) }
}

/// The accent as a soft diagonal gradient (hero cards, primary buttons).
func siteGradient(_ accent: Color) -> LinearGradient {
    LinearGradient(colors: [accent, accent.scaled(0.62)], startPoint: .topLeading, endPoint: .bottomTrailing)
}

struct SiteCard<Content: View>: View {
    @Environment(\.wa) private var wa
    var padding: CGFloat = 16
    var onClick: (() -> Void)?
    @ViewBuilder let content: () -> Content

    var body: some View {
        let c = wa.site
        let card = VStack(alignment: .leading, spacing: 0) { content() }
            .padding(padding)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(c.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).stroke(c.line, lineWidth: 1))
        if let onClick {
            Button(action: onClick) { card.contentShape(Rectangle()) }.buttonStyle(AiPressStyle())
        } else {
            card
        }
    }
}

struct SiteSectionTitle: View {
    @Environment(\.wa) private var wa
    let title: String
    var subtitle: String?

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(verbatim: title).font(.dorr(15, .heavy)).foregroundStyle(wa.site.ink)
            if let subtitle, !subtitle.isEmpty { Text(verbatim: subtitle).font(.dorr(12)).foregroundStyle(wa.site.mut) }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

struct SiteTopBar: View {
    @Environment(\.wa) private var wa
    let title: String
    let onBack: () -> Void

    var body: some View {
        let c = wa.site
        HStack(spacing: 12) {
            Button(action: onBack) {
                Image(systemName: "chevron.left").font(.system(size: 15, weight: .semibold)).foregroundStyle(c.ink).flipsForRightToLeftLayoutDirection(true)
                    .frame(width: 40, height: 40).background(c.surface, in: Circle()).overlay(Circle().stroke(c.line, lineWidth: 1))
            }
            .buttonStyle(.plain)
            Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(c.ink).lineLimit(1)
            Spacer()
        }
        .padding(.horizontal, 16).padding(.vertical, 10)
    }
}

struct SitePrimaryButton: View {
    @Environment(\.wa) private var wa
    let label: String
    var enabled = true
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: label).font(.dorr(15, .bold)).foregroundStyle(.white)
                .frame(maxWidth: .infinity, minHeight: 52)
                .background {
                    if enabled { RoundedRectangle(cornerRadius: 16, style: .continuous).fill(siteGradient(wa.accent)) } else {
                        RoundedRectangle(cornerRadius: 16, style: .continuous).fill(wa.accent.opacity(0.35))
                    }
                }
        }
        .buttonStyle(AiPressStyle())
        .disabled(!enabled)
    }
}

struct SiteSecondaryButton: View {
    @Environment(\.wa) private var wa
    let label: String
    var color: Color?
    var enabled = true
    let action: () -> Void

    var body: some View {
        let tint = color ?? wa.accent
        Button(action: action) {
            Text(verbatim: label).font(.dorr(14, .bold)).foregroundStyle(tint)
                .padding(.horizontal, 18)
                .frame(maxWidth: .infinity, minHeight: 52)
                .background(tint.opacity(0.1), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).stroke(tint.opacity(0.35), lineWidth: 1))
                .opacity(enabled ? 1 : 0.5)
        }
        .buttonStyle(AiPressStyle())
        .disabled(!enabled)
    }
}

/// The outlined field with its label above the text and an optional helper line.
struct SiteField: View {
    @Environment(\.wa) private var wa
    let label: String
    @Binding var text: String
    var lines: ClosedRange<Int> = 1...1
    var icon: String?
    var keyboard: UIKeyboardType = .default
    var helper: String?
    @FocusState private var focused: Bool

    var body: some View {
        let c = wa.site
        VStack(alignment: .leading, spacing: 4) {
            HStack(alignment: .top, spacing: 8) {
                if let icon { Image(systemName: icon).font(.system(size: 16)).foregroundStyle(c.mut).padding(.top, 14) }
                VStack(alignment: .leading, spacing: 2) {
                    Text(verbatim: label).font(.dorr(12.5)).foregroundStyle(focused ? wa.accent : c.mut)
                    TextField("", text: $text, axis: .vertical).lineLimit(lines).font(.dorr(15)).foregroundStyle(c.ink).tint(wa.accent)
                        .keyboardType(keyboard).focused($focused)
                }
            }
            .padding(.horizontal, 14).padding(.vertical, 10)
            .background(focused ? c.surface : c.field, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(focused ? wa.accent : c.fieldBorder, lineWidth: focused ? 2 : 1))
            .onTapGesture { focused = true }
            if let helper { Text(verbatim: helper).font(.dorr(11.5)).foregroundStyle(c.mut).padding(.horizontal, 14) }
        }
        .padding(.vertical, 5)
    }
}

/// A coloured letter tile standing in for a site thumbnail.
struct SiteAvatar: View {
    let title: String
    let seed: Int
    var size: CGFloat = 52

    private static let pairs: [(Color, Color)] = [
        (Color(hex: 0x1D4ED8), Color(hex: 0x38BDF8)), (Color(hex: 0x7C3AED), Color(hex: 0xEC4899)), (Color(hex: 0x059669), Color(hex: 0x84CC16)),
        (Color(hex: 0xEA580C), Color(hex: 0xFACC15)), (Color(hex: 0x0D9488), Color(hex: 0x38BDF8)), (Color(hex: 0xDB2777), Color(hex: 0xFB7185)),
    ]

    var body: some View {
        let (a, b) = Self.pairs[((seed % Self.pairs.count) + Self.pairs.count) % Self.pairs.count]
        Text(verbatim: String(title.trimmingCharacters(in: .whitespaces).prefix(1)).uppercased()).font(.dorr(size * 0.42, .heavy)).foregroundStyle(.white)
            .frame(width: size, height: size)
            .background(LinearGradient(colors: [a, b], startPoint: .topLeading, endPoint: .bottomTrailing), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
    }
}

/// androidApp `AiWalletPinSheet` — the wallet's own PIN pad in a dialog; it closes itself on success.
struct AiWalletPinDialog: View {
    @Environment(\.wa) private var wa
    let subtitle: String
    let onDismiss: () -> Void
    let onSubmit: (String) async -> PadResult

    var body: some View {
        WaPinPad(title: L("ai_subscription_pin_title"), sub: subtitle) { pin in
            let result = await onSubmit(pin)
            if case .ok = result { onDismiss() }
            return result
        }
        .padding(.vertical, 24)
        .background(wa.ai.surface, in: RoundedRectangle(cornerRadius: 24, style: .continuous))
        .padding(.horizontal, 20)
        .contentShape(Rectangle())
        .onTapGesture {}
    }
}

/// androidApp `AiInfoDialog` — a message and «OK».
struct AiInfoDialog: View {
    @Environment(\.wa) private var wa
    let message: String
    let onDismiss: () -> Void

    var body: some View {
        let c = wa.ai
        VStack(alignment: .leading, spacing: 16) {
            Text(verbatim: message).font(.dorr(14)).foregroundStyle(c.ink)
            Button(action: onDismiss) {
                Text(L("ai_ok")).font(.dorr(13, .semibold)).foregroundStyle(.white).frame(maxWidth: .infinity).padding(.vertical, 10)
                    .background(c.red, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            .buttonStyle(.plain)
        }
        .padding(20)
        .background(c.surface, in: RoundedRectangle(cornerRadius: 20, style: .continuous))
        .padding(.horizontal, 28)
        .contentShape(Rectangle())
        .onTapGesture {}
    }
}

/// Asks for the wallet PIN unless the wallet has none (then the «set a PIN first» message).
func aiNeedsPin(noPin: @escaping @MainActor () -> Void, askPin: @escaping @MainActor () -> Void) {
    Task { @MainActor in
        let status = try? await WalletApi.pinStatus().data
        if status?.hasPin == false { noPin() } else { askPin() }
    }
}
