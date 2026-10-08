package com.dorr.app.chat

import android.util.Log
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
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
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/** One real-time chat event (`chat.message.sent`, `chat.typing`…) with its JSON payload. */
data class ChatEvent(val name: String, val data: JsonObject)

/**
 * The app's single real-time connection for the chat. It subscribes to the account's own private
 * channel (`private-Modules.User.Models.User.{id}`) and fans events out through [events] to
 * whatever chat screen is open.
 *
 * Connection details come from the server (`GET chat/realtime-config`), never from the app, so
 * switching from Pusher to our own Soketi / Reverb server needs no app update. While the app is in
 * the foreground it also keeps the "online" presence alive with a heartbeat.
 */
object ChatRealtime {
    private const val TAG = "ChatRealtime"

    val EVENTS = listOf(
        "chat.message.sent", "chat.message.updated", "chat.message.deleted",
        "chat.receipt", "chat.reaction", "chat.typing", "chat.presence",
        "chat.conversation.updated", "chat.pins.updated",
        "chat.call.ringing", "chat.call.accepted", "chat.call.declined", "chat.call.left", "chat.call.ended",
        "chat.story.posted", "chat.story.deleted", "chat.story.viewed", "chat.story.reaction",
        "chat.poll.updated", "chat.view_once.opened", "chat.location.moved",
        "chat.group.join_requests", "chat.group.join_decided",
        "chat.scheduled.changed", "chat.reminder.due",
        // DORR Sports: an alert for a match I follow (the goal moment, 197–198).
        "sports.alert", "sports.prize",
    )

    /** Public channels some screen wants (DORR Sports live scores), kept across reconnects. */
    private val publicChannels = java.util.concurrent.ConcurrentHashMap<String, List<String>>()

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val _events = MutableSharedFlow<ChatEvent>(extraBufferCapacity = 128)
    val events: SharedFlow<ChatEvent> = _events

    private val _connected = MutableStateFlow(false)
    val connected: StateFlow<Boolean> = _connected

    private var pusher: Pusher? = null
    private var userId: Int? = null
    private var heartbeat: Job? = null

    /** Connect (idempotent) for the signed-in user. */
    fun start() {
        val token = AuthSession.token ?: return
        val id = AuthSession.user?.id ?: return
        if (pusher != null && userId == id) return
        stop()
        userId = id

        scope.launch {
            val config = runCatching { ApiClient.chat.realtimeConfig("Bearer $token").data }.getOrNull()
            // The same answer carries the push app id: start notifications and register this phone.
            ChatPush.ensure(config?.oneSignalAppId)
            if (config == null || !config.enabled || config.key.isNullOrBlank()) {
                Log.w(TAG, "Real-time is not configured on the server.")
                return@launch
            }

            val authorizer = HttpChannelAuthorizer(ApiClient.ORIGIN + config.authPath).apply {
                setHeaders(mapOf("Authorization" to "Bearer $token", "Accept" to "application/json", "ngrok-skip-browser-warning" to "1"))
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
                    _connected.value = change.currentState == ConnectionState.CONNECTED
                }

                override fun onError(message: String?, code: String?, e: Exception?) {
                    Log.w(TAG, "Connection error: $message ($code)", e)
                }
            }, ConnectionState.ALL)

            val listener = object : PrivateChannelEventListener {
                override fun onEvent(event: PusherEvent) {
                    val data = runCatching { JsonParser.parseString(event.data).asJsonObject }.getOrNull() ?: return
                    _events.tryEmit(ChatEvent(event.eventName, data))
                }

                override fun onSubscriptionSucceeded(channelName: String?) {
                    // Anything that arrived while we were away is now on this phone: grey double ticks.
                    scope.launch { runCatching { ApiClient.chat.markDelivered("Bearer $token") } }
                }

                override fun onAuthenticationFailure(message: String?, e: Exception?) {
                    Log.w(TAG, "Channel auth failed: $message", e)
                }
            }

            client.subscribePrivate("private-Modules.User.Models.User.$id", listener, *EVENTS.toTypedArray())
            publicChannels.forEach { (name, events) -> subscribePublic(client, name, events) }
        }
    }

    /**
     * Listen to a public channel (e.g. `sports.match.{id}`, `sports.live`); its events arrive on
     * [events] like the private ones. Safe to call before the connection is up.
     */
    fun watchPublic(channel: String, events: List<String>) {
        if (publicChannels.put(channel, events) == null) pusher?.let { subscribePublic(it, channel, events) }
    }

    fun unwatchPublic(channel: String) {
        if (publicChannels.remove(channel) != null) runCatching { pusher?.unsubscribe(channel) }
    }

    private fun subscribePublic(client: Pusher, channel: String, events: List<String>) {
        runCatching {
            if (client.getChannel(channel) != null) return
            client.subscribe(channel, object : com.pusher.client.channel.ChannelEventListener {
                override fun onEvent(event: PusherEvent) {
                    val data = runCatching { JsonParser.parseString(event.data).asJsonObject }.getOrNull()
                    if (data != null) _events.tryEmit(ChatEvent(event.eventName, data))
                }

                override fun onSubscriptionSucceeded(channelName: String?) = Unit
            }, *events.toTypedArray())
        }.onFailure { Log.w(TAG, "Public channel $channel failed", it) }
    }

    fun stop() {
        heartbeat?.cancel()
        heartbeat = null
        runCatching { pusher?.disconnect() }
        pusher = null
        userId = null
        _connected.value = false
    }

    /** The app is on screen (not just alive in the background) — decides in-app vs notification ringing. */
    @Volatile
    var inForeground = false
        private set

    /** App came to the foreground: say "online" now and every minute (the server forgets after 90s). */
    fun onForeground() {
        inForeground = true
        val token = AuthSession.token ?: return
        heartbeat?.cancel()
        heartbeat = scope.launch {
            runCatching { ApiClient.chat.markDelivered("Bearer $token") }
            while (isActive) {
                runCatching { ApiClient.chat.presence("Bearer $token", mapOf("online" to true)) }
                delay(60_000)
            }
        }
    }

    /** App went to the background: "last seen now". */
    fun onBackground() {
        inForeground = false
        val token = AuthSession.token ?: return
        heartbeat?.cancel()
        heartbeat = null
        scope.launch { runCatching { ApiClient.chat.presence("Bearer $token", mapOf("online" to false)) } }
    }

    /** Lets a screen feed an event it produced itself (e.g. an optimistic message) to the others. */
    internal fun emitLocal(event: ChatEvent) {
        _events.tryEmit(event)
    }
}
