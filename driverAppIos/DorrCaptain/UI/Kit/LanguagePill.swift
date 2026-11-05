import SwiftUI

// driverApp `ui/kit/LanguagePill.kt` — the language chip of the sign-in screens, and the two helpers behind it.

/// What each language is called, in its own words.
func languageName(_ code: String) -> String {
    switch code {
    case "ar": return L("acct_lang_ar") // DriverPrefs.langAr
    case "en": return L("acct_lang_en") // DriverPrefs.langEn
    default: return L("acct_lang_system")
    }
}

/// Picks the language and rebuilds the screen in it (the root view rebuilds on the change, Android's `recreate()`).
@MainActor
func chooseLanguage(_ code: String) {
    if DriverPrefs.shared.language == code { return }
    DriverPrefs.shared.chooseLanguage(code)
}

/// The language chip of the sign-in screens: a pill that opens the choices.
struct LanguagePill: View {
    @Environment(\.pal) private var pal
    @ObservedObject private var prefs = DriverPrefs.shared
    @State private var open = false
    @State private var anchor: CGRect = .zero

    var body: some View {
        // The label shows the language actually in use, so "like the phone" still names one.
        let shown = prefs.language.isEmpty
            ? ((Locale.preferredLanguages.first.map { String($0.prefix(2)) } ?? "") == "ar" ? DriverPrefs.langAr : DriverPrefs.langEn)
            : prefs.language
        let shape = Capsule()
        HStack(spacing: 0) {
            DorrIcon("globe", size: 20, tint: pal.accent)
            HSpace(8)
            Text(languageName(shown)).font(.dorr(13, .semibold)).foregroundStyle(pal.ink)
        }
        .padding(.horizontal, 12)
        .frame(height: 36)
        .background(shape.fill(pal.card).shadow(color: pal.night ? .clear : Color(hex: 0x001B53, alpha: 0x14 / 255.0), radius: 6, y: 3))
        .overlay { if pal.night { shape.strokeBorder(pal.line, lineWidth: 1) } }
        .background(GeometryReader { g in
            Color.clear.onAppear { anchor = g.frame(in: .global) }.onChange(of: g.frame(in: .global)) { anchor = $0 }
        })
        .bounceClick { open = true }
        .dorrDropdown(isPresented: $open, anchor: anchor) {
            ForEach([DriverPrefs.langAr, DriverPrefs.langEn], id: \.self) { code in
                let chosen = code == shown
                DropdownItem(onClick: {
                    open = false
                    chooseLanguage(code)
                }) {
                    HStack(spacing: 12) {
                        Text(languageName(code)).font(.dorr(13, chosen ? .bold : .medium))
                            .foregroundStyle(chosen ? pal.accent : pal.ink)
                            .frame(maxWidth: .infinity, alignment: .leading)
                        if chosen { DorrIcon("checkmark", size: 16, tint: pal.accent) }
                    }
                }
            }
        }
    }
}
