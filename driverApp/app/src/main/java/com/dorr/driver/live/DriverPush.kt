package com.dorr.driver.live

import android.content.Context
import android.util.Log
import com.dorr.core.network.apiCall
import com.dorr.driver.net.DriverBackend
import com.google.gson.JsonObject
import com.onesignal.OneSignal
import com.onesignal.notifications.INotificationClickEvent
import com.onesignal.notifications.INotificationClickListener
import com.onesignal.user.subscriptions.IPushSubscriptionObserver
import com.onesignal.user.subscriptions.PushSubscriptionChangedState
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * Phone push for the captain app (OneSignal) — what reaches a captain whose app is closed.
 *  - The app id comes from the server (`driver/v1/realtime-config`, the captain's own OneSignal app
 *    when the owner set one) and is cached for cold starts, so it can change without an app release.
 *  - This phone's subscription id is registered with the server whenever it appears or changes.
 *  - A ride request push never shows as a plain banner: [DriverPushExtension] turns it into the
 *    ringing, full-screen request with Accept / Decline.
 */
object DriverPush {
    private const val TAG = "DriverPush"
    private const val PREFS = "driver_push"
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var context: Context? = null
    private var initialised = false
    private var registeredId: String? = null
    private var permissionAsked = false

    fun attach(appContext: Context) {
        context = appContext.applicationContext
        cachedAppId()?.let { init(it) }
    }

    /** Called once the server told us the app id. */
    fun ensure(appId: String?) {
        if (appId.isNullOrBlank()) return
        if (cachedAppId() != appId) context?.getSharedPreferences(PREFS, Context.MODE_PRIVATE)?.edit()?.putString("app_id", appId)?.apply()
        init(appId)
        register()
    }

    /** Ask for the notification permission (Android 13+) — once per run. */
    fun requestPermission() {
        if (!initialised || permissionAsked) return
        permissionAsked = true
        scope.launch(Dispatchers.Main) { runCatching { OneSignal.Notifications.requestPermission(true) } }
    }

    /** Signing out: this phone stops getting this captain's pushes. */
    suspend fun signedOut() {
        val id = runCatching { if (initialised) OneSignal.User.pushSubscription.id else null }.getOrNull()?.takeIf { it.isNotBlank() }
        if (id != null) apiCall { DriverBackend.api.unregisterDevice(JsonObject().apply { addProperty("player_id", id) }) }
        registeredId = null
        runCatching { if (initialised) OneSignal.logout() }
    }

    private fun cachedAppId(): String? = context?.getSharedPreferences(PREFS, Context.MODE_PRIVATE)?.getString("app_id", null)

    private fun init(appId: String) {
        val ctx = context ?: return
        if (initialised) return
        runCatching {
            OneSignal.initWithContext(ctx, appId)
            initialised = true
            OneSignal.User.pushSubscription.addObserver(object : IPushSubscriptionObserver {
                override fun onPushSubscriptionChange(state: PushSubscriptionChangedState) {
                    register()
                }
            })
            // A tap on an ordinary push: the app opens (a request's own tap is handled by its notification).
            OneSignal.Notifications.addClickListener(object : INotificationClickListener {
                override fun onClick(event: INotificationClickEvent) {
                    DriverLive.requestSync()
                }
            })
        }.onFailure { Log.w(TAG, "OneSignal init failed", it) }
    }

    /** Tell the server this phone's subscription id (only when it changed). */
    private fun register() {
        if (!initialised || !DriverBackend.session.isSignedIn) return
        val id = runCatching { OneSignal.User.pushSubscription.id }.getOrNull()?.takeIf { it.isNotBlank() } ?: return
        if (id == registeredId) return
        scope.launch {
            val body = JsonObject().apply { addProperty("player_id", id); addProperty("platform", "android") }
            val ok = apiCall { DriverBackend.api.registerDevice(body) } is com.dorr.core.network.ApiResult.Ok
            if (ok) registeredId = id else Log.w(TAG, "Device registration failed")
        }
    }
}
