package com.dorr.provider.ui.screens

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.content.BroadcastReceiver
import android.content.Context
import android.content.ContextWrapper
import android.content.Intent
import android.content.IntentFilter
import android.location.Address
import android.location.Geocoder
import android.location.LocationManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.IntentSenderRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.TouchApp
import androidx.compose.material.icons.rounded.TrackChanges
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Slider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.layout.positionInRoot
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.core.content.ContextCompat
import androidx.core.location.LocationManagerCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.dorr.provider.R
import com.dorr.provider.net.AppLocale
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.kit.AccountDark
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsBackground
import com.dorr.provider.ui.kit.settingsCard
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.dorr.provider.ui.kit.settingsNight
import com.dorr.provider.ui.kit.settingsSurface
import com.google.android.gms.common.api.ResolvableApiException
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.LocationSettingsRequest
import com.google.android.gms.location.Priority
import com.google.android.gms.maps.model.LatLng
import com.dorr.provider.map.DorrMap
import com.dorr.provider.map.MapZone
import com.dorr.provider.map.rememberDorrCamera
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import kotlin.coroutines.resume
import kotlin.math.roundToInt
import kotlin.math.sqrt

/*
 * The branch's place on a real map (the onboarding's main branch and the branches page), in the look of the rider app's ride
 * map: the map fills the screen, a pin stays in the middle (lifted while the map moves, landing with a pulse), "my location"
 * brings the map back to the phone, and once the map rests the address under the pin is written in the address field by
 * itself (the phone's geocoder, off the main thread) — the person can still change it. No location yet: the rider app's
 * location window asks for the permission and to switch location on, instead of a text explaining how.
 * The map is [DorrMap] (map/DorrMap.kt, the captain app's): Google or Mapbox, the engine the admin chose; a light circle
 * shows the branch's range around the pin.
 */

/** The range a new branch starts with (the server gives a branch without one the same 100 km). */
internal const val DEFAULT_RADIUS_KM = 100.0

/** Quick choices under the range slider (km). */
private val RADIUS_CHIPS = listOf(10, 25, 50, 100, 200, 500)

/** Where the map waits before the phone's place is known: a wide view, never taken as the branch's place. */
private val WAITING_VIEW = LatLng(24.0, 45.0)

/** Location is allowed and switched on. */
internal fun locationReady(context: Context): Boolean =
    hasLocationPermission(context) && LocationManagerCompat.isLocationEnabled(context.getSystemService(LocationManager::class.java))

/**
 * The full-screen map to set a branch's place. [start] is the place it already has (null = open on the phone's location).
 * [onPlace] gets the point under the pin each time the map rests after a move, [onAddress] the address found for it.
 * [top] goes under the title in the header card (the onboarding's step bar); [panel] is the card at the bottom (the address,
 * the range and the main button). [radiusKm]: the range chosen in the panel, drawn as a light circle around the pin.
 */
