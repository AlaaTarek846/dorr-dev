import SwiftUI
import UIKit

// driverApp `ui/kit/Fields.kt` — the DORR app's text field (`DorrTextField`), the verification-code boxes (`OtpBoxes`) and
// the `OutlinedTextField` drop-in the old screens call (`DorrField`).

/// Compose's `KeyboardOptions`: the keyboard, the capitals, and the return key (`ImeAction`).
/// `KeyboardType.NumberPassword` → `.numberPad`, `Decimal` → `.decimalPad`, `Phone` → `.phonePad`, `Email` → `.emailAddress`.
struct KeyboardOptions {
    var keyboardType: UIKeyboardType = .default
    var capitalization: TextInputAutocapitalization = .never
    var imeAction: SubmitLabel = .done
    var contentType: UITextContentType? = nil

    init(keyboardType: UIKeyboardType = .default, capitalization: TextInputAutocapitalization = .never,
         imeAction: SubmitLabel = .done, contentType: UITextContentType? = nil) {
        self.keyboardType = keyboardType
        self.capitalization = capitalization
        self.imeAction = imeAction
        self.contentType = contentType
    }

    static let `default` = KeyboardOptions()
}

/// The text field of the DORR app, in the same design: a rounded pill with a soft fill, a hairline that turns the
/// brand colour when focused, an icon in the brand colour, and a small caption above (never a floating label).
///
/// `onClick` turns the pill into a tap target (date and time pickers) without opening the keyboard.
/// `secure` is Android's `visualTransformation = PasswordVisualTransformation()` (masked, no eye button);
/// `password` adds the eye button.
struct DorrTextField: View {
    @Environment(\.pal) private var pal
    let value: String
    let onValueChange: (String) -> Void
    var label: String? = nil
    var placeholder: String? = nil
    /// An SF Symbol.
    var icon: String? = nil
    var password = false
    var leading: AnyView? = nil
    var trailing: AnyView? = nil
    var keyboardOptions: KeyboardOptions = .default
    /// `KeyboardActions(onDone / onNext …)`.
    var onSubmit: (() -> Void)? = nil
    var singleLine = true
    var minLines = 1
    var error = false
    var enabled = true
    var onClick: (() -> Void)? = nil
    var minHeight: CGFloat = 50
    /// The pill's corner radius (nil: 999 single-line, 20 multi-line).
    var cornerRadius: CGFloat? = nil
    var secure = false
    /// `textStyle` — bodyLarge (16, normal) by default.
    var fontSize: CGFloat = 16
    var fontWeight: Font.Weight = .regular

    @FocusState private var focused: Bool
    @State private var revealed = false

    init(value: String, onValueChange: @escaping (String) -> Void, label: String? = nil, placeholder: String? = nil,
         icon: String? = nil, password: Bool = false, leading: AnyView? = nil, trailing: AnyView? = nil,
         keyboardOptions: KeyboardOptions = .default, onSubmit: (() -> Void)? = nil, singleLine: Bool = true, minLines: Int = 1,
         error: Bool = false, enabled: Bool = true, onClick: (() -> Void)? = nil, minHeight: CGFloat = 50,
         cornerRadius: CGFloat? = nil, secure: Bool = false, fontSize: CGFloat = 16, fontWeight: Font.Weight = .regular) {
        self.value = value
        self.onValueChange = onValueChange
        self.label = label
        self.placeholder = placeholder
        self.icon = icon
        self.password = password
        self.leading = leading
        self.trailing = trailing
        self.keyboardOptions = keyboardOptions
        self.onSubmit = onSubmit
        self.singleLine = singleLine
        self.minLines = minLines
        self.error = error
        self.enabled = enabled
        self.onClick = onClick
        self.minHeight = minHeight
        self.cornerRadius = cornerRadius
        self.secure = secure
        self.fontSize = fontSize
        self.fontWeight = fontWeight
    }

