@file:OptIn(ExperimentalLayoutApi::class)

package com.dorr.app.ui.screens.moving

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Straighten
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.map.DorrMap
import com.dorr.app.map.GoogleDarkStyle
import com.dorr.app.map.MapColors
import com.dorr.app.map.MapEngine
import com.dorr.app.map.MapLine
import com.dorr.app.map.MapPin
import com.dorr.app.map.PinKind
import com.dorr.app.map.dpPx
import com.dorr.app.map.rememberDorrCamera
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MovingRouteDto
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.rides.decodePolyline
import com.dorr.app.ui.screens.rides.minutesText
import com.dorr.app.ui.theme.AppColors
import com.google.android.gms.maps.model.LatLng
import java.util.Locale
import kotlin.math.asin
import kotlin.math.cos
import kotlin.math.pow
import kotlin.math.sin
import kotlin.math.sqrt

/*
 * The road between the two ends of a move: asked once from `GET moving/route` when both ends are known or one of them
 * changes (the server caches it per pair; nothing asks again on a timer), drawn on the admin's map engine with the
 * pickup pin (navy) and the drop-off pin (orange), with the road distance and the drive time under it. Used by the
 * wizard's places step, its review, and the request page.
 */

/** Where a map with nothing on it yet opens. */
internal val MovingDefaultCenter = LatLng(24.7136, 46.6753)

/** The route of the two ends on screen; [loading] while it is being asked for. */
@Stable
internal class MovingRouteState {
    var route by mutableStateOf<MovingRouteDto?>(null)
    var loading by mutableStateOf(false)
}

/** Asks for the road once per pair of ends (rounded as the server caches it); no ends, no call. */
@Composable
internal fun rememberMovingRoute(from: LatLng?, to: LatLng?): MovingRouteState {
    val state = remember { MovingRouteState() }
    val key = if (from != null && to != null) {
        String.format(Locale.US, "%.4f,%.4f,%.4f,%.4f", from.latitude, from.longitude, to.latitude, to.longitude)
    } else null
    LaunchedEffect(key) {
        if (from == null || to == null) {
            state.route = null
            state.loading = false
            return@LaunchedEffect
        }
        state.route = null
        state.loading = true
        state.route = runCatching { ApiClient.moving.route(mvAuth(), from.latitude, from.longitude, to.latitude, to.longitude).data }.getOrNull()
        state.loading = false
    }
    return state
}

/** The straight line between two points, in km (the fallback when the road could not be asked for). */
internal fun straightKm(a: LatLng, b: LatLng): Double {
    val r = 6371.0
    val dLat = Math.toRadians(b.latitude - a.latitude)
    val dLng = Math.toRadians(b.longitude - a.longitude)
    val h = sin(dLat / 2).pow(2) + cos(Math.toRadians(a.latitude)) * cos(Math.toRadians(b.latitude)) * sin(dLng / 2).pow(2)
    return 2 * r * asin(sqrt(h).coerceAtMost(1.0))
}

/** "18.4" for the `mv_km` string. */
internal fun movingKmText(km: Double): String = String.format(Locale.US, "%.1f", km)

/** A straight line cut into dashes (the engines draw solid lines only): the "estimated" look. */
private fun dashes(a: LatLng, b: LatLng, count: Int = 22): List<List<LatLng>> {
    fun at(t: Double) = LatLng(a.latitude + (b.latitude - a.latitude) * t, a.longitude + (b.longitude - a.longitude) * t)
    val step = 1.0 / (count * 2 - 1)
    return (0 until count).map { i -> listOf(at(i * 2 * step), at((i * 2 + 1) * step)) }
}

/**
 * The map card: both pins, the road (a dashed straight line while it is unknown or estimated), the camera fitted to
 * them. It takes no gestures (it sits in a scrolling page); [onTap] makes the whole card a button. Without both a map
 * engine and an end, a drawn sketch of a route stands in for it.
 */
