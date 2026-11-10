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
import com.google.maps.android.compose.Polygon
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
import com.mapbox.maps.plugin.annotation.generated.PointAnnotation
import com.mapbox.maps.plugin.annotation.generated.PointAnnotationOptions
import com.mapbox.maps.plugin.annotation.generated.PolygonAnnotationOptions
import com.mapbox.maps.plugin.annotation.generated.createPolygonAnnotationManager
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

/**
 * A pin on the map. `id`: the same pin across updates (a car that moves) — it slides to its new place
 * instead of being drawn again.
 */
data class MapPin(val at: LatLng, val kind: PinKind, val rotation: Float = 0f, val id: String? = null)

data class MapLine(val points: List<LatLng>, val color: Color, val widthPx: Float)

/** A round area on the ground (metres): the captain's hot areas (O-58). */
data class MapZone(val center: LatLng, val radiusM: Double, val color: Color)

/**
 * The map: pins, lines, zones, «me», kept inside [padding] (the sheets over it). Google or Mapbox — the
 * admin's choice ([MapEngine]); the other engine isn't started at all.
 *
 * [showMe]: my place on the map — a car turning with my heading when [meAsCar] (the captain, O-60), the
 * usual dot otherwise.
 */
@Composable
fun DorrMap(
    camera: DorrCamera,
    modifier: Modifier = Modifier,
    pins: List<MapPin> = emptyList(),
    lines: List<MapLine> = emptyList(),
    zones: List<MapZone> = emptyList(),
    showMe: Boolean = false,
    meAsCar: Boolean = false,
    padding: PaddingValues = PaddingValues(),
    night: Boolean = false,
    googleNightStyle: Int? = null,
    onLoaded: () -> Unit = {},
) {
    if (MapEngine.mapbox) MapboxEngineMap(camera, modifier, pins, lines, zones, showMe, meAsCar, padding, night, onLoaded)
    else GoogleEngineMap(camera, modifier, pins, lines, zones, showMe, meAsCar, padding, night, googleNightStyle, onLoaded)
}

// ---------------------------------------------------------------- Google

@Composable
private fun GoogleEngineMap(
    camera: DorrCamera, modifier: Modifier, pins: List<MapPin>, lines: List<MapLine>, zones: List<MapZone>, showMe: Boolean, meAsCar: Boolean,
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
    // O-60: «me» as a car — my place from the phone itself (its location updates; nothing asks the server).
    val me = if (showMe && meAsCar) rememberMyPlace() else null
    GoogleMap(
        modifier = modifier,
        cameraPositionState = cps,
        properties = MapProperties(
            isMyLocationEnabled = showMe && !meAsCar,
            mapStyleOptions = if (night && nightStyle != null) remember(nightStyle) { MapStyleOptions.loadRawResourceStyle(context, nightStyle) } else null,
        ),
        uiSettings = MapUiSettings(zoomControlsEnabled = false, myLocationButtonEnabled = false, mapToolbarEnabled = false, compassEnabled = false),
        contentPadding = padding,
        onMapLoaded = onLoaded,
    ) {
        zones.forEach { z ->
            Polygon(points = circle(z.center, z.radiusM), fillColor = z.color, strokeColor = Color.Transparent, strokeWidth = 0f)
        }
        lines.forEach { Polyline(points = it.points, color = it.color, width = it.widthPx) }
        pins.forEachIndexed { i, pin ->
            androidx.compose.runtime.key(pin.id ?: "$i:${pin.kind}") {
                val icon = remember(pin.kind) { BitmapDescriptorFactory.fromBitmap(MapIcons.of(context, pin.kind)) }
                val state = remember { MarkerState(pin.at) }
                // A moving car slides: the same marker, its position updated.
                androidx.compose.runtime.SideEffect { if (state.position != pin.at) state.position = pin.at }
                Marker(
                    state = state, icon = icon, rotation = pin.rotation, flat = pin.kind == PinKind.Car,
                    anchor = if (pin.kind == PinKind.Car) androidx.compose.ui.geometry.Offset(0.5f, 0.5f) else androidx.compose.ui.geometry.Offset(0.5f, 1f),
                )
            }
        }
        me?.let { (at, bearing) ->
            val icon = remember { BitmapDescriptorFactory.fromBitmap(MapIcons.me(context)) }
            val state = remember { MarkerState(at) }
            androidx.compose.runtime.SideEffect { if (state.position != at) state.position = at }
            Marker(state = state, icon = icon, rotation = bearing, flat = true, anchor = androidx.compose.ui.geometry.Offset(0.5f, 0.5f), zIndex = 10f)
        }
    }
}

