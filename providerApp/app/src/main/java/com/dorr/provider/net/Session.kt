package com.dorr.provider.net

import android.content.Context
import android.content.SharedPreferences
import com.dorr.core.session.TokenStore
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * The provider's session: the `provider_api` token and this install's device id, in their own file (never the DORR app's,
 * the driver app's or the freelancer app's, even on the same phone). [signedIn] is what the screens follow — a 401 on a
 * request that carried the token ends the session, and the navigation goes back to the sign-in by itself.
 *
 * It also keeps **the establishment the app works in** ([establishment], its uuid): one person may hold seats in several
 * establishments (docs/moving_business.md §14), and every request carries the chosen one as `X-Establishment` (ApiClient).
 * The choice is kept on the phone so the app opens on the same establishment next time.
 */
object Session {
    private const val STORE = "dorr_provider_session"
    private const val WORKSPACE = "dorr_provider_workspace"
    private const val KEY_ESTABLISHMENT = "establishment_uuid"

    private var store: TokenStore? = null
    private var workspacePrefs: SharedPreferences? = null
    private val _signedIn = MutableStateFlow(false)

    val signedIn: StateFlow<Boolean> = _signedIn.asStateFlow()

    /** Call once at startup (ProviderApp.onCreate). */
    fun attach(context: Context) {
        store = TokenStore(context, STORE).also { _signedIn.value = it.isSignedIn }
        workspacePrefs = context.applicationContext.getSharedPreferences(WORKSPACE, Context.MODE_PRIVATE).also {
            _establishment.value = it.getString(KEY_ESTABLISHMENT, null)
        }
    }

    val token: String? get() = store?.token

    private val _me = MutableStateFlow<ProviderDto?>(null)

    /** The person as the server last described them (`me`, or the sign-in's answer); in memory only. */
    val me: StateFlow<ProviderDto?> = _me.asStateFlow()

    fun setMe(provider: ProviderDto?) {
        _me.value = provider
    }

    private val _establishment = MutableStateFlow<String?>(null)

    /** The uuid of the establishment the app works in, sent as `X-Establishment`; null before one is chosen. */
    val establishment: StateFlow<String?> = _establishment.asStateFlow()

    private val _membership = MutableStateFlow<MembershipDto?>(null)

    /** My seat in that establishment (`GET establishment` → membership): its permissions decide which parts show (§17). */
    val membership: StateFlow<MembershipDto?> = _membership.asStateFlow()

    /** Works in [uuid] from now on (kept on the phone); the seat is read again for it. */
    fun chooseEstablishment(uuid: String?) {
        if (_establishment.value != uuid) _membership.value = null
        _establishment.value = uuid
        workspacePrefs?.edit()?.apply { if (uuid == null) remove(KEY_ESTABLISHMENT) else putString(KEY_ESTABLISHMENT, uuid) }?.apply()
    }

    fun setMembership(membership: MembershipDto?) {
        _membership.value = membership
    }

    /** Whether my seat holds [permission] (the owner holds them all; the server checks every one again). */
    fun can(permission: String): Boolean {
        val seat = _membership.value ?: return false
        return seat.isOwner || permission in seat.permissions
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
        _membership.value = null
        chooseEstablishment(null)
        Workspace.clear()
        _signedIn.value = false
    }
}
