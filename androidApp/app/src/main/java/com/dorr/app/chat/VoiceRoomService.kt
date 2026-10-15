package com.dorr.app.chat

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.dorr.app.MainActivity
import com.dorr.app.R

/**
 * Keeps the voice room going when I leave the app (like Messenger): Android freezes a backgrounded
 * app's sound and microphone unless a foreground service holds them. Its notification is the
 * room "minimised" — tap to come back, mute / unmute, or leave — and it goes when I leave the room.
 */
class VoiceRoomService : Service() {
    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_MIC -> VoiceRoomController.toggleMic()
            ACTION_LEAVE -> {
                VoiceRoomController.leave()
                stopSelf()
                return START_NOT_STICKY
            }
        }
        if (VoiceRoomController.room == null) {
            stopSelf()
            return START_NOT_STICKY
        }
        show()
        return START_NOT_STICKY
    }

    private fun show() {
        val manager = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && manager.getNotificationChannel(CHANNEL) == null) {
            manager.createNotificationChannel(NotificationChannel(CHANNEL, getString(R.string.vr_title), NotificationManager.IMPORTANCE_LOW))
        }
        val open = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        fun action(name: String, code: Int) = PendingIntent.getService(this, code, Intent(this, VoiceRoomService::class.java).setAction(name), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)

        val speaker = VoiceRoomController.room?.me?.role != "listener"
        val builder = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(com.dorr.app.R.drawable.ic_stat_onesignal_default)
            .setContentTitle(VoiceRoomController.chatTitle ?: getString(R.string.vr_title))
            .setContentText(getString(if (!speaker) R.string.vr_note_listening else if (VoiceRoomController.micOn) R.string.vr_note_mic_on else R.string.vr_note_mic_off))
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setContentIntent(open)
        if (speaker) builder.addAction(0, getString(if (VoiceRoomController.micOn) R.string.vr_mute else R.string.vr_unmute), action(ACTION_MIC, 1))
        builder.addAction(0, getString(R.string.vr_leave), action(ACTION_LEAVE, 2))

        // The microphone only when I may use it (listeners, or no permission: sound only).
        val mic = ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED
        val notification = builder.build()
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) {
            runCatching { startForeground(NOTIFICATION_ID, notification) }.onFailure { stopSelf() }
            return
        }
        // From the background Android refuses the microphone type: sound only then, else nothing
        // (stopping beats the crash of a service that never went foreground).
        runCatching { startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK or (if (mic) ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE else 0)) }
            .recoverCatching { startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK) }
            .onFailure { android.util.Log.w("VoiceRoom", "foreground failed", it); stopSelf() }
    }

    companion object {
        private const val CHANNEL = "voice_room"
        private const val NOTIFICATION_ID = 4711
        private const val ACTION_MIC = "com.dorr.app.voice.MIC"
        private const val ACTION_LEAVE = "com.dorr.app.voice.LEAVE"

        /** Start, or refresh the notification (mic on / off, role). */
        fun update(context: Context) {
            // Started "foreground" it must go foreground at once: never without a room to show.
            if (VoiceRoomController.room == null) return
            runCatching { ContextCompat.startForegroundService(context, Intent(context, VoiceRoomService::class.java)) }
        }

        fun stop(context: Context) {
            runCatching { context.stopService(Intent(context, VoiceRoomService::class.java)) }
        }
    }
}
