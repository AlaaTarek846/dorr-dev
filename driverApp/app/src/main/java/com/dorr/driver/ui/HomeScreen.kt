package com.dorr.driver.ui

import android.Manifest
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.location.LocationManager
import android.provider.Settings
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.animateColorAsState
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
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.AccountBalanceWallet
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.Coffee
import androidx.compose.material.icons.filled.ErrorOutline
import androidx.compose.material.icons.filled.GpsOff
import androidx.compose.material.icons.filled.Event
import androidx.compose.material.icons.filled.Flight
import androidx.compose.material.icons.filled.PowerSettingsNew
import androidx.compose.material.icons.filled.Schedule
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.Tune
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
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
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.core.location.LocationManagerCompat
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.core.ui.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.duty.Duty
import com.dorr.driver.net.AirportQueueDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.DriverDto
import com.dorr.driver.net.DrivingHoursDto
import com.dorr.driver.net.EarningsDto
import com.dorr.driver.net.PresenceDto
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * The captain's home: today's net at a glance, the big online switch (breathing while online),
 * and — when they can't work yet — exactly why, with the way to fix it (sign-up, Wasl).
 */
@Composable
fun HomeScreen(
    onSignedOut: () -> Unit, onOpenSignup: () -> Unit, onOpenRide: () -> Unit, onOpenEarnings: () -> Unit, onOpenScheduled: () -> Unit, onOpenAirport: () -> Unit,
    onOpenSafety: () -> Unit, onOpenNotifications: () -> Unit, onOpenWallet: () -> Unit, onOpenPreferences: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var driver by remember { mutableStateOf<DriverDto?>(null) }
    var presence by remember { mutableStateOf<PresenceDto?>(null) }
    var earnings by remember { mutableStateOf<EarningsDto?>(null) }
    var airport by remember { mutableStateOf<AirportQueueDto?>(null) }
    var driving by remember { mutableStateOf<DrivingHoursDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    val rideTick by Duty.rideChanged.collectAsState()

    fun granted(permission: String) = ContextCompat.checkSelfPermission(context, permission) == PackageManager.PERMISSION_GRANTED
    var permissionsOk by remember { mutableStateOf(granted(Manifest.permission.ACCESS_FINE_LOCATION)) }

    LaunchedEffect(reload, rideTick) {
        (apiCall { DriverBackend.api.me() } as? ApiResult.Ok)?.let { driver = it.data }
        (apiCall { DriverBackend.api.presence() } as? ApiResult.Ok)?.let { presence = it.data }
        (apiCall { DriverBackend.api.earnings() } as? ApiResult.Ok)?.let { earnings = it.data }
        (apiCall { DriverBackend.api.airportQueue() } as? ApiResult.Ok)?.let { airport = it.data }
        (apiCall { DriverBackend.api.drivingHours() } as? ApiResult.Ok)?.let { driving = it.data }
        // A ride running (accepted from the notification, or the app was closed): straight to it.
        val current = apiCall { DriverBackend.api.currentRide() }
        if (current is ApiResult.Ok && current.data.isJsonObject && current.data.asJsonObject.has("uuid")) {
            Duty.setOnTrip(true)
            onOpenRide()
        } else {
            Duty.setOnTrip(false)
        }
        // Online on the server but the shift isn't running on this phone (rebooted…): start it again.
        if (presence?.status in setOf("online", "on_trip") && permissionsOk) Duty.start(context)
    }
    // The presence follows the server through real-time: any change for me (a paused / timed-out shift, a ride) reloads it.
    LaunchedEffect(Unit) {
        com.dorr.driver.live.DriverLive.events.collect { e ->
            if (e.name == com.dorr.driver.live.DriverLive.DRIVER_CHANGED || e.name.startsWith("ride.")) {
                (apiCall { DriverBackend.api.presence() } as? ApiResult.Ok)?.let { presence = it.data }
                // My place in the airport queue moves with the line.
                (apiCall { DriverBackend.api.airportQueue() } as? ApiResult.Ok)?.let { airport = it.data }
                // The driving-time limit moves with the shift (a reminder, or being taken offline).
                (apiCall { DriverBackend.api.drivingHours() } as? ApiResult.Ok)?.let { driving = it.data }
            }
        }
    }

    // The phone's location switch (D-44): the banner follows it as it flips, no asking.
    var locationOn by remember { mutableStateOf(LocationManagerCompat.isLocationEnabled(context.getSystemService(LocationManager::class.java))) }
    DisposableEffect(Unit) {
        val receiver = object : BroadcastReceiver() {
            override fun onReceive(c: Context, intent: Intent) {
                locationOn = LocationManagerCompat.isLocationEnabled(c.getSystemService(LocationManager::class.java))
            }
        }
        ContextCompat.registerReceiver(context, receiver, IntentFilter(LocationManager.PROVIDERS_CHANGED_ACTION), ContextCompat.RECEIVER_NOT_EXPORTED)
        onDispose { context.unregisterReceiver(receiver) }
    }

    // A break ends by the clock (D-41): one reload when it's due — no asking the server meanwhile.
    LaunchedEffect(presence?.pausedUntil) {
        val end = instantOf(presence?.pausedUntil) ?: return@LaunchedEffect
        val wait = java.time.Duration.between(java.time.Instant.now(), end).toMillis()
        if (wait > 0) {
            delay(wait + 1500)
            reload++
        }
    }

    fun pause(minutes: Int) {
        busy = true
        error = null
        scope.launch {
            when (val result = apiCall { DriverBackend.api.pause(JsonObject().apply { addProperty("minutes", minutes) }) }) {
                is ApiResult.Ok -> presence = result.data
                is ApiResult.Failed -> error = result.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    fun goOnline() {
        busy = true
        error = null
        scope.launch {
            val here = lastPosition(context)
            if (here == null) {
                error = context.getString(R.string.home_permissions)
                busy = false
                return@launch
            }
            val body = JsonObject().apply { addProperty("lat", here.first); addProperty("lng", here.second) }
            when (val result = apiCall { DriverBackend.api.goOnline(body) }) {
                is ApiResult.Ok -> { presence = result.data; Duty.start(context) }
                is ApiResult.Failed -> error = result.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    val askPermissions = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { result ->
        permissionsOk = result[Manifest.permission.ACCESS_FINE_LOCATION] == true || granted(Manifest.permission.ACCESS_FINE_LOCATION)
        if (permissionsOk) goOnline()
    }

    val online = presence?.status in setOf("online", "on_trip", "paused")
    val headerColor by animateColorAsState(if (online) DorrBrand.navy else Color(0xFF3A4256), tween(500), label = "header")

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        // Header: name, today's net, the state.
        Column(
            Modifier.fillMaxWidth().background(Brush.verticalGradient(listOf(headerColor, headerColor.copy(alpha = 0.85f))))
                .statusBarsPadding().padding(horizontal = 20.dp).padding(top = 12.dp, bottom = 22.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.home_greeting), color = Color.White.copy(alpha = 0.75f), fontSize = 13.sp)
                    Text(driver?.name ?: driver?.phone ?: "…", color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.Bold)
                }
                earnings?.profile?.rating?.let {
                    Row(Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.15f)).padding(horizontal = 10.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Filled.Star, null, tint = DorrBrand.warning, modifier = Modifier.size(16.dp))
                        Text(String.format("%.1f", it), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                    }
                }
                // The bell: the unread count follows real-time.
                NotificationBell(onClick = onOpenNotifications)
                IconButton(onClick = {
                    scope.launch {
                        Duty.stop(context)
                        apiCall { DriverBackend.api.goOffline() }
                        com.dorr.driver.live.DriverPush.signedOut()
                        apiCall { DriverBackend.api.logout() }
                        com.dorr.driver.live.DriverLive.stop()
                        DriverBackend.session.clear()
                        onSignedOut()
                    }
                }) { Icon(Icons.AutoMirrored.Filled.Logout, stringResource(R.string.home_logout), tint = Color.White) }
            }
            Spacer(Modifier.height(16.dp))
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Color.White.copy(alpha = 0.1f)).clickable(onClick = onOpenEarnings).padding(14.dp)) {
                val today = earnings?.today
                Stat(stringResource(R.string.home_net), money(today?.netMinor ?: 0, earnings?.currency), Modifier.weight(1.4f), big = true)
                Stat(stringResource(R.string.home_trips), (today?.trips ?: 0).toString(), Modifier.weight(1f))
                Stat(stringResource(R.string.home_online_time), ((today?.onlineS ?: 0) / 3600).let { h -> "${h}h ${((today?.onlineS ?: 0) % 3600) / 60}m" }, Modifier.weight(1f))
            }
        }

        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(20.dp).navigationBarsPadding(),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(18.dp),
        ) {
            // Why not, when they can't work — with the way out.
            val problem = presence?.reason
            if (presence != null && !presence!!.canGoOnline && problem != null && problem != "driving_limit") {
                Card(Modifier.clickable(onClick = onOpenSignup)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(44.dp).clip(CircleShape).background(DorrBrand.warning.copy(alpha = 0.15f)), contentAlignment = Alignment.Center) {
                            Icon(Icons.Filled.ErrorOutline, null, tint = DorrBrand.warning)
                        }
                        Spacer(Modifier.size(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.home_cannot_work), fontWeight = FontWeight.Bold)
                            Text(driver?.statusReason ?: problemText(context, problem), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f))
                        }
                        Icon(Icons.Filled.ChevronRight, null)
                    }
                    Spacer(Modifier.height(10.dp))
                    BigButton(stringResource(R.string.home_complete_signup), color = DorrBrand.navy, onClick = onOpenSignup)
                }
            }

            Spacer(Modifier.height(8.dp))
            OnlineButton(online = online, enabled = online || presence?.canGoOnline == true, busy = busy) {
                if (online) {
                    busy = true
                    scope.launch {
                        Duty.stop(context)
                        (apiCall { DriverBackend.api.goOffline() } as? ApiResult.Ok)?.let { presence = it.data }
                        busy = false
                    }
                } else {
                    val wanted = buildList {
                        add(Manifest.permission.ACCESS_FINE_LOCATION)
                        add(Manifest.permission.ACCESS_COARSE_LOCATION)
                        if (Build.VERSION.SDK_INT >= 33) add(Manifest.permission.POST_NOTIFICATIONS)
                    }
                    if (wanted.all { granted(it) }) goOnline() else askPermissions.launch(wanted.toTypedArray())
                }
            }
            Text(
                if (online) stringResource(R.string.home_waiting_requests) else stringResource(R.string.home_offline),
                color = MaterialTheme.colorScheme.onBackground.copy(alpha = 0.6f), textAlign = TextAlign.Center,
            )
            error?.let { Text(it, color = MaterialTheme.colorScheme.error, textAlign = TextAlign.Center) }

            // D-44: GPS off — nothing can be offered.
            if (online && !locationOn) {
                Card {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(44.dp).clip(CircleShape).background(DorrBrand.danger.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                            Icon(Icons.Filled.GpsOff, null, tint = DorrBrand.danger)
                        }
                        Spacer(Modifier.size(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.gps_off_title), fontWeight = FontWeight.Bold)
                            Text(stringResource(R.string.gps_off_body), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f))
                        }
                    }
                    Spacer(Modifier.height(10.dp))
                    BigButton(stringResource(R.string.gps_turn_on), color = DorrBrand.danger) { context.startActivity(Intent(Settings.ACTION_LOCATION_SOURCE_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
                }
            }
            // D-42, D-43: the driving-time limit — how much is used, a reminder, and when they can work again.
            driving?.takeIf { it.enabled }?.let { DrivingTimeCard(it) }
            // D-41: a break — no requests until it ends, then online again by itself.
            val breakEnd = instantOf(presence?.pausedUntil)
            val onBreak = presence?.status == "paused" && (breakEnd == null || breakEnd.isAfter(java.time.Instant.now()))
            if (onBreak) {
                Card {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(44.dp).clip(CircleShape).background(DorrBrand.warning.copy(alpha = 0.15f)), contentAlignment = Alignment.Center) {
                            Icon(Icons.Filled.Coffee, null, tint = DorrBrand.warning)
                        }
                        Spacer(Modifier.size(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.br_title), fontWeight = FontWeight.Bold)
                            breakEnd?.let { Text(stringResource(R.string.br_until, timeOfDay(it)), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f)) }
                        }
                    }
                    Spacer(Modifier.height(10.dp))
                    BigButton(stringResource(R.string.br_back), color = DorrBrand.success, busy = busy) { goOnline() }
                }
            } else if (presence?.status == "online" || presence?.status == "paused") {
                Card {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(44.dp).clip(CircleShape).background(DorrBrand.warning.copy(alpha = 0.15f)), contentAlignment = Alignment.Center) {
                            Icon(Icons.Filled.Coffee, null, tint = DorrBrand.warning)
                        }
                        Spacer(Modifier.size(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.br_take), fontWeight = FontWeight.Bold)
                            Text(stringResource(R.string.br_hint), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f))
                        }
                    }
                    Spacer(Modifier.height(10.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        listOf(15, 30, 60).forEach { minutes ->
                            Text(
                                stringResource(R.string.br_min, minutes), color = DorrBrand.navy, fontWeight = FontWeight.SemiBold, fontSize = 13.sp,
                                modifier = Modifier.clip(RoundedCornerShape(50)).background(DorrBrand.navy.copy(alpha = 0.07f)).clickable(enabled = !busy) { pause(minutes) }.padding(horizontal = 16.dp, vertical = 8.dp),
                            )
                        }
                    }
                }
            }
            if (online) {
                Card {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(stringResource(R.string.home_last_ride), Modifier.weight(1f))
                        Switch(checked = presence?.lastRideRequested == true, onCheckedChange = { on ->
                            scope.launch { (apiCall { DriverBackend.api.lastRide(JsonObject().apply { addProperty("on", on) }) } as? ApiResult.Ok)?.let { presence = it.data } }
                        })
                    }
                }
            }
            // Driving preferences: categories, pickup range, Auto-Accept (D-32..D-37).
            Card(Modifier.clickable(onClick = onOpenPreferences)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(44.dp).clip(CircleShape).background(DorrBrand.navy.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Filled.Tune, null, tint = DorrBrand.navy)
                    }
                    Spacer(Modifier.size(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.pf_title), fontWeight = FontWeight.Bold)
                        Text(stringResource(R.string.pf_home_hint), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f))
                    }
                    Icon(Icons.Filled.ChevronRight, null)
                }
            }
            // The wallet: the balance, the statement, withdrawing the earnings.
            Card(Modifier.clickable(onClick = onOpenWallet)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(44.dp).clip(CircleShape).background(DorrBrand.success.copy(alpha = 0.15f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Filled.AccountBalanceWallet, null, tint = DorrBrand.success)
                    }
                    Spacer(Modifier.size(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.wl_title), fontWeight = FontWeight.Bold)
                        Text(stringResource(R.string.wl_home_hint), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f))
                    }
                    Icon(Icons.Filled.ChevronRight, null)
                }
            }
            // The Safety Center (D-140, D-381).
            Card(Modifier.clickable(onClick = onOpenSafety)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(44.dp).clip(CircleShape).background(Color(0xFFA32D2D).copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        Text("🛡️", fontSize = 20.sp)
                    }
                    Spacer(Modifier.size(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.sc_title), fontWeight = FontWeight.Bold)
                        Text(stringResource(R.string.sc_home_hint), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f))
                    }
                    Icon(Icons.Filled.ChevronRight, null)
                }
            }
            // Scheduled rides to reserve, and my reservations (D-260..269).
            Card(Modifier.clickable(onClick = onOpenScheduled)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(44.dp).clip(CircleShape).background(DorrBrand.orange.copy(alpha = 0.15f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Filled.Event, null, tint = DorrBrand.orange)
                    }
                    Spacer(Modifier.size(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.sch_title), fontWeight = FontWeight.Bold)
                        Text(stringResource(R.string.sch_home_hint), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f))
                    }
                    Icon(Icons.Filled.ChevronRight, null)
                }
            }
            // The airport queue, its official points and the restricted areas (D-276..D-283).
            Card(Modifier.clickable(onClick = onOpenAirport)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(44.dp).clip(CircleShape).background(DorrBrand.navy.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Filled.Flight, null, tint = DorrBrand.navy)
                    }
                    Spacer(Modifier.size(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.air_title), fontWeight = FontWeight.Bold)
                        Text(
                            when {
                                airport?.queued == true -> stringResource(R.string.air_home_queued, airport?.position ?: 0, airport?.size ?: 0)
                                airport?.zone != null -> stringResource(R.string.air_home_at, airport?.zone?.name.orEmpty())
                                else -> stringResource(R.string.air_home_hint)
                            },
                            fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f),
                        )
                    }
                    Icon(Icons.Filled.ChevronRight, null)
                }
            }
            Text(
                stringResource(R.string.home_earnings), color = DorrBrand.orange, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(12.dp)).clickable(onClick = onOpenEarnings).padding(12.dp),
            )
        }
    }
}