/** My place and heading from the phone's own location updates, while the map shows it. */
@Composable
private fun rememberMyPlace(): Pair<LatLng, Float>? {
    val context = LocalContext.current
    var place by remember { mutableStateOf<Pair<LatLng, Float>?>(null) }
    DisposableEffect(Unit) {
        val client = com.google.android.gms.location.LocationServices.getFusedLocationProviderClient(context)
        val callback = object : com.google.android.gms.location.LocationCallback() {
            override fun onLocationResult(result: com.google.android.gms.location.LocationResult) {
                val l = result.lastLocation ?: return
                place = LatLng(l.latitude, l.longitude) to (if (l.hasBearing()) l.bearing else place?.second ?: 0f)
            }
        }
        runCatching {
            @Suppress("MissingPermission")
            client.requestLocationUpdates(
                com.google.android.gms.location.LocationRequest.Builder(com.google.android.gms.location.Priority.PRIORITY_HIGH_ACCURACY, 3000).build(),
                callback, android.os.Looper.getMainLooper(),
            )
        }
        onDispose { client.removeLocationUpdates(callback) }
    }
    return place
}

private fun kotlinx.coroutines.CoroutineScope.launchAttach(camera: DorrCamera, engine: CameraEngine) =
    launch { camera.attach(engine) }

// ---------------------------------------------------------------- Mapbox

@Composable
private fun MapboxEngineMap(
    camera: DorrCamera, modifier: Modifier, pins: List<MapPin>, lines: List<MapLine>, zones: List<MapZone>, showMe: Boolean, meAsCar: Boolean,
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
    val zoneManager = remember { mapView.annotations.createPolygonAnnotationManager() }
    val lineManager = remember { mapView.annotations.createPolylineAnnotationManager() }
    val pointManager = remember { mapView.annotations.createPointAnnotationManager() }
    // Pins with an id (moving cars) are moved, not drawn again.
    val byId = remember { HashMap<String, PointAnnotation>() }
    val loose = remember { ArrayList<PointAnnotation>() }

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
    LaunchedEffect(showMe, meAsCar) {
        mapView.location.updateSettings {
            enabled = showMe
            if (showMe) {
                locationPuck = if (meAsCar) com.mapbox.maps.plugin.LocationPuck2D(bearingImage = com.mapbox.maps.ImageHolder.from(MapIcons.me(context)))
                else createDefault2DPuck(withBearing = false)
                puckBearingEnabled = meAsCar
                if (meAsCar) puckBearing = com.mapbox.maps.plugin.PuckBearing.COURSE
            }
        }
    }
    LaunchedEffect(zones) {
        zoneManager.deleteAll()
        zones.forEach { z ->
            zoneManager.create(
                PolygonAnnotationOptions().withPoints(listOf(circle(z.center, z.radiusM).map { it.point() }))
                    .withFillColor(z.color.copy(alpha = 1f).toArgb()).withFillOpacity(z.color.alpha.toDouble()),
            )
        }
    }
    LaunchedEffect(lines) {
        lineManager.deleteAll()
        lines.filter { it.points.size >= 2 }.forEach { l ->
            lineManager.create(PolylineAnnotationOptions().withPoints(l.points.map { it.point() }).withLineColor(l.color.toArgb()).withLineWidth((l.widthPx / density.density).toDouble()))
        }
    }
    LaunchedEffect(pins) {
        // Fixed pins: drawn again; pins with an id: moved in place, the ones gone removed.
        loose.forEach { pointManager.delete(it) }
        loose.clear()
        val keep = HashSet<String>()
        pins.forEach { pin ->
            val anchor = if (pin.kind == PinKind.Car) com.mapbox.maps.extension.style.layers.properties.generated.IconAnchor.CENTER else com.mapbox.maps.extension.style.layers.properties.generated.IconAnchor.BOTTOM
            val id = pin.id
            if (id == null) {
                loose += pointManager.create(PointAnnotationOptions().withPoint(pin.at.point()).withIconImage(MapIcons.of(context, pin.kind)).withIconRotate(pin.rotation.toDouble()).withIconAnchor(anchor))
                return@forEach
            }
            keep += id
            val existing = byId[id]
            if (existing == null) {
                byId[id] = pointManager.create(PointAnnotationOptions().withPoint(pin.at.point()).withIconImage(MapIcons.of(context, pin.kind)).withIconRotate(pin.rotation.toDouble()).withIconAnchor(anchor))
            } else {
                existing.point = pin.at.point()
                existing.iconRotate = pin.rotation.toDouble()
                pointManager.update(existing)
            }
        }
        byId.keys.filter { it !in keep }.forEach { gone -> byId.remove(gone)?.let { pointManager.delete(it) } }
    }
    AndroidView(factory = { mapView }, modifier = modifier)
}