@Composable
internal fun BranchMapScreen(
    title: String,
    start: LatLng?,
    onBack: () -> Unit,
    onPlace: (LatLng) -> Unit,
    onAddress: (String) -> Unit,
    radiusKm: Double? = null,
    top: (@Composable () -> Unit)? = null,
    panel: @Composable ColumnScope.() -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val density = LocalDensity.current
    val night = settingsNight()
    val locateFailed = stringResource(R.string.onb_map_locate_failed)

    val camera = rememberDorrCamera(start ?: WAITING_VIEW, if (start != null) 16f else 4f)
    // The pin means a place only once the phone's position is known or the person moved the map (the waiting view is no place).
    var placed by rememberSaveable { mutableStateOf(start != null) }
    var touched by rememberSaveable { mutableStateOf(false) }
    var gate by remember { mutableStateOf(start == null && !locationReady(context)) }
    var permitted by remember { mutableStateOf(hasLocationPermission(context)) }
    var locating by remember { mutableStateOf(false) }
    var resolving by remember { mutableStateOf(false) }
    var label by remember { mutableStateOf<String?>(null) }
    var sawMove by remember { mutableStateOf(false) }
    // The last place given to [onPlace]: a rest on the same point (the map settling after the panel's padding changed, the
    // engine's first idle) neither moves the branch nor writes over an address the person already has.
    var lastPlace by remember { mutableStateOf(start) }
    var error by remember { mutableStateOf<String?>(null) }
    // Where the header card ends and the panel's height, so the pin and the map's centre sit in the free part between them.
    var rootY by remember { mutableFloatStateOf(0f) }
    var headerBottomPx by remember { mutableFloatStateOf(0f) }
    var panelPx by remember { mutableIntStateOf(0) }

    fun goToMe() {
        if (locating) return
        locating = true
        error = null
        scope.launch {
            val here = phonePosition(context)
            locating = false
            if (here == null) {
                error = locateFailed
                return@launch
            }
            placed = true
            runCatching { camera.animate(here, 16f) }
        }
    }

    fun myLocation() {
        if (locationReady(context)) {
            permitted = true
            goToMe()
        } else {
            gate = true
        }
    }

    // Opened with no place yet and location already allowed and on: straight to where the phone is.
    LaunchedEffect(Unit) { if (start == null && !placed && !gate) goToMe() }

    // The pin: once the map rests after a move, the point under it is the branch's place and its address is looked up.
    LaunchedEffect(camera.isMoving) {
        if (camera.isMoving) {
            sawMove = true
            if (camera.byGesture) {
                placed = true
                touched = true
            }
            return@LaunchedEffect
        }
        if (!placed || !sawMove) return@LaunchedEffect
        val at = camera.target
        if (lastPlace?.let { samePlace(it, at) } == true) return@LaunchedEffect
        lastPlace = at
        onPlace(at)
        resolving = true
        val found = addressOf(context, at.latitude, at.longitude)
        resolving = false
        label = found
        if (found != null) onAddress(found)
    }

    BoxWithConstraints(
        Modifier.fillMaxSize().background(settingsBackground()).imePadding().onGloballyPositioned { rootY = it.positionInRoot().y },
    ) {
        val maxPanel = maxHeight * 0.62f
        val headerDp = with(density) { (headerBottomPx - rootY).coerceAtLeast(0f).toDp() }
        val panelDp = with(density) { panelPx.toDp() }

        // The range around the pin while it rests (hidden while the map moves: the circle would lag behind the pin).
        val accent = settingsAccent()
        val edgePx = with(density) { 1.5.dp.toPx() }
        val range = radiusKm
        val zones = if (placed && range != null && !camera.isMoving) {
            listOf(MapZone(camera.target, range * 1000.0, accent.copy(alpha = 0.07f), stroke = accent.copy(alpha = 0.55f), strokePx = edgePx))
        } else {
            emptyList()
        }
        DorrMap(
            camera = camera,
            modifier = Modifier.fillMaxSize(),
            zones = zones,
            showMe = permitted,
            padding = PaddingValues(top = headerDp, bottom = panelDp),
            night = night,
            googleNightStyle = R.raw.map_night,
            fixedNorth = true,
        )

        // The pin sits in the middle of the map part left between the header and the panel.
        Box(Modifier.fillMaxSize().padding(top = headerDp, bottom = panelDp), contentAlignment = Alignment.Center) {
            PlacePin(
                lifted = camera.isMoving,
                label = when {
                    !placed -> null
                    resolving -> stringResource(R.string.onb_map_finding_address)
                    else -> label ?: stringResource(R.string.onb_map_pin_here)
                },
            )
        }

        // Header: the title, the back button and what the caller adds (the steps); a hint until the map is first moved.
        Column(Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 14.dp).padding(top = 10.dp)) {
            Column(
                Modifier.onGloballyPositioned { headerBottomPx = it.positionInRoot().y + it.size.height }.fillMaxWidth()
                    .settingsSurface(RoundedCornerShape(22.dp))
                    .padding(horizontal = 14.dp, vertical = 12.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        title, color = settingsAccent(), fontSize = 19.sp, fontWeight = FontWeight.Black,
                        maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f),
                    )
                    Spacer(Modifier.width(8.dp))
                    Box(
                        Modifier.size(34.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.10f)).clickable(onClick = onBack),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(
                            Icons.AutoMirrored.Rounded.ArrowBack, contentDescription = stringResource(R.string.common_back), tint = settingsAccent(),
                            // Same arrow as the app's headers.
                            modifier = Modifier.size(16.dp).graphicsLayer { scaleX = -1f },
                        )
                    }
                }
                top?.invoke()
            }
            AnimatedVisibility(
                visible = !touched,
                enter = fadeIn() + expandVertically(),
                exit = fadeOut(tween(200)) + shrinkVertically(tween(220)),
                modifier = Modifier.align(Alignment.CenterHorizontally),
            ) {
                Row(
                    Modifier.padding(top = 10.dp).shadow(6.dp, CircleShape).clip(CircleShape).background(Color(0xFF111928))
                        .padding(horizontal = 14.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.TouchApp, contentDescription = null, tint = Color.White, modifier = Modifier.size(16.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(R.string.onb_map_hint), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                }
            }
        }

        // Bottom: "my location" over the map, then the panel (it scrolls when it is taller than most of the screen).
        Column(Modifier.align(Alignment.BottomCenter).fillMaxWidth()) {
            Box(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp), contentAlignment = Alignment.CenterEnd) {
                MapRoundButton(busy = locating, onClick = ::myLocation)
            }
            val shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)
            Column(
                Modifier.onSizeChanged { panelPx = it.height }
                    .fillMaxWidth()
                    .shadow(18.dp, shape)
                    .clip(shape)
                    .background(settingsCard())
                    .then(if (night) Modifier.border(1.dp, AccountDark.line, shape) else Modifier)
                    .heightIn(max = maxPanel)
                    .verticalScroll(rememberScrollState())
                    .navigationBarsPadding()
                    .padding(horizontal = 18.dp)
                    .padding(top = 10.dp, bottom = 20.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                Box(Modifier.align(Alignment.CenterHorizontally).size(width = 38.dp, height = 4.dp).clip(CircleShape).background(settingsInk().copy(alpha = 0.15f)))
                panel()
            }
        }
    }

    if (gate) {
        LocationGate(
            onReady = {
                gate = false
                permitted = hasLocationPermission(context)
                goToMe()
            },
            // Without a place there is nothing to show: back to where the person came from.
            onLeave = {
                gate = false
                if (!placed) onBack()
            },
        )
    }
    ErrorBanner(error)
}

