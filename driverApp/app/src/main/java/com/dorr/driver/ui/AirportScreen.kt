package com.dorr.driver.ui

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Block
import androidx.compose.material.icons.filled.Flight
import androidx.compose.material.icons.filled.PinDrop
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.core.ui.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.live.DriverLive
import com.dorr.driver.net.AirportQueueDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.PickupPointDto
import com.dorr.driver.net.RestrictedZoneDto
import kotlinx.coroutines.launch

/**
 * The Airport screen (D-276..D-283): where I am, my place in the airport queue and a wait estimate when
 * there is enough to estimate from, joining and leaving with the rules said plainly, the airport's official
 * pickup points, and the areas where pickups aren't allowed. Live: a change of my place (someone ahead left,
 * my turn came) arrives as `driver.changed` and the screen reloads once — it never asks on a timer.
 */
@Composable
fun AirportScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var status by remember { mutableStateOf<AirportQueueDto?>(null) }
    var zones by remember { mutableStateOf<List<RestrictedZoneDto>>(emptyList()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var refresh by remember { mutableIntStateOf(0) }
    BackHandler(onBack = onBack)

    LaunchedEffect(refresh) {
        when (val r = apiCall { DriverBackend.api.airportQueue() }) {
            is ApiResult.Ok -> { status = r.data; error = null }
            is ApiResult.Failed -> error = r.error.displayMessage
            is ApiResult.Offline -> error = context.getString(R.string.error_offline)
        }
        (apiCall { DriverBackend.api.restrictedZones() } as? ApiResult.Ok)?.let { zones = it.data }
    }
    LaunchedEffect(Unit) {
        DriverLive.events.collect { e -> if (e.name == DriverLive.DRIVER_CHANGED) refresh++ }
    }

    fun act(block: suspend () -> ApiResult<AirportQueueDto>) {
        busy = true
        error = null
        scope.launch {
            when (val r = block()) {
                is ApiResult.Ok -> status = r.data
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        Row(Modifier.fillMaxWidth().background(DorrBrand.navy).statusBarsPadding().padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, null, tint = Color.White) }
            Text(stringResource(R.string.air_title), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
        }
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            val s = status
            when {
                s == null && error == null -> Box(Modifier.fillMaxWidth().height(160.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = DorrBrand.orange) }
                s == null -> Unit
                s.zone == null -> Card {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Filled.Flight, null, tint = DorrBrand.navy)
                        Spacer(Modifier.width(10.dp))
                        Text(stringResource(R.string.air_none), color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.75f))
                    }
                }
                else -> {
                    QueueCard(s, busy, onJoin = { act { apiCall { DriverBackend.api.joinAirportQueue() } } }, onLeave = { act { apiCall { DriverBackend.api.leaveAirportQueue() } } })
                    if (s.pickupPoints.isNotEmpty()) PointsCard(s.pickupPoints)
                }
            }
            if (zones.isNotEmpty()) RestrictedCard(zones)
            Spacer(Modifier.height(8.dp))
        }
    }
}

