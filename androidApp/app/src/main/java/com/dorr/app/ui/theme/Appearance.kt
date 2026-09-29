package com.dorr.app.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import com.dorr.app.network.AppearanceDto
import com.dorr.app.network.AppearanceStore

/**
 * User appearance after login ([snapshot]) plus platform defaults from
 * `GET general/v1/mobile-appearance-defaults` (Splash / pre-login screens).
 */
class AppearanceState {
    var snapshot by mutableStateOf(AppearanceStore.loadUser())
        private set

    var fontFamily by mutableStateOf(CairoFontFamily)
        internal set

    var platformLight by mutableStateOf(AppearanceStore.loadPlatformLight())
        private set

    var platformDark by mutableStateOf(AppearanceStore.loadPlatformDark())
        private set

    fun apply(dto: AppearanceDto) {
        snapshot = dto
        AppearanceStore.saveUser(dto)
    }

    fun applyPlatform(light: Map<String, String>?, dark: Map<String, String>?) {
        if (!light.isNullOrEmpty()) {
            platformLight = light
        }
        if (!dark.isNullOrEmpty()) {
            platformDark = dark
        }
        AppearanceStore.savePlatform(platformLight, platformDark)
    }

    /** Clears logged-in overrides only; platform defaults stay for Login / OTP. */
    fun clear() {
        snapshot = null
        AppearanceStore.clearUser()
    }
}

val LocalAppearance = compositionLocalOf { AppearanceState() }

/** Splash, onboarding, login, and OTP keep the platform default colors. */
val LocalUsePlatformColors = compositionLocalOf { false }

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

@Composable
fun appearanceColor(key: String, fallback: Color, night: Boolean? = null): Color {
    val isNight = night ?: (LocalThemeState.current.isDark ?: isSystemInDarkTheme())
    val state = LocalAppearance.current
    val userMap = if (LocalUsePlatformColors.current) {
        null
    } else {
        state.snapshot?.resolved?.let { if (isNight) it.darkTokens else it.lightTokens }
    }
    val platformMap = if (isNight) state.platformDark else state.platformLight
    val map = userMap ?: platformMap
    if (map.isNullOrEmpty()) return fallback
    return hexToColor(map[key]) ?: fallback
}
