package com.dorr.app.map

import android.content.Context
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.Path
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.model.BitmapDescriptorFactory
import com.google.android.gms.maps.model.CameraPosition
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.LatLngBounds
import com.google.android.gms.maps.model.MapStyleOptions
import com.google.maps.android.compose.CameraMoveStartedReason
import com.google.maps.android.compose.GoogleMap
import com.google.maps.android.compose.MapProperties
import com.google.maps.android.compose.MapUiSettings
import com.google.maps.android.compose.Marker
import com.google.maps.android.compose.MarkerState
import com.google.maps.android.compose.Polyline
import com.google.maps.android.compose.rememberCameraPositionState
import com.mapbox.common.MapboxOptions
import com.mapbox.geojson.Point
import com.mapbox.maps.CameraOptions
import com.mapbox.maps.EdgeInsets
import com.mapbox.maps.MapInitOptions
import com.mapbox.maps.MapView
import com.mapbox.maps.plugin.animation.MapAnimationOptions
import com.mapbox.maps.plugin.animation.easeTo
import com.mapbox.maps.plugin.annotation.annotations
import com.mapbox.maps.plugin.annotation.generated.PointAnnotationOptions
import com.mapbox.maps.plugin.annotation.generated.PolylineAnnotationOptions
import com.mapbox.maps.plugin.annotation.generated.createPointAnnotationManager
import com.mapbox.maps.plugin.annotation.generated.createPolylineAnnotationManager
import com.mapbox.maps.plugin.compass.compass
import com.mapbox.maps.plugin.gestures.OnMoveListener
import com.mapbox.maps.plugin.gestures.gestures
import com.mapbox.maps.plugin.locationcomponent.createDefault2DPuck
import com.mapbox.maps.plugin.locationcomponent.location
import com.mapbox.maps.plugin.scalebar.scalebar
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume

/*
 * Two map engines, one chosen by the admin (docs/rides_tasks.md O-53): admin → Rides → Settings → map provider.
 * Google means the whole app draws with the Google Maps SDK; Mapbox means the whole app draws with the
 * Mapbox Maps SDK — the other engine is never started (as if it weren't in the app). The choice and the
 * Mapbox token are saved on the phone (SharedPreferences) and read when the app starts, so a map screen
 * opens at once with the right engine; the server is asked again in the background for any change.
 *
 * Screens never touch an engine: they draw a [DorrMap] with pins and lines, and move it through [DorrCamera].
 */

/** The admin's choice, kept on the phone. */
object MapEngine {
    private const val PREFS = "dorr_map_engine"

    var provider by mutableStateOf("google")
        private set
    private var token: String? = null
    private var style: String = "mapbox/streets-v12"

    /** Mapbox draws the whole app (chosen, with a token). */
    val mapbox: Boolean get() = provider == "mapbox" && !token.isNullOrBlank()

    /** At app start, before any screen: the last saved choice — and Mapbox's token set up front when it's Mapbox. */
    fun init(context: Context) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        token = prefs.getString("token", null)
        style = prefs.getString("style", null) ?: style
        provider = prefs.getString("provider", null) ?: "google"
        if (mapbox) runCatching { MapboxOptions.accessToken = token!! }
    }

    /** The server's current choice (the app's start and each map screen); saved for the next start. */
    suspend fun refresh(context: Context, fetch: suspend () -> Triple<String?, String?, String?>?) {
        val (p, t, s) = runCatching { fetch() }.getOrNull() ?: return
        token = t
        style = s ?: style
        if (mapbox || p == "mapbox") t?.takeIf { it.isNotBlank() }?.let { runCatching { MapboxOptions.accessToken = it } }
        provider = p ?: "google"
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString("provider", provider).putString("token", t).putString("style", style).apply()
    }

    fun styleUri(night: Boolean): String =
        if (night && style == "mapbox/streets-v12") "mapbox://styles/mapbox/dark-v11" else "mapbox://styles/$style"
}

/** What screens ask of a map's camera — the same with either engine. */
class DorrCamera internal constructor(start: LatLng, startZoom: Float) {
    /** Where the map's centre is (after it stops). */
    var target by mutableStateOf(start)
        internal set
    var zoom by mutableFloatStateOf(startZoom)
        internal set
    var isMoving by mutableStateOf(false)
        internal set