@Composable
private fun QueueCard(s: AirportQueueDto, busy: Boolean, onJoin: () -> Unit, onLeave: () -> Unit) {
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Filled.Flight, null, tint = DorrBrand.navy)
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.air_at, s.zone?.name.orEmpty()), fontWeight = FontWeight.Bold, fontSize = 16.sp)
        }
        Spacer(Modifier.height(14.dp))
        if (s.queued) {
            // The place rolls to its new number when the line moves.
            Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(stringResource(R.string.air_position), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                AnimatedContent(
                    targetState = s.position ?: 0,
                    transitionSpec = { (slideInVertically(tween(300)) { -it } + fadeIn(tween(240))) togetherWith (slideOutVertically(tween(300)) { it } + fadeOut(tween(160))) },
                    label = "position",
                ) { n ->
                    Box(Modifier.size(104.dp).clip(CircleShape).background(DorrBrand.orange.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                        Text("$n", fontWeight = FontWeight.ExtraBold, fontSize = 44.sp, color = DorrBrand.orange)
                    }
                }
                Text(stringResource(R.string.air_of, s.size), fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp))
                Row(Modifier.padding(top = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Filled.Schedule, null, tint = DorrBrand.navy, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(
                        s.estimatedWaitS?.let { stringResource(R.string.air_wait, stringResource(R.string.air_minutes, (it + 59) / 60)) } ?: stringResource(R.string.air_wait_unknown),
                        fontSize = 13.sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center,
                    )
                }
                Text(stringResource(if (s.entry == "auto") R.string.air_joined_auto else R.string.air_joined_manual), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.55f), modifier = Modifier.padding(top = 6.dp))
            }
            Spacer(Modifier.height(16.dp))
            BigButton(stringResource(R.string.air_leave), color = DorrBrand.danger, busy = busy, onClick = onLeave)
            s.rules?.let { rules ->
                Spacer(Modifier.height(12.dp))
                Text(stringResource(R.string.air_rules_title), fontWeight = FontWeight.Bold, fontSize = 13.sp)
                listOfNotNull(
                    stringResource(R.string.air_rule_manual),
                    stringResource(R.string.air_rule_offline),
                    stringResource(R.string.air_rule_zone, (rules.leaveGraceS + 59) / 60),
                    rules.maxWaitS.takeIf { it > 0 }?.let { stringResource(R.string.air_rule_wait, (it + 59) / 60) },
                    stringResource(R.string.air_rule_trip),
                ).forEach { Text("• $it", fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f), modifier = Modifier.padding(top = 2.dp)) }
            }
        } else {
            Text(stringResource(if (s.entryMode == "auto") R.string.air_entry_auto else R.string.air_entry_manual), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f))
            if (s.size > 0) Text(stringResource(R.string.air_of, s.size), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 4.dp))
            Spacer(Modifier.height(12.dp))
            BigButton(stringResource(R.string.air_join), enabled = s.canJoin, busy = busy, onClick = onJoin)
            reasonText(s.cannotJoinReason)?.let { Text(it, fontSize = 12.sp, color = DorrBrand.danger, modifier = Modifier.padding(top = 8.dp)) }
        }
    }
}

@Composable
private fun reasonText(code: String?): String? = when (code) {
    null -> null
    "offline" -> stringResource(R.string.air_reason_offline)
    "stale" -> stringResource(R.string.air_reason_stale)
    "busy" -> stringResource(R.string.air_reason_busy)
    "not_eligible" -> stringResource(R.string.air_reason_not_eligible)
    else -> null
}

/** D-281: the airport's official pickup points — where to meet the rider. */
@Composable
private fun PointsCard(points: List<PickupPointDto>) {
    Card {
        Text(stringResource(R.string.air_points_title), fontWeight = FontWeight.Bold, fontSize = 15.sp)
        points.forEach { point ->
            Row(Modifier.fillMaxWidth().padding(top = 10.dp), verticalAlignment = Alignment.Top) {
                Icon(Icons.Filled.PinDrop, null, tint = DorrBrand.navy, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(10.dp))
                Column {
                    Text("${point.name.orEmpty()} · ${point.kindLabel.orEmpty()}", fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                    point.instructions?.takeIf { it.isNotBlank() }?.let { Text(it, fontSize = 12.sp, fontStyle = FontStyle.Italic, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.65f)) }
                }
            }
        }
    }
}

/** D-282: where pickups aren't allowed, or only at approved points. */
@Composable
private fun RestrictedCard(zones: List<RestrictedZoneDto>) {
    Card {
        Text(stringResource(R.string.air_restricted_title), fontWeight = FontWeight.Bold, fontSize = 15.sp)
        zones.forEach { zone ->
            Row(Modifier.fillMaxWidth().padding(top = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.Block, null, tint = if (zone.type == "blocked") DorrBrand.danger else DorrBrand.warning, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(10.dp))
                Column {
                    Text(zone.name.orEmpty(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                    Text(stringResource(if (zone.type == "blocked") R.string.air_restricted_blocked else R.string.air_restricted_pickup_only), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.65f))
                }
            }
        }
    }
}
