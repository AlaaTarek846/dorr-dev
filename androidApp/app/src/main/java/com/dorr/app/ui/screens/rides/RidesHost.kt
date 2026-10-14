package com.dorr.app.ui.screens.rides

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.EventNote
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideDto
import com.dorr.app.network.RideFeaturesDto
import com.dorr.app.network.RideQuoteDto
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.theme.AppColors
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.model.BitmapDescriptorFactory
import com.google.android.gms.maps.model.CameraPosition
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.LatLngBounds
import com.google.gson.Gson
import com.google.maps.android.compose.CameraPositionState
import com.google.maps.android.compose.GoogleMap
import com.google.maps.android.compose.MapProperties
import com.google.maps.android.compose.MapUiSettings
import com.google.maps.android.compose.Marker
import com.google.maps.android.compose.MarkerState
import com.google.maps.android.compose.Polyline
import com.google.maps.android.compose.rememberCameraPositionState
import kotlinx.coroutines.launch

/** Where the booking is: the map (pickup), the place search, picking a point on the map, the prices. */
internal enum class RideStep { Map, Search, PickOnMap, Quotes }

/** Everything the rides screens share while one is open. */
internal class RidesState {
    var me by mutableStateOf<LatLng?>(null)
    var pickup by mutableStateOf<RidePoint?>(null)
    var destination by mutableStateOf<RidePoint?>(null)
    val stops = mutableStateListOf<RidePoint>()
    var openDestination by mutableStateOf(false)
    var features by mutableStateOf<RideFeaturesDto?>(null)
    var step by mutableStateOf(RideStep.Map)

    /** Which point the search / the map pin is setting: "pickup", "destination" or "stop:<index>". */
    var editing by mutableStateOf("destination")
    var quotes by mutableStateOf<List<RideQuoteDto>>(emptyList())
    var selected by mutableStateOf<RideQuoteDto?>(null)
    var payment by mutableStateOf("wallet")

    /** Bidding: the price the rider offers (starts at the suggested price). */
    var riderPrice by mutableStateOf<Long?>(null)

    /** Booked ahead (R-12): the chosen pickup time; null = now. */
    var scheduledAt by mutableStateOf<java.time.ZonedDateTime?>(null)
    var scheduleOpen by mutableStateOf(false)
    var myRidesOpen by mutableStateOf(false)

    /** The Safety Center page is open (R-219). */
    var safetyOpen by mutableStateOf(false)

    /** An "Are you OK?" from outside a ride on screen — e.g. the check-in after arriving (R-223). */
    var pendingCheck by mutableStateOf<com.dorr.app.network.SafetyCheckDto?>(null)

    /** Changing the trip during the ride: "destination" or "add_stop" while its page is open (R-91..95). */
    var changing by mutableStateOf<String?>(null)

    /** The ride on screen was opened from "My rides": closing it goes back to booking, not out. */
    var fromHistory by mutableStateOf(false)
    var ride by mutableStateOf<RideDto?>(null)
    var busy by mutableStateOf(false)
    var error by mutableStateOf<String?>(null)

    fun feature(name: String): Boolean = features?.features?.get(name) == true

    fun set(point: RidePoint) {
        when {
            editing == "pickup" -> pickup = point
            editing == "destination" -> { destination = point; openDestination = false }
            editing.startsWith("stop:") -> editing.removePrefix("stop:").toIntOrNull()?.let { i ->
                if (i < stops.size) stops[i] = point else stops.add(point)
            }
        }
    }

    fun resetBooking() {
        destination = null
        stops.clear()
        openDestination = false
        quotes = emptyList()
        selected = null
        step = RideStep.Map
        error = null
        scheduledAt = null
        fromHistory = false
    }
}

private val Riyadh = LatLng(24.7136, 46.6753)

/**
 * DORR Rides for the rider — opened from the services (module `passenger_ride`). One screen on a
 * map: set the pickup under the pin, say where to, pick a ride and a way to pay, then follow the
 * captain until the end and rate. An active ride is picked up again when the screen opens.
 */
