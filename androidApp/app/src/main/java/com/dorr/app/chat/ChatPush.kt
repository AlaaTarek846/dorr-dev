package com.dorr.app.chat

import android.content.Context
import android.util.Log
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.RegisterDeviceRequest
import com.onesignal.OneSignal
import com.onesignal.notifications.INotificationClickEvent
import com.onesignal.notifications.INotificationClickListener
import com.onesignal.notifications.INotificationLifecycleListener
import com.onesignal.notifications.INotificationWillDisplayEvent
import com.onesignal.user.subscriptions.IPushSubscriptionObserver
import com.onesignal.user.subscriptions.PushSubscriptionChangedState
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch

/** Where a tapped notification wants to go. */
sealed interface ChatDeepLink {
    data class Conversation(val id: String) : ChatDeepLink
    data class Call(val id: String, val conversationId: String?) : ChatDeepLink
    /** DORR Moments: a reminder of one of my own dates (or a group card invite). */
    data object Moments : ChatDeepLink
    /** One of my tasks is due (spec 38). */
    data object Tasks : ChatDeepLink
    /** A DORR Calendar reminder (spec 204). */
    data object Calendar : ChatDeepLink
    /** DORR Discover: an event I'm interested in changed, or one I shouldn't miss. */
    data class Discover(val eventId: String?) : ChatDeepLink
    /** DORR Sports: a match I follow (a goal, kick-off, full time…). */
    data class Sports(val matchId: String?) : ChatDeepLink
}

/**
 * Phone notifications (OneSignal). Until now the app never told the server which phone to push
 * to (`notifications/devices` was never called), so no chat — or wallet — push could arrive.
 *
 *  - The OneSignal app id comes from the server (`chat/realtime-config`) and is cached for cold starts.
 *  - The device's subscription id is registered with the server whenever it appears or changes.
 *  - A chat notification is not shown while that very conversation is open on screen.
 *  - Tapping one opens the conversation (or the ringing call) through [deepLink].
 */
object ChatPush {
    private const val TAG = "ChatPush"
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var context: Context? = null
    private var initialised = false
    private var registeredId: String? = null
    private var permissionAsked = false

    private val _deepLink = MutableStateFlow<ChatDeepLink?>(null)
    val deepLink: StateFlow<ChatDeepLink?> = _deepLink

    /** The conversation currently on screen — its notifications stay silent. */
    @Volatile
    var openConversationId: String? = null

    fun attach(appContext: Context) {
        context = appContext.applicationContext
        ChatStore.oneSignalAppId?.let { init(it) }
    }

    /** Called once the server told us the app id (ChatRealtime reads it with the real-time config). */
    fun ensure(appId: String?) {
        if (appId.isNullOrBlank()) return
        if (ChatStore.oneSignalAppId != appId) ChatStore.oneSignalAppId = appId
        init(appId)
        register()
    }

    /** Ask for the notification permission (Android 13+) — once per app run. */
    fun requestPermission() {
        if (!initialised || permissionAsked) return
        permissionAsked = true
        scope.launch(Dispatchers.Main) { runCatching { OneSignal.Notifications.requestPermission(true) } }
    }

    fun consumeDeepLink() {
        _deepLink.value = null
    }

    /** Open something from outside the chat (the calendar's "open the chat"). */
    fun open(link: ChatDeepLink) {
        _deepLink.value = link
    }

    /** This phone's push id — sent with the logout so the server stops pushing to it. */
    fun currentId(): String? =
        if (!initialised) null else runCatching { OneSignal.User.pushSubscription.id }.getOrNull()?.takeIf { it.isNotBlank() }

    fun signedOut() {
        registeredId = null
        runCatching { if (initialised) OneSignal.logout() }
    }

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

            OneSignal.Notifications.addForegroundLifecycleListener(object : INotificationLifecycleListener {
                override fun onWillDisplay(event: INotificationWillDisplayEvent) {
                    val data = event.notification.additionalData
                    val conversation = data?.optString("conversation_uuid")?.takeIf { it.isNotBlank() }
                    // A goal, a kick-off…: the home-screen widgets read the new score.
                    if (data?.optString("type") == "sports") context?.let { com.dorr.app.widget.SportsWidgets.refreshNow(it) }
                    // Already reading that chat: the message is on screen, no banner needed.
                    if (conversation != null && conversation == openConversationId && data.optString("type") == "chat") {
                        event.preventDefault()
                    }
                }
            })

            OneSignal.Notifications.addClickListener(object : INotificationClickListener {
                override fun onClick(event: INotificationClickEvent) {
                    val data = event.notification.additionalData ?: return
                    val conversation = data.optString("conversation_uuid").takeIf { it.isNotBlank() }
                    _deepLink.value = when {
                        data.optString("type") == "chat_call" && data.optString("call_id").isNotBlank() ->
                            ChatDeepLink.Call(data.optString("call_id"), conversation)
                        conversation != null -> ChatDeepLink.Conversation(conversation)
                        data.optString("type") == "tasks" -> ChatDeepLink.Tasks
                        data.optString("type") == "calendar" -> ChatDeepLink.Calendar
                        data.optString("type") == "sports" -> ChatDeepLink.Sports(data.optString("match_id").takeIf { it.isNotBlank() && it != "null" })
                        data.optString("type") == "discover" -> ChatDeepLink.Discover(data.optString("event_id").takeIf { it.isNotBlank() && it != "null" })
                        data.optString("type") == "moments" || data.optString("event") == "chat.collab.invited" -> ChatDeepLink.Moments
                        else -> null
                    }
                }
            })
        }.onFailure { Log.w(TAG, "OneSignal init failed", it) }
    }

    /** Tell the server this phone's subscription id (only when it changed). */
    private fun register() {
        if (!initialised) return
        val token = AuthSession.token ?: return
        val id = runCatching { OneSignal.User.pushSubscription.id }.getOrNull()?.takeIf { it.isNotBlank() } ?: return
        if (id == registeredId) return
        scope.launch {
            runCatching { ApiClient.notifications.registerDevice("Bearer $token", RegisterDeviceRequest(playerId = id)) }
                .onSuccess { registeredId = id }
                .onFailure { Log.w(TAG, "Device registration failed", it) }
        }
    }
}
