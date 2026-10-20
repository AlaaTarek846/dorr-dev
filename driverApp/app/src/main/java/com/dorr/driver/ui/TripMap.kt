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
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.model.BitmapDescriptorFactory
import com.google.android.gms.maps.model.CameraPosition
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.LatLngBounds
import com.google.android.gms.maps.model.MapStyleOptions
import com.google.maps.android.compose.GoogleMap
import com.google.maps.android.compose.MapProperties
import com.google.maps.android.compose.MapUiSettings
import com.google.maps.android.compose.Marker
import com.google.maps.android.compose.MarkerState
import com.google.maps.android.compose.Polyline
import com.google.maps.android.compose.rememberCameraPositionState
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
    val camera = rememberCameraPositionState { position = CameraPosition.fromLatLngZoom(pickup ?: LatLng(24.7136, 46.6753), 14f) }

    fun fitRoute() {
        val points = route + listOfNotNull(pickup, drop)
        if (points.size < 2) return
        val bounds = LatLngBounds.builder().apply { points.forEach { include(it) } }.build()
        scope.launch { runCatching { camera.animate(CameraUpdateFactory.newLatLngBounds(bounds, 140)) } }
    }
    LaunchedEffect(t.uuid, route.size) { fitRoute() }

    Box(modifier) {
        GoogleMap(
            modifier = Modifier.fillMaxSize(),
            cameraPositionState = camera,
            properties = DriverMap.properties(MapProperties(
                isMyLocationEnabled = located,
                mapStyleOptions = if (Pal.night) remember { MapStyleOptions.loadRawResourceStyle(context, R.raw.map_night) } else null,
            )),
            uiSettings = MapUiSettings(zoomControlsEnabled = false, myLocationButtonEnabled = false, mapToolbarEnabled = false, compassEnabled = false),
            contentPadding = PaddingValues(top = 150.dp, bottom = bottomPadding),
            onMapLoaded = { fitRoute() },
        ) {
            DriverMapboxTiles()
            if (route.size >= 2) {
                Polyline(points = route, color = Color.Black.copy(alpha = 0.18f), width = 18f)
                Polyline(points = route, color = DorrBrand.navy, width = 11f)
            }
            pickup?.let { Marker(state = MarkerState(it), title = stringResource(R.string.offer_pickup), icon = BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_AZURE)) }
            stops.forEach { Marker(state = MarkerState(it), icon = BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_YELLOW)) }
            drop?.let { Marker(state = MarkerState(it), title = stringResource(R.string.offer_dropoff), icon = BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_ORANGE)) }
        }

        DriverMapboxAttribution(Modifier.align(Alignment.BottomStart).padding(start = 6.dp, bottom = bottomPadding + 6.dp))
        LaunchedEffect(Unit) { DriverMap.load() }
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
