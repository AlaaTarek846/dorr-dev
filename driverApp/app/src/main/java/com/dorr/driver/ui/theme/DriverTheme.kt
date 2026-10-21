package com.dorr.driver.ui.theme

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.ExperimentalTextApi
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontVariation
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import androidx.core.view.WindowCompat
import com.dorr.driver.R

/**
 * The captain app's colours — the same system as the DORR app (server appearance tokens, then the
 * logo's navy and orange), in light and dark. Navy is the brand by day; at night the brand colour is
 * the orange, exactly as in the DORR app. Every value is read from Compose state, so a change of
 * mode or of the platform's tokens repaints everything that reads it.
 */
object Pal {
    private fun token(key: String, fallback: Color): Color {
        val map = if (DriverPrefs.night) DriverPrefs.platformDark else DriverPrefs.platformLight
        return hex(map[key]) ?: fallback
    }

    private fun hex(raw: String?): Color? {
        val h = raw?.trim()?.removePrefix("#") ?: return null
        if (h.length != 6 || h.any { it !in '0'..'9' && it !in 'a'..'f' && it !in 'A'..'F' }) return null
        return Color(0xFF000000.toInt() or h.toLong(16).toInt())
    }

    val night: Boolean get() = DriverPrefs.night

    /** The page. */
    val bg: Color get() = if (night) token("background", Color(0xFF101216)) else token("background", Color.White)

    /** Cards, sheets, bars. */
    val card: Color get() = if (night) token("surface", Color(0xFF1A1D24)) else token("surface", Color.White)

    /** The soft tinted fill behind an icon or a chip. */
    val well: Color get() = if (night) Color(0xFF2C2226) else accent.copy(alpha = 0.14f)

    val line: Color get() = if (night) token("border", Color(0xFF2C313A)) else token("border", Color(0xFFE5E7EB))

    val ink: Color get() = if (night) token("textPrimary", Color(0xFFF4F5F7)) else token("textPrimary", Color(0xFF111928))

    /** Secondary text. */
    val mut: Color get() = if (night) token("textSecondary", Color(0xFF9AA1AC)) else token("textSecondary", Color(0xFF6B7280))

    /** Hints, placeholders, disabled. */
    val faint: Color get() = if (night) Color(0xFF8B93A0) else token("textMuted", Color(0xFF9CA3AF))

    /** The brand colour: navy by day, orange at night. */
    val accent: Color get() = if (night) token("primary", Color(0xFFFA7552)) else token("primary", Color(0xFF001B53))

    /** The logo's orange, in both modes. */
    val orange: Color get() = token("secondary", Color(0xFFFA7552))

    val danger: Color get() = token("danger", Color(0xFFF05252))
    val success: Color = Color(0xFF059669)
    val warning: Color = Color(0xFFF59E0B)
    val info: Color = Color(0xFF06B6D4)

    /** Text field fill and hairline (the DORR app's pill fields). */
    val fieldFill: Color get() = if (night) bg else Color(0xFFFBF7F8)
    val fieldLine: Color get() = if (night) line else Color(0xFFF3D5DB)
}

/**
 * Kept under its old name so every screen that says `DorrBrand.navy` follows the theme without being
 * touched: `navy` is the brand colour of the moment, `orange` the logo's orange.
 */
object DorrBrand {
    val navy: Color get() = Pal.accent
    val navyLight: Color get() = Pal.accent.copy(alpha = 0.85f)
    val orange: Color get() = Pal.orange
    val success: Color get() = Pal.success
    val warning: Color get() = Pal.warning
    val danger: Color get() = Pal.danger
    val info: Color get() = Pal.info
}

/** Cairo is one variable font file; each entry asks the text engine for one weight (same as the DORR app). */
@OptIn(ExperimentalTextApi::class)
private fun cairoWeight(weight: Int) = Font(
    resId = R.font.cairo,
    weight = FontWeight(weight),
    variationSettings = FontVariation.Settings(FontVariation.weight(weight)),
)

val CairoFamily = FontFamily(cairoWeight(400), cairoWeight(500), cairoWeight(600), cairoWeight(700), cairoWeight(900))

