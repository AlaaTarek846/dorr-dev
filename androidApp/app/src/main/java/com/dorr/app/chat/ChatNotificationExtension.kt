package com.dorr.app.chat

import com.onesignal.notifications.INotificationReceivedEvent
import com.onesignal.notifications.INotificationServiceExtension

/**
 * Runs for every push the moment it arrives — even when the app is closed (registered in the
 * manifest as OneSignal's NotificationServiceExtension).
 *
 *  - An incoming call is not shown as a plain banner: it becomes the ringing call notification
 *    (full-screen page, looping ringtone, Answer / Decline).
 *  - A missed / cancelled call stops that ringing and lets the "missed call" notification show.
 *  - While the app is open, the in-app ringing screen already handles the call, so no notification.
 *  - A message the sender sent "without sound" shows silently (no sound, no vibration).
 */
class ChatNotificationExtension : INotificationServiceExtension {
    override fun onNotificationReceived(event: INotificationReceivedEvent) {
        // Nobody signed in on this phone (logged out, or a push sent before the server forgot this
        // phone): nothing is shown — no message, no ringing.
        if (!com.dorr.app.network.AuthSession.isSignedIn(event.context)) {
            event.preventDefault()
            return
        }
        val data = event.notification.additionalData
        // No data (a broadcast from the dashboard): an ordinary notification — that still pops down.
        if (data == null) {
            event.notification.setExtender { PushChannels.popDown(event.context, it, chat = false) }
            return
        }
        // A message sent "without sound": shown as usual, but no sound and no vibration.
        // Nothing shown / privacy mode: folded into one "N new messages" that names nobody.
        // P4 (a circle that shows nothing): no notification at all — the app's counter updates on its own.
        if (data.optString("type") == "chat" && data.optString("hidden") == "1") {
            event.preventDefault()
            return
        }
        if (data.optString("type") == "chat" && data.optString("private") == "1") {
            event.preventDefault()
            ChatShield.showPrivate(event.context)
            return
        }
        if (data.optString("type") == "chat" && data.optString("silent") == "1") {
            event.notification.setExtender { builder -> PushChannels.popDown(event.context, builder, chat = true).setSilent(true) }
            return
        }
        // This chat has its own tone (or "none") picked on this phone; and local intelligence
        // (spec 156): its kind, my words, codes and money kept off the lock screen — all here.
        if (data.optString("type") == "chat") {
            val conversationId = data.optString("conversation_uuid")
            val tone = conversationId.isNotBlank() && ChatTones.get(event.context, conversationId) != null
            val local = LocalIntelligence.enabled(event.context)
            val title = event.notification.title
            val body = event.notification.body
            event.notification.setExtender { builder ->
                // Pops down from the top like WhatsApp; a chat's own tone keeps its own channel.
                PushChannels.popDown(event.context, builder, chat = true).also {
                    if (tone) ChatTones.apply(event.context, conversationId, it)
                    if (local) LocalIntelligence.apply(event.context, it, title, body)
                }
            }
            return
        }
        if (data.optString("type") != "chat_call") {
            // Everything else (Discover, Sports, wallet…): pops down too.
            event.notification.setExtender { PushChannels.popDown(event.context, it, chat = false) }
            return
        }

        val callId = data.optString("call_id")
        when (data.optString("event")) {
            "chat.call.ringing" -> {
                event.preventDefault()
                // App on screen: CallOverlay rings already (from the real-time event).
                if (ChatRealtime.inForeground && CallController.phase != null) return
                CallNotifications.showIncoming(
                    context = event.context,
                    callId = callId,
                    conversationId = data.optString("conversation_uuid").takeIf { it.isNotBlank() },
                    callerName = data.optString("caller_name").ifBlank { event.notification.title.orEmpty() },
                    video = data.optString("call_type") == "video",
                )
            }
            "chat.call.missed" -> {
                CallNotifications.cancel(event.context)
                CallController.onRemoteEnded(callId)
            }
        }
    }
}

/**
 * Where a push shows. OneSignal's own "Miscellaneous" channel only lands in the shade — the
 * banner that drops down over the screen (heads-up) needs a channel of high importance, so
 * every push goes through one of ours: Messages (chats) or Notifications (the rest). Both stay
 * in the phone's settings for the user to tune.
 */
object PushChannels {
    private const val MESSAGES = "dorr_messages"
    private const val GENERAL = "dorr_notifications"

    fun popDown(context: android.content.Context, builder: androidx.core.app.NotificationCompat.Builder, chat: Boolean): androidx.core.app.NotificationCompat.Builder {
        val id = if (chat) MESSAGES else GENERAL
        val manager = context.getSystemService(android.app.NotificationManager::class.java)
        if (manager != null && manager.getNotificationChannel(id) == null) {
            val name = context.getString(if (chat) com.dorr.app.R.string.push_channel_messages else com.dorr.app.R.string.push_channel_general)
            manager.createNotificationChannel(
                android.app.NotificationChannel(id, name, android.app.NotificationManager.IMPORTANCE_HIGH).apply {
                    enableVibration(true)
                    lockscreenVisibility = android.app.Notification.VISIBILITY_PRIVATE
                },
            )
        }
        return builder
            .setChannelId(id)
            .setPriority(androidx.core.app.NotificationCompat.PRIORITY_HIGH)
            .setCategory(if (chat) androidx.core.app.NotificationCompat.CATEGORY_MESSAGE else androidx.core.app.NotificationCompat.CATEGORY_SOCIAL)
            .setSmallIcon(com.dorr.app.R.drawable.ic_stat_onesignal_default)
            .setColor(0xFF001B53.toInt())
    }
}
