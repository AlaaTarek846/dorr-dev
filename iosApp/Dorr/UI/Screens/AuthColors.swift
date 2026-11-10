import SwiftUI

/// androidApp `AuthColors.kt` — the colours of the pages before sign-in (onboarding, sign-in, OTP) — every one from
/// the admin's colours (the platform defaults, or the user's own once signed in), by day and by night, so nothing on
/// these pages stays a fixed grey, pink or brown when the colours change. Only the logo (an image) and the splash (it
/// must match the system's) stay fixed. Errors are the `danger` red and successes the `success` green — never the
/// brand navy / orange.
///
/// By night each one is the page's night palette (`wa.ink` / `wa.mut` / `wa.line` / `wa.darkCard` — Android's
/// `AccountDark.*`); by day the token itself.
@MainActor
enum AuthColors {
    /// Main words: names, the dial code, typed digits.
    static func ink(_ wa: WaPalette) -> Color {
        wa.night ? wa.ink : AppearanceState.shared.color("textPrimary", Color(hex: 0x111928), dark: false)
    }

    /// Secondary words: descriptions, hints.
    static func mut(_ wa: WaPalette) -> Color {
        wa.night ? wa.mut : AppearanceState.shared.color("authTextMuted", Color(hex: 0x6B7280), dark: false)
    }

    /// The faintest words: placeholders, a disabled button's label, small icons.
    static func soft(_ wa: WaPalette) -> Color {
        wa.night ? wa.mut : AppearanceState.shared.color("textMuted", Color(hex: 0x9CA3AF), dark: false)
    }

    /// Frames: fields, the language / skip buttons, the code boxes.
    static func line(_ wa: WaPalette) -> Color {
        wa.night ? wa.line : AppearanceState.shared.color("border", Color(hex: 0xE5E7EB), dark: false)
    }

    /// Fields and menus.
    static func card(_ wa: WaPalette) -> Color {
        wa.night ? wa.darkCard : AppearanceState.shared.color("surface", .white, dark: false)
    }

    /// Something went wrong (frames, the OTP shield).
    static func danger(_ wa: WaPalette) -> Color {
        AppearanceState.shared.color("danger", Color(hex: 0xF05252), dark: wa.night)
    }

    /// It worked (the right code, a restored account).
    static func success(_ wa: WaPalette) -> Color {
        AppearanceState.shared.color("success", Color(hex: 0x059669), dark: wa.night)
    }

    /// A status colour as words / icons: deeper by day, lighter by night — readable on either background.
    static func onStatus(_ color: Color, night: Bool) -> Color {
        night ? color.mix(.white, 0.45) : color.mix(.black, 0.25)
    }

    /// A status box's fill and frame: the status colour, faint.
    static func statusFill(_ color: Color, night: Bool) -> Color {
        color.opacity(night ? 0.16 : 0.08)
    }

    static func statusFrame(_ color: Color) -> Color {
        color.opacity(0.35)
    }
}
