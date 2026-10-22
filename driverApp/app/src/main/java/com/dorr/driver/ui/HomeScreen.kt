package com.dorr.driver.ui

import android.Manifest
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.location.LocationManager
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Coffee
import androidx.compose.material.icons.filled.GpsOff
import androidx.compose.material.icons.filled.PowerSettingsNew
import androidx.compose.material.icons.rounded.AssignmentInd
import androidx.compose.material.icons.rounded.Flight
import androidx.compose.material.icons.rounded.KeyboardArrowUp
import androidx.compose.material.icons.rounded.Radar
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material3.BottomSheetScaffold
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.rememberBottomSheetScaffoldState
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
import com.dorr.driver.R
import com.dorr.driver.duty.Duty
import com.dorr.driver.net.AirportQueueDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.DriverDto
import com.dorr.driver.net.DrivingHoursDto
import com.dorr.driver.net.EarningsDto
import com.dorr.driver.net.PresenceDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.Chip
import com.dorr.driver.ui.kit.DorrToggle
import com.dorr.driver.ui.kit.ListRow
import com.dorr.driver.ui.kit.RoundIconButton
import com.dorr.driver.ui.kit.TileIcon
import com.dorr.driver.ui.kit.bounceClick
import com.dorr.driver.ui.kit.staggerIn
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.MapStyleOptions
import com.google.gson.JsonObject
import com.dorr.driver.map.DorrMap
import com.dorr.driver.map.MapZone
import com.dorr.driver.map.rememberDorrCamera
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * The captain's home: the map with their position, today's net on it, and a sheet over it with the big online switch
 * (breathing while online) and — when they can't work yet — exactly why, with the way to fix it (sign-up, Wasl).
 * Dragging the sheet up shows the rest of the day (breaks, driving time, the airport queue).
 * Everything else (preferences, scheduled rides, cases, safety…) lives in the Account tab.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(
    onOpenSignup: () -> Unit, onOpenRide: () -> Unit, onOpenEarnings: () -> Unit, onOpenAirport: () -> Unit, onOpenNotifications: () -> Unit,
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
            // O-66: activated (or anything about my account changed) from the admin — me and my presence again.
            if (e.name == "driver.status" || e.name == "driver.vehicle" || e.name == "driver.document") {
                (apiCall { DriverBackend.api.me() } as? ApiResult.Ok)?.let { driver = it.data }
            }
            if (e.name == com.dorr.driver.live.DriverLive.DRIVER_CHANGED || e.name.startsWith("ride.") || e.name.startsWith("driver.")) {
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
    val breakEnd = instantOf(presence?.pausedUntil)
    val onBreak = presence?.status == "paused" && (breakEnd == null || breakEnd.isAfter(java.time.Instant.now()))
    val problem = presence?.reason

    val blocked = presence != null && !presence!!.canGoOnline && problem != null && problem != "driving_limit"
    // The sheet shows the switch (and why not, when they can't work); the rest is a drag away.
    val peek = if (blocked) 430.dp else 312.dp
    // O-45: the sheet drags all the way down (the whole map shows); a small pill brings it back.
    val sheet = rememberBottomSheetScaffoldState(
        bottomSheetState = androidx.compose.material3.rememberStandardBottomSheetState(
            initialValue = androidx.compose.material3.SheetValue.PartiallyExpanded, skipHiddenState = false,
        ),
    )
    val sheetHidden = sheet.bottomSheetState.currentValue == androidx.compose.material3.SheetValue.Hidden
    val mapBottom = if (sheetHidden) 0.dp else peek
    var checkOpen by remember { mutableStateOf(false) }
    val camera = rememberDorrCamera(Riyadh, 12f)
    // The map opens on the captain once the position is known (and again after the permission is given).
    // O-49: and again the moment the location is allowed and switched on — no closing and reopening the app.
    LaunchedEffect(permissionsOk, locationOn) {
        if (!permissionsOk || !locationOn) return@LaunchedEffect
        lastPosition(context)?.let { (lat, lng) -> camera.move(LatLng(lat, lng), 15f) }
    }
    // O-49: no working without the location — a window that stays until it's allowed and on.
    if (!permissionsOk || !locationOn) {
        LocationGate(onReady = {
            permissionsOk = true
            locationOn = true
            if (presence?.status in setOf("online", "on_trip")) Duty.start(context)
        })
    }
    if (checkOpen) DispatchCheckSheet(onClose = { checkOpen = false })

    BottomSheetScaffold(
        scaffoldState = sheet,
        sheetPeekHeight = peek,
        sheetContainerColor = Pal.card,
        sheetShadowElevation = 16.dp,
        sheetShape = RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp),
        sheetDragHandle = {
            Box(Modifier.padding(top = 10.dp, bottom = 6.dp).size(width = 40.dp, height = 4.dp).clip(CircleShape).background(Pal.line))
        },
        containerColor = Pal.bg,
        sheetContent = {
            Column(
                Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).padding(bottom = 18.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                // Where they stand, in words as well as colour.
                val statusColor by animateColorAsState(if (onBreak) Pal.warning else if (online) Pal.success else Pal.faint, tween(300), label = "status")
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Box(Modifier.size(10.dp).clip(CircleShape).background(statusColor))
                        Text(
                            stringResource(if (onBreak) R.string.br_title else if (online) R.string.home_status_online else R.string.home_status_offline),
                            color = Pal.ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold,
                        )
                    }
                    Text(
                        if (online) stringResource(R.string.home_waiting_requests) else stringResource(R.string.home_offline_hint),
                        color = Pal.mut, textAlign = TextAlign.Center, fontSize = 13.sp, modifier = Modifier.padding(top = 2.dp),
                    )
                }

                // Why not, when they can't work — with the way out.
                if (blocked) {
                    Column(Modifier.fillMaxWidth().surface().staggerIn(1)) {
                        ListRow(
                            Icons.Rounded.AssignmentInd, stringResource(R.string.home_cannot_work),
                            subtitle = driver?.statusReason ?: problemText(context, problem), tint = Pal.warning, onClick = onOpenSignup,
                        )
                        Box(Modifier.padding(horizontal = 14.dp).padding(bottom = 14.dp)) {
                            BigButton(stringResource(R.string.home_complete_signup), color = Pal.accent, onClick = onOpenSignup)
                        }
                    }
                }

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
                Banner(error, Modifier.fillMaxWidth())

                // D-44: GPS off — nothing can be offered.
                if (online && !locationOn) {
                    Card {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            TileIcon(Icons.Filled.GpsOff, Pal.danger, 44.dp)
                            Column(Modifier.weight(1f)) {
                                Text(stringResource(R.string.gps_off_title), color = Pal.ink, fontWeight = FontWeight.Bold)
                                Text(stringResource(R.string.gps_off_body), fontSize = 13.sp, color = Pal.mut)
                            }
                        }
                        Spacer(Modifier.height(12.dp))
                        BigButton(stringResource(R.string.gps_turn_on), color = Pal.danger) { context.startActivity(Intent(Settings.ACTION_LOCATION_SOURCE_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
                    }
                }

                // Today at a glance — a tap opens the earnings.
                Column(Modifier.fillMaxWidth().bounceClick(onClick = onOpenEarnings).surface(RoundedCornerShape(24.dp), elevation = 8.dp).padding(18.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(stringResource(R.string.home_today_title), color = Pal.mut, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        Text(stringResource(R.string.home_open_earnings), color = Pal.accent, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                    Spacer(Modifier.height(12.dp))
                    Row {
                        val today = earnings?.today
                        Stat(stringResource(R.string.home_net), money(today?.netMinor ?: 0, earnings?.currency), Modifier.weight(1.5f), big = true)
                        Stat(stringResource(R.string.home_trips), (today?.trips ?: 0).toString(), Modifier.weight(1f))
                        Stat(stringResource(R.string.home_online_time), ((today?.onlineS ?: 0) / 3600).let { h -> "${h}h ${((today?.onlineS ?: 0) % 3600) / 60}m" }, Modifier.weight(1f))
                    }
                }

                // D-42, D-43: the driving-time limit — how much is used, a reminder, and when they can work again.
                driving?.takeIf { it.enabled }?.let { DrivingTimeCard(it) }
                // D-41: a break — no requests until it ends, then online again by itself.
                if (onBreak) {
                    Card {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            TileIcon(Icons.Filled.Coffee, Pal.warning, 44.dp)
                            Column(Modifier.weight(1f)) {
                                Text(stringResource(R.string.br_title), color = Pal.ink, fontWeight = FontWeight.Bold)
                                breakEnd?.let { Text(stringResource(R.string.br_until, timeOfDay(it)), fontSize = 13.sp, color = Pal.mut) }
                            }
                        }
                        Spacer(Modifier.height(12.dp))
                        BigButton(stringResource(R.string.br_back), color = Pal.success, busy = busy) { goOnline() }
                    }
                } else if (presence?.status == "online" || presence?.status == "paused") {
                    Card {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            TileIcon(Icons.Filled.Coffee, Pal.warning, 44.dp)
                            Column(Modifier.weight(1f)) {
                                Text(stringResource(R.string.br_take), color = Pal.ink, fontWeight = FontWeight.Bold)
                                Text(stringResource(R.string.br_hint), fontSize = 12.sp, color = Pal.mut)
                            }
                        }
                        Spacer(Modifier.height(12.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            listOf(15, 30, 60).forEach { minutes ->
                                Chip(stringResource(R.string.br_min, minutes), onClick = { if (!busy) pause(minutes) })
                            }
                        }
                    }
                }
                // O-47: «why don't requests reach me?» — every condition, checked now.
                Column(Modifier.fillMaxWidth().surface()) {
                    ListRow(Icons.Rounded.Radar, stringResource(R.string.dc_entry), subtitle = stringResource(R.string.dc_entry_hint), tint = Pal.accent, onClick = { checkOpen = true })
                }
                if (online) {
                    Card {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(stringResource(R.string.home_last_ride), color = Pal.ink, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                            DorrToggle(presence?.lastRideRequested == true) { on ->
                                scope.launch { (apiCall { DriverBackend.api.lastRide(JsonObject().apply { addProperty("on", on) }) } as? ApiResult.Ok)?.let { presence = it.data } }
                            }
                        }
                    }
                }
                // The airport queue, while in it or at its zone (D-276..D-283).
                if (airport?.queued == true || airport?.zone != null) {
                    Column(Modifier.fillMaxWidth().surface()) {
                        ListRow(
                            Icons.Rounded.Flight, stringResource(R.string.air_title),
                            subtitle = if (airport?.queued == true) stringResource(R.string.air_home_queued, airport?.position ?: 0, airport?.size ?: 0)
                            else stringResource(R.string.air_home_at, airport?.zone?.name.orEmpty()),
                            onClick = onOpenAirport,
                        )
                    }
                }
            }
        },
    ) {
        Box(Modifier.fillMaxSize()) {
            // The map under everything: the captain's blue dot (the map follows the phone itself — no asking the server).
            // O-58: the hot areas (where requests came from in the last hour) — read on open, when going
            // online and when the captain recentres; nothing asks on a timer.
            var heat by remember { mutableStateOf<List<com.dorr.driver.net.HeatCellDto>>(emptyList()) }
            LaunchedEffect(online, permissionsOk, locationOn) {
                if (!permissionsOk || !locationOn) return@LaunchedEffect
                val here = lastPosition(context) ?: return@LaunchedEffect
                (apiCall { DriverBackend.api.demandHeat(here.first, here.second) } as? ApiResult.Ok)?.let { heat = it.data }
            }
            // O-53: the admin's engine (Google or Mapbox) — the whole app draws with it.
            DorrMap(
                camera = camera,
                modifier = Modifier.fillMaxSize(),
                zones = heat.map { c ->
                    val hot = c.weight.toFloat().coerceIn(0.15f, 1f)
                    MapZone(LatLng(c.lat, c.lng), 650.0, androidx.compose.ui.graphics.lerp(Color(0xFFFFB300), Color(0xFFE53935), hot).copy(alpha = 0.18f + 0.27f * hot))
                },
                // O-60: my place as a small car turning with my heading.
                meAsCar = true,
                showMe = permissionsOk && locationOn,
                padding = PaddingValues(top = 120.dp, bottom = mapBottom),
                night = Pal.night,
                googleNightStyle = R.raw.map_night,
            )
            LaunchedEffect(Unit) {
                com.dorr.driver.map.MapEngine.refresh(context) {
                (com.dorr.core.network.apiCall { DriverBackend.api.mapConfig() } as? ApiResult.Ok)?.data?.let { Triple(it.provider, it.mapboxToken, it.mapboxStyle) }
            }
            }
            // Offline, the map steps back a little.
            val dim by animateFloatAsState(if (online) 0f else 0.18f, tween(400), label = "map-dim")
            if (dim > 0f) Box(Modifier.matchParentSize().background(Pal.bg.copy(alpha = dim)))

            // Header: who, the rating, the bell — floating on the map; today's net under it, a tap opens the earnings.
            Column(
                Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 12.dp).padding(top = 10.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Row(
                    Modifier.fillMaxWidth().surface(RoundedCornerShape(22.dp)).padding(horizontal = 14.dp, vertical = 10.dp).staggerIn(0),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    Box(Modifier.size(40.dp).clip(CircleShape).background(Pal.well), contentAlignment = Alignment.Center) {
                        Text(
                            (driver?.name ?: "").trim().take(1).uppercase().ifBlank { "D" },
                            color = Pal.accent, fontWeight = FontWeight.Black, fontSize = 17.sp,
                        )
                    }
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.home_greeting), color = Pal.mut, fontSize = 12.sp)
                        Text(driver?.name ?: driver?.phone ?: "…", color = Pal.accent, fontSize = 17.sp, fontWeight = FontWeight.Black, maxLines = 1)
                    }
                    earnings?.profile?.rating?.let {
                        Row(
                            Modifier.clip(CircleShape).background(Pal.warning.copy(alpha = 0.14f)).padding(horizontal = 10.dp, vertical = 5.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Rounded.Star, null, tint = Pal.warning, modifier = Modifier.size(16.dp))
                            Text(String.format("%.1f", it), color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(start = 3.dp))
                        }
                    }
                    NotificationBell(onClick = onOpenNotifications)
                }
                Row(
                    Modifier.staggerIn(1).clip(CircleShape).bounceClick(onClick = onOpenEarnings).background(Pal.accent).padding(horizontal = 18.dp, vertical = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    Text(stringResource(R.string.home_today_title), color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    Text(money(earnings?.today?.netMinor ?: 0, earnings?.currency), color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                }
            }

            // O-45: the sheet is down — a pill (with my status) brings it back.
            if (sheetHidden) {
                Row(
                    Modifier.align(Alignment.BottomCenter).navigationBarsPadding().padding(bottom = 18.dp)
                        .clip(CircleShape).bounceClick { scope.launch { sheet.bottomSheetState.partialExpand() } }
                        .background(Pal.card).padding(horizontal = 18.dp, vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Box(Modifier.size(10.dp).clip(CircleShape).background(if (online) Pal.success else Pal.faint))
                    Text(stringResource(if (online) R.string.home_status_online else R.string.home_status_offline), color = Pal.ink, fontWeight = FontWeight.Bold)
                    Icon(Icons.Rounded.KeyboardArrowUp, null, tint = Pal.ink)
                }
            }
            // Back to where I am.
            Box(Modifier.align(Alignment.BottomEnd).navigationBarsPadding().padding(end = 16.dp, bottom = if (sheetHidden) 80.dp else peek + 14.dp)) {
                RoundIconButton(Icons.Rounded.MyLocation, stringResource(R.string.home_recenter), onClick = {
                    scope.launch {
                        lastPosition(context)?.let { (lat, lng) -> camera.animate(LatLng(lat, lng), 16f) }
                    }
                })
            }
        }
    }
}

/** Until the phone says where it is: the launch city (as in the DORR app). */
private val Riyadh = LatLng(24.7136, 46.6753)

@Composable
private fun Stat(label: String, value: String, modifier: Modifier, big: Boolean = false) {
    Column(modifier) {
        Text(label, color = Pal.mut, fontSize = 12.sp)
        Text(value, color = if (big) Pal.accent else Pal.ink, fontWeight = FontWeight.ExtraBold, fontSize = if (big) 24.sp else 18.sp, maxLines = 1)
    }
}

/** The big round switch; online it breathes with a soft ring, offline it waits. */
@Composable
private fun OnlineButton(online: Boolean, enabled: Boolean, busy: Boolean, onClick: () -> Unit) {
    val pulse = rememberInfiniteTransition(label = "online-pulse")
    val ring by pulse.animateFloat(1f, 1.22f, infiniteRepeatable(tween(1300), RepeatMode.Reverse), label = "ring")
    val color by animateColorAsState(if (online) Pal.success else Pal.accent, tween(400), label = "button")
    val press by animateFloatAsState(if (busy) 0.94f else 1f, tween(160), label = "press")

    Box(contentAlignment = Alignment.Center, modifier = Modifier.size(200.dp)) {
        if (enabled && !busy) {
            Box(Modifier.size(170.dp).scale(if (online) ring else 1.06f).alpha(0.22f).background(color, CircleShape))
        }
        Box(
            Modifier.size(160.dp).scale(press).alpha(if (enabled) 1f else 0.35f).clip(CircleShape)
                .background(Brush.linearGradient(listOf(color, color.copy(alpha = 0.75f))))
                .bounceClick(enabled = enabled && !busy, onClick = onClick),
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
    val color = when (d.level) { "limit" -> Pal.danger; "warn" -> Pal.warning; else -> Pal.success }
    fun hours(s: Long) = context.getString(R.string.earn_hours, (s / 3600).toInt(), ((s % 3600) / 60).toInt())
    val used by animateFloatAsState((d.usedS.toFloat() / limit.coerceAtLeast(1)).coerceIn(0.02f, 1f), tween(600), label = "driving-used")
    Card {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            TileIcon(Icons.Rounded.Schedule, color, 44.dp)
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.dh_title, d.windowH), color = Pal.ink, fontWeight = FontWeight.Bold)
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
        Spacer(Modifier.height(12.dp))
        Box(Modifier.fillMaxWidth().height(8.dp).clip(CircleShape).background(color.copy(alpha = 0.15f))) {
            Box(Modifier.fillMaxWidth(used).height(8.dp).clip(CircleShape).background(color))
        }
        Text(
            if (d.maxS > 0) stringResource(R.string.dh_of, hours(d.usedS), hours(d.maxS)) else hours(d.usedS),
            fontSize = 12.sp, color = Pal.mut, modifier = Modifier.padding(top = 6.dp),
        )
    }
}
