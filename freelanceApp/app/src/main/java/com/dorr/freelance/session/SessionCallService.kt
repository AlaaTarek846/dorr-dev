package com.dorr.freelance.session

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
import com.dorr.freelance.MainActivity
import com.dorr.freelance.R

/**
 * Keeps the consultation session going outside its screen (a copy of the DORR app's `chat/CallService`): Android stops a backgrounded
 * app's microphone and camera unless a foreground service holds them. Its notification is the session itself — who, the timer, mute
 * and leave; a tap brings the app back.
 */
class SessionCallService : Service() {
    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_MIC -> SessionCallController.toggleMic()
            ACTION_LEAVE -> {
                SessionCallController.leave()
                stopSelf()
                return START_NOT_STICKY
            }
        }
        if (!SessionCallController.ongoing()) {
            stopSelf()
            return START_NOT_STICKY
        }
        show()
        return START_NOT_STICKY
    }

    private fun show() {
        val manager = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && manager.getNotificationChannel(CHANNEL) == null) {
            manager.createNotificationChannel(NotificationChannel(CHANNEL, getString(R.string.ses_channel), NotificationManager.IMPORTANCE_LOW))
        }
        val open = PendingIntent.getActivity(
            this, 0,
            Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        fun action(name: String, code: Int) =
            PendingIntent.getService(this, code, Intent(this, SessionCallService::class.java).setAction(name), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)

        val active = SessionCallController.phase == SessionPhase.Active
        val builder = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(android.R.drawable.presence_video_online)
            .setContentTitle(SessionCallController.title.ifBlank { getString(R.string.ses_title) })
            .setContentText(getString(if (active) R.string.ses_ongoing else R.string.ses_connecting))
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setContentIntent(open)
            .addAction(0, getString(if (SessionCallController.micOn) R.string.ses_mute else R.string.ses_unmute), action(ACTION_MIC, 1))
            .addAction(0, getString(R.string.ses_leave), action(ACTION_LEAVE, 2))
        SessionCallController.connectedAt?.let { builder.setUsesChronometer(true).setWhen(it).setShowWhen(true) }

        val notification = builder.build()
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) {
            runCatching { startForeground(NOTIFICATION_ID, notification) }.onFailure { stopSelf() }
            return
        }
        val mic = ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED
        val camera = ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED
        val types = ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK or
            (if (mic) ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE else 0) or
            (if (camera) ServiceInfo.FOREGROUND_SERVICE_TYPE_CAMERA else 0)
        // From the background Android may refuse the microphone / camera types: sound only then, else nothing.
        runCatching { startForeground(NOTIFICATION_ID, notification, types) }
            .recoverCatching { startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK) }
            .onFailure { android.util.Log.w("SessionCallService", "foreground failed", it); stopSelf() }
    }

    companion object {
        private const val CHANNEL = "dorr_consultation_session"
        private const val NOTIFICATION_ID = 7402
        private const val ACTION_MIC = "com.dorr.freelance.session.MIC"
        private const val ACTION_LEAVE = "com.dorr.freelance.session.LEAVE"

        fun update(context: Context) {
            if (!SessionCallController.ongoing()) return
            runCatching { ContextCompat.startForegroundService(context, Intent(context, SessionCallService::class.java)) }
        }

        fun stop(context: Context) {
            runCatching { context.stopService(Intent(context, SessionCallService::class.java)) }
        }
    }
}