private fun typography(family: FontFamily) = Typography(
    displayLarge = TextStyle(fontFamily = family, fontWeight = FontWeight.Bold, fontSize = 32.sp),
    displayMedium = TextStyle(fontFamily = family, fontWeight = FontWeight.Bold, fontSize = 28.sp),
    headlineLarge = TextStyle(fontFamily = family, fontWeight = FontWeight.SemiBold, fontSize = 24.sp),
    headlineMedium = TextStyle(fontFamily = family, fontWeight = FontWeight.SemiBold, fontSize = 20.sp),
    titleLarge = TextStyle(fontFamily = family, fontWeight = FontWeight.SemiBold, fontSize = 18.sp),
    titleMedium = TextStyle(fontFamily = family, fontWeight = FontWeight.Medium, fontSize = 16.sp),
    titleSmall = TextStyle(fontFamily = family, fontWeight = FontWeight.Medium, fontSize = 14.sp),
    bodyLarge = TextStyle(fontFamily = family, fontWeight = FontWeight.Normal, fontSize = 16.sp),
    bodyMedium = TextStyle(fontFamily = family, fontWeight = FontWeight.Normal, fontSize = 14.sp),
    bodySmall = TextStyle(fontFamily = family, fontWeight = FontWeight.Normal, fontSize = 12.sp),
    labelLarge = TextStyle(fontFamily = family, fontWeight = FontWeight.SemiBold, fontSize = 14.sp),
    labelMedium = TextStyle(fontFamily = family, fontWeight = FontWeight.Medium, fontSize = 12.sp),
    labelSmall = TextStyle(fontFamily = family, fontWeight = FontWeight.Medium, fontSize = 11.sp),
)

private fun scheme(): ColorScheme {
    val night = Pal.night
    return if (night) {
        darkColorScheme(
            primary = Pal.accent, onPrimary = Color.White,
            secondary = Pal.orange, onSecondary = Color.White,
            tertiary = Pal.success,
            background = Pal.bg, onBackground = Pal.ink,
            surface = Pal.card, onSurface = Pal.ink,
            surfaceVariant = Pal.well, onSurfaceVariant = Pal.mut,
            error = Pal.danger, outline = Pal.line, outlineVariant = Pal.line,
            surfaceTint = Color.Transparent,
            surfaceContainerLowest = Pal.card, surfaceContainerLow = Pal.card, surfaceContainer = Pal.card,
            surfaceContainerHigh = Pal.card, surfaceContainerHighest = Pal.card,
        )
    } else {
        lightColorScheme(
            primary = Pal.accent, onPrimary = Color.White,
            secondary = Pal.orange, onSecondary = Color.White,
            tertiary = Pal.success,
            background = Pal.bg, onBackground = Pal.ink,
            surface = Pal.card, onSurface = Pal.ink,
            surfaceVariant = Pal.fieldFill, onSurfaceVariant = Pal.mut,
            error = Pal.danger, outline = Pal.line, outlineVariant = Pal.line,
            surfaceTint = Color.Transparent,
            surfaceContainerLowest = Pal.card, surfaceContainerLow = Pal.card, surfaceContainer = Pal.card,
            surfaceContainerHigh = Pal.card, surfaceContainerHighest = Pal.card,
        )
    }
}

/** The app's theme: colours, Cairo type, the page colour behind everything, and system bars that read on it. */
@Composable
fun DriverTheme(content: @Composable () -> Unit) {
    val night = Pal.night
    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            view.context.findActivity()?.window?.let { window ->
                val controls = WindowCompat.getInsetsController(window, view)
                controls.isAppearanceLightStatusBars = !night
                controls.isAppearanceLightNavigationBars = !night
            }
        }
    }
    val type = remember { typography(CairoFamily) }
    // D-307: the text size the captain chose, on top of the phone's own.
    val density = androidx.compose.ui.platform.LocalDensity.current
    androidx.compose.runtime.CompositionLocalProvider(androidx.compose.ui.platform.LocalDensity provides androidx.compose.ui.unit.Density(density.density, density.fontScale * DriverPrefs.fontScale)) {
        MaterialTheme(colorScheme = scheme(), typography = type) {
            Surface(modifier = Modifier.fillMaxSize(), color = Pal.bg, contentColor = Pal.ink, content = content)
        }
    }
}

internal fun Context.findActivity(): Activity? {
    var c: Context? = this
    while (c is ContextWrapper) {
        if (c is Activity) return c
        c = c.baseContext
    }
    return null
}
