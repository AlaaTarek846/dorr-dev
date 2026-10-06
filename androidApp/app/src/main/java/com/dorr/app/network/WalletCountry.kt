package com.dorr.app.network

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

/**
 * Which of my wallets the wallet screens work on (one wallet per country, docs/wallet-plan.md §7).
 *
 * By default it's the wallet of where I am (the server's country for my sign-in). Someone with a
 * Saudi wallet who is in Egypt can switch to it: every wallet request then carries `X-Country: SA`,
 * so the Saudi wallet shows its own number / QR, receives, and sends to Saudi numbers and wallets
 * only — a wallet never pays another country's wallets. Kept on the phone across visits.
 */
object WalletCountry {
    private const val PREFS = "dorr_wallet_country"

    /** The wallet I chose (ISO code), or null = the one of where I am. */
    var selected by mutableStateOf<String?>(null)
        private set

    /** The country of where I am (the wallet the server opens without a choice) — learnt on the first look. */
    var here by mutableStateOf<String?>(null)

    private var loaded = false
    private var appContext: Context? = null

    fun load(context: Context) {
        if (loaded) return
        appContext = context.applicationContext
        val prefs = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        selected = prefs.getString("selected", null)
        here = prefs.getString("here", null)
        loaded = true
    }

    /** The server opened its default wallet (no choice made): that's where I am. */
    fun learnHere(code: String?) {
        if (code.isNullOrBlank() || code.equals(here, ignoreCase = true)) return
        here = code.uppercase()
        appContext?.getSharedPreferences(PREFS, Context.MODE_PRIVATE)?.edit()?.putString("here", here)?.apply()
    }

    fun select(context: Context, code: String?) {
        val value = code?.uppercase()?.takeIf { it != here?.uppercase() }
        selected = value
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString("selected", value).apply()
    }

    /** Signing out forgets the choice (the next account starts where it is). */
    fun forget() {
        selected = null
        here = null
        appContext?.getSharedPreferences(PREFS, Context.MODE_PRIVATE)?.edit()?.clear()?.apply()
    }

    /** For the HTTP client: the header value for a wallet request, if I chose another wallet. */
    fun headerFor(path: String): String? = selected?.takeIf { path.contains("/v1/wallet") }
}