    /** The last move was the person's finger (not the app). */
    var byGesture by mutableStateOf(false)
        internal set

    private var engine: CameraEngine? = null
    private var pending: (suspend (CameraEngine) -> Unit)? = null

    suspend fun move(to: LatLng, zoom: Float) = run { it.move(to, zoom, false) }

    suspend fun animate(to: LatLng, zoom: Float) = run { it.move(to, zoom, true) }

    /** Every point in view, with this much room around them (px). */
    suspend fun fit(points: List<LatLng>, paddingPx: Int) {
        if (points.size < 2) return
        run { it.fit(points, paddingPx) }
    }

    private suspend fun run(block: suspend (CameraEngine) -> Unit) {
        val e = engine
        if (e != null) block(e) else pending = block
    }

    internal suspend fun attach(e: CameraEngine) {
        engine = e
        pending?.let { pending = null; it(e) }
    }

    internal fun detach(e: CameraEngine) {
        if (engine === e) engine = null
    }
}

internal interface CameraEngine {
    suspend fun move(to: LatLng, zoom: Float, animate: Boolean)
    suspend fun fit(points: List<LatLng>, paddingPx: Int)
}

@Composable
fun rememberDorrCamera(start: LatLng, zoom: Float): DorrCamera = remember { DorrCamera(start, zoom) }

enum class PinKind { Pickup, Dropoff, Stop, Car }

data class MapPin(val at: LatLng, val kind: PinKind, val rotation: Float = 0f)

data class MapLine(val points: List<LatLng>, val color: Color, val widthPx: Float)

/**
 * The map: pins, lines, «me», kept inside [padding] (the sheets over it). Google or Mapbox — the admin's
 * choice ([MapEngine]); the other engine isn't started at all.
 */
@Composable
fun DorrMap(
    camera: DorrCamera,
    modifier: Modifier = Modifier,
    pins: List<MapPin> = emptyList(),
    lines: List<MapLine> = emptyList(),
    showMe: Boolean = false,
    padding: PaddingValues = PaddingValues(),
    night: Boolean = false,
    googleNightStyle: Int? = null,
    onLoaded: () -> Unit = {},
) {
    if (MapEngine.mapbox) MapboxEngineMap(camera, modifier, pins, lines, showMe, padding, night, onLoaded)
    else GoogleEngineMap(camera, modifier, pins, lines, showMe, padding, night, googleNightStyle, onLoaded)
}

// ---------------------------------------------------------------- Google

@Composable
private fun GoogleEngineMap(
    camera: DorrCamera, modifier: Modifier, pins: List<MapPin>, lines: List<MapLine>, showMe: Boolean,
    padding: PaddingValues, night: Boolean, nightStyle: Int?, onLoaded: () -> Unit,
) {
    val context = LocalContext.current
    val cps = rememberCameraPositionState { position = CameraPosition.fromLatLngZoom(camera.target, camera.zoom) }
    DisposableEffect(cps) {
        val engine = object : CameraEngine {
            override suspend fun move(to: LatLng, zoom: Float, animate: Boolean) {
                val update = CameraUpdateFactory.newLatLngZoom(to, zoom)
                runCatching { if (animate) cps.animate(update) else cps.move(update) }
            }

            override suspend fun fit(points: List<LatLng>, paddingPx: Int) {
                val bounds = LatLngBounds.builder().apply { points.forEach { include(it) } }.build()
                runCatching { cps.animate(CameraUpdateFactory.newLatLngBounds(bounds, paddingPx)) }
            }
        }
        val job = kotlinx.coroutines.MainScope().launchAttach(camera, engine)
        onDispose { job.cancel(); camera.detach(engine) }
    }
    LaunchedEffect(cps.isMoving) {
        camera.isMoving = cps.isMoving
        if (cps.isMoving) camera.byGesture = cps.cameraMoveStartedReason == CameraMoveStartedReason.GESTURE
        else { camera.target = cps.position.target; camera.zoom = cps.position.zoom }
    }
    GoogleMap(
        modifier = modifier,
        cameraPositionState = cps,
        properties = MapProperties(
            isMyLocationEnabled = showMe,
            mapStyleOptions = if (night && nightStyle != null) remember(nightStyle) { MapStyleOptions.loadRawResourceStyle(context, nightStyle) } else null,
        ),
        uiSettings = MapUiSettings(zoomControlsEnabled = false, myLocationButtonEnabled = false, mapToolbarEnabled = false, compassEnabled = false),
        contentPadding = padding,
        onMapLoaded = onLoaded,
    ) {
        lines.forEach { Polyline(points = it.points, color = it.color, width = it.widthPx) }
        pins.forEach { pin ->
            val icon = remember(pin.kind) { BitmapDescriptorFactory.fromBitmap(MapIcons.of(context, pin.kind)) }
            Marker(
                state = remember(pin.at) { MarkerState(pin.at) }, icon = icon,
                rotation = pin.rotation, flat = pin.kind == PinKind.Car,
                anchor = if (pin.kind == PinKind.Car) androidx.compose.ui.geometry.Offset(0.5f, 0.5f) else androidx.compose.ui.geometry.Offset(0.5f, 1f),
            )
        }
    }
}

