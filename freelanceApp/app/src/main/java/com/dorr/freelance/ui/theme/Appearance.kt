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
import androidx.compose.ui.text.font.FontFamily
import com.dorr.freelance.net.AppearanceDto
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken

/**
 * The freelancer's appearance after sign-in ([snapshot]: colours, dark mode, font — chosen in the settings, kept on the
 * account) plus the platform defaults from `GET general/v1/mobile-appearance-defaults` (the splash and the sign-in pages),
 * as in the DORR app.
 */
class AppearanceState {
    var snapshot by mutableStateOf(AppearanceStore.loadUser())
        private set

    var fontFamily by mutableStateOf<FontFamily>(CairoBuiltIn)
        internal set

    var platformLight by mutableStateOf(AppearanceStore.loadLight())
        private set

    var platformDark by mutableStateOf(AppearanceStore.loadDark())
        private set

    fun apply(dto: AppearanceDto) {
        snapshot = dto
        AppearanceStore.saveUser(dto)
    }

    fun applyPlatform(light: Map<String, String>?, dark: Map<String, String>?) {
        if (!light.isNullOrEmpty()) platformLight = light
        if (!dark.isNullOrEmpty()) platformDark = dark
        AppearanceStore.save(platformLight, platformDark)
    }

    /** Signed out: the account's own choice goes, the platform defaults stay for the sign-in pages. */
    fun clear() {
        snapshot = null
        AppearanceStore.clearUser()
    }
}

/** The saved appearance (one small file): the signed-in account's own, and the platform tokens. */
object AppearanceStore {
    private const val PREFS_NAME = "dorr_freelance_appearance"
    private const val KEY_USER = "appearance_user"
    private const val KEY_LIGHT = "platform_light_tokens"
    private const val KEY_DARK = "platform_dark_tokens"

    private var prefs: SharedPreferences? = null
    private val gson = Gson()
    private val mapType = object : TypeToken<Map<String, String>>() {}.type

    fun attach(context: Context) {
        prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    }

    fun loadUser(): AppearanceDto? =
        prefs?.getString(KEY_USER, null)?.let { raw -> runCatching { gson.fromJson(raw, AppearanceDto::class.java) }.getOrNull() }

    fun saveUser(dto: AppearanceDto) {
        prefs?.edit()?.putString(KEY_USER, gson.toJson(dto))?.apply()
    }

    fun clearUser() {
        prefs?.edit()?.remove(KEY_USER)?.apply()
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

/** One appearance token ([key], e.g. `primary`) for the current light / dark mode: the account's own, else the platform's, else [fallback]. */
@Composable
fun appearanceColor(key: String, fallback: Color, night: Boolean? = null): Color {
    val isNight = night ?: (LocalThemeState.current.isDark ?: isSystemInDarkTheme())
    val state = LocalAppearance.current
    val userMap = state.snapshot?.resolved?.let { if (isNight) it.darkTokens else it.lightTokens }
    val map = userMap ?: if (isNight) state.platformDark else state.platformLight
    if (map.isNullOrEmpty()) return fallback
    return hexToColor(map[key]) ?: fallback
}
