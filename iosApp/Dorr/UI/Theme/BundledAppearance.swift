import Foundation

/// androidApp `ui/theme/BundledAppearance.kt` — a copy, inside the app, of the platform colours the admin sets
/// (`GET general/v1/mobile-appearance-defaults`), taken 2026-11-09. Used only until the real ones have been saved on
/// the phone: the very first launch, before the request answers or with no internet — so that first launch already
/// looks like every later one. The server's answer always replaces it (`AppearanceState.applyPlatform`). If the admin
/// changes the defaults, refresh this with the next release.
enum BundledAppearance {
    static let light: [String: String] = [
        "primary": "#001B53", "primaryLight": "#1E3A7B", "primaryDark": "#00113A",
        "secondary": "#FA7552", "accent": "#FA7552",
        "danger": "#F05252", "success": "#059669", "warning": "#F59E0B", "info": "#06B6D4",
        "background": "#FFFFFF", "surface": "#FFFFFF",
        "textPrimary": "#111928", "textSecondary": "#6B7280", "textMuted": "#9CA3AF",
        "border": "#E5E7EB", "headerBackground": "#000000",
        "authAccent": "#001B53", "authBackdropBase": "#FFFFFF",
        "authGlowDeep": "#F8BCA9", "authGlowMid": "#FBD2C4", "authGlowSoft": "#F9C4B4",
        "authSurface": "#FFFFFF", "authWell": "#FFEEE8", "authBorder": "#E5E7EB", "authBorderPink": "#FBC8B7",
        "authTextPrimary": "#111928", "authTextMuted": "#6B7280", "authTextEmphasis": "#001B53",
    ]

    static let dark: [String: String] = [
        "primary": "#FA7552", "primaryLight": "#FF8E6E", "primaryDark": "#D9583A",
        "secondary": "#8EA6DD", "accent": "#FA7552",
        "danger": "#F05252", "success": "#059669", "warning": "#F59E0B", "info": "#06B6D4",
        "background": "#111928", "surface": "#1F2937",
        "textPrimary": "#F9FAFB", "textSecondary": "#9CA3AF", "textMuted": "#9CA3AF",
        "border": "#4B5563", "headerBackground": "#000000",
        "authAccent": "#FA7552", "authBackdropBase": "#101216", "authGlowAccent": "#FA7552",
        "authSurface": "#1A1D24", "authBorder": "#2C313A",
        "authTextPrimary": "#F4F5F7", "authTextMuted": "#9AA1AC",
    ]
}
