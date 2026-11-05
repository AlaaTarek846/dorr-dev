import SwiftUI

// Small pieces the screens share, copied from freelanceAppIOS `ProfileKit.swift` (no Kotlin twin: Compose does these
// inline — `{ number = it.take(64) }`, a clickable bold text link).

extension Binding where Value == String {
    /// A text binding that rewrites what is typed (`{ years = it.filter(Char::isDigit).take(2) }`).
    func profileMap(_ transform: @escaping (String) -> String) -> Binding<String> {
        Binding(get: { wrappedValue }, set: { wrappedValue = transform($0) })
    }

    /// At most `count` characters (`it.take(count)`).
    func profileTake(_ count: Int) -> Binding<String> { profileMap { String($0.prefix(count)) } }

    /// Only digits, at most `count` (`it.filter(Char::isDigit).take(count)`).
    func profileDigits(_ count: Int) -> Binding<String> { profileMap { String($0.filter(\.isNumber).filter(\.isASCII).prefix(count)) } }
}

/// A bold text that acts (`Text(...).clickable { }` on Android): the brand colour, or `muted`.
struct ProfileTextLink: View {
    @Environment(\.wa) private var wa
    let text: String
    var muted = false
    var size: CGFloat = 14
    var enabled = true
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Text(verbatim: text).font(.dorr(size, .bold)).foregroundStyle(muted ? wa.settingsMut : wa.red)
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
    }
}