private fun kotlinx.coroutines.CoroutineScope.launchAttach(camera: DorrCamera, engine: CameraEngine) =
    launch { camera.attach(engine) }

// ---------------------------------------------------------------- Mapbox

@Composable
private fun MapboxEngineMap(
    camera: DorrCamera, modifier: Modifier, pins: List<MapPin>, lines: List<MapLine>, showMe: Boolean,
    padding: PaddingValues, night: Boolean, onLoaded: () -> Unit,
) {
    val context = LocalContext.current
    val density = LocalDensity.current
    val top = with(density) { padding.calculateTopPadding().toPx().toDouble() }
    val bottom = with(density) { padding.calculateBottomPadding().toPx().toDouble() }
    val insets = EdgeInsets(top, 0.0, bottom, 0.0)

    val mapView = remember {
        MapView(context, MapInitOptions(context, cameraOptions = CameraOptions.Builder()
            .center(camera.target.point()).zoom(camera.zoom.toDouble()).padding(insets).build()))
    }
    val style = MapEngine.styleUri(night)
    val pointManager = remember { mapView.annotations.createPointAnnotationManager() }
    val lineManager = remember { mapView.annotations.createPolylineAnnotationManager() }

    DisposableEffect(mapView) {
        mapView.compass.enabled = false
        mapView.scalebar.enabled = false
        val map = mapView.mapboxMap
        map.loadStyle(style) { onLoaded() }
        val moving = map.subscribeCameraChanged { if (!camera.isMoving) camera.isMoving = true }
        val idle = map.subscribeMapIdle {
            val c = map.cameraState
            camera.target = LatLng(c.center.latitude(), c.center.longitude())
            camera.zoom = c.zoom.toFloat()
            camera.isMoving = false
        }
        val finger = object : OnMoveListener {
            override fun onMoveBegin(detector: com.mapbox.android.gestures.MoveGestureDetector) { camera.byGesture = true }
            override fun onMove(detector: com.mapbox.android.gestures.MoveGestureDetector): Boolean = false
            override fun onMoveEnd(detector: com.mapbox.android.gestures.MoveGestureDetector) {}
        }
        mapView.gestures.addOnMoveListener(finger)
        val engine = object : CameraEngine {
            override suspend fun move(to: LatLng, zoom: Float, animate: Boolean) {
                camera.byGesture = false
                val options = CameraOptions.Builder().center(to.point()).zoom(zoom.toDouble()).build()
                if (animate) map.easeTo(options, MapAnimationOptions.Builder().duration(600).build()) else map.setCamera(options)
            }

            override suspend fun fit(points: List<LatLng>, paddingPx: Int) {
                camera.byGesture = false
                val p = paddingPx.toDouble()
                val options = suspendCancellableCoroutine<CameraOptions> { cont ->
                    map.cameraForCoordinates(points.map { it.point() }, CameraOptions.Builder().build(), EdgeInsets(top + p, p, bottom + p, p), null, null) { cont.resume(it) }
                }
                map.easeTo(options, MapAnimationOptions.Builder().duration(700).build())
            }
        }
        val job = kotlinx.coroutines.MainScope().launchAttach(camera, engine)
        onDispose {
            job.cancel()
            camera.detach(engine)
            moving.cancel()
            idle.cancel()
            mapView.gestures.removeOnMoveListener(finger)
            runCatching { mapView.onStop() }
            runCatching { mapView.onDestroy() }
        }
    }
    // The sheets over the map: the centre stays in the visible part.
    LaunchedEffect(top, bottom) { mapView.mapboxMap.setCamera(CameraOptions.Builder().padding(insets).build()) }
    LaunchedEffect(style) { mapView.mapboxMap.loadStyle(style) }
    LaunchedEffect(showMe) {
        mapView.location.updateSettings {
            enabled = showMe
            if (showMe) locationPuck = createDefault2DPuck(withBearing = false)
        }
    }
    LaunchedEffect(lines) {
        lineManager.deleteAll()
        lines.filter { it.points.size >= 2 }.forEach { l ->
            lineManager.create(PolylineAnnotationOptions().withPoints(l.points.map { it.point() }).withLineColor(l.color.toArgb()).withLineWidth((l.widthPx / density.density).toDouble()))
        }
    }
    LaunchedEffect(pins) {
        pointManager.deleteAll()
        pins.forEach { pin ->
            pointManager.create(
                PointAnnotationOptions().withPoint(pin.at.point()).withIconImage(MapIcons.of(context, pin.kind))
                    .withIconRotate(pin.rotation.toDouble())
                    .withIconAnchor(if (pin.kind == PinKind.Car) com.mapbox.maps.extension.style.layers.properties.generated.IconAnchor.CENTER else com.mapbox.maps.extension.style.layers.properties.generated.IconAnchor.BOTTOM),
            )
        }
    }
    AndroidView(factory = { mapView }, modifier = modifier)
}

