package com.dorr.freelance.net

import android.content.Context

/** Whether the welcome page (StartScreen) was shown: it appears once, the first time the app opens after install. */
object Welcome {
    private const val PREFS = "dorr_freelance_welcome"
    private const val KEY_SEEN = "seen"

    fun seen(context: Context): Boolean = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getBoolean(KEY_SEEN, false)

    fun markSeen(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putBoolean(KEY_SEEN, true).apply()
    }
}
