package com.dorr.app

import com.google.firebase.crashlytics.FirebaseCrashlytics

/**
 * Crash reports in Firebase Crashlytics: each crash with its stack, the phone, the app version
 * and the account id, so a bug report says which user hit it. Crashlytics catches the crashes by
 * itself; this adds who it was and the handled errors worth seeing.
 *
 * Without app/google-services.json there is no Firebase app: every call here does nothing.
 */
object CrashReports {
    private fun crashlytics(): FirebaseCrashlytics? = runCatching { FirebaseCrashlytics.getInstance() }.getOrNull()

    fun setUser(id: Int?) {
        crashlytics()?.setUserId(id?.toString().orEmpty())
    }

    /** A line kept with the next crash report ("opened chat …"). */
    fun log(message: String) {
        crashlytics()?.log(message)
    }

    /** An error the app survived but we want to see. */
    fun record(error: Throwable) {
        crashlytics()?.recordException(error)
    }
}