private fun LatLng.point(): Point = Point.fromLngLat(longitude, latitude)

/** A circle of [radiusM] metres around [center], as points (both engines draw it as a polygon). */
private fun circle(center: LatLng, radiusM: Double, steps: Int = 36): List<LatLng> {
    val lat = Math.toRadians(center.latitude)
    val dLat = radiusM / 111_320.0
    val dLng = radiusM / (111_320.0 * kotlin.math.cos(lat).coerceAtLeast(0.01))
    return (0..steps).map { i ->
        val a = 2 * Math.PI * i / steps
        LatLng(center.latitude + dLat * kotlin.math.sin(a), center.longitude + dLng * kotlin.math.cos(a))
    }
}

// ---------------------------------------------------------------- icons (the same on both engines)

private object MapIcons {
    private val cache = HashMap<String, Bitmap>()

    fun of(context: Context, kind: PinKind): Bitmap = cache.getOrPut(kind.name) { draw(context.resources.displayMetrics.density, kind) }

    /** O-60: the captain's own car (brand blue), a little bigger than the others. */
    fun me(context: Context): Bitmap = cache.getOrPut("me") { car(context.resources.displayMetrics.density, 0xFF0B3D91.toInt(), 1.15f) }

    private fun draw(d: Float, kind: PinKind): Bitmap = when (kind) {
        PinKind.Car -> car(d, 0xFF1F2937.toInt(), 1f)
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

    /**
     * A car seen from above, pointing up (the map turns it with the heading): a soft shadow, the body, a
     * lighter roof, the windscreen and rear glass, mirrors and headlights — small and clean, like inDrive's.
     */
    private fun car(d: Float, body: Int, scale: Float): Bitmap {
        val u = d * scale
        val w = (30 * u).toInt()
        val h = (52 * u).toInt()
        val bmp = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
        val c = Canvas(bmp)
        val cx = w / 2f
        // Shadow.
        c.drawRoundRect(4 * u, 5 * u, w - 3 * u, h - 2 * u, 11 * u, 11 * u, Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0x33000000 })
        // Mirrors.
        val mirror = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = body }
        c.drawRoundRect(1 * u, 16 * u, 6 * u, 21 * u, 2 * u, 2 * u, mirror)
        c.drawRoundRect(w - 6 * u, 16 * u, w - 1 * u, 21 * u, 2 * u, 2 * u, mirror)
        // Body.
        val bodyPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = body }
        c.drawRoundRect(4 * u, 2 * u, w - 4 * u, h - 4 * u, 10 * u, 10 * u, bodyPaint)
        // Roof (a lighter shade).
        val roof = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = android.graphics.Color.argb(255, (android.graphics.Color.red(body) + 40).coerceAtMost(255), (android.graphics.Color.green(body) + 40).coerceAtMost(255), (android.graphics.Color.blue(body) + 40).coerceAtMost(255)) }
        c.drawRoundRect(7 * u, 19 * u, w - 7 * u, h - 17 * u, 5 * u, 5 * u, roof)
        // Windscreen and rear glass.
        val glass = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0xFFA9D2FF.toInt() }
        val front = Path().apply { moveTo(8 * u, 18 * u); lineTo(w - 8 * u, 18 * u); lineTo(w - 10 * u, 11 * u); lineTo(10 * u, 11 * u); close() }
        c.drawPath(front, glass)
        val rear = Path().apply { moveTo(9 * u, h - 16 * u); lineTo(w - 9 * u, h - 16 * u); lineTo(w - 10 * u, h - 10 * u); lineTo(10 * u, h - 10 * u); close() }
        c.drawPath(rear, glass)
        // Headlights and tail lights.
        c.drawCircle(cx - 8 * u, 4.5f * u, 1.8f * u, Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0xFFFFF3B0.toInt() })
        c.drawCircle(cx + 8 * u, 4.5f * u, 1.8f * u, Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0xFFFFF3B0.toInt() })
        c.drawRect(cx - 10 * u, h - 6.5f * u, cx - 6 * u, h - 5 * u, Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0xFFEF4444.toInt() })
        c.drawRect(cx + 6 * u, h - 6.5f * u, cx + 10 * u, h - 5 * u, Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0xFFEF4444.toInt() })
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