    /// The same field bound to a `@State` string.
    init(text: Binding<String>, label: String? = nil, placeholder: String? = nil, icon: String? = nil, password: Bool = false,
         keyboardOptions: KeyboardOptions = .default, onSubmit: (() -> Void)? = nil, singleLine: Bool = true, minLines: Int = 1,
         error: Bool = false, enabled: Bool = true) {
        self.init(value: text.wrappedValue, onValueChange: { text.wrappedValue = $0 }, label: label, placeholder: placeholder,
                  icon: icon, password: password, keyboardOptions: keyboardOptions, onSubmit: onSubmit, singleLine: singleLine,
                  minLines: minLines, error: error, enabled: enabled)
    }

    private var typing: Bool { enabled && onClick == nil }

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: cornerRadius ?? (singleLine ? 999 : 20))
        let fill = focused && !pal.night ? pal.card : pal.fieldFill
        let borderColor = error ? pal.danger : (focused ? pal.accent : pal.fieldLine)
        let iconTint = error ? pal.danger : (focused ? pal.accent : pal.accent.opacity(0.8))
        VStack(alignment: .leading, spacing: 0) {
            if let label {
                Text(label).font(.dorr(13, .bold)).foregroundStyle(pal.ink).padding(.bottom, 8)
            }
            HStack(alignment: singleLine ? .center : .top, spacing: 8) {
                if let icon { DorrIcon(icon, size: 18, tint: iconTint).padding(.top, singleLine ? 0 : 3) }
                if let leading { leading }
                ZStack(alignment: singleLine ? .leading : .topLeading) {
                    if value.isEmpty, let placeholder {
                        Text(placeholder).font(.dorr(fontSize, fontWeight)).foregroundStyle(pal.faint).lineLimit(singleLine ? 1 : nil)
                    }
                    input
                }
                .frame(maxWidth: .infinity, alignment: .leading)
                if password {
                    DorrIcon(revealed ? "eye.slash" : "eye", size: 18, tint: pal.faint)
                        .frame(width: 30, height: 30)
                        .contentShape(Circle())
                        .onTapGesture { revealed.toggle() }
                }
                if let trailing { trailing }
            }
            .padding(.horizontal, 14)
            .padding(.vertical, singleLine ? 8 : 12)
            .frame(maxWidth: .infinity, minHeight: minHeight, alignment: singleLine ? .leading : .topLeading)
            .background(shape.fill(fill))
            .overlay(shape.strokeBorder(borderColor, lineWidth: focused || error ? 1.5 : 1))
            .contentShape(shape)
            .onTapGesture {
                if let onClick {
                    if enabled { onClick() }
                } else if typing {
                    focused = true
                }
            }
            .animation(.tween(200), value: focused)
            .animation(.tween(200), value: error)
        }
    }

    @ViewBuilder private var input: some View {
        let binding = Binding(get: { value }, set: { onValueChange($0) })
        if !typing {
            Text(value)
                .font(.dorr(fontSize, fontWeight))
                .foregroundStyle(pal.ink)
                .lineLimit(singleLine ? 1 : nil)
                .frame(maxWidth: .infinity, alignment: .leading)
        } else {
            Group {
                if (password && !revealed) || (secure && !password) {
                    SecureField("", text: binding)
                } else if singleLine {
                    TextField("", text: binding)
                } else {
                    TextField("", text: binding, axis: .vertical).lineLimit(max(minLines, 1)...)
                }
            }
            .font(.dorr(fontSize, fontWeight))
            .foregroundStyle(pal.ink)
            .tint(pal.accent)
            .keyboardType(keyboardOptions.keyboardType)
            .textInputAutocapitalization(keyboardOptions.capitalization)
            .textContentType(keyboardOptions.contentType)
            .autocorrectionDisabled(password || secure)
            .submitLabel(keyboardOptions.imeAction)
            .onSubmit { onSubmit?() }
            .focused($focused)
        }
    }
}

/// A verification code as separate boxes, driven by one hidden field (so paste and the SMS keyboard
/// suggestion both work). The focused box takes the brand colour, a typed digit pops, and a wrong code
/// shakes the row and turns it red.
struct OtpBoxes: View {
    let code: String
    let length: Int
    let error: Bool
    let onChange: (String) -> Void
    @FocusState private var focused: Bool
    @State private var shake: CGFloat = 0

