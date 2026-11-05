package com.dorr.provider.duty

import android.annotation.SuppressLint
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.content.res.Configuration
import android.location.Location
import android.os.Build
import android.os.IBinder
import android.os.Looper
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.MainActivity
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.AppLocale
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.ui.screens.hasLocationPermission
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import java.io.IOException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/**
 * Starts and stops [MoveTrackingService]. The truck's position is sent **only** while the driver's job is in one of [TRACKED]
 * (docs/moving_business.md §5, task T10). The job and its establishment are kept on the phone so the service can come back by itself.
 */
object MoveTracking {
    /** The statuses in which the customer follows the truck on the map. */
    val TRACKED = setOf("driver_on_way", "arrived", "loading", "in_transit", "unloading")

    private const val PREFS = "dorr_provider_tracking"
    private const val KEY_JOB = "job_uuid"
    private const val KEY_ESTABLISHMENT = "establishment_uuid"

    private fun prefs(context: Context) = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    fun job(context: Context): String? = prefs(context).getString(KEY_JOB, null)

    fun establishment(context: Context): String? = prefs(context).getString(KEY_ESTABLISHMENT, null)

    /**
     * Sets the service to what this job's status says. Call it from a screen (the app in front): the phone only lets a location service
     * start from there. Without the location permission nothing starts; the driver's page says so and asks again.
     */
    fun follow(context: Context, uuid: String, status: String?) {
        if (status in TRACKED) {
            if (hasLocationPermission(context)) start(context, uuid)
        } else if (job(context) == uuid) {
            stop(context)
        }
    }

    /** One catch-up when the app opens or reconnects: back on after the phone killed the service, off when the job ended while away. */
    suspend fun sync(context: Context) {
        val jobs = (apiCall { ApiClient.moving.myJobs() } as? ApiResult.Ok)?.data ?: return
        val running = jobs.firstOrNull { it.status in TRACKED }
        when {
            running != null -> if (hasLocationPermission(context)) start(context, running.uuid)
            // A job of another establishment isn't in this list: only this establishment's job is known to have ended.
            establishment(context) == Session.establishment.value -> stop(context)
        }
    }

    private fun start(context: Context, uuid: String) {
        val app = context.applicationContext
        prefs(app).edit().putString(KEY_JOB, uuid).putString(KEY_ESTABLISHMENT, Session.establishment.value).apply()
        runCatching { ContextCompat.startForegroundService(app, Intent(app, MoveTrackingService::class.java)) }
    }

    fun stop(context: Context) {
        val app = context.applicationContext
        prefs(app).edit().clear().apply()
        runCatching { app.stopService(Intent(app, MoveTrackingService::class.java)) }
    }
}

/**
 * The truck's position while a job runs, even with the app closed: the generic half of driverApp's `DriverDutyService` (a foreground
 * service of type location, the fused provider, points kept and sent in batches, retried while there is no network), without the
 * shift and without any ride request. It reads nothing on a timer: it only **sends** positions, and it learns that the job ended from
 * the move's live channel (`moving.changed`), from one read when the connection returns, and from the screens ([MoveTracking.follow]).
 */
