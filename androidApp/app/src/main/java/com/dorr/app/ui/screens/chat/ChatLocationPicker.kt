package com.dorr.app.ui.screens.chat

import android.annotation.SuppressLint
import android.content.Context
import android.location.Location
import android.location.LocationManager
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.NearMe
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.DialogProperties
import com.dorr.app.R
import com.dorr.app.map.DorrMap
import com.dorr.app.map.rememberDorrCamera
import com.dorr.app.network.ApiClient
import com.dorr.app.network.PlaceHitDto
import com.dorr.app.ui.locale.LocaleAwareDialog
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.theme.CairoFontFamily
import com.google.android.gms.maps.model.LatLng
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** What the location page sends: a point, with the place's name and address when a place was picked. */
data class LocationPick(val lat: Double, val lng: Double, val name: String? = null, val address: String? = null)

/**
 * «Send location», as in WhatsApp: the real map on top (Google or Mapbox — the admin's choice) with a pin in its
 * middle — move the map and «Send this location» sends where the pin is; then «Share live location» (15 min /
 * 1 h / 8 h), «Send your current location» with how accurate the phone's fix is, and the places nearby (around
 * the pin, from the server's map provider). 🔍 searches places by name. Opened once the location permission is
 * granted and location is on.
 *
 * The phone's own location updates (no server) keep the fix and its accuracy fresh while the page is open.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LocationPickerPage(onDismiss: () -> Unit, onSend: (LocationPick) -> Unit, onLive: (Int) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val start = remember { lastKnown(context) }
    val camera = rememberDorrCamera(start?.let { LatLng(it.latitude, it.longitude) } ?: LatLng(30.0444, 31.2357), if (start != null) 17f else 12f)
    var fix by remember { mutableStateOf(start) }
    var centredOnMe by remember { mutableStateOf(false) }
    var nearby by remember { mutableStateOf<List<PlaceHitDto>?>(null) }
    var nearbyAt by remember { mutableStateOf<String?>(null) }
    var searching by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<PlaceHitDto>?>(null) }
    var liveChoice by remember { mutableStateOf(false) }
    var refreshTick by remember { mutableStateOf(0) }

    // The phone's location, kept fresh while the page is open (its sensors — nothing asks the server).
    DisposableEffect(refreshTick) {
        val client = com.google.android.gms.location.LocationServices.getFusedLocationProviderClient(context)
        val callback = object : com.google.android.gms.location.LocationCallback() {
            override fun onLocationResult(result: com.google.android.gms.location.LocationResult) {
                result.lastLocation?.let { fix = it }
            }
        }
        runCatching {
            @Suppress("MissingPermission")
            client.requestLocationUpdates(
                com.google.android.gms.location.LocationRequest.Builder(com.google.android.gms.location.Priority.PRIORITY_HIGH_ACCURACY, 2500).build(),
                callback, android.os.Looper.getMainLooper(),
            )
        }
        onDispose { client.removeLocationUpdates(callback) }
    }
    // The first good fix: the map goes there (unless the pin was already moved by hand).
    LaunchedEffect(fix != null) {
        val f = fix ?: return@LaunchedEffect
        if (!centredOnMe && !camera.byGesture) camera.animate(LatLng(f.latitude, f.longitude), 17f)
        centredOnMe = true
    }
    // The places around where the pin stops (~100 m steps, so a nudge doesn't ask again).
    LaunchedEffect(camera.target, camera.isMoving, centredOnMe, refreshTick) {
        if (camera.isMoving || !centredOnMe) return@LaunchedEffect
        val t = camera.target
        val key = "%.3f,%.3f|%d".format(java.util.Locale.US, t.latitude, t.longitude, refreshTick)
        if (key == nearbyAt) return@LaunchedEffect
        delay(300)
        nearbyAt = key
        nearby = null
        nearby = runCatching { ApiClient.rides.placeNearby(chatAuth(), t.latitude, t.longitude).data }.getOrNull().orEmpty()
    }
    // Search as I type (from two letters), nearest to the pin first.
    LaunchedEffect(query, searching) {
        val q = query.trim()
        if (!searching || q.length < 2) { results = null; return@LaunchedEffect }
        delay(350)
        val t = camera.target
        results = runCatching { ApiClient.rides.placeSuggest(chatAuth(), q, t.latitude, t.longitude).data }.getOrNull().orEmpty()
    }

    LocaleAwareDialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = false)) {
        // The app's inner-screen look (as «Starred messages»): the soft backdrop, the title with back, then cards.
        Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize().statusBarsPadding().imePadding()) {
            // ------------------------------------------------ the header: title · 🔍 · ⟳ · back
            SubHeader(stringResource(R.string.ch_loc_page_title), onBack = { if (searching) { searching = false; query = "" } else onDismiss() }) {
                if (!searching) {
                    HeaderCircle(Icons.Rounded.Search, stringResource(R.string.ch_loc_search_hint)) { searching = true }
                    Spacer(Modifier.width(8.dp))
                    HeaderCircle(Icons.Rounded.Refresh, stringResource(R.string.ch_loc_refresh)) {
                        refreshTick++
                        fix?.let { f -> scope.launch { camera.animate(LatLng(f.latitude, f.longitude), 17f) } }
                    }
                    Spacer(Modifier.width(8.dp))
                }
            }
            // Searching: the field under the header (as in «Starred messages»), ready to type in.
            if (searching) {
                val focus = remember { FocusRequester() }
                ChField(
                    query, { query = it.take(80) }, stringResource(R.string.ch_loc_search_hint),
                    icon = Icons.Rounded.Search, clearable = true, fontSize = 13.sp,
                    modifier = Modifier.padding(start = 14.dp, end = 14.dp, bottom = 10.dp),
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                    fieldModifier = Modifier.focusRequester(focus),
                )
                LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
            }

            // ------------------------------------------------ the map, with the pin in the middle
            val mapHeight = (LocalConfiguration.current.screenHeightDp * 0.4f).dp
            Box(Modifier.padding(horizontal = 14.dp).fillMaxWidth().height(if (searching) mapHeight * 0.5f else mapHeight).clip(RoundedCornerShape(22.dp))) {
                DorrMap(camera, Modifier.fillMaxSize(), showMe = true, night = Ch.dark, googleNightJson = com.dorr.app.map.GoogleDarkStyle)
                // The pin lifts while the map moves under it, and lands where it stops.
                val lift by animateDpAsState(if (camera.isMoving) 12.dp else 0.dp, label = "pinLift")
                Box(Modifier.align(Alignment.Center).size(8.dp, 4.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.3f)))
                Icon(
                    Icons.Rounded.Place, null, tint = Ch.Red,
                    modifier = Modifier.align(Alignment.Center).size(44.dp).offset(y = -20.dp - lift),
                )
                // Back to me.
                Box(
                    Modifier.align(Alignment.TopEnd).padding(12.dp).size(44.dp).shadow(4.dp, CircleShape).clip(CircleShape).background(Ch.Surface)
                        .clickable { fix?.let { f -> scope.launch { camera.animate(LatLng(f.latitude, f.longitude), 17f) } } },
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.MyLocation, stringResource(R.string.ch_loc_my_place), tint = Ch.Ink, modifier = Modifier.size(22.dp)) }
            }

            // ------------------------------------------------ live · the pin · my place · nearby (or the search's results)
            val me = fix
            val pinMoved = me != null && distanceM(me.latitude, me.longitude, camera.target.latitude, camera.target.longitude) > 25
            LazyColumn(
                Modifier.padding(start = 14.dp, end = 14.dp, top = 12.dp).weight(1f).fillMaxWidth()
                    .clip(RoundedCornerShape(topStart = 22.dp, topEnd = 22.dp)).background(Ch.Surface),
                contentPadding = PaddingValues(top = 4.dp, bottom = 12.dp),
            ) {
                val shown = results
                if (searching && shown != null) {
                    item { SectionTitle(stringResource(R.string.ch_loc_results)) }
                    if (shown.isEmpty()) item { EmptyLine(stringResource(R.string.ch_loc_nothing)) }
                    items(shown) { p -> PlaceRow(p) { onSend(LocationPick(p.lat, p.lng, p.name, p.address)) } }
                    return@LazyColumn
                }
                item {
                    PickRow(Icons.Rounded.NearMe, stringResource(R.string.ch_live_title), null, strong = true) { liveChoice = true }
                    Box(Modifier.fillMaxWidth().padding(start = 72.dp).height(1.dp).background(Ch.Line))
                }
                if (pinMoved) item {
                    val t = camera.target
                    PickRow(Icons.Rounded.Place, stringResource(R.string.ch_loc_send_pin), "%.5f, %.5f".format(java.util.Locale.US, t.latitude, t.longitude), strong = true) {
                        onSend(LocationPick(t.latitude, t.longitude))
                    }
                }
                item { SectionTitle(stringResource(R.string.ch_loc_nearby)) }
                item {
                    val accuracy = me?.takeIf { it.hasAccuracy() }?.accuracy?.toInt()
                    PickRow(
                        Icons.Rounded.MyLocation, stringResource(R.string.ch_loc_send_current),
                        when {
                            me == null -> stringResource(R.string.ch_loc_locating)
                            accuracy != null -> stringResource(R.string.ch_loc_accuracy, accuracy.coerceAtLeast(1))
                            else -> null
                        },
                        strong = true, enabled = me != null,
                    ) { me?.let { onSend(LocationPick(it.latitude, it.longitude)) } }
                }
                val places = nearby
                when {
                    places == null -> item {
                        Box(Modifier.fillMaxWidth().padding(20.dp), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(Modifier.size(24.dp), color = Ch.Red, strokeWidth = 2.5.dp)
                        }
                    }
                    places.isEmpty() -> item { EmptyLine(stringResource(R.string.ch_loc_nothing)) }
                    else -> items(places) { p -> PlaceRow(p) { onSend(LocationPick(p.lat, p.lng, p.name, p.address)) } }
                }
            }
            Box(Modifier.padding(horizontal = 14.dp).fillMaxWidth().background(Ch.Surface).navigationBarsPadding())
        }
        }

        // Live: for how long.
        if (liveChoice) ChSheet(onDismissRequest = { liveChoice = false }, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
            Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 30.dp)) {
                Text(stringResource(R.string.ch_live_title), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.ch_live_sub), color = Ch.Mut, fontSize = 13.sp)
                Spacer(Modifier.height(12.dp))
                listOf(900 to R.string.ch_live_15m, 3600 to R.string.ch_live_1h, 28800 to R.string.ch_live_8h).forEach { (seconds, label) ->
                    Row(
                        Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted)
                            .clickable { liveChoice = false; onLive(seconds) }.padding(14.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.NearMe, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
                        Spacer(Modifier.width(12.dp))
                        Text(stringResource(label), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.5.sp)
                    }
                }
            }
        }
    }
}

/** A round button beside the header's back (the same look: the settings card colour, the accent icon). */
@Composable
private fun HeaderCircle(icon: ImageVector, description: String, onClick: () -> Unit) {
    val dark = com.dorr.app.ui.screens.profile.settingsNight()
    Box(
        Modifier.size(34.dp)
            .then(if (dark) Modifier else Modifier.shadow(6.dp, CircleShape, ambientColor = Color(0x14001B53), spotColor = Color(0x14001B53)))
            .clip(CircleShape).background(com.dorr.app.ui.screens.profile.settingsCard())
            .then(if (dark) Modifier.border(1.dp, com.dorr.app.ui.screens.AccountDark.line, CircleShape) else Modifier)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, description, tint = com.dorr.app.ui.screens.profile.settingsAccent(), modifier = Modifier.size(17.dp)) }
}