/** Two points the same to about two metres. */
private fun samePlace(a: LatLng, b: LatLng): Boolean =
    kotlin.math.abs(a.latitude - b.latitude) < 2e-5 && kotlin.math.abs(a.longitude - b.longitude) < 2e-5

/** The rider app's pin: a drop with a soft pulse on the ground while it rests, lifted while the map moves, and its bubble. */
@Composable
private fun PlacePin(lifted: Boolean, label: String?) {
    val color = settingsAccent()
    val lift by animateFloatAsState(if (lifted) -26f else 0f, spring(dampingRatio = 0.5f), label = "pin")
    val pulse by rememberInfiniteTransition(label = "ground").animateFloat(0f, 1f, infiniteRepeatable(tween(1600, easing = LinearEasing)), label = "p")
    Box(contentAlignment = Alignment.Center) {
        if (!lifted) {
            Box(Modifier.size(46.dp).graphicsLayer { scaleX = 0.4f + pulse; scaleY = (0.4f + pulse) * 0.4f; alpha = 1f - pulse }.clip(CircleShape).background(color.copy(alpha = 0.35f)))
        }
        Box(Modifier.size(width = 12.dp, height = 5.dp).clip(CircleShape).background(Color.Black.copy(alpha = if (lifted) 0.12f else 0.28f)))
        Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.offset(y = (-30).dp).graphicsLayer { translationY = lift }) {
            AnimatedVisibility(visible = !lifted && label != null, enter = scaleIn(spring(dampingRatio = 0.55f)) + fadeIn(), exit = scaleOut() + fadeOut()) {
                Text(
                    label.orEmpty(), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.widthIn(max = 260.dp).padding(bottom = 6.dp).shadow(6.dp, CircleShape).clip(CircleShape)
                        .background(Color(0xFF111928)).padding(horizontal = 12.dp, vertical = 6.dp),
                )
            }
            Box(Modifier.size(38.dp).shadow(8.dp, CircleShape).clip(CircleShape).background(color), contentAlignment = Alignment.Center) {
                Box(Modifier.size(14.dp).clip(CircleShape).background(Color.White))
            }
            Box(Modifier.size(width = 4.dp, height = 18.dp).clip(RoundedCornerShape(bottomStart = 2.dp, bottomEnd = 2.dp)).background(color))
        }
    }
}