    init(code: String, length: Int, error: Bool, onChange: @escaping (String) -> Void) {
        self.code = code
        self.length = length
        self.error = error
        self.onChange = onChange
    }

    var body: some View {
        ZStack {
            TextField("", text: Binding(get: { code }, set: { onChange(String($0.filter(\.isNumber).prefix(length))) }))
                .keyboardType(.numberPad)
                .textContentType(.oneTimeCode)
                .foregroundStyle(.clear)
                .tint(.clear)
                .focused($focused)
                .frame(width: 1, height: 1)
                .opacity(0.02)
            HStack(spacing: 12) {
                ForEach(0..<length, id: \.self) { index in
                    let chars = Array(code)
                    DigitBox(digit: index < chars.count ? String(chars[index]) : nil, current: index == chars.count, error: error)
                }
            }
            .offset(x: shake)
        }
        .contentShape(Rectangle())
        .onTapGesture { focused = true }
        .task { focused = true }
        .task(id: error) {
            guard error else { return }
            for _ in 0..<3 {
                withAnimation(.linear(duration: 0.05)) { shake = 10 }
                try? await Task.sleep(nanoseconds: 50_000_000)
                withAnimation(.linear(duration: 0.05)) { shake = -10 }
                try? await Task.sleep(nanoseconds: 50_000_000)
            }
            withAnimation(.linear(duration: 0.05)) { shake = 0 }
        }
    }
}

private struct DigitBox: View {
    @Environment(\.pal) private var pal
    let digit: String?
    let current: Bool
    let error: Bool
    @State private var pop: CGFloat = 1

    var body: some View {
        let shape = RoundedRectangle(cornerRadius: 16)
        let edge: Color = error ? pal.danger : (current ? pal.accent : (digit != nil ? pal.accent.opacity(0.55) : pal.fieldLine))
        Text(digit ?? "")
            .font(.dorr(26, .bold))
            .foregroundStyle(pal.ink)
            .multilineTextAlignment(.center)
            .frame(width: 58, height: 66)
            .background(shape.fill(error ? pal.danger.opacity(0.06) : pal.fieldFill))
            .overlay(shape.strokeBorder(edge, lineWidth: current || error ? 2 : 1.5).animation(.tween(180), value: edge))
            .scaleEffect(pop)
            .task(id: digit ?? "") {
                guard digit != nil else { return }
                withAnimation(.linear(duration: 0.09)) { pop = 1.14 }
                try? await Task.sleep(nanoseconds: 90_000_000)
                withAnimation(.linear(duration: 0.11)) { pop = 1 }
            }
    }
}

/// A drop-in for Material's `OutlinedTextField` as the old screens call it, drawn as the app's own field: the label a
/// bold caption above, the pill below. Multi-line by default, as there.
struct DorrField: View {
    let value: String
    let onValueChange: (String) -> Void
    var label: String? = nil
    var placeholder: String? = nil
    var singleLine = false
    var minLines = 1
    var keyboardOptions: KeyboardOptions = .default
    /// `visualTransformation = PasswordVisualTransformation()`.
    var secure = false
    var enabled = true
    var error = false

    init(value: String, onValueChange: @escaping (String) -> Void, label: String? = nil, placeholder: String? = nil,
         singleLine: Bool = false, minLines: Int = 1, keyboardOptions: KeyboardOptions = .default, secure: Bool = false,
         enabled: Bool = true, error: Bool = false) {
        self.value = value
        self.onValueChange = onValueChange
        self.label = label
        self.placeholder = placeholder
        self.singleLine = singleLine
        self.minLines = minLines
        self.keyboardOptions = keyboardOptions
        self.secure = secure
        self.enabled = enabled
        self.error = error
    }

    var body: some View {
        DorrTextField(value: value, onValueChange: onValueChange, label: label, placeholder: placeholder,
                      keyboardOptions: keyboardOptions, singleLine: singleLine, minLines: minLines, error: error,
                      enabled: enabled, secure: secure)
    }
}