@Composable
fun RidesHost(onExit: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val state = remember { RidesState() }
    val camera = rememberCameraPositionState { position = CameraPosition.fromLatLngZoom(Riyadh, 15f) }
    var hasLocation by remember {
        mutableStateOf(ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED)
    }
    var resumed by remember { mutableStateOf(false) }
    val askLocation = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        hasLocation = granted.values.any { it }
    }

    // An active ride first: the app may have been closed in the middle of one.
    LaunchedEffect(Unit) {
        runCatching { ApiClient.rides.current(ridesAuth()).data }.getOrNull()?.takeIf { it.isJsonObject }?.let {
            state.ride = Gson().fromJson(it, RideDto::class.java)
        }
        resumed = true
        // A check-in still waiting after a ride that ended (R-223).
        state.pendingCheck = runCatching { ApiClient.rides.safety(ridesAuth()).data?.pendingCheck }.getOrNull()
        if (!hasLocation) askLocation.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
    }

    // Where am I: the camera goes there, and the service switches for this city are read.
    LaunchedEffect(hasLocation) {
        val here = if (hasLocation) currentLocation(context) else null
        state.me = here
        if (here != null && state.ride == null) camera.move(CameraUpdateFactory.newLatLngZoom(here, 16f))
        state.features = runCatching { ApiClient.rides.features(ridesAuth(), here?.latitude, here?.longitude).data }.getOrNull()
    }

    // The pin: when the map stops, the point under it becomes the pickup (or the point being picked).
    LaunchedEffect(camera.isMoving, state.step) {
        if (!camera.isMoving && state.ride == null && (state.step == RideStep.Map || state.step == RideStep.PickOnMap)) {
            val target = camera.position.target
            val name = addressOf(context, target) ?: String.format("%.5f, %.5f", target.latitude, target.longitude)
            val point = RidePoint(target.latitude, target.longitude, name)
            if (state.step == RideStep.Map) state.pickup = point else state.set(point)
        }
    }

    BackHandler {
        when {
            state.scheduleOpen -> state.scheduleOpen = false
            state.safetyOpen -> state.safetyOpen = false
            state.changing != null -> state.changing = null
            state.myRidesOpen -> state.myRidesOpen = false
            state.ride != null && state.fromHistory -> { state.ride = null; state.resetBooking() }
            state.ride != null -> onExit()
            state.step == RideStep.Search || state.step == RideStep.PickOnMap -> state.step = if (state.quotes.isNotEmpty()) RideStep.Quotes else RideStep.Map
            state.step == RideStep.Quotes -> state.resetBooking()
            else -> onExit()
        }
    }

    Box(Modifier.fillMaxSize().background(settingsBackground())) {
        RidesMap(state, camera)

        val pinVisible = state.ride == null && (state.step == RideStep.Map || state.step == RideStep.PickOnMap)
        if (pinVisible) CenterPin(lifted = camera.isMoving, color = if (state.step == RideStep.Map) AppColors.brandNavy else AppColors.brandOrange)

        // Back, and "my location".
        Box(Modifier.fillMaxWidth().statusBarsPadding().padding(16.dp)) {
            RoundButton(Icons.AutoMirrored.Rounded.ArrowBack, Modifier.align(Alignment.TopStart)) {
                if (state.ride == null && state.step != RideStep.Map) state.resetBooking() else onExit()
            }
            Column(Modifier.align(Alignment.TopEnd), horizontalAlignment = Alignment.End) {
                // 🛡️ The safety button, always on top (plan §18.13, R-219).
                RoundButton(Icons.Rounded.Shield) { state.safetyOpen = true }
                Spacer(Modifier.height(10.dp))
                // My rides: current, upcoming and past (R-163..165).
                if (state.ride == null) RoundButton(Icons.Rounded.EventNote) { state.myRidesOpen = true }
                if (state.me != null && state.ride == null) {
                    Spacer(Modifier.height(10.dp))
                    RoundButton(Icons.Rounded.MyLocation) {
                        state.me?.let { scope.launch { camera.animate(CameraUpdateFactory.newLatLngZoom(it, 16f)) } }
                    }
                }
            }
        }

        Box(Modifier.align(Alignment.BottomCenter).fillMaxWidth()) {
            AnimatedContent(
                targetState = when {
                    state.ride != null -> "ride"
                    !resumed -> "loading"
                    state.features != null && !state.features!!.service -> "off"
                    else -> state.step.name
                },
                transitionSpec = { (slideInVertically(tween(320)) { it / 3 } + fadeIn(tween(240))) togetherWith fadeOut(tween(160)) },
                label = "rides-panel",
            ) { panel ->
                when (panel) {
                    "ride" -> RideActivePanel(state, camera, onExit = onExit)
                    "loading" -> PanelCard { Text(stringResource(R.string.rd_locating), color = settingsInk(), modifier = Modifier.padding(8.dp)) }
                    "off" -> PanelCard { Text(stringResource(R.string.rd_not_available), color = settingsInk(), fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(8.dp)) }
                    RideStep.Map.name -> WhereToPanel(state, hasLocation, onAskLocation = {
                        askLocation.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
                    })
                    RideStep.PickOnMap.name -> ConfirmPointPanel(state)
                    RideStep.Quotes.name -> QuotesPanel(state, camera)
                    else -> Spacer(Modifier.height(1.dp))
                }
            }
        }

        if (state.step == RideStep.Search && state.ride == null) PlaceSearchPage(state, camera)

        androidx.compose.animation.AnimatedVisibility(
            visible = state.myRidesOpen,
            enter = androidx.compose.animation.slideInHorizontally(tween(300)) { it } + fadeIn(tween(200)),
            exit = androidx.compose.animation.slideOutHorizontally(tween(260)) { it } + fadeOut(tween(160)),
        ) { MyRidesPage(state, onClose = { state.myRidesOpen = false }) }

        androidx.compose.animation.AnimatedVisibility(
            visible = state.changing != null && state.ride != null,
            enter = androidx.compose.animation.slideInVertically(tween(300)) { it / 2 } + fadeIn(tween(200)),
            exit = fadeOut(tween(160)),
        ) { ChangeTripPage(state, onClose = { state.changing = null }) }

        androidx.compose.animation.AnimatedVisibility(
            visible = state.safetyOpen,
            enter = androidx.compose.animation.slideInHorizontally(tween(300)) { it } + fadeIn(tween(200)),
            exit = androidx.compose.animation.slideOutHorizontally(tween(260)) { it } + fadeOut(tween(160)),
        ) { SafetyCenterPage(state, onClose = { state.safetyOpen = false }) }
    }

    if (state.scheduleOpen) SchedulePickerSheet(state, onClose = { state.scheduleOpen = false })
    // "Are you OK?" — during the ride (from the ride) or after it (the check-in).
    val check = state.ride?.safetyCheck ?: state.pendingCheck
    val checkRide = state.ride?.takeIf { it.safetyCheck != null }?.uuid ?: state.pendingCheck?.rideUuid
    if (check != null && checkRide != null) SafetyCheckDialog(checkRide, check, onDone = {
        state.pendingCheck = null
        state.ride = state.ride?.copy(safetyCheck = null)
    })
}

