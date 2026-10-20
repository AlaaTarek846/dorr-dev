package com.dorr.driver.wallet.compat

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import com.dorr.driver.ui.theme.CairoFamily
import com.dorr.driver.ui.theme.DriverPrefs
import com.dorr.driver.ui.theme.Pal

/*
 * The DORR app's theme names, as the wallet copied from it (O-35) asks for them — answered by the captain
 * app's own palette, which reads the same server colour tokens (`mobile-appearance-defaults`). Nothing
 * here decides a colour of its own.
 */

/** The DORR app's fallbacks, for a token the server hasn't sent. */
object AppColors {
    val danger = Color(0xFFF05252)
    val textPrimary = Color(0xFF111928)
    val textMuted = Color(0xFF9CA3AF)
    val waRed = Color(0xFF001B53)
}

/** The night palette by name (the DORR app's `AccountDark`). */
object AccountDark {
    val bg: Color @Composable get() = appearanceColor("background", Color(0xFF101216), night = true)
    val card: Color @Composable get() = appearanceColor("surface", Color(0xFF1A1D24), night = true)
    val well: Color @Composable get() = Color(0xFF2C2226)
    val line: Color @Composable get() = appearanceColor("border", Color(0xFF2C313A), night = true)
    val ink: Color @Composable get() = appearanceColor("textPrimary", Color(0xFFF4F5F7), night = true)
    val mut: Color @Composable get() = appearanceColor("textSecondary", Color(0xFF9AA1AC), night = true)
    val accent: Color @Composable get() = appearanceColor("primary", Color(0xFFFA7552), night = true)
}

class ThemeStateCompat(val isDark: Boolean?)

/** `LocalThemeState.current.isDark` — the captain app's own day / night choice. */
object LocalThemeState {
    val current: ThemeStateCompat @Composable get() = ThemeStateCompat(DriverPrefs.night)
}

/** A server colour token for day or night, else [fallback] (the DORR app's `appearanceColor`). */
@Composable
fun appearanceColor(key: String, fallback: Color, night: Boolean? = null): Color {
    val isNight = night ?: DriverPrefs.night
    val map = if (isNight) DriverPrefs.platformDark else DriverPrefs.platformLight
    val hex = map[key]?.trim()?.removePrefix("#") ?: return fallback
    if (hex.length != 6 || hex.any { it !in '0'..'9' && it.lowercaseChar() !in 'a'..'f' }) return fallback
    return Color(0xFF000000.toInt() or hex.toLong(16).toInt())
}

@Composable
fun settingsNight(): Boolean = DriverPrefs.night

@Composable
fun settingsAccent(): Color = Pal.accent

@Composable
fun settingsInk(): Color = Pal.ink

val CairoFontFamily: FontFamily get() = CairoFamily

/** The captain app's theme already sets the app font. */
@Composable
fun ProvideAppFont(content: @Composable () -> Unit) = content()

class AppLanguageCompat(val code: String)

object LocalAppLanguage {
    val current: AppLanguageCompat @Composable get() = AppLanguageCompat(com.dorr.driver.wallet.net.AppLocale.current)
}

/** The DORR app's switch (the wallet's biometric setting). */
@Composable
fun RedToggle(on: Boolean, dark: Boolean, onClick: () -> Unit) {
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Box(
            modifier = Modifier
                .size(width = 42.dp, height = 24.dp)
                .clip(RoundedCornerShape(12.dp))
                .background(if (on) settingsAccent() else if (dark) Color(0xFF3A3F48) else Color(0xFFE5E7EB))
                .clickable(onClick = onClick),
        ) {
            Box(
                modifier = Modifier
                    .padding(2.dp)
                    .size(20.dp)
                    .offset(x = if (on) 18.dp else 0.dp)
                    .shadow(1.dp, CircleShape)
                    .clip(CircleShape)
                    .background(Color.White),
            )
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LocaleAwareBottomSheet(
    onDismissRequest: () -> Unit,
    containerColor: Color,
    shape: Shape,
    fullHeight: Boolean = false,
    content: @Composable ColumnScope.() -> Unit,
) {
    ModalBottomSheet(
        onDismissRequest = onDismissRequest, containerColor = containerColor, shape = shape,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = fullHeight),
        content = content,
    )
}
