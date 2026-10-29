package com.dorr.freelance.net

import android.content.Context
import android.util.Log
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.onesignal.OneSignal
import com.onesignal.notifications.INotificationClickEvent
import com.onesignal.notifications.INotificationClickListener
import com.pusher.client.Pusher
import com.pusher.client.PusherOptions
import com.pusher.client.channel.PrivateChannelEventListener
import com.pusher.client.channel.PusherEvent
import com.pusher.client.connection.ConnectionEventListener
import com.pusher.client.connection.ConnectionState
import com.pusher.client.connection.ConnectionStateChange
import com.pusher.client.util.HttpChannelAuthorizer
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/**
 * How things reach the freelancer's phone without the app ever asking on a timer (FL-007):
 *
 *  - **Live:** one connection to the real-time server, subscribed to the freelancer's own private channel. Every notice the
 *    server raises arrives on it; [changed] fires once per notice and the screens that care reload once.
 *  - **Push:** OneSignal wakes the phone when the app is closed; this phone's push id is sent to the server on every open
 *    (an id can move between accounts) and removed on sign-out.
 *
 * Both start when the freelancer signs in and stop when the session ends (see FreelanceApp). The connection settings and the
 * push app id come from `GET realtime-config`, asked once per sign-in.
 */
object Realtime {
    private const val TAG = "Realtime"

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var appContext: Context? = null
    private var pusher: Pusher? = null
    private var oneSignalReady = false
    private var registeredId: String? = null
    @Volatile private var registering: String? = null

    private val _changed = MutableSharedFlow<Unit>(extraBufferCapacity = 16)

    /** A notice just arrived: the account, the unread count or the list may have changed. */
    val changed: SharedFlow<Unit> = _changed

    private val _events = MutableSharedFlow<LiveEvent>(extraBufferCapacity = 64)

    /** Support's live events (`support.message`, `support.ticket.updated`…) with their payload: an open ticket list or conversation updates itself. */
    val events: SharedFlow<LiveEvent> = _events

    private val _connected = MutableStateFlow(false)
    val connected: StateFlow<Boolean> = _connected.asStateFlow()

    private val _unread = MutableStateFlow(0)

    /** The number of unread notices (the dot on the bell). */
    val unread: StateFlow<Int> = _unread.asStateFlow()

    private val _opened = MutableStateFlow<String?>(null)

    /** The event of a notification the freelancer tapped in the tray, until the navigation has consumed it. */
    val opened: StateFlow<String?> = _opened.asStateFlow()

    private val _openedRoute = MutableStateFlow<String?>(null)

    /** The screen a tapped notification leads to (see [NoticeRoute]); null: the notifications page. */
    val openedRoute: StateFlow<String?> = _openedRoute.asStateFlow()

    fun consumeOpened() {
        _opened.value = null
        _openedRoute.value = null
    }

    fun attach(context: Context) {
        appContext = context.applicationContext
    }

    /** Connects (idempotent) for the signed-in freelancer. */
    fun start() {
        if (pusher != null || Session.token == null) return
        scope.launch {
            val config = (apiCall { ApiClient.notifications.realtimeConfig() } as? ApiResult.Ok)?.data
            if (Session.token == null || pusher != null) return@launch
            if (config?.oneSignalAppId != null) startPush(config.oneSignalAppId)
            if (config == null || !config.enabled || config.key.isNullOrBlank() || config.channel.isNullOrBlank()) {
                Log.w(TAG, "Real-time is not configured on the server.")
                refreshUnread()
                return@launch
            }
            connect(config)
            refreshUnread()
        }
    }