@Composable
private fun RidesMap(state: RidesState, camera: CameraPositionState) {
    val ride = state.ride
    val route = remember(ride?.route?.polyline, state.selected?.route?.polyline) {
        decodePolyline(ride?.route?.polyline ?: state.selected?.route?.polyline)
    }
    GoogleMap(
        modifier = Modifier.fillMaxSize(),
        cameraPositionState = camera,
        properties = MapProperties(isMyLocationEnabled = false),
        uiSettings = MapUiSettings(zoomControlsEnabled = false, myLocationButtonEnabled = false, mapToolbarEnabled = false, compassEnabled = false),
        contentPadding = androidx.compose.foundation.layout.PaddingValues(bottom = 260.dp),
    ) {
        val showPoints = ride != null || state.step == RideStep.Quotes
        if (showPoints) {
            val from = ride?.origin?.let { LatLng(it.lat, it.lng) } ?: state.pickup?.latLng
            val to = ride?.destination?.let { LatLng(it.lat, it.lng) } ?: state.destination?.latLng
            from?.let { Marker(state = remember(it) { MarkerState(it) }, icon = BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_AZURE)) }
            to?.let { Marker(state = remember(it) { MarkerState(it) }, icon = BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_ORANGE)) }
            (ride?.stops?.map { LatLng(it.lat, it.lng) } ?: state.stops.map { it.latLng }).forEach { stop ->
                Marker(state = remember(stop) { MarkerState(stop) }, icon = BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_YELLOW))
            }
            if (route.size > 1) Polyline(points = route, color = AppColors.brandNavy, width = 12f)
        }
        ride?.driver?.location?.let { loc ->
            val at = LatLng(loc.lat, loc.lng)
            Marker(state = remember(at) { MarkerState(at) }, icon = BitmapDescriptorFactory.defaultMarker(BitmapDescriptorFactory.HUE_GREEN), rotation = (loc.heading ?: 0).toFloat(), flat = true)
        }
    }

    // Keep the whole trip in view once it is planned or running.
    LaunchedEffect(route.size, ride?.status) {
        val points = route.ifEmpty { listOfNotNull(ride?.origin?.let { LatLng(it.lat, it.lng) }, ride?.destination?.let { LatLng(it.lat, it.lng) }) }
        if (points.size > 1) {
            val bounds = LatLngBounds.builder().apply { points.forEach { include(it) } }.build()
            runCatching { camera.animate(CameraUpdateFactory.newLatLngBounds(bounds, 120)) }
        }
    }
}

