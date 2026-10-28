package com.dorr.freelance.net

import android.content.Context
import com.dorr.core.session.TokenStore
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * The freelancer's session: the `freelance_api` token and this install's device id, in their own file (never the
 * DORR app's or the driver app's, even on the same phone). [signedIn] is what the screens follow — a 401 on a request
 * that carried the token ends the session, and the navigation goes back to the start page by itself.
 */
object Session {
    private const val STORE = "dorr_freelance_session"

    private var store: TokenStore? = null
    private val _signedIn = MutableStateFlow(false)

    val signedIn: StateFlow<Boolean> = _signedIn.asStateFlow()

    /** Call once at startup (FreelanceApp.onCreate). */
    fun attach(context: Context) {
        store = TokenStore(context, STORE).also { _signedIn.value = it.isSignedIn }
    }

    val token: String? get() = store?.token

    private val _me = MutableStateFlow<FreelancerDto?>(null)

    /** The freelancer as the server last described them (`auth/me`, or the sign-in's answer); in memory only. */
    val me: StateFlow<FreelancerDto?> = _me.asStateFlow()

    fun setMe(freelancer: FreelancerDto?) {
        _me.value = freelancer
    }

    /** Stable per install; sent as `X-Device-Id` and with the sign-in so the server lists this phone under my devices. */
    val deviceId: String get() = store?.deviceId.orEmpty()

    fun signIn(token: String) {
        store?.token = token
        _signedIn.value = true
    }

    fun clear() {
        store?.clear()
        _me.value = null
        _signedIn.value = false
    }
}
