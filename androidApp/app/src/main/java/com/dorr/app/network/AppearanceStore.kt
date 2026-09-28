package com.dorr.app.network

import android.content.Context
import android.content.SharedPreferences
import com.google.gson.Gson

/** Cached appearance so the theme is ready before the next network refresh. */
object AppearanceStore {
    private const val PREFS_NAME = "dorr_appearance"
    private const val KEY_JSON = "appearance"

    private var prefs: SharedPreferences? = null
    private val gson = Gson()

    fun attach(context: Context) {
        prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    }

    fun load(): AppearanceDto? =
        prefs?.getString(KEY_JSON, null)?.let { raw ->
            runCatching { gson.fromJson(raw, AppearanceDto::class.java) }.getOrNull()
        }

    fun save(dto: AppearanceDto) {
        prefs?.edit()?.putString(KEY_JSON, gson.toJson(dto))?.apply()
    }

    fun clear() {
        prefs?.edit()?.clear()?.apply()
    }
}
