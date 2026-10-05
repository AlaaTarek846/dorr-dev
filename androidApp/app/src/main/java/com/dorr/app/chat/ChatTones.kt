package com.dorr.app.chat

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.net.Uri
import androidx.core.app.NotificationCompat

/**
 * A notification tone per chat (like WhatsApp's "custom notifications"), kept on this phone only.
 *
 * Since Android 8 a sound belongs to a notification channel, not to a notification, so each tone
 * gets its own channel ("chat_tone_<hash>"); a message of that chat is moved onto it as it
 * arrives (ChatNotificationExtension). "None" is a silent channel. No entry = the app's default.
 */
object ChatTones {
    private const val PREFS = "chat_tones"
    const val SILENT = "none"

    /** The chat's tone: a sound uri, [SILENT], or null for the default. */
    fun get(context: Context, conversationId: String): String? =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(conversationId, null)

    fun set(context: Context, conversationId: String, tone: String?) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().apply {
            if (tone == null) remove(conversationId) else putString(conversationId, tone)
        }.apply()
    }

    /** The tone's name as the phone shows it ("Chime"), or null for the default. */
    fun title(context: Context, tone: String?): String? = when (tone) {
        null -> null
        SILENT -> null
        else -> runCatching { RingtoneManager.getRingtone(context, Uri.parse(tone))?.getTitle(context) }.getOrNull()
    }

    /** Puts a message of this chat on its tone's channel (made the first time it's needed). */
    fun apply(context: Context, conversationId: String, builder: NotificationCompat.Builder) {
        val tone = get(context, conversationId) ?: return
        val id = "chat_tone_" + Integer.toHexString(tone.hashCode())
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        if (manager.getNotificationChannel(id) == null) {
            val channel = NotificationChannel(id, title(context, tone) ?: "Silent", NotificationManager.IMPORTANCE_HIGH).apply {
                if (tone == SILENT) {
                    setSound(null, null)
                    enableVibration(false)
                } else {
                    setSound(Uri.parse(tone), AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
                    enableVibration(true)
                }
            }
            manager.createNotificationChannel(channel)
        }
        builder.setChannelId(id)
        if (tone == SILENT) builder.setSilent(true)
    }
}
