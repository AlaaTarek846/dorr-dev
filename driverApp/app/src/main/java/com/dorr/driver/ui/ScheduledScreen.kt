package com.dorr.driver.ui

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Circle
import androidx.compose.material.icons.filled.Event
import androidx.compose.material.icons.filled.Place
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material.icons.filled.WarningAmber
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.CoreHttp
import com.dorr.core.network.apiCall
import com.dorr.core.ui.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.duty.Duty
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.ScheduledRideDto
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.TextStyle
import java.util.Locale

/**
 * Scheduled rides (D-260..269): the bookings near me I may reserve — when, where, the fixed net and
 * when to be online — and my own: head to the pickup once it's time, or give one back (told first
 * whether that counts as a cancel).
 */
@Composable
fun ScheduledScreen(onBack: () -> Unit, onStarted: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var tab by remember { mutableIntStateOf(0) }
    var items by remember { mutableStateOf<List<ScheduledRideDto>?>(null) }
    var busy by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var releasing by remember { mutableStateOf<ScheduledRideDto?>(null) }
    var refresh by remember { mutableIntStateOf(0) }
    BackHandler(onBack = onBack)

    LaunchedEffect(tab, refresh) {
        items = null
        error = null
        val r = apiCall { if (tab == 0) DriverBackend.api.scheduled() else DriverBackend.api.scheduledMine() }
        items = (r as? ApiResult.Ok)?.data.orEmpty()
        if (r is ApiResult.Failed) error = r.error.displayMessage
        if (r is ApiResult.Offline) error = context.getString(R.string.error_offline)
    }
    // "Head out" turns on by itself when the time comes — by the phone's clock, no request; and a change of mine reloads the list.
    LaunchedEffect(tab) {
        com.dorr.driver.live.DriverLive.events.collect { e ->
            if (e.name == com.dorr.driver.live.DriverLive.DRIVER_CHANGED) refresh++
        }
    }

    fun act(uuid: String, block: suspend () -> ApiResult<*>, after: (ApiResult<*>) -> Unit) {
        busy = uuid
        error = null
        scope.launch {
            val r = block()
            when (r) {
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                else -> after(r)
            }
            busy = null
        }
    }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        Column(Modifier.fillMaxWidth().background(DorrBrand.navy).statusBarsPadding().padding(bottom = 16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(8.dp)) {
                IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, null, tint = Color.White) }
                Text(stringResource(R.string.sch_title), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
            }
            Row(Modifier.padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(R.string.sch_available, R.string.sch_mine).forEachIndexed { i, label ->
                    val bg by animateColorAsState(if (tab == i) Color.White else Color.White.copy(alpha = 0.12f), tween(220), label = "tab")
                    Text(
                        stringResource(label), color = if (tab == i) DorrBrand.navy else Color.White, fontWeight = FontWeight.SemiBold,
                        modifier = Modifier.clip(RoundedCornerShape(50)).background(bg).clickable { tab = i }.padding(horizontal = 16.dp, vertical = 7.dp),
                    )
                }
            }
        }
        error?.let { Text(it, color = MaterialTheme.colorScheme.error, modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp)) }

        AnimatedContent(targetState = items, transitionSpec = { (fadeIn(tween(220)) + slideInVertically(tween(260)) { it / 12 }) togetherWith fadeOut(tween(120)) }, label = "scheduled") { list ->
            when {
                list == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = DorrBrand.orange) }
                list.isEmpty() -> Column(Modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                    Box(Modifier.size(76.dp).clip(CircleShape).background(DorrBrand.orange.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Filled.Event, null, tint = DorrBrand.orange, modifier = Modifier.size(36.dp))
                    }
                    Spacer(Modifier.height(10.dp))
                    Text(stringResource(if (tab == 0) R.string.sch_empty else R.string.sch_mine_empty), textAlign = TextAlign.Center, color = MaterialTheme.colorScheme.onBackground.copy(alpha = 0.6f))
                }
                else -> LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    item { Spacer(Modifier.height(4.dp)) }
                    items(list, key = { it.uuid }) { ride ->
                        ScheduledCard(ride, busy = busy == ride.uuid,
                            onReserve = {
                                act(ride.uuid, { apiCall { DriverBackend.api.reserve(CoreHttp.newIdempotencyKey(), ride.uuid) } }) { items = items?.filterNot { it.uuid == ride.uuid } }
                            },
                            onStart = {
                                act(ride.uuid, { apiCall { DriverBackend.api.startScheduled(CoreHttp.newIdempotencyKey(), ride.uuid) } }) {
                                    Duty.setOnTrip(true)
                                    onStarted()
                                }
                            },
                            onRelease = { releasing = ride },
                        )
                    }
                    item { Spacer(Modifier.height(16.dp)) }
                }
            }
        }
    }

    // Giving a booking back: the cost is said first (D-170).
    releasing?.let { ride ->
        AlertDialog(
            onDismissRequest = { releasing = null },
            icon = { Icon(Icons.Filled.WarningAmber, null, tint = if (ride.releaseCountsAsCancel == true) DorrBrand.danger else DorrBrand.warning) },
            title = { Text(stringResource(R.string.sch_release_title)) },
            text = { Text(stringResource(if (ride.releaseCountsAsCancel == true) R.string.sch_release_counts else R.string.sch_release_free)) },
            confirmButton = {
                TextButton(onClick = {
                    releasing = null
                    act(ride.uuid, { apiCall { DriverBackend.api.releaseReservation(ride.uuid, JsonObject()) } }) { items = items?.filterNot { it.uuid == ride.uuid } }
                }) { Text(stringResource(R.string.sch_release), color = DorrBrand.danger) }
            },
            dismissButton = { TextButton(onClick = { releasing = null }) { Text(stringResource(R.string.sch_keep)) } },
        )
    }
}

