package com.dorr.app.chat

import android.Manifest
import android.app.Activity
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.PictureInPictureParams
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.net.Uri
import android.os.Build
import android.os.IBinder
import android.provider.Settings
import android.util.Rational
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.dorr.app.MainActivity
import com.dorr.app.R

/**
 * Keeps a call going outside the call screen, like Messenger: minimised in the app, on another
 * app, or with the screen off. Android stops a backgrounded app's microphone and camera unless a
 * foreground service holds them. Its notification is the call itself: who, the timer, mute and
 * hang up — a tap brings the call screen back.
 */
class CallService : Service() {
    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_MIC -> CallController.toggleMic()
            ACTION_HANG_UP -> {
                CallController.hangUp()
                stopSelf()
                return START_NOT_STICKY
            }
        }
        if (!CallPip.ongoing()) {
            stopSelf()
            return START_NOT_STICKY
        }
        show()
        return START_NOT_STICKY
    }

    private fun show() {
        val manager = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && manager.getNotificationChannel(CHANNEL) == null) {
            manager.createNotificationChannel(NotificationChannel(CHANNEL, getString(R.string.ch_call_ongoing_channel), NotificationManager.IMPORTANCE_LOW))
        }
        val open = PendingIntent.getActivity(
            this, 0,
            Intent(this, MainActivity::class.java).putExtra(EXTRA_OPEN_CALL, true).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        fun action(name: String, code: Int) = PendingIntent.getService(this, code, Intent(this, CallService::class.java).setAction(name), PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)

        val active = CallController.phase == CallPhase.Active
        val builder = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(com.dorr.app.R.drawable.ic_stat_onesignal_default)
            .setContentTitle(CallController.title.ifBlank { getString(R.string.ch_calls_title) })
            .setContentText(getString(if (!active) R.string.ch_call_calling else if (CallController.isVideo) R.string.ch_call_ongoing_video else R.string.ch_call_ongoing_voice))
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setColor(0xFF001B53.toInt())
            .setContentIntent(open)
            .addAction(0, getString(if (CallController.micOn) R.string.vr_mute else R.string.vr_unmute), action(ACTION_MIC, 1))
            .addAction(0, getString(R.string.ch_call_hang_up), action(ACTION_HANG_UP, 2))
        CallController.connectedAt?.let { builder.setUsesChronometer(true).setWhen(it).setShowWhen(true) }

        val notification = builder.build()
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) {
            runCatching { startForeground(NOTIFICATION_ID, notification) }.onFailure { stopSelf() }
            return
        }
        val mic = ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED
        val camera = CallController.isVideo && ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED
        val types = ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK or
            (if (mic) ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE else 0) or
            (if (camera) ServiceInfo.FOREGROUND_SERVICE_TYPE_CAMERA else 0)
        // From the background Android may refuse the microphone / camera types: sound only then,
        // else nothing (stopping beats the crash of a service that never went foreground).
        runCatching { startForeground(NOTIFICATION_ID, notification, types) }
            .recoverCatching { startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK) }
            .onFailure { android.util.Log.w("CallService", "foreground failed", it); stopSelf() }
    }

    companion object {
        private const val CHANNEL = "dorr_call_ongoing"
        private const val NOTIFICATION_ID = 7302
        private const val ACTION_MIC = "com.dorr.app.call.MIC"
        private const val ACTION_HANG_UP = "com.dorr.app.call.HANG_UP"

        /** The call notification was tapped: show the call screen again. */
        const val EXTRA_OPEN_CALL = "open_call"

        /** Start, or refresh the notification (calling → in the call, mic on / off). */
        fun update(context: Context) {
            if (!CallPip.ongoing()) return
            runCatching { ContextCompat.startForegroundService(context, Intent(context, CallService::class.java)) }
        }

        fun stop(context: Context) {
            runCatching { context.stopService(Intent(context, CallService::class.java)) }
        }
    }
}

/** The call shrunk to a small window over other apps (picture in picture), when I leave the app mid-call. */
object CallPip {
    fun ongoing(): Boolean = CallController.phase.let { it == CallPhase.Outgoing || it == CallPhase.Connecting || it == CallPhase.Active }

    fun enter(activity: Activity) {
        if (!ongoing()) return
        if (!activity.packageManager.hasSystemFeature(PackageManager.FEATURE_PICTURE_IN_PICTURE)) return
        val ratio = if (CallController.isVideo) Rational(9, 16) else Rational(3, 4)
        runCatching { activity.enterPictureInPictureMode(PictureInPictureParams.Builder().setAspectRatio(ratio).build()) }
            .onFailure { android.util.Log.w("CallPip", "picture in picture failed", it) }
    }
}

/**
 * What a call needs to show over other apps and on the lock screen like a phone call: "display over
 * other apps" (the ringing page opens even while I'm in another app), and on Android 14+ the
 * "full-screen notifications" permission.
 */
object CallPermissions {
    fun canOverlay(context: Context): Boolean = Settings.canDrawOverlays(context)

    fun canFullScreen(context: Context): Boolean =
        Build.VERSION.SDK_INT < 34 || context.getSystemService(NotificationManager::class.java).canUseFullScreenIntent()

    fun missing(context: Context): Boolean = !canOverlay(context) || !canFullScreen(context)

    fun openOverlay(context: Context) {
        runCatching {
            context.startActivity(Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:" + context.packageName)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        }
    }

    fun openFullScreen(context: Context) {
        if (Build.VERSION.SDK_INT < 34) return
        runCatching {
            context.startActivity(Intent(Settings.ACTION_MANAGE_APP_USE_FULL_SCREEN_INTENT, Uri.parse("package:" + context.packageName)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        }
    }
}