/** A main line (live · this pin · my place): a round icon in the app's red, a title and a line under it. */
@Composable
private fun PickRow(icon: ImageVector, title: String, sub: String?, strong: Boolean, enabled: Boolean = true, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(enabled = enabled, onClick = onClick).padding(horizontal = 16.dp, vertical = 11.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(44.dp).clip(CircleShape).background(if (strong) Ch.Red else Ch.SurfaceMuted), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = if (strong) Color.White else Ch.Ink, modifier = Modifier.size(22.dp))
        }
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = Ch.Ink.copy(alpha = if (enabled) 1f else 0.5f), fontSize = 15.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
            if (sub != null) Text(sub, color = Ch.Mut, fontSize = 12.5.sp, maxLines = 1)
        }
    }
}

/** A nearby place / a search result: its name and address (each in its own direction). */
@Composable
private fun PlaceRow(p: PlaceHitDto, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(44.dp).clip(CircleShape).background(Ch.SurfaceMuted), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Place, null, tint = Ch.Mut, modifier = Modifier.size(22.dp))
        }
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            val content = TextStyle(textDirection = TextDirection.Content)
            Text(p.name, color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, style = content)
            if (p.address.isNotBlank() && p.address != p.name) {
                Text(p.address, color = Ch.Mut, fontSize = 12.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, style = content)
            }
        }
    }
}

@Composable
private fun SectionTitle(text: String) {
    Text(text, color = Ch.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 14.dp, bottom = 4.dp))
}

@Composable
private fun EmptyLine(text: String) {
    Text(text, color = Ch.Mut, fontSize = 13.sp, modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 16.dp))
}

/** The freshest position the phone already has (to open the map there at once), or null. */
@SuppressLint("MissingPermission")
private fun lastKnown(context: Context): Location? {
    val manager = context.getSystemService(Context.LOCATION_SERVICE) as LocationManager
    return listOf(LocationManager.GPS_PROVIDER, LocationManager.NETWORK_PROVIDER, LocationManager.PASSIVE_PROVIDER)
        .mapNotNull { runCatching { manager.getLastKnownLocation(it) }.getOrNull() }
        .maxByOrNull { it.time }
}

private fun distanceM(lat1: Double, lng1: Double, lat2: Double, lng2: Double): Float {
    val out = FloatArray(1)
    Location.distanceBetween(lat1, lng1, lat2, lng2, out)
    return out[0]
}