/** The rider app's round map button ("my location"), with a spinner while the phone looks for its place. */
@Composable
private fun MapRoundButton(busy: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(48.dp)
            .then(if (settingsNight()) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier.shadow(6.dp, CircleShape))
            .clip(CircleShape).background(settingsCard()).clickable(enabled = !busy, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (busy) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
        else Icon(Icons.Rounded.MyLocation, contentDescription = stringResource(R.string.onb_map_my_location), tint = settingsAccent())
    }
}

/**
 * The range around the branch that requests reach it from: the value in big letters, a slider (finer on short distances), quick
 * choices, and one plain sentence saying what it means with the value chosen.
 */
@Composable
internal fun RadiusPicker(km: Double, onChange: (Double) -> Unit) {
    val accent = settingsAccent()
    val night = settingsNight()
    val ink = settingsInk()
    val shape = RoundedCornerShape(20.dp)
    Column(
        Modifier.fillMaxWidth().clip(shape).background(accent.copy(alpha = if (night) 0.14f else 0.05f))
            .border(1.dp, accent.copy(alpha = 0.18f), shape).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(40.dp).clip(RoundedCornerShape(13.dp)).background(accent.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.TrackChanges, contentDescription = null, tint = accent, modifier = Modifier.size(22.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.onb_radius_title), color = ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.onb_radius_sub), color = settingsMut(), fontSize = 12.sp, lineHeight = 16.sp)
            }
            Spacer(Modifier.width(8.dp))
            AnimatedContent(
                targetState = formatKm(km),
                transitionSpec = { (fadeIn(tween(180)) + slideInVertically(tween(220)) { it / 2 }) togetherWith fadeOut(tween(120)) },
                label = "radiusValue",
            ) { value ->
                Text(stringResource(R.string.onb_radius_value, value), color = accent, fontSize = 20.sp, fontWeight = FontWeight.Black)
            }
        }
        Slider(
            value = radiusToSlider(km),
            onValueChange = { onChange(sliderToRadius(it)) },
            colors = SliderDefaults.colors(
                thumbColor = accent, activeTrackColor = accent, inactiveTrackColor = accent.copy(alpha = 0.18f),
                activeTickColor = Color.Transparent, inactiveTickColor = Color.Transparent,
            ),
        )
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            RADIUS_CHIPS.forEach { chip ->
                val on = km == chip.toDouble()
                val chipShape = RoundedCornerShape(12.dp)
                val fill by animateColorAsState(if (on) accent else settingsCard(), tween(180), label = "chip$chip")
                Text(
                    chip.toString(), color = if (on) Color.White else ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                    modifier = Modifier.weight(1f).clip(chipShape).background(fill)
                        .border(1.dp, if (on) accent else if (night) AccountDark.line else Color(0xFFE5E7EB), chipShape)
                        .clickable { onChange(chip.toDouble()) }.padding(vertical = 8.dp),
                )
            }
        }
        Row(verticalAlignment = Alignment.Top) {
            Icon(Icons.Rounded.Info, contentDescription = null, tint = accent, modifier = Modifier.padding(top = 1.dp).size(16.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.onb_radius_explain, formatKm(km)), color = ink, fontSize = 12.sp, lineHeight = 18.sp)
        }
    }
}

