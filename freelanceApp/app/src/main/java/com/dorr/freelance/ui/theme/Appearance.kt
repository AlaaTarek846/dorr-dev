package com.dorr.freelance.ui.theme

import android.content.Context
import android.content.SharedPreferences
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.Color
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken

/**
 * The platform colours the admin sets for the apps (`GET general/v1/mobile-appearance-defaults`, the same tokens the
 * DORR app uses before sign-in), kept on the phone so the next launch starts in them. The freelancer app has no
 * per-account appearance (yet): every screen reads these, or [AppColors] when the server sent nothing.
 */
class AppearanceState {
    var platformLight by mutableStateOf(AppearanceStore.loadLight())
        private set

    var platformDark by mutableStateOf(AppearanceStore.loadDark())
        private set

    fun applyPlatform(light: Map<String, String>?, dark: Map<String, String>?) {
        if (!light.isNullOrEmpty()) platformLight = light
        if (!dark.isNullOrEmpty()) platformDark = dark
        AppearanceStore.save(platformLight, platformDark)
    }
}

/** The saved platform tokens (one small file). */
object AppearanceStore {
    private const val PREFS_NAME = "dorr_freelance_appearance"
    private const val KEY_LIGHT = "platform_light_tokens"
    private const val KEY_DARK = "platform_dark_tokens"

    private var prefs: SharedPreferences? = null
    private val gson = Gson()
    private val mapType = object : TypeToken<Map<String, String>>() {}.type

    fun attach(context: Context) {
        prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    }

    fun loadLight(): Map<String, String>? = load(KEY_LIGHT)

    fun loadDark(): Map<String, String>? = load(KEY_DARK)

    fun save(light: Map<String, String>?, dark: Map<String, String>?) {
        prefs?.edit()?.apply {
            if (light != null) putString(KEY_LIGHT, gson.toJson(light))
            if (dark != null) putString(KEY_DARK, gson.toJson(dark))
        }?.apply()
    }

    private fun load(key: String): Map<String, String>? =
        prefs?.getString(key, null)?.let { raw -> runCatching { gson.fromJson<Map<String, String>>(raw, mapType) }.getOrNull() }
}

val LocalAppearance = compositionLocalOf { AppearanceState() }

fun hexToColor(raw: String?): Color? {
    val hex = raw?.trim()?.removePrefix("#") ?: return null
    if (hex.length != 6 || hex.any { it !in '0'..'9' && it !in 'a'..'f' && it !in 'A'..'F' }) return null
    return Color(0xFF000000.toInt() or hex.toLong(16).toInt())
}

/** One appearance token ([key], e.g. `primary`) for the current light / dark mode, or [fallback]. */
@Composable
fun appearanceColor(key: String, fallback: Color, night: Boolean? = null): Color {
    val isNight = night ?: (LocalThemeState.current.isDark ?: isSystemInDarkTheme())
    val state = LocalAppearance.current
    val map = if (isNight) state.platformDark else state.platformLight
    if (map.isNullOrEmpty()) return fallback
    return hexToColor(map[key]) ?: fallback
}
