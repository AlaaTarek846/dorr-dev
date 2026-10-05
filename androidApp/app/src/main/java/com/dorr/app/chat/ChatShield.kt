package com.dorr.app.chat

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.app.NotificationCompat
import com.dorr.app.MainActivity
import com.dorr.app.R

/**
 * Privacy that lives on this phone (spec 104–110), next to the server's privacy mode:
 *  - Safe View: chat previews hidden, and chats I marked kept out of the list while it's on,
 *  - hide the app's content in the recent-apps screen,
 *  - blur photos and videos until I tap them, and hide what I revealed again after a while,
 *  - private notifications (nothing shown / privacy mode) folded into one "N new messages".
 */
object ChatShield {
    private const val PREFS = "chat_shield"
    private const val PRIVATE_ID = 4242
    private const val PRIVATE_CHANNEL = "chat_private"

    var safeView by mutableStateOf(false)
        private set
    var hideInRecents by mutableStateOf(false)
        private set
    var blurMedia by mutableStateOf(false)
        private set
    /** Seconds before revealed content hides again; 0 = when I leave the chat. */
    var rehideSeconds by mutableStateOf(0)
        private set
    /** Chats kept out of the list while Safe View is on. */
    var safeHidden by mutableStateOf<Set<String>>(emptySet())
        private set

    fun load(context: Context) {
        val p = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        safeView = p.getBoolean("safe_view", false)
        hideInRecents = p.getBoolean("hide_in_recents", false)
        blurMedia = p.getBoolean("blur_media", false)
        rehideSeconds = p.getInt("rehide_seconds", 0)
        safeHidden = p.getStringSet("safe_hidden", emptySet()).orEmpty().toSet()
    }

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun setSafeView(context: Context, on: Boolean) { safeView = on; prefs(context).edit().putBoolean("safe_view", on).apply() }
    fun setHideInRecents(context: Context, on: Boolean) { hideInRecents = on; prefs(context).edit().putBoolean("hide_in_recents", on).apply() }
    fun setBlurMedia(context: Context, on: Boolean) { blurMedia = on; prefs(context).edit().putBoolean("blur_media", on).apply() }
    fun setRehideSeconds(context: Context, seconds: Int) { rehideSeconds = seconds; prefs(context).edit().putInt("rehide_seconds", seconds).apply() }

    fun toggleSafeHidden(context: Context, conversationId: String) {
        safeHidden = if (conversationId in safeHidden) safeHidden - conversationId else safeHidden + conversationId
        prefs(context).edit().putStringSet("safe_hidden", safeHidden).apply()
    }

    /** When a timed privacy mode started (for the summary once it's over), or 0. */
    fun privacyStartedAt(context: Context): Long = prefs(context).getLong("privacy_started", 0)
    fun setPrivacyStartedAt(context: Context, millis: Long) = prefs(context).edit().putLong("privacy_started", millis).apply()

    // ------------------------------------------------------------------ private notifications

    /**
     * A private chat push arrived: one notification that names nobody, with a running count
     * ("3 new messages"), instead of one per message.
     */
    fun showPrivate(context: Context) {
        val count = prefs(context).getInt("private_count", 0) + 1
        prefs(context).edit().putInt("private_count", count).apply()

        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        if (manager.getNotificationChannel(PRIVATE_CHANNEL) == null) {
            manager.createNotificationChannel(NotificationChannel(PRIVATE_CHANNEL, context.getString(R.string.ch_private_channel), NotificationManager.IMPORTANCE_HIGH))
        }
        val open = PendingIntent.getActivity(
            context, PRIVATE_ID, Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = NotificationCompat.Builder(context, PRIVATE_CHANNEL)
            .setSmallIcon(android.R.drawable.stat_notify_chat)
            .setContentTitle(context.getString(R.string.app_name))
            .setContentText(context.resources.getQuantityString(R.plurals.ch_private_count, count, count))
            .setNumber(count)
            .setVisibility(NotificationCompat.VISIBILITY_SECRET)
            .setOnlyAlertOnce(false)
            .setAutoCancel(true)
            .setContentIntent(open)
            .build()
        runCatching { manager.notify(PRIVATE_ID, notification) }
    }

    /** The chat is open: the private count starts over. */
    fun clearPrivate(context: Context) {
        prefs(context).edit().putInt("private_count", 0).apply()
        context.getSystemService(NotificationManager::class.java)?.cancel(PRIVATE_ID)
    }
}
