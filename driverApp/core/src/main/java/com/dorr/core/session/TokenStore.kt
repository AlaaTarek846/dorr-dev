package com.dorr.core.session

import android.content.Context
import android.content.SharedPreferences
import java.util.UUID

/**
 * The signed-in token and the device id, one file per app (`name`) so the DORR app, the driver app and the freelancer
 * app never read each other's session even on the same phone.
 */
class TokenStore(context: Context, name: String) {
    private val prefs: SharedPreferences = context.applicationContext.getSharedPreferences(name, Context.MODE_PRIVATE)

    var token: String?
        get() = prefs.getString(KEY_TOKEN, null)
        set(value) {
            prefs.edit().apply { if (value == null) remove(KEY_TOKEN) else putString(KEY_TOKEN, value) }.apply()
        }

    val isSignedIn: Boolean
        get() = token != null

    /** Stable per install; sent as X-Device-Id. */
    val deviceId: String
        get() = prefs.getString(KEY_DEVICE, null) ?: UUID.randomUUID().toString().also {
            prefs.edit().putString(KEY_DEVICE, it).apply()
        }

    fun clear() {
        token = null
    }

    private companion object {
        const val KEY_TOKEN = "token"
        const val KEY_DEVICE = "device_id"
    }
}
