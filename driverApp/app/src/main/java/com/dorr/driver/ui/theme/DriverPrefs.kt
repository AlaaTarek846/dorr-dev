package com.dorr.driver.ui.theme

import android.content.Context
import android.content.SharedPreferences
import android.content.res.Resources
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken

/**
 * What the captain chose about how the app looks: dark / light / follow the phone, and the language
 * (Arabic, English, or follow the phone) — plus the platform's colours from
 * `GET general/v1/mobile-appearance-defaults`, kept so the first frame after a restart is already in
 * the right colours. The same appearance settings the DORR app reads; the admin changes them once.
 *
 * Every property is Compose state, so a change repaints the whole app without a restart.
 */
object DriverPrefs {
    const val MODE_SYSTEM = "system"
    const val MODE_LIGHT = "light"
    const val MODE_DARK = "dark"

    const val LANG_SYSTEM = ""
    const val LANG_AR = "ar"
    const val LANG_EN = "en"

    private var prefs: SharedPreferences? = null
    private val gson = Gson()
    private val mapType = object : TypeToken<Map<String, String>>() {}.type

    var darkMode by mutableStateOf(MODE_SYSTEM)
        private set

    var language by mutableStateOf(LANG_SYSTEM)
        private set

    var platformLight by mutableStateOf<Map<String, String>>(emptyMap())
        private set

    var platformDark by mutableStateOf<Map<String, String>>(emptyMap())
        private set

    /** Idempotent: the Application and the Activity (which needs the language before it draws) both call it. */
    fun init(context: Context) {
        if (prefs != null) return
        val p = (context.applicationContext ?: context).getSharedPreferences("driver_look", Context.MODE_PRIVATE)
        prefs = p
        darkMode = p.getString(KEY_MODE, MODE_SYSTEM) ?: MODE_SYSTEM
        language = p.getString(KEY_LANG, LANG_SYSTEM) ?: LANG_SYSTEM
        platformLight = read(p.getString(KEY_LIGHT, null))
        platformDark = read(p.getString(KEY_DARK, null))
    }

    /** Dark now? The chosen mode, or the phone's own setting while the choice is "system". */
    val night: Boolean
        get() = when (darkMode) {
            MODE_DARK -> true
            MODE_LIGHT -> false
            else -> (Resources.getSystem().configuration.uiMode and android.content.res.Configuration.UI_MODE_NIGHT_MASK) ==
                android.content.res.Configuration.UI_MODE_NIGHT_YES
        }

    fun chooseDarkMode(mode: String) {
        darkMode = mode
        prefs?.edit()?.putString(KEY_MODE, mode)?.apply()
    }

    /** Stores the choice; the caller recreates the activity so every string and the layout direction follow. */
    fun chooseLanguage(code: String) {
        language = code
        prefs?.edit()?.putString(KEY_LANG, code)?.apply()
    }

    fun applyPlatform(light: Map<String, String>?, dark: Map<String, String>?) {
        if (!light.isNullOrEmpty()) platformLight = light
        if (!dark.isNullOrEmpty()) platformDark = dark
        prefs?.edit()?.putString(KEY_LIGHT, gson.toJson(platformLight))?.putString(KEY_DARK, gson.toJson(platformDark))?.apply()
    }

    private fun read(json: String?): Map<String, String> =
        json?.let { runCatching { gson.fromJson<Map<String, String>>(it, mapType) }.getOrNull() }.orEmpty()

    private const val KEY_MODE = "dark_mode"
    private const val KEY_LANG = "language"
    private const val KEY_LIGHT = "platform_light"
    private const val KEY_DARK = "platform_dark"
}