private fun LatLng.point(): Point = Point.fromLngLat(longitude, latitude)

// ---------------------------------------------------------------- icons (the same on both engines)

private object MapIcons {
    private val cache = HashMap<PinKind, Bitmap>()

    fun of(context: Context, kind: PinKind): Bitmap = cache.getOrPut(kind) { draw(context.resources.displayMetrics.density, kind) }

    private fun draw(d: Float, kind: PinKind): Bitmap = when (kind) {
        PinKind.Car -> car(d)
        PinKind.Pickup -> drop(d, 0xFF001B53.toInt())
        PinKind.Dropoff -> drop(d, 0xFFF57C00.toInt())
        PinKind.Stop -> drop(d, 0xFFF9A825.toInt())
    }

    /** A drop: a round head with a white dot, a point at the bottom (the place). */
    private fun drop(d: Float, color: Int): Bitmap {
        val w = (30 * d).toInt()
        val h = (40 * d).toInt()
        val bmp = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
        val c = Canvas(bmp)
        val paint = Paint(Paint.ANTI_ALIAS_FLAG).apply { this.color = color }
        val r = w / 2f
        val path = Path().apply {
            addCircle(r, r, r - d, Path.Direction.CW)
            moveTo(r - r * 0.55f, r * 1.5f)
            lineTo(r, h.toFloat() - d)
            lineTo(r + r * 0.55f, r * 1.5f)
            close()
        }
        c.drawPath(path, paint)
        c.drawCircle(r, r, r * 0.38f, Paint(Paint.ANTI_ALIAS_FLAG).apply { this.color = android.graphics.Color.WHITE })
        return bmp
    }

    /** The captain's car from above, pointing up (the map turns it with the heading). */
    private fun car(d: Float): Bitmap {
        val w = (26 * d).toInt()
        val h = (44 * d).toInt()
        val bmp = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
        val c = Canvas(bmp)
        val body = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0xFF111928.toInt() }
        c.drawRoundRect(2 * d, 2 * d, w - 2 * d, h - 2 * d, 9 * d, 9 * d, body)
        val glass = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0xFF7FB3FF.toInt() }
        c.drawRoundRect(5 * d, 8 * d, w - 5 * d, 16 * d, 3 * d, 3 * d, glass)
        c.drawRoundRect(5 * d, h - 14 * d, w - 5 * d, h - 8 * d, 3 * d, 3 * d, glass)
        val lights = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0xFFFFE082.toInt() }
        c.drawCircle(7 * d, 4 * d, 1.6f * d, lights)
        c.drawCircle(w - 7 * d, 4 * d, 1.6f * d, lights)
        return bmp
    }
}

/** Colours the screens use for their routes. */
object MapColors {
    val route = Color(0xFF001B53)
    val routeShadow = Color.Black.copy(alpha = 0.18f)
}

/** 1.dp in px, for line widths given in dp. */
@Composable
fun dpPx(dp: Float): Float = with(LocalDensity.current) { dp.dp.toPx() }