@Composable
internal fun MovingRouteMap(
    pickup: LatLng?,
    dropoff: LatLng?,
    route: MovingRouteDto?,
    modifier: Modifier = Modifier,
    onTap: (() -> Unit)? = null,
) {
    val context = LocalContext.current
    val shape = RoundedCornerShape(16.dp)
    if ((pickup == null && dropoff == null) || !MapEngine.canDraw(context)) {
        MovingRouteSketch(modifier.clip(shape).then(if (onTap != null) Modifier.clickable(onClick = onTap) else Modifier))
        return
    }
    val night = settingsNight()
    val start = remember {
        if (pickup != null && dropoff != null) LatLng((pickup.latitude + dropoff.latitude) / 2, (pickup.longitude + dropoff.longitude) / 2)
        else pickup ?: dropoff ?: MovingDefaultCenter
    }
    val camera = rememberDorrCamera(start, if (pickup != null && dropoff != null) 11f else 15f)
    var ready by remember { mutableStateOf(false) }
    val road = remember(route?.polyline) { decodePolyline(route?.polyline) }
    val lineColor = if (night) settingsAccent() else MapColors.route
    val wide = dpPx(5f)
    val shadow = dpPx(8f)
    val thin = dpPx(3f)
    val lines = remember(road, pickup, dropoff, lineColor, wide, thin) {
        when {
            road.size > 1 -> listOf(MapLine(road, MapColors.routeShadow, shadow), MapLine(road, lineColor, wide))
            pickup != null && dropoff != null -> dashes(pickup, dropoff).map { MapLine(it, lineColor.copy(alpha = 0.85f), thin) }
            else -> emptyList()
        }
    }
    val pins = remember(pickup, dropoff) {
        listOfNotNull(pickup?.let { MapPin(it, PinKind.Pickup) }, dropoff?.let { MapPin(it, PinKind.Dropoff) })
    }
    Box(modifier.clip(shape).background(mvInsetColor())) {
        DorrMap(
            camera = camera,
            modifier = Modifier.matchParentSize(),
            pins = pins,
            lines = lines,
            night = night,
            googleNightJson = GoogleDarkStyle,
            onLoaded = { ready = true },
        )
        // Over the map: the page scrolls through it, and a tap is the card's.
        Box(
            Modifier.matchParentSize()
                .pointerInput(Unit) { awaitPointerEventScope { while (true) awaitPointerEvent() } }
                .then(if (onTap != null) Modifier.clickable(onClick = onTap) else Modifier),
        )
    }
    // The camera once the map is drawn, and again when an end or the road changes.
    val pad = dpPx(36f).toInt()
    LaunchedEffect(ready, pickup, dropoff, road.size) {
        if (!ready) return@LaunchedEffect
        val points = road.ifEmpty { listOfNotNull(pickup, dropoff) }
        val single = points.size == 1 || (pickup != null && dropoff != null && straightKm(pickup, dropoff) < 0.05)
        when {
            points.isEmpty() -> Unit
            single -> camera.animate(points.first(), 15f)
            else -> camera.fit(points, pad)
        }
    }
}

/** Before any end is set (or with no map engine): a little drawn route, the dashes moving along it. */
@Composable
private fun MovingRouteSketch(modifier: Modifier) {
    val ink = settingsInk()
    val accent = settingsAccent()
    val phase by rememberInfiniteTransition(label = "mv-sketch").animateFloat(
        0f, 52f, infiniteRepeatable(tween(1600, easing = LinearEasing)), label = "phase",
    )
    Box(modifier.background(mvInsetColor()), contentAlignment = Alignment.Center) {
        Canvas(Modifier.matchParentSize()) {
            val w = size.width
            val h = size.height
            val street = ink.copy(alpha = 0.05f)
            val streetWidth = 7.dp.toPx()
            for (i in 1..4) drawLine(street, Offset(0f, h * i / 5f), Offset(w, h * i / 5f + h * 0.08f), streetWidth)
            for (i in 1..5) drawLine(street, Offset(w * i / 6f, 0f), Offset(w * i / 6f - w * 0.05f, h), streetWidth)
            val a = Offset(w * 0.18f, h * 0.70f)
            val b = Offset(w * 0.82f, h * 0.30f)
            val path = Path().apply {
                moveTo(a.x, a.y)
                cubicTo(w * 0.45f, h * 0.98f, w * 0.55f, h * 0.02f, b.x, b.y)
            }
            drawPath(
                path, accent.copy(alpha = 0.6f),
                style = Stroke(width = 3.dp.toPx(), cap = StrokeCap.Round, pathEffect = PathEffect.dashPathEffect(floatArrayOf(16f, 10f), -phase)),
            )
            listOf(a to AppColors.brandNavy, b to AppColors.brandOrange).forEach { (at, color) ->
                drawCircle(Color.White, 11.dp.toPx(), at)
                drawCircle(color, 9.dp.toPx(), at)
                drawCircle(Color.White, 3.5.dp.toPx(), at)
            }
        }
    }
}

/**
 * Under the map: the road distance, the drive time, and «تقديرية» when the server could only estimate (or the road
 * could not be asked for and [fallbackKm], the straight line, is shown). Nothing until both ends are set.
 */
@Composable
internal fun MovingRouteChips(state: MovingRouteState, bothEnds: Boolean, fallbackKm: Double?, modifier: Modifier = Modifier) {
    if (!bothEnds) return
    if (state.loading) {
        Row(modifier.padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(14.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.mv_route_loading), color = settingsMut(), fontSize = 12.5.sp)
        }
        return
    }
    val route = state.route
    val km = route?.distanceM?.let { it / 1000.0 } ?: fallbackKm ?: return
    val estimated = route == null || route.estimated || route.distanceM == null
    val minute = stringResource(R.string.rd_minutes)
    val hour = stringResource(R.string.rd_hours)
    FlowRow(modifier, horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        RouteChip(Icons.Rounded.Straighten, stringResource(R.string.mv_km, movingKmText(km)), settingsAccent(), strong = true)
        route?.durationS?.takeIf { it > 0 }?.let { RouteChip(Icons.Rounded.Schedule, minutesText(it, minute, hour), settingsAccent()) }
        if (estimated) RouteChip(Icons.Rounded.Info, stringResource(R.string.mv_route_estimated), AppColors.warning)
    }
}

@Composable
private fun RouteChip(icon: ImageVector, text: String, tint: Color, strong: Boolean = false) {
    Row(
        Modifier.clip(CircleShape).background(tint.copy(alpha = 0.12f)).padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = settingsInk(), fontSize = if (strong) 14.sp else 12.5.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.SemiBold)
    }
}
