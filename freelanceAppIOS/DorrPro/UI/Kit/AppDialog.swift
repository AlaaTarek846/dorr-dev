import SwiftUI

// freelanceApp `ui/kit/AppDialog.kt` — the app's confirm / form dialog: the same call shape as Material's AlertDialog, drawn
// like the app's own popups (Language, FAQ…): a 20pt card on the soft app-colour backdrop with a shadow, a tinted icon tile and
// an accent title on top, the body below, the buttons in a row at the end. Screens use this one, so every dialog looks the
// same and follows the appearance / night settings.
//
// Present it inside `.dorrDialog(isPresented:)` (the dimmed screen that closes it when tapped — Android's
// `LocaleAwareDialog` + the 45 % scrim):
//
//     .dorrDialog(isPresented: $asking) {
//         AlertDialog(onDismissRequest: { asking = false }, title: { Text(L("…")) }, text: { Text(L("…")) },
//                     confirmButton: { DialogButton(onClick: { … }) { Text(L("common_yes")) } },
//                     dismissButton: { DialogButton(onClick: { asking = false }) { Text(L("common_cancel")) } })
//     }
//
// A slot left out (title, text, dismissButton) is simply not drawn, like a `null` slot on Android.

struct AlertDialog<Title: View, TextContent: View, Confirm: View, Dismiss: View>: View {
    @Environment(\.wa) private var wa
    let onDismissRequest: () -> Void
    /// SF Symbol of the tile on top (Android `Icons.Rounded.Info` by default).
    var icon: String
    let title: () -> Title
    let text: () -> TextContent
    let confirmButton: () -> Confirm
    let dismissButton: () -> Dismiss

    init(
        onDismissRequest: @escaping () -> Void,
        icon: String = "info.circle.fill",
        @ViewBuilder title: @escaping () -> Title,
        @ViewBuilder text: @escaping () -> TextContent,
        @ViewBuilder confirmButton: @escaping () -> Confirm,
        @ViewBuilder dismissButton: @escaping () -> Dismiss
    ) {
        self.onDismissRequest = onDismissRequest
        self.icon = icon
        self.title = title
        self.text = text
        self.confirmButton = confirmButton
        self.dismissButton = dismissButton
    }

    private var hasTitle: Bool { Title.self != EmptyView.self }
    private var hasText: Bool { TextContent.self != EmptyView.self }
    private var hasDismiss: Bool { Dismiss.self != EmptyView.self }

    var body: some View {
        let night = wa.night
        VStack(spacing: 0) {
            VStack(spacing: 0) {
                Image(systemName: icon).font(.system(size: 28, weight: .semibold)).foregroundStyle(wa.red)
                    .frame(width: 64, height: 64)
                    .background(night ? wa.darkWell : wa.red.opacity(0.14), in: RoundedRectangle(cornerRadius: 20, style: .continuous))
                if hasTitle {
                    title()
                        .font(.dorr(18, .heavy))
                        .foregroundStyle(night ? wa.darkAccent : wa.red)
                        .multilineTextAlignment(.center)
                        .padding(.top, 12)
                }
            }
            .frame(maxWidth: .infinity)
            .padding(.bottom, 14)
            if hasText {
                DialogTextBox {
                    text()
                        .font(.dorr(hasTitle ? 14 : 16, hasTitle ? .regular : .bold))
                        .foregroundStyle(hasTitle ? wa.settingsMut : wa.settingsInk)
                        .multilineTextAlignment(.center)
                        .frame(maxWidth: .infinity)
                }
            }
            HStack(spacing: 10) {
                confirmButton().environment(\.dialogButtonPrimary, true).frame(maxWidth: .infinity)
                if hasDismiss { dismissButton().environment(\.dialogButtonPrimary, false).frame(maxWidth: .infinity) }
            }
            .padding(.top, 14)
        }
        .padding(18)
        .background(PinkBackdropCard())
        .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
        .shadow(color: Color(hex: 0x001B53).opacity(0.14), radius: 16, y: 6)
        .frame(maxWidth: 340)
        .padding(.horizontal, 24)
        .contentShape(Rectangle())
        .onTapGesture {}
    }
}

