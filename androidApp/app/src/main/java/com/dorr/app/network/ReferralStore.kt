package com.dorr.app.network

import android.content.Context
import android.content.SharedPreferences
import java.util.regex.Pattern

/**
 * Holds a referral code until the person is signed in and the backend accepts it.
 * The same store is filled from share/copy today and from Play Install Referrer later.
 */
object ReferralStore {
    private const val PREFS = "dorr_referral"
    private const val KEY_PENDING = "pending_code"
    private const val KEY_REFERRER_READ = "install_referrer_read"

    private val codePattern: Pattern = Pattern.compile("DORRFC-[A-Z0-9]{6}", Pattern.CASE_INSENSITIVE)

    private var prefs: SharedPreferences? = null

    fun attach(context: Context) {
        prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
    }

    fun extract(raw: String?): String? {
        if (raw.isNullOrBlank()) return null
        val match = codePattern.matcher(raw.uppercase())
        return if (match.find()) match.group() else null
    }

    fun remember(raw: String?) {
        val code = extract(raw) ?: return
        prefs?.edit()?.putString(KEY_PENDING, code)?.apply()
    }

    fun pending(): String? = prefs?.getString(KEY_PENDING, null)

    fun clearPending() {
        prefs?.edit()?.remove(KEY_PENDING)?.apply()
    }

    fun installReferrerAlreadyRead(): Boolean = prefs?.getBoolean(KEY_REFERRER_READ, false) == true

    fun markInstallReferrerRead() {
        prefs?.edit()?.putBoolean(KEY_REFERRER_READ, true)?.apply()
    }
}
