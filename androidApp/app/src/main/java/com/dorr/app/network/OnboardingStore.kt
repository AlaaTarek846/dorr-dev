package com.dorr.app.network

import android.content.Context
import android.content.SharedPreferences

/**
 * Remembers that the first-launch onboarding was finished or skipped.
 * Kept out of [AuthSession] because logout clears that file, and onboarding
 * must stay completed after sign-out.
 */
object OnboardingStore {
    private const val PREFS_NAME = "dorr_onboarding"
    private const val KEY_COMPLETED = "onboarding_completed"

    private var prefs: SharedPreferences? = null

    val isCompleted: Boolean
        get() = prefs?.getBoolean(KEY_COMPLETED, false) == true

    fun attach(context: Context) {
        prefs = context.applicationContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    }

    fun markCompleted() {
        prefs?.edit()?.putBoolean(KEY_COMPLETED, true)?.commit()
    }
}
