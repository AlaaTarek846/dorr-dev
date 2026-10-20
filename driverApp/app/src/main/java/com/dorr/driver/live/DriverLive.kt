package com.dorr.driver.live

import android.content.Context
import android.util.Log
import androidx.core.app.NotificationManagerCompat
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.BuildConfig
import com.dorr.driver.duty.Duty
import com.dorr.driver.duty.DriverDutyService
import com.dorr.driver.net.DriverBackend
import com.google.gson.JsonObject
import com.google.gson.JsonParser
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
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch

/** One real-time event: `driver.changed`, `ride.offer`, `ride.message`, `wallet.…` — with its JSON payload. */
data class LiveEvent(val name: String, val data: JsonObject)

/**
 * The captain app's real-time connection — nothing asks the server on a timer. It subscribes to the
 * captain's own private channel and, while a ride runs, to that ride's channel, and reacts:
 *  - `driver.changed` (a request came, was taken or withdrawn, a bid was answered, the ride moved) →
 *    one reload of what the app shows ([requestSync]);
 *  - any other event is a notification (wallet, support, Wasl…): the unread counter follows.
 * Connection details come from the server (`driver/v1/realtime-config`), so moving from Pusher to our own
 * Reverb / Soketi needs no app update. After a lost connection, one reload catches up on what was missed.
 */
object DriverLive {
    private const val TAG = "DriverLive"
    const val DRIVER_CHANGED = "driver.changed"

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val _events = MutableSharedFlow<LiveEvent>(extraBufferCapacity = 128)

    /** Everything that arrived, for the screens that care (the trip, the chat, the inbox, the wallet). */
    val events: SharedFlow<LiveEvent> = _events

    private val _connected = MutableStateFlow(false)
    val connected: StateFlow<Boolean> = _connected

    private val _unread = MutableStateFlow(0)

    /** Unread notifications (the bell). */
    val unread: StateFlow<Int> = _unread

    private var pusher: Pusher? = null
    private var appContext: Context? = null
    private var started = false
    private val syncSignal = Channel<Unit>(Channel.CONFLATED)
    private var syncLoopStarted = false
    private val watchedRides = java.util.concurrent.ConcurrentHashMap.newKeySet<String>()

    /** The app is on screen: a request shows as the in-app card, not as a ringing notification too. */
    @Volatile
    var inForeground = false

    /** Connect (idempotent) for the signed-in captain. */
    fun start(context: Context) {
        appContext = context.applicationContext
        if (!DriverBackend.session.isSignedIn) return
        startSyncLoop()
        if (started) return
        started = true
        scope.launch {
            var wait = 2_000L
            var config: com.dorr.driver.net.RealtimeConfigDto? = null
            for (attempt in 1..8) {
                config = (apiCall { DriverBackend.api.realtimeConfig() } as? ApiResult.Ok)?.data
                if (config != null || !started) break
                Log.w(TAG, "Real-time config failed (try $attempt)")
                delay(wait)
                wait = (wait * 2).coerceAtMost(30_000L)
            }
            if (!started) return@launch
            val cfg = config ?: run { started = false; return@launch }
            // The same answer carries the push app id: start notifications and register this phone.
            DriverPush.ensure(cfg.onesignalAppId)
            if (!cfg.enabled || cfg.key.isNullOrBlank() || cfg.channel.isNullOrBlank()) {
                Log.w(TAG, "Real-time is not configured on the server.")
                return@launch
            }
            connect(cfg)
        }
        refreshUnread()
        requestSync()
    }

    fun stop() {
        started = false
        runCatching { pusher?.disconnect() }
        pusher = null
        watchedRides.clear()
        _connected.value = false
        _unread.value = 0
    }

    /** A screen needs this ride's events (its messages) while it is open. */
    fun watchRide(uuid: String) {
        if (!watchedRides.add(uuid)) return
        pusher?.let { subscribeRide(it, uuid) }
    }

    fun unwatchRide(uuid: String) {
        if (watchedRides.remove(uuid)) runCatching { pusher?.unsubscribe("private-rides.$uuid") }
    }

    /** Reload the requests and the ride once (events arriving together make one reload). */
    fun requestSync() {
        syncSignal.trySend(Unit)
    }

    fun refreshUnread() {
        scope.launch { (apiCall { DriverBackend.api.unreadCount() } as? ApiResult.Ok)?.let { _unread.value = it.data.count } }
    }

    fun setUnread(count: Int) {
        _unread.value = count
    }

    private fun startSyncLoop() {
        if (syncLoopStarted) return
        syncLoopStarted = true
        scope.launch {
            for (signal in syncSignal) {
                val context = appContext ?: continue
                runCatching { sync(context) }.onFailure { Log.w(TAG, "Sync failed", it) }
                delay(300)
            }
        }
    }

    /**
     * Reconciles with the server once: a new request rings (and shows on the card), a withdrawn one goes,
     * and a ride that became mine (an offer taken, a booking at its time) opens.
     */
    suspend fun sync(context: Context) {
        if (!DriverBackend.session.isSignedIn) return
        if (!Duty.onTrip.value) syncOffers(context)
        syncRide()
    }

