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
        val data = event.notification.additionalData ?: return
        // A message sent "without sound": shown as usual, but no sound and no vibration.
        if (data.optString("type") == "chat" && data.optString("silent") == "1") {
            event.notification.setExtender { builder -> builder.setSilent(true) }
            return
        }
        if (data.optString("type") != "chat_call") return

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
