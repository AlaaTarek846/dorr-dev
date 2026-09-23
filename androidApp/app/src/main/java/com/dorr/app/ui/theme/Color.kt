package com.dorr.app.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/**
 * Placeholder brand palette — a 1:1 port of the reference app's color
 * constants. Swap these values (and nothing else) to re-skin the whole app;
 * every screen reads colors from here or from [DorrColorScheme], never
 * hard-coded hex.
 */
object AppColors {
    // Primary
    val primary = Color(0xFF21888F)
    val primaryLight = Color(0xFF2DA8B0)
    val primaryDark = Color(0xFF166064)

    // Secondary / accent
    val secondary = Color(0xFF0E9F6E)
    val accent = Color(0xFFFF8A4C)

    // Status
    val danger = Color(0xFFF05252)
    val success = Color(0xFF059669)
    val warning = Color(0xFFF59E0B)
    val info = Color(0xFF06B6D4)

    // Light neutrals
    val background = Color(0xFFF9FAFB)
    val surface = Color(0xFFFFFFFF)
    val card = Color(0xFFFFFFFF)
    val textPrimary = Color(0xFF111928)
    val textSecondary = Color(0xFF6B7280)
    val textMuted = Color(0xFF9CA3AF)
    val border = Color(0xFFE5E7EB)
    val divider = Color(0xFFF3F4F6)

    // Dark neutrals
    val darkBackground = Color(0xFF111928)
    val darkSurface = Color(0xFF1F2937)
    val darkCard = Color(0xFF374151)
    val darkTextPrimary = Color(0xFFF9FAFB)
    val darkTextSecondary = Color(0xFF9CA3AF)
    val darkBorder = Color(0xFF4B5563)

    // Header — solid black in both themes (matches the reference app's
    // "premium" header treatment, reads the same on light + dark).
    val headerBackground = Color(0xFF000000)

    /**
     * Soft tinted background (e.g. an unread notification row) — dark theme
     * needs a higher alpha to stay visible against the near-black background.
     */
    @Composable
    fun tint(base: Color, light: Float = 0.04f, dark: Float = 0.14f): Color {
        val isDark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
        return base.copy(alpha = if (isDark) dark else light)
    }
}
