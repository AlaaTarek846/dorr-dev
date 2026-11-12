import SwiftUI
import UIKit

// freelanceApp `ui/components/DorrTextField.kt`, `DateField.kt`.

/// The app's text field: a rounded white pill lifted by a soft shadow (like the sign-in fields), an outline in the app colour when focused, an icon in
/// the app colour. `label` is a small caption above the field; `password` hides the text and adds an eye; `onClick`
/// makes the pill a tap target (date pickers) without opening the keyboard.
struct DorrTextField: View {
    @Environment(\.wa) private var wa
    @EnvironmentObject private var appearance: AppearanceState
    @Binding var value: String
    var label: String?
    var placeholder: String?
    var icon: String?
    var password = false
    var leading: AnyView?
    var trailing: AnyView?
    var keyboard: UIKeyboardType = .default
    var singleLine = true
    var minLines = 1
    var error = false
    var enabled = true
    var onClick: (() -> Void)?
    var minHeight: CGFloat = 46
    var radius: CGFloat?
    var fill: Color?
    var focus: FocusState<Bool>.Binding?

    @FocusState private var ownFocus: Bool
    @State private var revealed = false

    private var focused: Bool { focus?.wrappedValue ?? ownFocus }

    var body: some View {
        let danger = appearance.color("danger", AppColors.danger, dark: wa.night)
        let shape = RoundedRectangle(cornerRadius: radius ?? (singleLine ? 999 : 20), style: .continuous)
        let background = fill ?? (wa.night ? wa.bg : appearance.color("surface", .white, dark: false))
        let border = error ? danger : focused ? wa.red : (wa.night ? wa.line : Color.clear)
        VStack(alignment: .leading, spacing: 0) {
            if let label {
                Text(verbatim: label).font(.dorr(13, .bold)).foregroundStyle(wa.settingsInk).padding(.bottom, 8)
            }
            HStack(alignment: singleLine ? .center : .top, spacing: 8) {
                if let icon {
                    Image(systemName: icon).font(.system(size: 15, weight: .semibold))
                        .foregroundStyle(error ? danger : focused ? wa.red : wa.red.opacity(0.8))
                        .frame(width: 18, height: 18)
                }
                if let leading { leading }
                ZStack(alignment: singleLine ? .leading : .topLeading) {
                    if value.isEmpty, let placeholder {
                        Text(verbatim: placeholder).font(.dorr(16)).foregroundStyle(wa.night ? wa.mut : Color(hex: 0xB4BAC4)).lineLimit(singleLine ? 1 : nil)
                    }
                    if onClick != nil {
                        Text(verbatim: value).font(.dorr(16)).foregroundStyle(wa.settingsInk).frame(maxWidth: .infinity, alignment: .leading)
                    } else {
                        field
                    }
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if password {
                    Button { revealed.toggle() } label: {
                        Image(systemName: revealed ? "eye.slash" : "eye").font(.system(size: 15))
                            .foregroundStyle(wa.night ? wa.mut : AppColors.textMuted).frame(width: 30, height: 30)
                    }
                    .buttonStyle(.plain)
                }
                if let trailing { trailing }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, singleLine ? 8 : 12)
            .frame(minHeight: minHeight)
            .background(background, in: shape)
            .shadow(color: wa.night ? .clear : .black.opacity(0.08), radius: 3, y: 1)
            .overlay(shape.stroke(border, lineWidth: focused || error ? 1.5 : 1))
            .contentShape(shape)
            .onTapGesture { if enabled { onClick?() } }
            .animation(.easeOut(duration: 0.2), value: focused)
            .opacity(enabled ? 1 : 0.6)
        }
    }

    @ViewBuilder
    private var field: some View {
        Group {
            if password && !revealed {
                SecureField("", text: $value)
            } else if singleLine {
                TextField("", text: $value)
            } else {
                TextField("", text: $value, axis: .vertical).lineLimit(minLines...)
            }
        }
        .font(.dorr(16))
        .foregroundStyle(wa.settingsInk)
        .tint(wa.red)
        .keyboardType(keyboard)
        .disabled(!enabled)
        .modifier(FocusBinding(external: focus, own: $ownFocus))
    }
}

private struct FocusBinding: ViewModifier {
    var external: FocusState<Bool>.Binding?
    var own: FocusState<Bool>.Binding

    func body(content: Content) -> some View {
        if let external { content.focused(external) } else { content.focused(own) }
    }
}

/// One box of a verification code: a single centred digit, 52 tall.
struct DorrDigitBox: View {
    @Binding var value: String
    let borderColor: Color
    let focusedBorderColor: Color
    var background: Color = .clear
    var textColor: Color = AppColors.textPrimary
    var focus: FocusState<Int?>.Binding
    let index: Int

    var body: some View {
        let focused = focus.wrappedValue == index
        TextField("", text: $value)
            .font(.dorr(22, .semibold))
            .foregroundStyle(textColor)
            .multilineTextAlignment(.center)
            .keyboardType(.numberPad)
            .textContentType(index == 0 ? .oneTimeCode : nil)
            .tint(AppColors.brandNavy)
            .focused(focus, equals: index)
            .frame(maxWidth: .infinity, minHeight: 52)
            .background(background, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 14, style: .continuous).stroke(focused ? focusedBorderColor : borderColor, lineWidth: focused ? 2 : 1))
            .environment(\.layoutDirection, .leftToRight)
    }
}

/// A date as a field that opens the date picker. `value` is `YYYY-MM-DD` (what the server takes, in Latin digits);
/// an empty string means no date. The X clears it.
struct DateField: View {
    @Environment(\.wa) private var wa
    @Binding var value: String
    let label: String
    var enabled = true
    @State private var open = false
    @State private var picked = Date()

    var body: some View {
        DorrTextField(
            value: .constant(value), label: label, placeholder: L("profile_dob_hint"), icon: "calendar",
            trailing: value.isEmpty || !enabled ? nil : AnyView(
                Button { value = "" } label: { Image(systemName: "xmark").font(.system(size: 13, weight: .bold)).foregroundStyle(wa.settingsMut) }
                    .buttonStyle(.plain)
            ),
            enabled: enabled,
            onClick: {
                picked = Self.parse(value) ?? Date()
                open = true
            }
        )
        .dorrDialog(isPresented: $open) {
            DialogCard {
                VStack(spacing: 12) {
                    DatePicker("", selection: $picked, displayedComponents: .date)
                        .datePickerStyle(.graphical)
                        .tint(wa.red)
                        .environment(\.locale, AppLocale.locale)
                    HStack {
                        Button(L("common_cancel")) { open = false }.foregroundStyle(wa.settingsMut)
                        Spacer()
                        Button(L("common_ok")) {
                            value = Self.format(picked)
                            open = false
                        }
                        .foregroundStyle(wa.red)
                    }
                    .font(.dorr(15, .bold))
                }
                .padding(14)
            }
            .frame(maxWidth: 360)
        }
    }

    private static let formatter: DateFormatter = {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return f
    }()

    static func parse(_ s: String) -> Date? { formatter.date(from: s) }
    static func format(_ d: Date) -> String { formatter.string(from: d) }
}
