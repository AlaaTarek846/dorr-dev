package com.dorr.app.network

import android.content.Context
import android.content.SharedPreferences
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken

/** Cached user appearance + platform defaults (pre-login theme from general API). */
object AppearanceStore {
    private const val PREFS_NAME = "dorr_appearance"
    private const val KEY_USER = "appearance_user"
    private const val KEY_PLATFORM_LIGHT = "platform_light_tokens"
    private const val KEY_PLATFORM_DARK = "platform_dark_tokens"
    /** @deprecated migrated to [KEY_USER] */
    private const val KEY_JSON_LEGACY = "appearance"

    private var prefs: SharedPreferences? = null
    private val gson = Gson()
    private val mapType = object : TypeToken<Map<String, String>>() {}.type

    fun attach(context: Context) {
        prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        migrateLegacyUser()
    }

    fun loadUser(): AppearanceDto? =
        prefs?.getString(KEY_USER, null)?.let { raw ->
            runCatching { gson.fromJson(raw, AppearanceDto::class.java) }.getOrNull()
        }

    fun loadPlatformLight(): Map<String, String>? = loadTokenMap(KEY_PLATFORM_LIGHT)

    fun loadPlatformDark(): Map<String, String>? = loadTokenMap(KEY_PLATFORM_DARK)

    fun saveUser(dto: AppearanceDto) {
        prefs?.edit()?.putString(KEY_USER, gson.toJson(dto))?.apply()
    }

    fun savePlatform(light: Map<String, String>?, dark: Map<String, String>?) {
        prefs?.edit()?.apply {
            if (light != null) putString(KEY_PLATFORM_LIGHT, gson.toJson(light))
            if (dark != null) putString(KEY_PLATFORM_DARK, gson.toJson(dark))
        }?.apply()
    }

    fun clearUser() {
        prefs?.edit()?.remove(KEY_USER)?.apply()
    }

    /** @deprecated use [clearUser]; platform defaults are kept. */
    fun clear() {
        clearUser()
    }

    private fun loadTokenMap(key: String): Map<String, String>? =
        prefs?.getString(key, null)?.let { raw ->
            runCatching { gson.fromJson<Map<String, String>>(raw, mapType) }.getOrNull()
        }

    private fun migrateLegacyUser() {
        val store = prefs ?: return
        if (store.contains(KEY_USER) || !store.contains(KEY_JSON_LEGACY)) return
        store.getString(KEY_JSON_LEGACY, null)?.let { legacy ->
            store.edit().putString(KEY_USER, legacy).remove(KEY_JSON_LEGACY).apply()
        }
    }
}
