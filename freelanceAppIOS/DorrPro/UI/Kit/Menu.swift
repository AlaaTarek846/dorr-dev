import SwiftUI
import UIKit

// freelanceApp `ui/kit/Menu.kt` and the buttons of `ui/kit/Kit.kt`.

/// One row of an account menu: an icon tile, a title, a short line under it, and a chevron (or [trailing]).
struct MenuEntry: Identifiable {
    var id: String { label }
    let icon: String
    let label: String
    var subtitle: String?
    var danger = false
    var trailing: AnyView?
    let action: () -> Void
}

struct MenuRow: View {
    @Environment(\.wa) private var wa
    let entry: MenuEntry

    var body: some View {
        Button(action: entry.action) {
            HStack(spacing: 10) {
                PinkIcon(icon: entry.icon)
                VStack(alignment: .leading, spacing: 0) {
                    Text(verbatim: entry.label).font(.dorr(13.5, .bold)).foregroundStyle(entry.danger ? wa.red : wa.settingsInk)
                    if let subtitle = entry.subtitle {
                        Text(verbatim: subtitle).font(.dorr(11)).foregroundStyle(wa.settingsInk.opacity(0.65))
                    }
                }
                Spacer(minLength: 0)
                if let trailing = entry.trailing {
                    trailing
                } else {
                    Image(systemName: "chevron.forward").font(.system(size: 12, weight: .bold))
                        .foregroundStyle(wa.night ? Color(hex: 0x8B93A0) : wa.red.opacity(0.55))
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay { if wa.night { RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.line, lineWidth: 1) } }
            .shadow(color: wa.night ? .clear : wa.red.opacity(0.07), radius: 6, y: 2)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .padding(.vertical, 4)
    }
}

// MARK: - Dialogs

extension View {
    /// Android's `Dialog`: over everything (the tab bar too), a 45 % black scrim that closes it when tapped.
    /// `bottom`: a panel rising from the bottom edge (Contact us, FAQ) instead of a centred card.
    func dorrDialog<Content: View>(isPresented: Binding<Bool>, bottom: Bool = false, @ViewBuilder content: @escaping () -> Content) -> some View {
        fullScreenCover(isPresented: isPresented) {
            DialogScrim(onDismiss: { isPresented.wrappedValue = false }, bottom: bottom, content: content)
        }
        .transaction { $0.disablesAnimations = true }
    }
}

private struct DialogScrim<Content: View>: View {
    @EnvironmentObject private var appearance: AppearanceState
    let onDismiss: () -> Void
    var bottom = false
    @ViewBuilder let content: () -> Content
    @State private var shown = false

    var body: some View {
        ZStack {
            Color.black.opacity(shown ? 0.45 : 0).ignoresSafeArea().onTapGesture(perform: onDismiss)
            if bottom {
                content().offset(y: shown ? 0 : 700)
            } else {
                content()
                    .opacity(shown ? 1 : 0)
                    .scaleEffect(shown ? 1 : 0.94)
            }
        }
        .background(ClearPresentationBackground())
        .onAppear { withAnimation(bottom ? .timingCurve(0.4, 0, 0.2, 1, duration: 0.3) : .easeOut(duration: 0.2)) { shown = true } }
    }
}

/// A full-screen cover's own background made clear (iOS 16.0 has no presentationBackground).
private struct ClearPresentationBackground: UIViewRepresentable {
    func makeUIView(context: Context) -> UIView {
        let view = UIView()
        DispatchQueue.main.async { view.superview?.superview?.backgroundColor = .clear }
        return view
    }

    func updateUIView(_ view: UIView, context: Context) {}
}

/// The dialog card: max 300 wide, rounded 20, the pink backdrop behind its content.
struct DialogCard<Content: View>: View {
    @ViewBuilder let content: () -> Content

    var body: some View {
        content()
            .background(PinkBackdropCard())
            .clipShape(RoundedRectangle(cornerRadius: 20, style: .continuous))
            .shadow(color: Color(hex: 0x001B53).opacity(0.14), radius: 16, y: 6)
            .frame(maxWidth: 300)
            .padding(.horizontal, 24)
            .contentShape(Rectangle())
            .onTapGesture {}
    }
}

/// [PinkBackdrop] sized to its container (a dialog), not to the screen.
struct PinkBackdropCard: View {
    @EnvironmentObject private var appearance: AppearanceState
    @Environment(\.wa) private var wa

    var body: some View {
        GeometryReader { proxy in
            let w = proxy.size.width
            let glow = wa.night ? wa.darkAccent : wa.red
            ZStack {
                wa.night ? wa.bg : appearance.color("background", .white, dark: false)
                RadialGradient(colors: [glow.opacity(0.35), glow.opacity(0.10), .clear], center: UnitPoint(x: 0.5, y: -0.08), startRadius: 0, endRadius: w * 0.85)
                RadialGradient(colors: [glow.opacity(0.20), .clear], center: UnitPoint(x: 1.05, y: 0.02), startRadius: 0, endRadius: w * 0.55)
            }
        }
    }
}

/// freelanceApp `ConfirmDialog` — icon tile, title, message, then confirm (brand) and cancel (outlined).
struct ConfirmDialog: View {
    @Environment(\.wa) private var wa
    let icon: String
    let title: String
    let message: String
    var loading = false
    let confirmLabel: String
    let onConfirm: () -> Void
    let onDismiss: () -> Void

    var body: some View {
        DialogCard {
            VStack(spacing: 0) {
                Image(systemName: icon).font(.system(size: 22, weight: .semibold)).foregroundStyle(wa.accent)
                    .frame(width: 56, height: 56)
                    .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 18, style: .continuous))
                    .overlay { if wa.night { RoundedRectangle(cornerRadius: 18, style: .continuous).stroke(wa.line, lineWidth: 1) } }
                    .shadow(color: wa.night ? .clear : Color(hex: 0x001B53).opacity(0.1), radius: 8, y: 3)
                    .padding(.bottom, 12)
                Text(verbatim: title).font(.dorr(18, .heavy)).foregroundStyle(wa.accent).multilineTextAlignment(.center)
                Text(verbatim: message).font(.dorr(13, .bold)).foregroundStyle(wa.settingsMut).multilineTextAlignment(.center).lineSpacing(4).padding(.top, 4)
                HStack(spacing: 8) {
                    Button(action: onConfirm) {
                        HStack(spacing: 8) {
                            if loading {
                                ProgressView().tint(.white).scaleEffect(0.8)
                            } else {
                                Text(verbatim: confirmLabel).font(.dorr(15, .bold))
                            }
                        }
                        .foregroundStyle(.white)
                        .frame(maxWidth: .infinity, minHeight: 46)
                        .background(wa.red.opacity(loading ? 0.6 : 1), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                        .shadow(color: Color(hex: 0x001B53).opacity(0.22), radius: 8, y: 3)
                    }
                    .buttonStyle(.plain)
                    .allowsHitTesting(!loading)
                    Button(action: onDismiss) {
                        Text(L("common_cancel")).font(.dorr(15, .bold)).foregroundStyle(wa.accent.opacity(loading ? 0.5 : 1))
                            .frame(maxWidth: .infinity, minHeight: 46)
                            .background(wa.settingsCard, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
                            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(wa.night ? wa.line : wa.accent.opacity(0.25), lineWidth: 1.5))
                    }
                    .buttonStyle(.plain)
                    .allowsHitTesting(!loading)
                }
                .padding(.top, 16)
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 18)
        }
    }
}


/// freelanceApp `SettingsPrimaryButton` — 54 tall, rounded 18, brand; 55 % brand while disabled.
struct SettingsPrimaryButton: View {
    @Environment(\.wa) private var wa
    let text: String
    var enabled = true
    var loading = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            ZStack {
                if loading { ProgressView().tint(.white) } else { Text(verbatim: text).font(.dorr(16, .heavy)) }
            }
            .foregroundStyle(.white)
            .frame(maxWidth: .infinity, minHeight: 54)
            .background(wa.red.opacity(enabled && !loading ? 1 : 0.55), in: RoundedRectangle(cornerRadius: 18, style: .continuous))
            .shadow(color: wa.night ? .clear : wa.red.opacity(0.38), radius: 12, y: 4)
        }
        .buttonStyle(.plain)
        .allowsHitTesting(enabled && !loading)
    }
}

/// freelanceApp `kit/RedToggle` — 42×24 track in the brand colour, white knob.
struct RedToggle: View {
    @Environment(\.wa) private var wa
    let on: Bool
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            ZStack(alignment: on ? .trailing : .leading) {
                RoundedRectangle(cornerRadius: 12, style: .continuous)
                    .fill(on ? wa.red : (wa.night ? Color(hex: 0x3A3F48) : Color(hex: 0xE5E7EB)))
                    .frame(width: 42, height: 24)
                Circle().fill(.white).frame(width: 20, height: 20).shadow(color: .black.opacity(0.2), radius: 1, y: 0.5).padding(2)
            }
            .animation(.easeOut(duration: 0.18), value: on)
            .environment(\.layoutDirection, .leftToRight)
        }
        .buttonStyle(.plain)
    }
}