    private fun connect(config: RealtimeConfigDto) {
        val authorizer = HttpChannelAuthorizer(ApiClient.origin + config.authPath).apply {
            setHeaders(mapOf("Authorization" to "Bearer ${Session.token.orEmpty()}", "Accept" to "application/json", "ngrok-skip-browser-warning" to "1"))
        }
        val options = PusherOptions().setChannelAuthorizer(authorizer).setUseTLS(config.useTls)
        if (config.host != null) {
            options.setHost(config.host)
            config.port?.let { options.setWsPort(it).setWssPort(it) }
        } else {
            options.setCluster(config.cluster ?: "mt1")
        }
        val client = Pusher(config.key, options)
        pusher = client

        client.connect(object : ConnectionEventListener {
            override fun onConnectionStateChange(change: ConnectionStateChange) {
                Log.i(TAG, "Connection: ${change.previousState} -> ${change.currentState}")
                val now = change.currentState == ConnectionState.CONNECTED
                // Back after a drop: one catch-up request (nothing is polled while connected).
                if (now && !_connected.value && change.previousState == ConnectionState.RECONNECTING) _changed.tryEmit(Unit)
                _connected.value = now
            }

            override fun onError(message: String?, code: String?, e: Exception?) {
                Log.w(TAG, "Connection error: $message ($code)", e)
            }
        }, ConnectionState.ALL)

        val channel = client.subscribePrivate(config.channel, object : PrivateChannelEventListener {
            override fun onEvent(event: PusherEvent) = Unit

            override fun onSubscriptionSucceeded(channelName: String?) {
                Log.i(TAG, "Subscribed to $channelName")
            }

            override fun onAuthenticationFailure(message: String?, e: Exception?) {
                Log.w(TAG, "Channel authorization failed: $message", e)
            }
        })
        // A notice is broadcast under its own name (`freelance.verification.approved`, `freelance.offer.accepted`…): any event on
        // this private channel means "something new", so one global listener is enough.
        channel.bindGlobal { event ->
            if (event.eventName.startsWith("pusher:")) return@bindGlobal
            Log.i(TAG, "Live event: ${event.eventName}")
            if (event.eventName.startsWith("support.")) {
                runCatching { com.google.gson.JsonParser.parseString(event.data).asJsonObject }.getOrNull()?.let { _events.tryEmit(LiveEvent(event.eventName, it)) }
            }
            _changed.tryEmit(Unit)
            scope.launch { refreshUnread() }
        }
    }

    /** Reads the unread count (one request) — also after the screen marked something as read. */
    suspend fun refreshUnread() {
        val result = apiCall { ApiClient.notifications.unreadCount() }
        if (result is ApiResult.Ok) _unread.value = result.data.count
    }

    fun setUnread(count: Int) {
        _unread.value = count
    }

    // ---------------------------------------------------------------- push

    private fun startPush(appId: String) {
        val ctx = appContext ?: return
        if (oneSignalReady) {
            registerDevice()
            return
        }
        // The SDK builds handlers while it starts, so it must start on the main thread.
        scope.launch(Dispatchers.Main) {
          runCatching {
            OneSignal.initWithContext(ctx, appId)
            oneSignalReady = true
            OneSignal.User.pushSubscription.addObserver(object : com.onesignal.user.subscriptions.IPushSubscriptionObserver {
                override fun onPushSubscriptionChange(state: com.onesignal.user.subscriptions.PushSubscriptionChangedState) {
                    registerDevice()
                }
            })
            OneSignal.Notifications.addClickListener(object : INotificationClickListener {
                override fun onClick(event: INotificationClickEvent) {
                    val data = event.notification.additionalData
                    val type = data?.optString("type")?.takeIf { it.isNotBlank() } ?: "freelance"
                    _openedRoute.value = NoticeRoute.resolve(data?.optString("event")) { key -> data?.optString(key)?.takeIf { it.isNotBlank() } }
                    _opened.value = type
                }
            })
            registerDevice()
          }.onFailure { Log.w(TAG, "OneSignal init failed", it) }
        }
    }

    /** Whether this phone receives push (the Notifications switch in the settings). */
    fun pushOptedIn(): Boolean = runCatching { OneSignal.User.pushSubscription.optedIn }.getOrDefault(true)

    /** Turns this phone's push on or off without signing out. */
    fun setPushOptedIn(on: Boolean) {
        runCatching { if (on) OneSignal.User.pushSubscription.optIn() else OneSignal.User.pushSubscription.optOut() }
    }

    /** Asks for the notification permission (Android 13+) — call once, after the first screen. */
    fun requestPushPermission() {
        if (!oneSignalReady) return
        scope.launch(Dispatchers.Main) { runCatching { OneSignal.Notifications.requestPermission(true) } }
    }

    /** This phone's push id, sent with the logout so the server stops pushing to it. */
    fun pushId(): String? =
        if (!oneSignalReady) null else runCatching { OneSignal.User.pushSubscription.id }.getOrNull()?.takeIf { it.isNotBlank() }

    private fun registerDevice() {
        val id = pushId() ?: return
        if (Session.token == null) return
        // Once per push id (the SDK reports the same subscription several times while it starts).
        if (id == registeredId || id == registering) return
        registering = id
        scope.launch {
            val result = apiCall { ApiClient.notifications.registerDevice(RegisterDeviceRequest(id)) }
            if (result is ApiResult.Ok) registeredId = id else Log.w(TAG, "Device registration failed")
            registering = null
        }
    }

    // ---------------------------------------------------------------- stop

    /** The session ended: close the live connection and let go of this phone's push id. */
    fun stop() {
        runCatching { pusher?.disconnect() }
        pusher = null
        _connected.value = false
        _unread.value = 0
        registeredId = null
        runCatching { if (oneSignalReady) OneSignal.logout() }
    }
}
