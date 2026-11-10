package com.dorr.app.ui.theme

/**
 * A copy, inside the app, of the platform colours the admin sets (`GET general/v1/mobile-appearance-defaults`), taken
 * 2026-11-09. Used only until the real ones have been saved on the phone: the very first launch, before the request
 * answers or with no internet — so that first launch already looks like every later one. The server's answer always
 * replaces it (AppearanceState.applyPlatform). If the admin changes the defaults, refresh this with the next release.
 * iosApp twin: `BundledAppearance` in UI/Theme/BundledAppearance.swift.
 */
internal object BundledAppearance {
    val light: Map<String, String> = mapOf(
        "primary" to "#001B53", "primaryLight" to "#1E3A7B", "primaryDark" to "#00113A",
        "secondary" to "#FA7552", "accent" to "#FA7552",
        "danger" to "#F05252", "success" to "#059669", "warning" to "#F59E0B", "info" to "#06B6D4",
        "background" to "#FFFFFF", "surface" to "#FFFFFF",
        "textPrimary" to "#111928", "textSecondary" to "#6B7280", "textMuted" to "#9CA3AF",
        "border" to "#E5E7EB", "headerBackground" to "#000000",
        "authAccent" to "#001B53", "authBackdropBase" to "#FFFFFF",
        "authGlowDeep" to "#F8BCA9", "authGlowMid" to "#FBD2C4", "authGlowSoft" to "#F9C4B4",
        "authSurface" to "#FFFFFF", "authWell" to "#FFEEE8", "authBorder" to "#E5E7EB", "authBorderPink" to "#FBC8B7",
        "authTextPrimary" to "#111928", "authTextMuted" to "#6B7280", "authTextEmphasis" to "#001B53",
    )

    val dark: Map<String, String> = mapOf(
        "primary" to "#FA7552", "primaryLight" to "#FF8E6E", "primaryDark" to "#D9583A",
        "secondary" to "#8EA6DD", "accent" to "#FA7552",
        "danger" to "#F05252", "success" to "#059669", "warning" to "#F59E0B", "info" to "#06B6D4",
        "background" to "#111928", "surface" to "#1F2937",
        "textPrimary" to "#F9FAFB", "textSecondary" to "#9CA3AF", "textMuted" to "#9CA3AF",
        "border" to "#4B5563", "headerBackground" to "#000000",
        "authAccent" to "#FA7552", "authBackdropBase" to "#101216", "authGlowAccent" to "#FA7552",
        "authSurface" to "#1A1D24", "authBorder" to "#2C313A",
        "authTextPrimary" to "#F4F5F7", "authTextMuted" to "#9AA1AC",
    )
}
