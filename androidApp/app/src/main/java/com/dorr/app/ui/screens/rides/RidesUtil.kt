package com.dorr.app.ui.screens.rides

import android.annotation.SuppressLint
import android.content.Context
import android.location.Geocoder
import com.dorr.app.network.AuthSession
import com.dorr.app.network.RideCurrencyDto
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.maps.model.LatLng
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import java.util.Locale
import kotlin.coroutines.resume
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.composed
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.unit.dp

internal fun ridesAuth(): String = "Bearer ${AuthSession.token.orEmpty()}"

/** A place on the trip: a point and its readable name; `name` = its short title in a list (O-57). */
data class RidePoint(val lat: Double, val lng: Double, val address: String, val name: String? = null) {
    val latLng: LatLng get() = LatLng(lat, lng)
}

/** "25.50 ر.س" from halalas and the ride's currency. */
internal fun money(minor: Long?, currency: RideCurrencyDto?): String {
    if (minor == null) return "—"
    val places = currency?.decimals ?: 2
    var divisor = 1.0
    repeat(places) { divisor *= 10 }
    val value = String.format(Locale.getDefault(), "%.${places}f", minor / divisor)
    return "$value ${currency?.symbol ?: ""}".trim()
}

/** Google's encoded polyline → points (the route drawn on the map). */
internal fun decodePolyline(encoded: String?): List<LatLng> {
    if (encoded.isNullOrEmpty()) return emptyList()
    val points = ArrayList<LatLng>()
    var index = 0
    var lat = 0
    var lng = 0
    while (index < encoded.length) {
        var result = 0
        var shift = 0
        var b: Int
        do {
            b = encoded[index++].code - 63
            result = result or ((b and 0x1f) shl shift)
            shift += 5
        } while (b >= 0x20 && index < encoded.length)
        lat += if (result and 1 != 0) (result shr 1).inv() else result shr 1
        result = 0
        shift = 0
        do {
            b = encoded[index++].code - 63
            result = result or ((b and 0x1f) shl shift)
            shift += 5
        } while (b >= 0x20 && index < encoded.length)
        lng += if (result and 1 != 0) (result shr 1).inv() else result shr 1
        points.add(LatLng(lat / 1e5, lng / 1e5))
    }
    return points
}

/** The phone's position now (null without the permission or a fix). */
@SuppressLint("MissingPermission")
internal suspend fun currentLocation(context: Context): LatLng? = runCatching {
    suspendCancellableCoroutine { cont ->
        LocationServices.getFusedLocationProviderClient(context)
            .getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, null)
            .addOnSuccessListener { loc -> cont.resume(loc?.let { LatLng(it.latitude, it.longitude) }) }
            .addOnFailureListener { cont.resume(null) }
    }
}.getOrNull()

/** The street / district under a point, in the app's language. */
@Suppress("DEPRECATION")
internal suspend fun addressOf(context: Context, at: LatLng): String? = withContext(Dispatchers.IO) {
    runCatching {
        Geocoder(context, Locale.getDefault()).getFromLocation(at.latitude, at.longitude, 1)?.firstOrNull()?.let { a ->
            listOfNotNull(a.thoroughfare ?: a.featureName, a.subLocality ?: a.locality).distinct().joinToString("، ").ifBlank { a.getAddressLine(0) }
        }
    }.getOrNull()
}

/**
 * Places matching what the rider typed, nearest to them first (O-57): the server's search (the admin's map
 * provider, with autocomplete — «الدق…» already suggests), from two letters; the phone's geocoder only when
 * the server has nothing.
 */
@Suppress("DEPRECATION")
internal suspend fun searchPlaces(context: Context, query: String, near: LatLng?): List<RidePoint> = withContext(Dispatchers.IO) {
    if (query.trim().length < 2) return@withContext emptyList()
    val server = runCatching {
        com.dorr.app.network.ApiClient.rides.placeSuggest(ridesAuth(), query.trim(), near?.latitude, near?.longitude).data.orEmpty()
            .map { RidePoint(it.lat, it.lng, it.address.ifBlank { it.name }, it.name.ifBlank { null }) }
    }.getOrDefault(emptyList())
    if (server.isNotEmpty() || query.trim().length < 3) return@withContext server
    runCatching {
        val geocoder = Geocoder(context, Locale.getDefault())
        val found = if (near != null) {
            geocoder.getFromLocationName(query, 8, near.latitude - 0.5, near.longitude - 0.5, near.latitude + 0.5, near.longitude + 0.5)
        } else {
            geocoder.getFromLocationName(query, 8)
        }
        found.orEmpty().map { a -> RidePoint(a.latitude, a.longitude, a.getAddressLine(0) ?: a.featureName ?: query) }
    }.getOrDefault(emptyList())
}

/** "12 د" / "1 س 5 د" from seconds. */
internal fun minutesText(seconds: Int?, minuteWord: String, hourWord: String): String {
    val total = ((seconds ?: 0) + 59) / 60
    return if (total < 60) "$total $minuteWord" else "${total / 60} $hourWord ${total % 60} $minuteWord"
}

/** A tap that answers with a small press-in, instead of a flat ripple. */
internal fun Modifier.pressable(enabled: Boolean = true, onClick: () -> Unit): Modifier = composed {
    val source = remember { MutableInteractionSource() }
    val pressed by source.collectIsPressedAsState()
    val scale by animateFloatAsState(if (pressed) 0.97f else 1f, spring(stiffness = Spring.StiffnessMedium), label = "press")
    this
        .graphicsLayer { scaleX = scale; scaleY = scale }
        .clickable(interactionSource = source, indication = null, enabled = enabled, onClick = onClick)
}

/** Rises and fades in the first time it is shown, one after another by [index] — lists arrive instead of popping. */
internal fun Modifier.staggerIn(index: Int = 0): Modifier = composed {
    val progress = remember { androidx.compose.animation.core.Animatable(0f) }
    androidx.compose.runtime.LaunchedEffect(Unit) {
        kotlinx.coroutines.delay(index.coerceAtMost(8) * 45L)
        progress.animateTo(1f, androidx.compose.animation.core.tween(380, easing = androidx.compose.animation.core.FastOutSlowInEasing))
    }
    this.graphicsLayer {
        alpha = progress.value
        translationY = (1f - progress.value) * 22.dp.toPx()
    }
}

/** O-57: before anything is typed — the popular places within 50 km (empty when unknown). */
internal suspend fun popularPlaces(near: LatLng?): List<RidePoint> = withContext(Dispatchers.IO) {
    if (near == null) return@withContext emptyList()
    runCatching {
        com.dorr.app.network.ApiClient.rides.placePopular(ridesAuth(), near.latitude, near.longitude).data.orEmpty()
            .map { RidePoint(it.lat, it.lng, it.address.ifBlank { it.name }, it.name.ifBlank { null }) }
    }.getOrDefault(emptyList())
}
