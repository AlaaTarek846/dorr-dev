package com.dorr.app.chat

import android.annotation.SuppressLint
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.location.Location
import android.location.LocationListener
import android.location.LocationManager
import android.os.Build
import android.os.IBinder
import android.os.Looper
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.dorr.app.MainActivity
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import java.time.Instant

/**
 * The live locations this phone is sharing (message id → ends at, epoch ms), kept on disk so a
 * restart resumes them. Starting / stopping one (re)starts or stops [LiveLocationService].
 */
object LiveLocationSharing {
    private const val PREFS = "chat_live_location"

    fun start(context: Context, messageId: String, liveUntilIso: String) {
        val until = runCatching { java.time.OffsetDateTime.parse(liveUntilIso).toInstant().toEpochMilli() }
            .recoverCatching { Instant.parse(liveUntilIso).toEpochMilli() }
            .getOrNull() ?: return
        prefs(context).edit().putLong(messageId, until).apply()
        ensureRunning(context)
    }

    fun stop(context: Context, messageId: String) {
        prefs(context).edit().remove(messageId).apply()
        if (active(context).isEmpty()) context.stopService(Intent(context, LiveLocationService::class.java))
    }

    fun stopAll(context: Context) {
        prefs(context).edit().clear().apply()
        context.stopService(Intent(context, LiveLocationService::class.java))
    }

    fun isSharing(context: Context, messageId: String): Boolean = active(context).containsKey(messageId)

    /** Still running (ended ones are dropped as they're read). */
    fun active(context: Context): Map<String, Long> {
        val now = System.currentTimeMillis()
        val all = prefs(context).all.mapNotNull { (k, v) -> (v as? Long)?.let { k to it } }.toMap()
        val (live, ended) = all.entries.partition { it.value > now }
        if (ended.isNotEmpty()) prefs(context).edit().apply { ended.forEach { remove(it.key) } }.apply()
        return live.associate { it.key to it.value }
    }

    /** After an app restart: ask the server which of mine are still live and pick them up again. */
    suspend fun resume(context: Context) {
        if (AuthSession.token.isNullOrBlank()) return
        val mine = runCatching { ApiClient.chat.myLiveLocations("Bearer ${AuthSession.token}").data }.getOrNull() ?: return
        prefs(context).edit().clear().apply()
        mine.forEach { start(context, it.messageId, it.liveUntil) }
    }

    private fun ensureRunning(context: Context) {
        val fine = ContextCompat.checkSelfPermission(context, android.Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
        val coarse = ContextCompat.checkSelfPermission(context, android.Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
        if (!fine && !coarse) return
        runCatching { ContextCompat.startForegroundService(context, Intent(context, LiveLocationService::class.java)) }
    }

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
}

/**
 * Sends my position to every live location I'm sharing — every 20 seconds or 25 metres — with an
 * ongoing notification ("Sharing live location · Stop"), until the last one ends.
 */
class LiveLocationService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var ticker: Job? = null
    private var last: Location? = null
    private var lastSentAt = 0L
    private val listener = LocationListener { location -> onLocation(location) }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP_ALL) {
            val ids = LiveLocationSharing.active(this).keys
            scope.launch {
                ids.forEach { id -> runCatching { ApiClient.chat.stopLive("Bearer ${AuthSession.token}", id) } }
            }
            LiveLocationSharing.stopAll(this)
            stopSelf()
            return START_NOT_STICKY
        }

        startAsForeground()
        listen()
        ticker?.cancel()
        ticker = scope.launch {
            while (isActive) {
                if (LiveLocationSharing.active(this@LiveLocationService).isEmpty()) {
                    stopSelf()
                    break
                }
                // Standing still still counts as "here": resend the last fix now and then.
                last?.let { if (System.currentTimeMillis() - lastSentAt > 60_000) send(it) }
                delay(20_000)
            }
        }
        return START_STICKY
    }

    @SuppressLint("MissingPermission")
    private fun listen() {
        val manager = getSystemService(Context.LOCATION_SERVICE) as LocationManager
        runCatching { manager.removeUpdates(listener) }
        listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER).forEach { provider ->
            if (runCatching { manager.isProviderEnabled(provider) }.getOrDefault(false)) {
                runCatching { manager.requestLocationUpdates(provider, 15_000L, 25f, listener, Looper.getMainLooper()) }
            }
        }
        listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER)
            .mapNotNull { runCatching { manager.getLastKnownLocation(it) }.getOrNull() }
            .maxByOrNull { it.time }?.let { onLocation(it) }
    }

    private fun onLocation(location: Location) {
        // Keep the better of two fixes that arrive close together.
        val previous = last
        if (previous != null && location.time - previous.time < 5_000 && location.accuracy > previous.accuracy) return
        last = location
        if (System.currentTimeMillis() - lastSentAt >= 10_000) send(location)
    }

    private fun send(location: Location) {
        lastSentAt = System.currentTimeMillis()
        val token = AuthSession.token ?: return
        val ids = LiveLocationSharing.active(this).keys
        scope.launch {
            ids.forEach { id ->
                val result = runCatching {
                    ApiClient.chat.moveLive("Bearer $token", id, mapOf(
                        "latitude" to location.latitude,
                        "longitude" to location.longitude,
                        "accuracy" to location.accuracy.toDouble(),
                    ))
                }
                // Stopped or ended on the server (another device, or its time is up): drop it here too.
                if (result.exceptionOrNull()?.apiFailure()?.httpStatus in listOf(403, 404, 422)) {
                    LiveLocationSharing.stop(this@LiveLocationService, id)
                }
            }
        }
    }

    private fun startAsForeground() {
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && manager.getNotificationChannel(CHANNEL) == null) {
            manager.createNotificationChannel(NotificationChannel(CHANNEL, getString(R.string.ch_live_channel), NotificationManager.IMPORTANCE_LOW))
        }
        val open = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        val stop = PendingIntent.getService(this, 1, Intent(this, LiveLocationService::class.java).setAction(ACTION_STOP_ALL), PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        val count = LiveLocationSharing.active(this).size.coerceAtLeast(1)
        val notification = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setContentTitle(getString(R.string.ch_live_sharing_title))
            .setContentText(resources.getQuantityString(R.plurals.ch_live_sharing_text, count, count))
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setContentIntent(open)
            .addAction(0, getString(R.string.ch_live_stop), stop)
            .build()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    override fun onDestroy() {
        runCatching { (getSystemService(Context.LOCATION_SERVICE) as LocationManager).removeUpdates(listener) }
        scope.cancel()
        super.onDestroy()
    }

    companion object {
        private const val CHANNEL = "dorr_live_location_v1"
        private const val NOTIFICATION_ID = 4711
        private const val ACTION_STOP_ALL = "com.dorr.app.chat.LIVE_STOP_ALL"
    }
}
