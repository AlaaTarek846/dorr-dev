package com.dorr.driver.duty

import android.Manifest
import android.annotation.SuppressLint
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.location.Location
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.os.Build
import android.os.IBinder
import android.os.Looper
import android.os.SystemClock
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.MainActivity
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.OfferDto
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.UUID

/**
 * The driver's shift, alive while they are online — even with the app closed:
 *  - the position every 30 s (owner's decision), every 5 s on a ride, sent in batches to
 *    `locations/batch` (the server keeps the log and passes it to Wasl); points wait while offline;
 *  - the requests: they arrive on the captain's real-time channel and by push (DriverLive / DriverPush);
 *    a new one rings as a full-screen notification with Accept / Decline right on it (docs/rides-plan.md §17.2).
 */
class DriverDutyService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val pending = ArrayList<Location>()
    private var locationJob: Job? = null
    private var callback: LocationCallback? = null
    private var lastFix: Location? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            stopSelf()
            return START_NOT_STICKY
        }
        channels(this)
        val notification = NotificationCompat.Builder(this, CHANNEL_SHIFT)
            .setSmallIcon(R.drawable.ic_stat_driver)
            .setColor(0xFF001B53.toInt())
            .setContentTitle(getString(R.string.duty_online_title))
            .setContentText(getString(R.string.duty_online_body))
            .setOngoing(true)
            .setContentIntent(openApp(this, null))
            .build()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(ID_SHIFT, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        } else {
            startForeground(ID_SHIFT, notification)
        }
        if (locationJob == null) {
            locationJob = scope.launch { Duty.onTrip.collectLatest { onTrip -> track(onTrip) } }
            scope.launch { flushLoop() }
        }
        // Requests reach the shift through real-time and push, never by asking the server on a timer.
        com.dorr.driver.live.DriverLive.start(this)
        return START_STICKY
    }

    @SuppressLint("MissingPermission")
    private suspend fun track(onTrip: Boolean) {
        val client = LocationServices.getFusedLocationProviderClient(this)
        callback?.let { client.removeLocationUpdates(it) }
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED) return
        val interval = if (onTrip) 5_000L else 30_000L
        val request = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, interval).setMinUpdateIntervalMillis(interval / 2).build()
        val cb = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) {
                synchronized(pending) { pending.addAll(result.locations) }
                lastFix = result.lastLocation ?: lastFix
            }
        }
        callback = cb
        client.requestLocationUpdates(request, cb, Looper.getMainLooper())
    }

    /** Batches out: every 30 s idle, 10 s on a ride; kept and retried while there's no network. */
    private suspend fun flushLoop() {
        while (scope.isActive) {
            delay(if (Duty.onTrip.value) 10_000 else 30_000)
            val points = synchronized(pending) { pending.toList() }
            if (points.isEmpty()) continue
            val body = JsonObject().apply {
                addProperty("batch_uuid", UUID.randomUUID().toString())
                addProperty("clock_offset_ms", 0)
                add("points", JsonArray().apply {
                    points.takeLast(500).forEach { p ->
                        add(JsonObject().apply {
                            addProperty("lat", p.latitude)
                            addProperty("lng", p.longitude)
                            addProperty("accuracy_m", p.accuracy.toInt())
                            if (p.hasSpeed()) addProperty("speed_mps", p.speed.toDouble())
                            if (p.hasBearing()) addProperty("heading", p.bearing.toInt() % 360)
                            addProperty("recorded_at", iso(p.time))
                            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) addProperty("is_mock", p.isMock) else @Suppress("DEPRECATION") addProperty("is_mock", p.isFromMockProvider)
                        })
                    }
                })
            }
            if (apiCall { DriverBackend.api.locations(body) } is ApiResult.Ok) {
                synchronized(pending) { pending.removeAll(points.toSet()) }
            }
        }
    }

    override fun onDestroy() {
        callback?.let { LocationServices.getFusedLocationProviderClient(this).removeLocationUpdates(it) }
        scope.cancel()
        super.onDestroy()
    }

    companion object {
        const val ACTION_START = "com.dorr.driver.duty.START"
        const val ACTION_STOP = "com.dorr.driver.duty.STOP"
        const val EXTRA_OFFER = "offer_uuid"
        const val CHANNEL_SHIFT = "shift"
        const val CHANNEL_OFFERS = "ride_requests"
        const val ID_SHIFT = 1001
        const val ID_OFFER = 1002

        private val isoFormat = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSS'Z'", Locale.US).apply { timeZone = TimeZone.getTimeZone("UTC") }

        private fun iso(millis: Long): String = synchronized(isoFormat) { isoFormat.format(Date(millis)) }

        fun channels(context: Context) {
            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
            val manager = context.getSystemService(NotificationManager::class.java)
            manager.createNotificationChannel(NotificationChannel(CHANNEL_SHIFT, context.getString(R.string.duty_channel_shift), NotificationManager.IMPORTANCE_LOW))
            val sound = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE)
            manager.createNotificationChannel(
                NotificationChannel(CHANNEL_OFFERS, context.getString(R.string.duty_channel_offers), NotificationManager.IMPORTANCE_HIGH).apply {
                    setSound(sound, AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
                    enableVibration(true)
                    vibrationPattern = longArrayOf(0, 600, 300, 600, 300, 600)
                    lockscreenVisibility = Notification.VISIBILITY_PUBLIC
                },
            )
        }

        fun openApp(context: Context, offerUuid: String?): PendingIntent = PendingIntent.getActivity(
            context, offerUuid?.hashCode() ?: 0,
            Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP).apply { offerUuid?.let { putExtra(EXTRA_OFFER, it) } },
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        /** The request as a ringing, full-screen notification, with Accept / Decline on it. */
        @SuppressLint("MissingPermission")
        fun ring(context: Context, offer: OfferDto) {
            channels(context)
            fun action(kind: String) = PendingIntent.getBroadcast(
                context, (offer.uuid + kind).hashCode(),
                Intent(context, OfferActionReceiver::class.java).setAction(kind).putExtra(EXTRA_OFFER, offer.uuid),
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
            )
            val earning = offer.earningMinor?.let { money(it, offer.currency?.decimals ?: 2, offer.currency?.symbol) }
            val body = listOfNotNull(
                context.getString(R.string.offer_pickup_km, String.format(Locale.getDefault(), "%.1f", offer.pickupDistanceM / 1000.0)),
                earning?.let { context.getString(R.string.offer_earning, it) },
            ).joinToString(" · ")
            val notification = NotificationCompat.Builder(context, CHANNEL_OFFERS)
                .setSmallIcon(R.drawable.ic_stat_driver)
                .setColor(0xFF001B53.toInt())
                .setContentTitle(context.getString(R.string.offer_title))
                .setContentText(body)
                .setStyle(NotificationCompat.BigTextStyle().bigText(listOfNotNull(body, offer.origin?.address, offer.destination?.address?.let { "→ $it" }).joinToString("\n")))
                .setPriority(NotificationCompat.PRIORITY_MAX)
                .setCategory(NotificationCompat.CATEGORY_CALL)
                .setAutoCancel(true)
                .setTimeoutAfter((offer.secondsLeft.coerceAtLeast(5) * 1000).toLong())
                .setContentIntent(openApp(context, offer.uuid))
                .setFullScreenIntent(openApp(context, offer.uuid), true)
                .addAction(0, context.getString(R.string.offer_decline), action(OfferActionReceiver.DECLINE))
                .addAction(0, context.getString(R.string.offer_accept), action(OfferActionReceiver.ACCEPT))
                .build()
            if (ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED || Build.VERSION.SDK_INT < 33) {
                NotificationManagerCompat.from(context).notify(ID_OFFER, notification)
            }
        }

        fun money(minor: Long, decimals: Int, symbol: String?): String {
            var divisor = 1.0
            repeat(decimals) { divisor *= 10 }
            return "${String.format(Locale.getDefault(), "%.${decimals}f", minor / divisor)} ${symbol.orEmpty()}".trim()
        }

        @Suppress("unused")
        private val boot = SystemClock.elapsedRealtime()
    }
}
