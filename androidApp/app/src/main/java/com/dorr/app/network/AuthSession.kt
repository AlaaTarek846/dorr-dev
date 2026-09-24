package com.dorr.app.network

import android.content.Context
import android.content.SharedPreferences
import com.google.gson.Gson

/**
 * Holds the authenticated session (mobile OTP flow). Persisted to
 * SharedPreferences so the token survives process death and cold starts:
 * the app restores the session in DorrApp.onCreate and Splash routes
 * straight to Main when one already exists.
 */
object AuthSession {
    private const val PREFS_NAME = "dorr_auth"
    private const val KEY_TOKEN = "token"
    private const val KEY_USER = "user"

    private var prefs: SharedPreferences? = null
    private val gson = Gson()

    var token: String? = null
        set(value) {
            field = value
            persist()
        }

    var user: UserDto? = null
        set(value) {
            field = value
            persist()
        }

    /**
     * Invoked whenever an authenticated request comes back 401 (expired or
     * revoked token). The interceptor clears the local session before calling
     * this, so callers only need to move the UI (e.g. back to Login).
     */
    var onUnauthorized: (() -> Unit)? = null

    val isAuthenticated: Boolean
        get() = !token.isNullOrBlank()

    /** Call once at startup (DorrApp.onCreate) to restore a persisted session. */
    fun attach(context: Context) {
        prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
        token = prefs?.getString(KEY_TOKEN, null)
        user = prefs?.getString(KEY_USER, null)?.let { raw ->
            runCatching { gson.fromJson(raw, UserDto::class.java) }.getOrNull()
        }
    }

    fun clear() {
        token = null
        user = null
        prefs?.edit()?.clear()?.apply()
    }

    private fun persist() {
        prefs?.edit()
            ?.putString(KEY_TOKEN, token)
            ?.putString(KEY_USER, user?.let { gson.toJson(it) })
            ?.apply()
    }
}