    /** The offers part alone — what a push that woke the process needs. */
    suspend fun syncOffers(context: Context) {
        val result = apiCall { DriverBackend.api.offers() }
        if (result !is ApiResult.Ok) return
        val open = result.data.filter { it.uuid !in Duty.dismissed }
        if (open.isEmpty()) {
            val hadBid = Duty.offer.value?.bid?.myBid?.status == "pending"
            if (Duty.offer.value != null) NotificationManagerCompat.from(context).cancel(DriverDutyService.ID_OFFER)
            Duty.setOffers(emptyList())
            // My offer was taken by the rider: the ride is mine.
            if (hadBid) syncRide()
            return
        }
        // O-36: several requests may be open at once; each new one rings once.
        open.forEach { offer ->
            if (Duty.seen.add(offer.uuid)) {
                if (Duty.seen.size > 50) Duty.seen.remove(Duty.seen.first())
                // On screen the card is the UI; closed or in the background it rings.
                if (!inForeground) DriverDutyService.ring(context, offer)
            }
        }
        // Fresh each time: a bidding request's price, my offer's state and its timer move.
        Duty.setOffers(open)
    }

    suspend fun syncRide() {
        val current = apiCall { DriverBackend.api.currentRide() }
        if (current is ApiResult.Ok) {
            val has = current.data.isJsonObject && current.data.asJsonObject.has("uuid")
            if (has && !Duty.onTrip.value) {
                Duty.setOnTrip(true)
                Duty.rideChanged()
            } else if (!has && Duty.onTrip.value) {
                Duty.setOnTrip(false)
            }
        }
    }

    private fun connect(cfg: com.dorr.driver.net.RealtimeConfigDto) {
        val token = DriverBackend.session.token ?: return
        val origin = "${BuildConfig.API_SCHEME}://${BuildConfig.API_HOST}"
        val authorizer = HttpChannelAuthorizer(origin + cfg.authPath).apply {
            setHeaders(mapOf("Authorization" to "Bearer $token", "Accept" to "application/json", "ngrok-skip-browser-warning" to "1"))
        }
        val options = PusherOptions().setChannelAuthorizer(authorizer).setUseTLS(cfg.useTls)
        if (cfg.host != null) {
            options.setHost(cfg.host)
            cfg.port?.let { options.setWsPort(it).setWssPort(it) }
        } else {
            options.setCluster(cfg.cluster ?: "mt1")
        }
        val client = Pusher(cfg.key, options)
        pusher = client
        var wasConnected = false
        client.connect(object : ConnectionEventListener {
            override fun onConnectionStateChange(change: ConnectionStateChange) {
                val up = change.currentState == ConnectionState.CONNECTED
                _connected.value = up
                // Back after a gap (the tunnel, the lift…): one reload for anything missed.
                if (up && wasConnected) {
                    requestSync()
                    refreshUnread()
                }
                if (up) wasConnected = true
            }

            override fun onError(message: String?, code: String?, e: Exception?) {
                Log.w(TAG, "Connection error: $message ($code)", e)
            }
        }, ConnectionState.ALL)

        val channel = client.subscribePrivate(cfg.channel, object : PrivateChannelEventListener {
            override fun onEvent(event: PusherEvent) = Unit

            override fun onSubscriptionSucceeded(channelName: String?) {
                // Subscribed: whatever came before is now only a reload away.
                requestSync()
            }

            override fun onAuthenticationFailure(message: String?, e: Exception?) {
                Log.w(TAG, "Channel auth failed: $message", e)
            }
        })
        channel.bindGlobal { event -> handle(event) }
        watchedRides.forEach { subscribeRide(client, it) }
    }

    private fun subscribeRide(client: Pusher, uuid: String) {
        runCatching {
            if (client.getPrivateChannel("private-rides.$uuid") != null) return
            val channel = client.subscribePrivate("private-rides.$uuid", object : PrivateChannelEventListener {
                override fun onEvent(event: PusherEvent) = Unit

                override fun onSubscriptionSucceeded(channelName: String?) = Unit

                override fun onAuthenticationFailure(message: String?, e: Exception?) {
                    Log.w(TAG, "Ride channel auth failed: $message", e)
                }
            })
            channel.bindGlobal { event -> handle(event) }
        }.onFailure { Log.w(TAG, "Ride channel failed", it) }
    }

    private fun handle(event: PusherEvent) {
        val name = event.eventName ?: return
        // Pusher's own bookkeeping.
        if (name.startsWith("pusher:") || name.startsWith("pusher_internal:")) return
        val data = runCatching { JsonParser.parseString(event.data).asJsonObject }.getOrNull() ?: JsonObject()
        _events.tryEmit(LiveEvent(name, data))
        when {
            name == DRIVER_CHANGED || name == "ride.changed" || name == "ride.offer" -> requestSync()
            // A notification row arrived (wallet, support, Wasl…): the bell follows.
            name != "ride.message" && name != "ride.driver_location" -> refreshUnread()
        }
        // A request came in a notification too: sync (the push that rings it may be a few seconds away).
        if (name == "ride.offer") requestSync()
    }
}