@Composable
private fun Stat(label: String, value: String, modifier: Modifier, big: Boolean = false) {
    Column(modifier) {
        Text(label, color = Color.White.copy(alpha = 0.7f), fontSize = 12.sp)
        Text(value, color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = if (big) 22.sp else 17.sp)
    }
}

/** The big round switch; online it breathes with a soft ring, offline it waits. */
@Composable
private fun OnlineButton(online: Boolean, enabled: Boolean, busy: Boolean, onClick: () -> Unit) {
    val pulse = rememberInfiniteTransition(label = "online-pulse")
    val ring by pulse.animateFloat(1f, 1.22f, infiniteRepeatable(tween(1300), RepeatMode.Reverse), label = "ring")
    val color by animateColorAsState(if (online) DorrBrand.success else DorrBrand.orange, tween(400), label = "button")

    Box(contentAlignment = Alignment.Center, modifier = Modifier.size(200.dp)) {
        if (enabled && !busy) {
            Box(Modifier.size(170.dp).scale(if (online) ring else 1.06f).alpha(0.22f).background(color, CircleShape))
        }
        Box(
            Modifier.size(160.dp).alpha(if (enabled) 1f else 0.35f).clip(CircleShape)
                .background(Brush.linearGradient(listOf(color, color.copy(alpha = 0.75f))))
                .clickable(enabled = enabled && !busy, onClick = onClick),
            contentAlignment = Alignment.Center,
        ) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Icon(Icons.Filled.PowerSettingsNew, null, tint = Color.White, modifier = Modifier.size(46.dp))
                Spacer(Modifier.height(6.dp))
                Text(
                    stringResource(if (online) R.string.home_go_offline else R.string.home_go_online),
                    color = Color.White, fontWeight = FontWeight.Bold,
                )
            }
        }
    }
}