class MoveTrackingService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val pending = ArrayList<Location>()
    private var callback: LocationCallback? = null
    private var tracked: String? = null
    private var work: List<Job> = emptyList()

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        // Started with startForegroundService: go to the foreground first, whatever comes next.
        val started = runCatching { goForeground() }.isSuccess
        val uuid = MoveTracking.job(this)
        if (!started || uuid == null || Session.token == null || !hasLocationPermission(this)) {
            stopSelf()
            return START_NOT_STICKY
        }
        if (uuid != tracked) {
            work.forEach { it.cancel() }
            synchronized(pending) { pending.clear() }
            tracked = uuid
            work = listOf(scope.launch { flushLoop(uuid) }, scope.launch { watchJob(uuid) })
            track()
        }
        return START_STICKY
    }

    private fun goForeground() {
        createChannel()
        // The app's own language, not the phone's.
        val text = createConfigurationContext(Configuration(resources.configuration).apply { setLocale(AppLocale.java()) })
        val open = PendingIntent.getActivity(
            this, 0,
            Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_onesignal_default)
            .setColor(0xFF001B53.toInt())
            .setContentTitle(text.getString(R.string.drv_tracking_title))
            .setContentText(text.getString(R.string.drv_tracking_body))
            .setOngoing(true)
            .setContentIntent(open)
            .build()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    private fun createChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val text = createConfigurationContext(Configuration(resources.configuration).apply { setLocale(AppLocale.java()) })
        getSystemService(NotificationManager::class.java)
            .createNotificationChannel(NotificationChannel(CHANNEL, text.getString(R.string.drv_tracking_channel), NotificationManager.IMPORTANCE_LOW))
    }

    @SuppressLint("MissingPermission")
    private fun track() {
        val client = LocationServices.getFusedLocationProviderClient(this)
        callback?.let { client.removeLocationUpdates(it) }
        val request = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, INTERVAL_MS).setMinUpdateIntervalMillis(INTERVAL_MS / 2).build()
        val listener = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) {
                synchronized(pending) {
                    pending.addAll(result.locations)
                    // The server keeps the newest point of a batch and takes at most this many: older ones are worth nothing.
                    while (pending.size > MAX_POINTS) pending.removeAt(0)
                }
            }
        }
        callback = listener
        client.requestLocationUpdates(request, listener, Looper.getMainLooper())
    }

    /** Batches out every few seconds; a batch that failed (no network) stays and goes with the next one. */
    private suspend fun flushLoop(uuid: String) {
        while (scope.isActive) {
            delay(FLUSH_MS)
            val points = synchronized(pending) { pending.toList() }
            if (points.isEmpty()) continue
            val body = JsonObject().apply {
                add("points", JsonArray().apply {
                    points.forEach { p ->
                        add(JsonObject().apply {
                            addProperty("lat", p.latitude)
                            addProperty("lng", p.longitude)
                            if (p.hasBearing()) addProperty("heading", p.bearing.toInt().mod(360))
                        })
                    }
                })
            }
            val response = try {
                ApiClient.moving.locations(MoveTracking.establishment(this), uuid, body)
            } catch (e: IOException) {
                continue
            } catch (e: com.google.gson.JsonParseException) {
                null
            }
            when {
                // An answer it could not read still reached the server; the points are not sent twice.
                response == null || response.isSuccessful -> synchronized(pending) { pending.removeAll(points.toSet()) }
                // The job is no longer this driver's (replaced, or removed from the establishment).
                response?.code() == 403 || response?.code() == 404 -> {
                    MoveTracking.stop(this)
                    return
                }
            }
        }
    }

    /** The move's live channel says when the job leaves the running states; one read each time the connection comes back. */
    private suspend fun watchJob(uuid: String) {
        Realtime.connected.collectLatest {
            val now = (apiCall { ApiClient.moving.request(uuid) } as? ApiResult.Ok)?.data?.status
            if (now != null && now !in MoveTracking.TRACKED) {
                MoveTracking.stop(this)
                return@collectLatest
            }
            Realtime.watchMove(uuid).collect { event ->
                val status = event.data?.get("status")?.takeIf { it.isJsonPrimitive }?.asString
                if (event.name == "moving.changed" && status != null && status !in MoveTracking.TRACKED) MoveTracking.stop(this)
            }
        }
    }

    override fun onDestroy() {
        callback?.let { LocationServices.getFusedLocationProviderClient(this).removeLocationUpdates(it) }
        scope.cancel()
        super.onDestroy()
    }

    private companion object {
        const val CHANNEL = "moving_job"
        const val NOTIFICATION_ID = 2001
        const val INTERVAL_MS = 5_000L
        const val FLUSH_MS = 8_000L
        const val MAX_POINTS = 100
    }
}