@Composable
private fun ScheduledCard(ride: ScheduledRideDto, busy: Boolean, onReserve: () -> Unit, onStart: () -> Unit, onRelease: () -> Unit) {
    val at = instantOf(ride.scheduledAt)
    val onlineFrom = instantOf(ride.onlineFrom)
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            // The day as a small calendar leaf.
            at?.let { DayLeaf(it) }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(at?.let { timeOfDay(it) } ?: "—", fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
                Text(ride.category?.name.orEmpty(), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
            }
            Column(horizontalAlignment = Alignment.End) {
                Text(money(ride.earningMinor, ride.currency), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = DorrBrand.success)
                Text(stringResource(R.string.sch_fixed_net), fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.55f))
            }
        }
        Spacer(Modifier.height(10.dp))
        RoutePoint(DorrBrand.navy, ride.origin?.address)
        ride.destination?.let { RoutePoint(DorrBrand.orange, it.address) }
        Row(Modifier.fillMaxWidth().padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            ride.route?.distanceM?.let { Pill(stringResource(R.string.sch_trip_km, String.format("%.1f", it / 1000.0))) }
            ride.pickupDistanceM?.let { Pill(stringResource(R.string.offer_pickup_km, String.format("%.1f", it / 1000.0))) }
            ride.riderRating?.let { Pill(String.format("%.1f ★", it)) }
            Pill(stringResource(if (ride.paymentMethod == "cash") R.string.offer_cash else R.string.offer_paid))
        }
        onlineFrom?.let {
            Row(Modifier.padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Filled.Schedule, null, tint = DorrBrand.orange, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.sch_online_from, timeOfDay(it)), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            }
        }
        Spacer(Modifier.height(12.dp))
        if (!ride.mine) {
            BigButton(stringResource(R.string.sch_reserve), busy = busy, onClick = onReserve)
        } else {
            BigButton(
                if (ride.canStart) stringResource(R.string.sch_start) else stringResource(R.string.sch_start_at, instantOf(ride.startsAt)?.let { timeOfDay(it) } ?: "—"),
                color = DorrBrand.success, enabled = ride.canStart, busy = busy, onClick = onStart,
            )
            Text(
                stringResource(R.string.sch_release), color = DorrBrand.danger, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable(enabled = !busy, onClick = onRelease).padding(10.dp),
            )
        }
    }
}

@Composable
private fun DayLeaf(at: Instant) {
    val date = at.atZone(ZoneId.systemDefault()).toLocalDate()
    val locale = Locale.getDefault()
    val label = when (date) {
        LocalDate.now() -> stringResource(R.string.sch_today)
        LocalDate.now().plusDays(1) -> stringResource(R.string.sch_tomorrow)
        else -> date.dayOfWeek.getDisplayName(TextStyle.SHORT, locale)
    }
    Column(Modifier.size(width = 58.dp, height = 64.dp).clip(RoundedCornerShape(14.dp)).background(DorrBrand.navy.copy(alpha = 0.06f)), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.fillMaxWidth().height(20.dp).background(DorrBrand.orange), contentAlignment = Alignment.Center) {
            Text(date.month.getDisplayName(TextStyle.SHORT, locale), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold)
        }
        Text(date.dayOfMonth.toString(), fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
        Text(label, fontSize = 9.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f), maxLines = 1)
    }
}

@Composable
private fun RoutePoint(color: Color, address: String?) {
    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(if (color == DorrBrand.orange) Icons.Filled.Place else Icons.Filled.Circle, null, tint = color, modifier = Modifier.size(if (color == DorrBrand.orange) 18.dp else 12.dp))
        Spacer(Modifier.width(10.dp))
        Text(address ?: "—", fontWeight = FontWeight.SemiBold, fontSize = 14.sp, maxLines = 1)
    }
}

@Composable
private fun Pill(text: String) {
    Text(text, fontSize = 11.sp, modifier = Modifier.clip(CircleShape).background(MaterialTheme.colorScheme.onSurface.copy(alpha = 0.07f)).padding(horizontal = 9.dp, vertical = 4.dp))
}
