package com.dorr.app.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.Color
import com.dorr.app.network.AppearanceDto
import com.dorr.app.network.AppearanceStore

/** In-memory appearance. `snapshot == null` means "no server colors yet" (pre-login). */
class AppearanceState {
    var snapshot by mutableStateOf(AppearanceStore.load())
        private set

    fun apply(dto: AppearanceDto) {
        snapshot = dto
        AppearanceStore.save(dto)
        AppFont.apply(dto.font)
    }

    fun clear() {
        snapshot = null
        AppearanceStore.clear()
        AppFont.reset()
    }
}

val LocalAppearance = compositionLocalOf { AppearanceState() }

/** `null` follows the phone. */
fun AppearanceDto?.toThemeOverride(): Boolean? = when (this?.darkMode) {
    "dark" -> true
    "light" -> false
    else -> null
}

fun hexToColor(raw: String?): Color? {
    val hex = raw?.trim()?.removePrefix("#") ?: return null
    if (hex.length != 6 || hex.any { it !in '0'..'9' && it !in 'a'..'f' && it !in 'A'..'F' }) return null
    return Color(0xFF000000.toInt() or hex.toLong(16).toInt())
}

/**
 * A resolved token for the active mode. Before login (no snapshot) the fallback is used,
 * so splash / login / OTP keep their current colors.
 */
@Composable
fun appearanceColor(key: String, fallback: Color, night: Boolean? = null): Color {
    val isNight = night ?: (LocalThemeState.current.isDark ?: isSystemInDarkTheme())
    val snap = LocalAppearance.current.snapshot ?: return fallback
    val map = if (isNight) snap.resolved?.darkTokens else snap.resolved?.lightTokens
    return hexToColor(map?.get(key)) ?: fallback
}
