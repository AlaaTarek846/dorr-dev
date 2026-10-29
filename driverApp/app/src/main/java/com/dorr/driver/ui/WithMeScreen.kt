package com.dorr.driver.ui

import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material3.Icon
import androidx.compose.material3.Slider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.PlaceDto
import com.dorr.driver.net.PoolStateDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.ListRow
import com.dorr.driver.ui.kit.OutlineButton
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.ScreenHeader
import com.dorr.driver.ui.kit.TileIcon
import com.dorr.driver.ui.kit.staggerIn
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.util.Locale

/**
 * «أنا معك» — With Me (docs/rides_tasks.md O-30): «I'm going there — send me riders on my way». The
 * captain picks where he's going and how far off his road he'll go (inside the admin's limits; no
 * control when the admin fixed it), and while it's on, With Me requests along his way reach him —
 * even with riders already in the car. The riders sharing the car are listed; each opens its own ride.
 */
@Composable
fun WithMeScreen(onBack: () -> Unit, onOpenRide: (String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var state by remember { mutableStateOf<PoolStateDto?>(null) }
    var destination by remember { mutableStateOf<PlaceDto?>(null) }
    var picking by remember { mutableStateOf(false) }
    var radiusKm by remember { mutableFloatStateOf(0f) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var tick by remember { mutableIntStateOf(0) }

    LaunchedEffect(tick) {
        (apiCall { DriverBackend.api.pool() } as? ApiResult.Ok)?.let { s ->
            state = s.data
            if (radiusKm == 0f) radiusKm = (s.data.pool?.radiusM ?: s.data.radius.maxM) / 1000f
        }
    }
    // A rider joining or getting off arrives in real time (the captain's channel): one reload.
    LaunchedEffect(Unit) {
        com.dorr.driver.live.DriverLive.events.collect { e -> if (e.name == com.dorr.driver.live.DriverLive.DRIVER_CHANGED || e.name.startsWith("ride.")) tick++ }
    }

    fun call(block: suspend () -> retrofit2.Response<com.dorr.core.network.ApiEnvelope<PoolStateDto>>) {
        busy = true
        error = null
        scope.launch {
            when (val r = apiCall { block() }) {
                is ApiResult.Ok -> state = r.data
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxSize().background(Pal.bg)) {
        ScreenHeader(stringResource(R.string.wm_title), onBack = onBack, modifier = Modifier.statusBarsPadding())
        val s = state ?: return@Column
        val pool = s.pool
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            // The hero: on → a pulsing ring around the riders' icon; off → the idea in one line.
            val pulse = rememberInfiniteTransition(label = "wm")
            val ring by pulse.animateFloat(1f, 1.25f, infiniteRepeatable(tween(1200), RepeatMode.Reverse), label = "wm-ring")
            Column(
                Modifier.fillMaxWidth().clip(androidx.compose.foundation.shape.RoundedCornerShape(26.dp))
                    .background(Brush.linearGradient(listOf(Color(0xFF00897B), Color(0xFF26A69A)))).padding(18.dp).staggerIn(0),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Box(contentAlignment = Alignment.Center, modifier = Modifier.size(96.dp)) {
                    if (pool != null) Box(Modifier.size(80.dp).scale(ring).alpha(0.25f).clip(CircleShape).background(Color.White))
                    Box(Modifier.size(70.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Groups, null, tint = Color.White, modifier = Modifier.size(38.dp))
                    }
                }
                Text(stringResource(if (pool != null) R.string.wm_on else R.string.wm_off), color = Color.White, fontWeight = FontWeight.Black, fontSize = 18.sp)
                Text(stringResource(R.string.wm_explain), color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
            }
            Banner(error)
            if (pool == null) {
                Column(Modifier.fillMaxWidth().surface()) {
                    ListRow(Icons.Rounded.Place, destination?.address ?: stringResource(R.string.wm_pick_destination), subtitle = stringResource(R.string.wm_going_to), tint = Pal.orange) { picking = true }
                }
                RadiusControl(s, radiusKm) { radiusKm = it }
                PrimaryButton(stringResource(R.string.wm_start), busy = busy, enabled = destination != null, color = Color(0xFF00897B)) {
                    val d = destination ?: return@PrimaryButton
                    call {
                        DriverBackend.api.openPool(JsonObject().apply {
                            add("destination", JsonObject().apply { addProperty("lat", d.lat); addProperty("lng", d.lng); addProperty("address", d.address) })
                            if (s.radius.editable) addProperty("radius_m", (radiusKm * 1000).toInt())
                        })
                    }
                }
            } else {
                Column(Modifier.fillMaxWidth().surface().padding(14.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        TileIcon(Icons.Rounded.Place, Pal.orange, 40.dp)
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.wm_going_to), color = Pal.mut, fontSize = 11.sp)
                            Text(pool.destination.address ?: "—", color = Pal.ink, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                        }
                    }
                    Text(
                        stringResource(R.string.wm_corridor, String.format(Locale.getDefault(), "%.1f", pool.radiusM / 1000f)) + " · " + stringResource(R.string.wm_seats, pool.rides.size, pool.seats),
                        color = Pal.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp),
                    )
                }
                Text(stringResource(R.string.wm_riders, pool.rides.size), color = Pal.ink, fontWeight = FontWeight.Bold)
                if (pool.rides.isEmpty()) Text(stringResource(R.string.wm_waiting), color = Pal.mut, fontSize = 13.sp)
                pool.rides.forEachIndexed { i, r ->
                    Row(
                        Modifier.fillMaxWidth().surface().clickable { onOpenRide(r.uuid) }.padding(12.dp).staggerIn(i + 1),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        TileIcon(Icons.Rounded.Person, Pal.accent, 40.dp)
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(r.rider?.name ?: "—", color = Pal.ink, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f, fill = false))
                                RiderTick(r.rider?.isVerified == true, 14.dp)
                            }
                            Text(r.destination?.address ?: "—", color = Pal.mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                        Text(statusText(r.status), color = Pal.accent, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                }
                OutlineButton(stringResource(R.string.wm_stop), color = Pal.danger) { call { DriverBackend.api.closePool() } }
            }
            Spacer(Modifier.navigationBarsPadding())
        }
    }
    if (picking) PlacePicker(onClose = { picking = false }) { p -> destination = p; picking = false }
}

/** How far off his road the captain will go — shown only when the admin left a range to choose from. */
@Composable
private fun RadiusControl(s: PoolStateDto, km: Float, onChange: (Float) -> Unit) {
    val min = s.radius.minM / 1000f
    val max = s.radius.maxM / 1000f
    Column(Modifier.fillMaxWidth().surface().padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.wm_radius), color = Pal.ink, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            Text(String.format(Locale.getDefault(), "%.1f km", if (s.radius.editable) km.coerceIn(min, max) else max), color = Color(0xFF00897B), fontWeight = FontWeight.Black)
        }
        if (s.radius.editable) {
            Slider(
                value = km.coerceIn(min, max), onValueChange = onChange, valueRange = min..max,
                colors = SliderDefaults.colors(thumbColor = Color(0xFF00897B), activeTrackColor = Color(0xFF00897B)),
            )
            Text(stringResource(R.string.wm_radius_hint), color = Pal.mut, fontSize = 11.sp)
        } else {
            Text(stringResource(R.string.wm_radius_fixed), color = Pal.mut, fontSize = 11.sp, modifier = Modifier.padding(top = 4.dp))
        }
    }
}

@Composable
private fun statusText(status: String): String = stringResource(
    when (status) {
        "arrived" -> R.string.trip_at_pickup
        "in_progress" -> R.string.trip_on_ride
        else -> R.string.trip_to_pickup
    },
)
