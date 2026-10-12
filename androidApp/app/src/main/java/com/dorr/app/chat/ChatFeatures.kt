package com.dorr.app.chat

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import android.os.Build
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.content.ContextCompat
import androidx.core.location.LocationManagerCompat
import com.dorr.app.network.ApiClient
import com.dorr.app.ui.screens.chat.chatAuth
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import kotlin.coroutines.resume

/**
 * Which chat features are on where I am (the admin's rules by country and city — spec "أمان 2",
 * shared requirement 10). The app hides what's off. The city is the one nearest the phone: with
 * the approximate-location permission the phone tells the server where it is at most every six
 * hours, and the server keeps only the city — never the coordinates. Without it, the country's
 * rules apply.
 */
object ChatFeatures {
    private const val PREFS = "chat_features"
    private const val LOCATE_EVERY_MS = 6 * 60 * 60_000L

    var features by mutableStateOf<Map<String, Boolean>>(emptyMap())
        private set

    /** The city the rules were decided for (null: the country's rules). */
    var city by mutableStateOf<String?>(null)
        private set

    /** Unknown counts as on: the server still refuses what's off. */
    fun allows(feature: String): Boolean = features[feature] != false

    suspend fun refresh(context: Context) {
        val located = if (locationAllowed(context) && due(context)) coarseLocation(context) else null
        val dto = runCatching {
            if (located != null) {
                ApiClient.chat.locate(chatAuth(), JsonObject().apply {
                    addProperty("latitude", located.latitude)
                    addProperty("longitude", located.longitude)
                }).data.also { prefs(context).edit().putLong("located_at", System.currentTimeMillis()).apply() }
            } else {
                ApiClient.chat.features(chatAuth()).data
            }
        }.getOrNull() ?: return
        features = dto.features
        city = dto.city?.name
    }

    /** Ask for the approximate location once (after our own explanation), never again. */
    fun shouldAsk(context: Context): Boolean = !locationAllowed(context) && !prefs(context).getBoolean("asked", false)

    fun markAsked(context: Context) = prefs(context).edit().putBoolean("asked", true).apply()

    /** A new answer to the permission: send the city right away. */
    fun forceLocate(context: Context) = prefs(context).edit().putLong("located_at", 0).apply()

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    private fun due(context: Context) = System.currentTimeMillis() - prefs(context).getLong("located_at", 0) > LOCATE_EVERY_MS

    private fun locationAllowed(context: Context) =
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED

    /** A recent last-known position, else one fresh network fix (10 s at most). */
    @SuppressLint("MissingPermission")
    private suspend fun coarseLocation(context: Context): Location? = withContext(Dispatchers.Main) {
        val manager = context.getSystemService(Context.LOCATION_SERVICE) as? LocationManager ?: return@withContext null
        val providers = buildList {
            add(LocationManager.NETWORK_PROVIDER)
            add(LocationManager.PASSIVE_PROVIDER)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) add(LocationManager.FUSED_PROVIDER)
            add(LocationManager.GPS_PROVIDER)
        }
        val recent = providers.mapNotNull { runCatching { manager.getLastKnownLocation(it) }.getOrNull() }
            .filter { System.currentTimeMillis() - it.time < LOCATE_EVERY_MS }
            .maxByOrNull { it.time }
        if (recent != null) return@withContext recent
        if (runCatching { !manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER) }.getOrDefault(true)) return@withContext null
        withTimeoutOrNull(10_000) {
            suspendCancellableCoroutine { cont ->
                val signal = androidx.core.os.CancellationSignal()
                cont.invokeOnCancellation { signal.cancel() }
                runCatching {
                    LocationManagerCompat.getCurrentLocation(manager, LocationManager.NETWORK_PROVIDER, signal, ContextCompat.getMainExecutor(context)) { loc ->
                        if (cont.isActive) cont.resume(loc)
                    }
                }.onFailure { if (cont.isActive) cont.resume(null) }
            }
        }
    }
}