extension AlertDialog where Dismiss == EmptyView {
    /// No cancel button.
    init(onDismissRequest: @escaping () -> Void, icon: String = "info.circle.fill",
         @ViewBuilder title: @escaping () -> Title, @ViewBuilder text: @escaping () -> TextContent, @ViewBuilder confirmButton: @escaping () -> Confirm) {
        self.init(onDismissRequest: onDismissRequest, icon: icon, title: title, text: text, confirmButton: confirmButton, dismissButton: { EmptyView() })
    }
}

extension AlertDialog where Title == EmptyView {
    /// No title: the text is the question itself (larger, bold, in the ink colour).
    init(onDismissRequest: @escaping () -> Void, icon: String = "info.circle.fill",
         @ViewBuilder text: @escaping () -> TextContent, @ViewBuilder confirmButton: @escaping () -> Confirm, @ViewBuilder dismissButton: @escaping () -> Dismiss) {
        self.init(onDismissRequest: onDismissRequest, icon: icon, title: { EmptyView() }, text: text, confirmButton: confirmButton, dismissButton: dismissButton)
    }
}

extension AlertDialog where Title == EmptyView, Dismiss == EmptyView {
    /// No title and no cancel button.
    init(onDismissRequest: @escaping () -> Void, icon: String = "info.circle.fill",
         @ViewBuilder text: @escaping () -> TextContent, @ViewBuilder confirmButton: @escaping () -> Confirm) {
        self.init(onDismissRequest: onDismissRequest, icon: icon, title: { EmptyView() }, text: text, confirmButton: confirmButton, dismissButton: { EmptyView() })
    }
}

/// The dialog's body: scrolls when it is taller than 440.
private struct DialogTextBox<Content: View>: View {
    @ViewBuilder let content: () -> Content
    @State private var height: CGFloat = 0

    var body: some View {
        ScrollView(showsIndicators: false) {
            content().background(GeometryReader { p in Color.clear.preference(key: DialogTextHeight.self, value: p.size.height) })
        }
        .frame(maxHeight: min(max(height, 1), 440))
        .onPreferenceChange(DialogTextHeight.self) { height = $0 }
    }
}

private struct DialogTextHeight: PreferenceKey {
    static var defaultValue: CGFloat = 0
    static func reduce(value: inout CGFloat, nextValue: () -> CGFloat) { value = max(value, nextValue()) }
}

private struct DialogButtonPrimaryKey: EnvironmentKey {
    static let defaultValue = true
}

extension EnvironmentValues {
    /// Set by [AlertDialog]: true in the confirm slot, false in the dismiss slot (Android `LocalDialogButtonPrimary`).
    var dialogButtonPrimary: Bool {
        get { self[DialogButtonPrimaryKey.self] }
        set { self[DialogButtonPrimaryKey.self] = newValue }
    }
}

/// A dialog's button, drawn like the popups' own close button: the main one (confirm) is a filled accent pill with white
/// text, the other a soft grey pill. Drop-in for a text button inside a dialog's confirm / dismiss slot.
struct DialogButton<Content: View>: View {
    @Environment(\.wa) private var wa
    @Environment(\.dialogButtonPrimary) private var primary
    let onClick: () -> Void
    var enabled = true
    @ViewBuilder let content: () -> Content

    init(onClick: @escaping () -> Void, enabled: Bool = true, @ViewBuilder content: @escaping () -> Content) {
        self.onClick = onClick
        self.enabled = enabled
        self.content = content
    }

    var body: some View {
        Button(action: onClick) {
            HStack(spacing: 6) { content() }
                .font(.dorr(15, .bold))
                .foregroundStyle(primary ? Color.white : wa.settingsInk)
                .padding(.horizontal, 12)
                .padding(.vertical, 8)
                .frame(maxWidth: .infinity, minHeight: 44)
                .background(primary ? wa.red : wa.settingsMut.opacity(0.16), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                .shadow(color: primary ? Color(hex: 0x001B53).opacity(0.22) : .clear, radius: 8, y: 3)
                .contentShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
        .opacity(enabled ? 1 : 0.45)
    }
}
