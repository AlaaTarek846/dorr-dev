package com.dorr.app.chat

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.Ringtone
import android.media.RingtoneManager
import android.media.ToneGenerator
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/**
 * An incoming call while the app is closed or in the background, WhatsApp-style: a notification
 * on the "calls" channel that rings (the phone's ringtone, looping) and vibrates until answered,
 * wakes the screen with the full-screen ringing page even on the lock screen, and carries
 * Answer / Decline buttons. It disappears by itself when the ring times out.
 */
object CallNotifications {
    private const val CHANNEL = "dorr_calls_v1"
    private const val NOTIFICATION_ID = 7301
    private const val RING_TIMEOUT_MS = 45_000L

    const val ACTION_ANSWER = "com.dorr.app.call.ANSWER"
    const val ACTION_DECLINE = "com.dorr.app.call.DECLINE"
    const val EXTRA_CALL = "call_id"
    const val EXTRA_CONVERSATION = "conversation_id"
    const val EXTRA_AUTO_ANSWER = "auto_answer"

    fun showIncoming(context: Context, callId: String, conversationId: String?, callerName: String, video: Boolean) {
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        ensureChannel(context, manager)

        fun activity(requestCode: Int, autoAnswer: Boolean) = PendingIntent.getActivity(
            context, requestCode,
            Intent(context, IncomingCallActivity::class.java)
                .putExtra(EXTRA_CALL, callId)
                .putExtra(EXTRA_CONVERSATION, conversationId)
                .putExtra(EXTRA_AUTO_ANSWER, autoAnswer)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val decline = PendingIntent.getBroadcast(
            context, 3,
            Intent(context, CallActionReceiver::class.java).setAction(ACTION_DECLINE).putExtra(EXTRA_CALL, callId),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val text = context.getString(if (video) R.string.ch_call_incoming_video else R.string.ch_call_incoming_voice)
        val builder = (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) Notification.Builder(context, CHANNEL) else @Suppress("DEPRECATION") Notification.Builder(context))
            .setSmallIcon(android.R.drawable.sym_call_incoming)
            .setContentTitle(callerName)
            .setContentText(text)
            .setCategory(Notification.CATEGORY_CALL)
            .setOngoing(true)
            .setAutoCancel(false)
            .setVisibility(Notification.VISIBILITY_PUBLIC)
            .setColor(0xFF001B53.toInt())
            .setContentIntent(activity(1, autoAnswer = false))
            // The ringing page, straight over the lock screen.
            .setFullScreenIntent(activity(1, autoAnswer = false), true)
            .addAction(Notification.Action.Builder(null, context.getString(R.string.ch_call_decline), decline).build())
            .addAction(Notification.Action.Builder(null, context.getString(R.string.ch_call_accept), activity(2, autoAnswer = true)).build())
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) builder.setTimeoutAfter(RING_TIMEOUT_MS)
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            @Suppress("DEPRECATION")
            builder.setPriority(Notification.PRIORITY_MAX).setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE), AudioManager.STREAM_RING)
        }

        val notification = builder.build()
        // Keep ringing (sound + vibration) until the user reacts, like a real phone call.
        notification.flags = notification.flags or Notification.FLAG_INSISTENT
        // One ringing call at a time (a second one replaces the first).
        runCatching { manager.notify(NOTIFICATION_ID, notification) }
    }

    fun cancel(context: Context) {
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        manager.cancel(NOTIFICATION_ID)
    }

    private fun ensureChannel(context: Context, manager: NotificationManager) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O || manager.getNotificationChannel(CHANNEL) != null) return
        val channel = NotificationChannel(CHANNEL, context.getString(R.string.ch_calls_title), NotificationManager.IMPORTANCE_HIGH).apply {
            setSound(
                RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE),
                AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build(),
            )
            enableVibration(true)
            vibrationPattern = longArrayOf(0, 800, 600, 800, 600)
            lockscreenVisibility = Notification.VISIBILITY_PUBLIC
            setBypassDnd(false)
        }
        manager.createNotificationChannel(channel)
    }
}

/** "Decline" from the notification: tell the server and stop ringing, without opening the app. */
class CallActionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != CallNotifications.ACTION_DECLINE) return
        CallNotifications.cancel(context)
        val callId = intent.getStringExtra(CallNotifications.EXTRA_CALL) ?: return
        val token = AuthSession.token ?: return
        val pending = goAsync()
        CoroutineScope(Dispatchers.IO).launch {
            runCatching { ApiClient.chat.declineCall("Bearer $token", callId) }
            pending.finish()
        }
    }
}

/**
 * Sounds while the app is open: the phone's ringtone + vibration for an incoming call, and the
 * "ring back" tone for the caller while the other side's phone rings.
 */
object Ringer {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private var ringtone: Ringtone? = null
    private var vibrator: Vibrator? = null
    private var ringback: Job? = null

    fun startIncoming(context: Context) {
        stop()
        val audio = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager
        // Respect silent / vibrate mode, like the phone's own calls.
        if (audio.ringerMode == AudioManager.RINGER_MODE_NORMAL) {
            ringtone = runCatching {
                RingtoneManager.getRingtone(context, RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE))?.apply {
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) isLooping = true
                    audioAttributes = AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE).build()
                    play()
                }
            }.getOrNull()
        }
        if (audio.ringerMode != AudioManager.RINGER_MODE_SILENT) {
            vibrator = (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) (context.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as VibratorManager).defaultVibrator
            else @Suppress("DEPRECATION") context.getSystemService(Context.VIBRATOR_SERVICE) as Vibrator).also {
                runCatching { it.vibrate(VibrationEffect.createWaveform(longArrayOf(0, 800, 600, 800, 600), 1)) }
            }
        }
    }

    fun startRingback() {
        stop()
        ringback = scope.launch(Dispatchers.IO) {
            val tone = runCatching { ToneGenerator(AudioManager.STREAM_VOICE_CALL, 70) }.getOrNull() ?: return@launch
            try {
                // Classic ring-back cadence: 1s tone, 3s silence.
                while (isActive) {
                    tone.startTone(ToneGenerator.TONE_SUP_RINGTONE, 1000)
                    delay(4000)
                }
            } finally {
                tone.release()
            }
        }
    }

    fun stop() {
        runCatching { ringtone?.stop() }
        ringtone = null
        runCatching { vibrator?.cancel() }
        vibrator = null
        ringback?.cancel()
        ringback = null
    }
}