/** Driving time in the last window against the country's limit: a bar, the words for where they stand, and when work resumes. */
@Composable
private fun DrivingTimeCard(d: DrivingHoursDto) {
    val context = LocalContext.current
    val limit = if (d.maxS > 0) d.maxS else d.warnS
    val color = when (d.level) { "limit" -> DorrBrand.danger; "warn" -> DorrBrand.warning; else -> DorrBrand.success }
    fun hours(s: Long) = context.getString(R.string.earn_hours, (s / 3600).toInt(), ((s % 3600) / 60).toInt())
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(44.dp).clip(CircleShape).background(color.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Filled.Schedule, null, tint = color)
            }
            Spacer(Modifier.size(12.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.dh_title, d.windowH), fontWeight = FontWeight.Bold)
                // The state in words as well as colour.
                Text(
                    when (d.level) {
                        "limit" -> d.freeAt?.let { instantOf(it) }?.let { stringResource(R.string.dh_limit_until, timeOfDay(it)) } ?: stringResource(R.string.dh_limit)
                        "warn" -> stringResource(R.string.dh_warn)
                        else -> stringResource(R.string.dh_ok)
                    },
                    fontSize = 13.sp, color = color, fontWeight = FontWeight.SemiBold,
                )
            }
        }
        Spacer(Modifier.height(10.dp))
        Box(Modifier.fillMaxWidth().height(8.dp).clip(CircleShape).background(color.copy(alpha = 0.15f))) {
            Box(Modifier.fillMaxWidth((d.usedS.toFloat() / limit.coerceAtLeast(1)).coerceIn(0.02f, 1f)).height(8.dp).clip(CircleShape).background(color))
        }
        Text(
            if (d.maxS > 0) stringResource(R.string.dh_of, hours(d.usedS), hours(d.maxS)) else hours(d.usedS),
            fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f), modifier = Modifier.padding(top = 6.dp),
        )
    }
}