/** The pin in the middle of the map: it lifts while the map moves, drops when it stops. */
@Composable
private fun CenterPin(lifted: Boolean, color: Color) {
    val lift by animateFloatAsState(if (lifted) -18f else 0f, tween(180), label = "pin")
    Box(Modifier.fillMaxSize().padding(bottom = 260.dp), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.offset(y = (-22).dp).graphicsLayer { translationY = lift }) {
            Box(Modifier.size(30.dp).shadow(6.dp, CircleShape).clip(CircleShape).background(color), contentAlignment = Alignment.Center) {
                Box(Modifier.size(10.dp).clip(CircleShape).background(Color.White))
            }
            Box(Modifier.width(3.dp).height(16.dp).background(color))
        }
        Box(Modifier.size(width = 10.dp, height = 4.dp).clip(CircleShape).background(Color.Black.copy(alpha = if (lifted) 0.15f else 0.3f)))
    }
}

@Composable
internal fun RoundButton(icon: androidx.compose.ui.graphics.vector.ImageVector, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Box(
        modifier.size(44.dp).shadow(6.dp, CircleShape).clip(CircleShape).background(settingsCard()).clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = settingsInk()) }
}

/** The white card at the bottom that every step draws into. */
@Composable
internal fun PanelCard(content: @Composable () -> Unit) {
    Column(
        Modifier.fillMaxWidth().shadow(18.dp, RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp))
            .clip(RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)).background(settingsCard())
            .padding(horizontal = 18.dp).padding(top = 10.dp, bottom = 24.dp),
    ) {
        Box(Modifier.align(Alignment.CenterHorizontally).size(width = 38.dp, height = 4.dp).clip(CircleShape).background(settingsInk().copy(alpha = 0.15f)))
        Spacer(Modifier.height(12.dp))
        content()
    }
}

@Composable
internal fun AccentButton(text: String, enabled: Boolean = true, busy: Boolean = false, color: Color = settingsAccent(), onClick: () -> Unit) {
    Box(
        Modifier.fillMaxWidth().height(54.dp).clip(RoundedCornerShape(16.dp))
            .background(if (enabled) color else color.copy(alpha = 0.4f))
            .clickable(enabled = enabled && !busy, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (busy) androidx.compose.material3.CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
        else Text(text, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 16.sp)
    }
}