/** Slider position (0..1) for a range: the square root, so the short distances get most of the track. */
private fun radiusToSlider(km: Double): Float = sqrt((km.coerceIn(1.0, 500.0) - 1.0) / 499.0).toFloat()

/** The range for a slider position: whole km, and steps of 5 km from 100 km up. */
private fun sliderToRadius(position: Float): Double {
    val raw = 1.0 + 499.0 * position * position
    val km = if (raw >= 100.0) (raw / 5.0).roundToInt() * 5.0 else raw.roundToInt().toDouble()
    return km.coerceIn(1.0, 500.0)
}

/**
 * The rider app's location window (androidApp `rides/LocationGate.kt`): it can't be dismissed by a tap outside, asks first for
 * the permission, then for the phone's location to be on — the system's own «turn on location» dialog, without leaving the
 * app. It checks again every time the app comes back (from the settings) and the moment the location switch flips.
 */
@Composable
internal fun LocationGate(onReady: () -> Unit, onLeave: (() -> Unit)? = null) {
    val context = LocalContext.current
    fun permitted() = hasLocationPermission(context)
    fun switchedOn() = LocationManagerCompat.isLocationEnabled(context.getSystemService(LocationManager::class.java))
    var allowed by remember { mutableStateOf(permitted()) }
    var on by remember { mutableStateOf(switchedOn()) }
    var askedOnce by remember { mutableStateOf(false) }

    // Back from the settings (or any other app): check again.
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    DisposableEffect(lifecycle) {
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME) {
                allowed = permitted()
                on = switchedOn()
            }
        }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }
    // The location switch flipped (from the quick settings): at once.
    DisposableEffect(Unit) {
        val receiver = object : BroadcastReceiver() {
            override fun onReceive(c: Context, intent: Intent) {
                on = switchedOn()
            }
        }
        ContextCompat.registerReceiver(context, receiver, IntentFilter(LocationManager.PROVIDERS_CHANGED_ACTION), ContextCompat.RECEIVER_NOT_EXPORTED)
        onDispose { runCatching { context.unregisterReceiver(receiver) } }
    }
    LaunchedEffect(allowed, on) { if (allowed && on) onReady() }

    val askPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        allowed = permitted()
        askedOnce = true
    }
    val turnOn = rememberLauncherForActivityResult(ActivityResultContracts.StartIntentSenderForResult()) { on = switchedOn() }
    if (allowed && on) return

    val denyForever = askedOnce && !allowed && context.findActivity()?.shouldShowRequestPermissionRationale(Manifest.permission.ACCESS_FINE_LOCATION) == false
    val bob = rememberInfiniteTransition(label = "gatePin").animateFloat(0f, 1f, infiniteRepeatable(tween(900), RepeatMode.Reverse), label = "bob")
    val accent = settingsAccent()

    Dialog(onDismissRequest = {}, properties = DialogProperties(dismissOnBackPress = false, dismissOnClickOutside = false, usePlatformDefaultWidth = false)) {
        Column(
            Modifier.padding(24.dp).fillMaxWidth().clip(RoundedCornerShape(28.dp)).background(settingsCard()).padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(
                Modifier.size(96.dp).clip(CircleShape).background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.72f)))),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Rounded.LocationOn, contentDescription = null, tint = Color.White, modifier = Modifier.size(52.dp).graphicsLayer { translationY = -bob.value * 10f })
            }
            Spacer(Modifier.height(18.dp))
            Text(
                stringResource(if (!allowed) R.string.lg_title_permission else R.string.lg_title_off),
                color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp, textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(8.dp))
            Text(
                stringResource(if (!allowed) R.string.lg_body_permission else R.string.lg_body_off),
                color = settingsMut(), fontSize = 14.sp, lineHeight = 21.sp, textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(22.dp))
            Text(
                stringResource(if (!allowed) (if (denyForever) R.string.lg_open_settings else R.string.lg_allow) else R.string.lg_turn_on),
                color = Color.White, fontWeight = FontWeight.Bold, fontSize = 16.sp, textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(accent).clickable {
                    when {
                        !allowed && denyForever -> context.startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", context.packageName, null)))
                        !allowed -> askPermission.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
                        else -> {
                            val request = LocationSettingsRequest.Builder()
                                .addLocationRequest(LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, 10_000).build())
                                .setAlwaysShow(true).build()
                            LocationServices.getSettingsClient(context).checkLocationSettings(request)
                                .addOnSuccessListener { on = switchedOn() }
                                .addOnFailureListener { e ->
                                    if (e is ResolvableApiException) runCatching { turnOn.launch(IntentSenderRequest.Builder(e.resolution).build()) }
                                    else context.startActivity(Intent(Settings.ACTION_LOCATION_SOURCE_SETTINGS))
                                }
                        }
                    }
                }.padding(vertical = 15.dp),
            )
            onLeave?.let {
                Text(
                    stringResource(R.string.lg_leave), color = settingsMut(), fontSize = 14.sp, textAlign = TextAlign.Center,
                    modifier = Modifier.padding(top = 10.dp).fillMaxWidth().clip(RoundedCornerShape(16.dp)).clickable(onClick = it).padding(vertical = 10.dp),
                )
            }
        }
    }
}

