package com.dorr.driver.ui

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.MyLocation
import androidx.compose.material.icons.filled.Navigation
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import com.dorr.driver.R
import com.dorr.driver.net.TripDto
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.ui.theme.Pal
import com.google.android.gms.maps.model.BitmapDescriptorFactory
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.LatLngBounds
import com.google.android.gms.maps.model.MapStyleOptions
import com.dorr.driver.map.DorrMap
import com.dorr.driver.map.MapLine
import com.dorr.driver.map.MapPin
import com.dorr.driver.map.PinKind
import com.dorr.driver.map.dpPx
import com.dorr.driver.map.rememberDorrCamera
import kotlinx.coroutines.launch

/**
 * The trip's map (O-34): the route drawn (the server's planned polyline, or a straight line when there is
 * none), the pickup, the stops and the destination, and the captain's own blue dot. Two buttons on it:
 * back to the whole route, and "navigate" in Google Maps to the next point — driving in the app or in
 * Google Maps is the captain's choice.
 */
@Composable
internal fun TripMap(t: TripDto, modifier: Modifier, bottomPadding: Dp) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val located = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
    val pickup = t.origin?.let { LatLng(it.lat, it.lng) }
    val drop = t.destination?.let { LatLng(it.lat, it.lng) }
    val stops = t.stops.map { LatLng(it.lat, it.lng) }
    val route = remember(t.route?.polyline, pickup, drop) {
        t.route?.polyline?.takeIf { it.isNotBlank() }?.let(::decodePolyline)?.takeIf { it.size >= 2 }
            ?: listOfNotNull(pickup, *stops.toTypedArray(), drop)
    }
    val camera = rememberDorrCamera(pickup ?: LatLng(24.7136, 46.6753), 14f)
    val fitPadding = dpPx(48f).toInt()

    fun fitRoute() {
        scope.launch { camera.fit(route + listOfNotNull(pickup, drop), fitPadding) }
    }
    LaunchedEffect(t.uuid, route.size) { fitRoute() }

    Box(modifier) {
        // O-53: the admin's engine (Google or Mapbox) — the route, the pins and the blue dot on either.
        val shadow = dpPx(7f)
        val line = dpPx(4.5f)
        DorrMap(
            camera = camera,
            modifier = Modifier.fillMaxSize(),
            pins = listOfNotNull(pickup?.let { MapPin(it, PinKind.Pickup) }) + stops.map { MapPin(it, PinKind.Stop) } + listOfNotNull(drop?.let { MapPin(it, PinKind.Dropoff) }),
            lines = if (route.size >= 2) listOf(MapLine(route, Color.Black.copy(alpha = 0.18f), shadow), MapLine(route, DorrBrand.navy, line)) else emptyList(),
            showMe = located,
            padding = PaddingValues(top = 150.dp, bottom = bottomPadding),
            night = Pal.night,
            googleNightStyle = R.raw.map_night,
            onLoaded = { fitRoute() },
        )
        Column(Modifier.align(Alignment.TopEnd).statusBarsPadding().padding(top = 150.dp, end = 14.dp)) {
            MapButton(Icons.Filled.MyLocation, DorrBrand.navy) { fitRoute() }
            t.navigation?.let { nav ->
                Box(Modifier.padding(top = 10.dp)) {
                    MapButton(Icons.Filled.Navigation, DorrBrand.orange, stringResource(R.string.trip_navigate)) {
                        val intent = Intent(Intent.ACTION_VIEW, Uri.parse("google.navigation:q=${nav.lat},${nav.lng}&mode=d")).setPackage("com.google.android.apps.maps")
                        runCatching { context.startActivity(intent) }.onFailure { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(nav.googleMapsUrl))) }
                    }
                }
            }
        }
    }
}

@Composable
private fun MapButton(icon: androidx.compose.ui.graphics.vector.ImageVector, tint: Color, label: String? = null, onClick: () -> Unit) {
    Box(
        Modifier.size(50.dp).shadow(8.dp, CircleShape).clip(CircleShape).background(Pal.card).clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, label, tint = tint) }
}

/** Google's encoded polyline → points (the format the server stores, same as the Directions API). */
internal fun decodePolyline(encoded: String): List<LatLng> {
    val points = ArrayList<LatLng>()
    var index = 0
    var lat = 0
    var lng = 0
    while (index < encoded.length) {
        var result = 0
        var shift = 0
        var b: Int
        do { b = encoded[index++].code - 63; result = result or ((b and 0x1f) shl shift); shift += 5 } while (b >= 0x20 && index < encoded.length)
        lat += if (result and 1 != 0) (result shr 1).inv() else result shr 1
        result = 0
        shift = 0
        do { b = encoded[index++].code - 63; result = result or ((b and 0x1f) shl shift); shift += 5 } while (b >= 0x20 && index < encoded.length)
        lng += if (result and 1 != 0) (result shr 1).inv() else result shr 1
        points.add(LatLng(lat / 1e5, lng / 1e5))
    }
    return points
}
