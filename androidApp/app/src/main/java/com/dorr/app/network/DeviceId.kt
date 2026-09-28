package com.dorr.app.network

import android.content.Context
import android.content.SharedPreferences
import java.util.UUID

/**
 * A random id generated once per app install and kept locally — sent as `X-Device-Id` on every
 * request so the server can tell "a device that has opened this wallet before" from a new one
 * (wallet policy bend 3, docs/wallet-tasks.md §10.10). Not a hardware identifier: it says nothing
 * beyond "the same app install asked before", which is the most a client-supplied value can honestly
 * promise — and exactly what device trust needs.
 */
object DeviceId {
    private const val PREFS_NAME = "dorr_device"
    private const val KEY_ID = "device_id"

    private var prefs: SharedPreferences? = null
    private var cached: String? = null

    fun attach(context: Context) {
        prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    }

    val current: String
        get() {
            cached?.let { return it }
            val store = prefs
            val existing = store?.getString(KEY_ID, null)
            val id = existing ?: UUID.randomUUID().toString().also { store?.edit()?.putString(KEY_ID, it)?.apply() }
            cached = id
            return id
        }
}