private fun Context.findActivity(): Activity? {
    var c: Context? = this
    while (c is ContextWrapper) {
        if (c is Activity) return c
        c = c.baseContext
    }
    return null
}

/** The phone's place now (12 s at most), else the last one it knew; null without the permission or any fix. */
@SuppressLint("MissingPermission")
internal suspend fun phonePosition(context: Context): LatLng? {
    if (!hasLocationPermission(context)) return null
    withTimeoutOrNull(12_000) { currentPosition(context) }?.let { return LatLng(it.first, it.second) }
    return runCatching {
        suspendCancellableCoroutine<LatLng?> { cont ->
            LocationServices.getFusedLocationProviderClient(context).lastLocation
                .addOnSuccessListener { loc -> cont.resume(loc?.let { LatLng(it.latitude, it.longitude) }) }
                .addOnFailureListener { cont.resume(null) }
        }
    }.getOrNull()
}

/**
 * The address under a point, in the app's language: street, district and city (the phone's geocoder; the listener API from
 * Android 13, the blocking one on a background thread before it). Null when the phone can't tell.
 */
internal suspend fun addressOf(context: Context, lat: Double, lng: Double): String? {
    if (!Geocoder.isPresent()) return null
    val geocoder = Geocoder(context, AppLocale.java())
    val found: Address? = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
        withTimeoutOrNull(8_000) {
            suspendCancellableCoroutine<Address?> { cont ->
                geocoder.getFromLocation(lat, lng, 1, object : Geocoder.GeocodeListener {
                    override fun onGeocode(addresses: MutableList<Address>) {
                        if (cont.isActive) cont.resume(addresses.firstOrNull())
                    }

                    override fun onError(errorMessage: String?) {
                        if (cont.isActive) cont.resume(null)
                    }
                })
            }
        }
    } else {
        withContext(Dispatchers.IO) {
            @Suppress("DEPRECATION")
            runCatching { geocoder.getFromLocation(lat, lng, 1)?.firstOrNull() }.getOrNull()
        }
    }
    val a = found ?: return null
    val street = listOfNotNull(a.thoroughfare, a.subThoroughfare).joinToString(" ").ifBlank { null }
    val parts = listOfNotNull(street ?: a.featureName, a.subLocality, a.locality ?: a.subAdminArea).map { it.trim() }.filter { it.isNotEmpty() }.distinct()
    val separator = if (AppLocale.current == "ar") "، " else ", "
    return parts.joinToString(separator).ifBlank { a.getAddressLine(0) }?.take(255)
}
