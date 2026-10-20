package com.dorr.app.ui.screens.rides

import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.AirlineSeatReclineNormal
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ConfirmationNumber
import androidx.compose.material.icons.rounded.DirectionsBus
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Sms
import androidx.compose.material.icons.rounded.SwapVert
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
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
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppLocale
import com.dorr.app.network.BusBookingDto
import com.dorr.app.network.BusTripDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.fromPlace
import com.dorr.app.network.leavesAt
import com.dorr.app.network.toPlace
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.android.gms.maps.model.LatLng
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.UUID

/*
 * Bus booking (docs/rides_tasks.md O-22, O-23): a service of its own inside rides. Where I leave from and /
 * or where I go (either alone filters too) → the trips captains published → a seat map like a plane's, for
 * going, coming back, or both → paid from the wallet → tickets with a QR per seat. Seats taken meanwhile
 * show at once (`bus.seats` on the trip's public channel) — nothing asks the server on a timer.
 */

private sealed interface BusPage {
    data object Search : BusPage
    data class Seats(val trip: BusTripDto) : BusPage
    data object Tickets : BusPage
    data class Ticket(val booking: BusBookingDto, val fresh: Boolean = false) : BusPage
}

@Composable
internal fun BusHost(state: RidesState, onClose: () -> Unit) {
    var page by remember { mutableStateOf<BusPage>(BusPage.Search) }
    var forward by remember { mutableStateOf(true) }
    fun go(to: BusPage, ahead: Boolean = true) { forward = ahead; page = to }

    // The search keeps its places and results while a seat page is opened and closed.
    val search = remember { BusSearchState() }

    BackHandler {
        when (page) {
            BusPage.Search -> onClose()
            is BusPage.Seats -> go(BusPage.Search, false)
            BusPage.Tickets -> go(BusPage.Search, false)
            is BusPage.Ticket -> go(BusPage.Tickets, false)
        }
    }
    Box(Modifier.fillMaxSize().background(settingsBackground())) {
        AnimatedContent(
            targetState = page,
            transitionSpec = {
                val sign = if (forward) 1 else -1
                (slideInHorizontally(tween(320)) { sign * it / 4 } + fadeIn(tween(260))) togetherWith fadeOut(tween(180))
            },
            label = "bus-pages",
        ) { p ->
            when (p) {
                BusPage.Search -> BusSearchPage(state, search, onBack = onClose, onTickets = { go(BusPage.Tickets) }, onPick = { go(BusPage.Seats(it)) })
                is BusPage.Seats -> BusSeatsPage(p.trip, onBack = { go(BusPage.Search, false) }, onBooked = { go(BusPage.Ticket(it, fresh = true)) })
                BusPage.Tickets -> BusTicketsPage(onBack = { go(BusPage.Search, false) }, onOpen = { go(BusPage.Ticket(it)) })
                is BusPage.Ticket -> BusTicketPage(p.booking, p.fresh, onBack = { go(BusPage.Tickets, false) })
            }
        }
    }
}

private class BusSearchState {
    var from by mutableStateOf<RidePoint?>(null)
    var to by mutableStateOf<RidePoint?>(null)
    var day by mutableStateOf<LocalDate?>(null)
    var results by mutableStateOf<List<BusTripDto>?>(null)
    var loading by mutableStateOf(false)
    var editing by mutableStateOf<String?>(null)
}

// ---------------------------------------------------------------- search

@Composable
private fun BusSearchPage(rides: RidesState, s: BusSearchState, onBack: () -> Unit, onTickets: () -> Unit, onPick: (BusTripDto) -> Unit) {
    val scope = rememberCoroutineScope()
    val accent = settingsAccent()
    var error by remember { mutableStateOf<String?>(null) }

    fun run() {
        if (s.from == null && s.to == null) return
        s.loading = true
        error = null
        scope.launch {
            runCatching {
                ApiClient.bus.search(ridesAuth(), s.from?.lat, s.from?.lng, s.to?.lat, s.to?.lng, s.day?.toString()).data.orEmpty()
            }.onSuccess { s.results = it }.onFailure { error = it.apiFailure().message }
            s.loading = false
        }
    }
    // The pickup the rides map already knows is where I leave from, until I choose.
    LaunchedEffect(Unit) { if (s.from == null) s.from = rides.pickup }
    LaunchedEffect(s.from, s.to, s.day) { if (s.from != null || s.to != null) run() }

    LazyColumn(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item {
            // The hero: a gradient band, the bus driving in, the title — the page's one big moment.
            Box(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 32.dp, bottomEnd = 32.dp))
                    .background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.78f), AppColors.brandOrange.copy(alpha = 0.85f))))
                    .statusBarsPadding().padding(horizontal = 18.dp).padding(top = 10.dp, bottom = 22.dp),
            ) {
                Column {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        CircleIcon(Icons.AutoMirrored.Rounded.ArrowBack, Color.White.copy(alpha = 0.18f), Color.White, onBack)
                        Spacer(Modifier.weight(1f))
                        CircleIcon(Icons.Rounded.ConfirmationNumber, Color.White.copy(alpha = 0.18f), Color.White, onTickets)
                    }
                    Spacer(Modifier.height(10.dp))
                    var arrived by remember { mutableStateOf(false) }
                    LaunchedEffect(Unit) { arrived = true }
                    val drive by animateFloatAsState(if (arrived) 0f else 1f, spring(dampingRatio = 0.62f, stiffness = Spring.StiffnessVeryLow), label = "bus-drive")
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.bus_title), color = Color.White, fontWeight = FontWeight.Black, fontSize = 26.sp)
                            Text(stringResource(R.string.bus_subtitle), color = Color.White.copy(alpha = 0.85f), fontSize = 13.sp)
                        }
                        Icon(
                            Icons.Rounded.DirectionsBus, null, tint = Color.White,
                            modifier = Modifier.size(64.dp).graphicsLayer { translationX = drive * 220f; rotationZ = -drive * 8f },
                        )
                    }
                }
            }
        }
        item {
            // From / to, with the swap between them.
            Column(
                Modifier.padding(horizontal = 16.dp).shadow(14.dp, RoundedCornerShape(24.dp), spotColor = accent.copy(alpha = 0.25f))
                    .clip(RoundedCornerShape(24.dp)).background(settingsCard()).padding(14.dp),
            ) {
                Box {
                    Column {
                        PlaceRow(Icons.Rounded.MyLocation, AppColors.success, stringResource(R.string.bus_from), s.from?.address, onClick = { s.editing = "from" }, onClear = s.from?.let { { s.from = null } })
                        Box(Modifier.padding(start = 38.dp).fillMaxWidth().height(1.dp).background(settingsInk().copy(alpha = 0.08f)))
                        PlaceRow(Icons.Rounded.Place, AppColors.brandOrange, stringResource(R.string.bus_to), s.to?.address, onClick = { s.editing = "to" }, onClear = s.to?.let { { s.to = null } })
                    }
                    var spun by remember { mutableStateOf(false) }
                    val spin by animateFloatAsState(if (spun) 180f else 0f, tween(380), label = "swap")
                    Box(
                        Modifier.align(Alignment.CenterEnd).size(40.dp).shadow(6.dp, CircleShape).clip(CircleShape).background(accent)
                            .clickable { val f = s.from; s.from = s.to; s.to = f; spun = !spun },
                        contentAlignment = Alignment.Center,
                    ) { Icon(Icons.Rounded.SwapVert, stringResource(R.string.bus_swap), tint = Color.White, modifier = Modifier.rotate(spin)) }
                }
                Spacer(Modifier.height(12.dp))
                DayChips(s.day) { s.day = it }
                Text(stringResource(R.string.bus_filter_hint), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(top = 8.dp))
            }
        }
        val results = s.results
        when {
            s.loading -> item { Box(Modifier.fillMaxWidth().padding(30.dp), contentAlignment = Alignment.Center) { androidx.compose.material3.CircularProgressIndicator(color = accent) } }
            error != null -> item { Text(error.orEmpty(), color = AppColors.danger, modifier = Modifier.padding(horizontal = 20.dp)) }
            results == null -> item { EmptyBus(stringResource(R.string.bus_start_hint)) }
            results.isEmpty() -> item { EmptyBus(stringResource(R.string.bus_none)) }
            else -> {
                item { Text(stringResource(R.string.bus_found, results.size), color = settingsInk(), fontWeight = FontWeight.Bold, modifier = Modifier.padding(horizontal = 20.dp)) }
                itemsIndexed(results, key = { _, t -> t.uuid + t.leg }) { i, trip ->
                    Box(Modifier.padding(horizontal = 16.dp).staggerIn(i)) { TripCard(trip) { onPick(trip) } }
                }
            }
        }
        item { Spacer(Modifier.navigationBarsPadding().height(24.dp)) }
    }

    s.editing?.let { which ->
        BusPlaceSearch(near = rides.me ?: rides.pickup?.latLng, onClose = { s.editing = null }) { point ->
            if (which == "from") s.from = point else s.to = point
            s.editing = null
        }
    }
}

@Composable
private fun PlaceRow(icon: androidx.compose.ui.graphics.vector.ImageVector, tint: Color, label: String, value: String?, onClick: () -> Unit, onClear: (() -> Unit)?) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(onClick = onClick).padding(vertical = 10.dp).padding(end = 48.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(30.dp).clip(CircleShape).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) { Icon(icon, null, tint = tint, modifier = Modifier.size(17.dp)) }
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f)) {
            Text(label, color = settingsMut(), fontSize = 11.sp)
            Text(value ?: stringResource(R.string.bus_any_place), color = if (value == null) settingsMut() else settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        onClear?.let { Text("✕", color = settingsMut(), modifier = Modifier.clip(CircleShape).clickable(onClick = it).padding(6.dp)) }
    }
}

@Composable
private fun DayChips(day: LocalDate?, onPick: (LocalDate?) -> Unit) {
    val today = LocalDate.now()
    val options = listOf<Pair<LocalDate?, String>>(
        null to stringResource(R.string.bus_any_day),
        today to stringResource(R.string.bus_today),
        today.plusDays(1) to stringResource(R.string.bus_tomorrow),
    ) + (2L..6L).map { today.plusDays(it) to today.plusDays(it).format(DateTimeFormatter.ofPattern("EEE d", AppLocale.java())) }
    Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        options.forEach { (d, label) ->
            val on = d == day
            val bg by animateColorAsState(if (on) settingsAccent() else settingsBackground(), tween(200), label = "day")
            Row(
                Modifier.clip(CircleShape).background(bg).clickable { onPick(d) }.padding(horizontal = 14.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                if (d == null) { Icon(Icons.Rounded.Event, null, tint = if (on) Color.White else settingsMut(), modifier = Modifier.size(15.dp)); Spacer(Modifier.width(4.dp)) }
                Text(label, color = if (on) Color.White else settingsInk(), fontSize = 13.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Medium)
            }
        }
    }
}

@Composable
private fun TripCard(trip: BusTripDto, onClick: () -> Unit) {
    val accent = settingsAccent()
    val leg = trip.leg
    val price = if (leg == "return") trip.prices.returnMinor else trip.prices.outboundMinor
    val free = if (leg == "return") trip.free.returnLeg ?: 0 else trip.free.outbound
    Column(
        Modifier.fillMaxWidth().shadow(8.dp, RoundedCornerShape(22.dp), spotColor = accent.copy(alpha = 0.18f)).clip(RoundedCornerShape(22.dp))
            .background(settingsCard()).pressable(onClick = onClick).padding(16.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(timeOf(trip.leavesAt(leg)), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 22.sp)
            Spacer(Modifier.width(8.dp))
            Text(dayOf(trip.leavesAt(leg)), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.weight(1f))
            if (leg == "return") Tag(stringResource(R.string.bus_return_leg), AppColors.brandOrange)
        }
        Spacer(Modifier.height(10.dp))
        RouteLine(trip.fromPlace(leg).address, trip.toPlace(leg).address)
        Spacer(Modifier.height(12.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.DirectionsBus, null, tint = settingsMut(), modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(4.dp))
            Text(listOfNotNull(trip.bus?.make, trip.bus?.model).joinToString(" ").ifBlank { "—" }, color = settingsMut(), fontSize = 12.sp, maxLines = 1, modifier = Modifier.weight(1f))
            Icon(Icons.Rounded.AirlineSeatReclineNormal, null, tint = if (free > 0) AppColors.success else AppColors.danger, modifier = Modifier.size(16.dp))
            Text(" " + stringResource(R.string.bus_free_seats, free), color = if (free > 0) AppColors.success else AppColors.danger, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            Spacer(Modifier.width(10.dp))
            Text(money(price, trip.currency), color = accent, fontWeight = FontWeight.Black, fontSize = 17.sp)
        }
        if (trip.returnAt != null && leg != "return") {
            Text(stringResource(R.string.bus_has_return, dayOf(trip.returnAt) + " " + timeOf(trip.returnAt)), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(top = 6.dp))
        }
    }
}

@Composable
private fun RouteLine(from: String?, to: String?) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(10.dp).clip(CircleShape).background(AppColors.success))
            Box(Modifier.width(2.dp).height(18.dp).background(settingsInk().copy(alpha = 0.15f)))
            Box(Modifier.size(10.dp).clip(RoundedCornerShape(2.dp)).background(AppColors.brandOrange))
        }
        Spacer(Modifier.width(10.dp))
        Column {
            Text(from.orEmpty(), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Spacer(Modifier.height(8.dp))
            Text(to.orEmpty(), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

// ---------------------------------------------------------------- seats

/**
 * The seat page (O-23): the bus drawn from above — the windscreen, the captain's wheel, the door — and its
 * seats in rows with an aisle; a seat pops when chosen, a taken one is greyed with a person on it. Going,
 * coming back, or both (then a tab per leg, the same number of seats on each).
 */
@Composable
private fun BusSeatsPage(initial: BusTripDto, onBack: () -> Unit, onBooked: (BusBookingDto) -> Unit) {
    val scope = rememberCoroutineScope()
    val accent = settingsAccent()
    var trip by remember { mutableStateOf(initial) }
    val canReturn = trip.prices.returnMinor != null && trip.returnAt != null
    var type by remember { mutableStateOf(if (initial.leg == "return") "return" else "outbound") }
    var leg by remember { mutableStateOf(if (initial.leg == "return") "return" else "outbound") }
    val outbound = remember { mutableStateListOf<Int>() }
    val back = remember { mutableStateListOf<Int>() }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val idempotency = remember { UUID.randomUUID().toString() }

    suspend fun reload() {
        runCatching { ApiClient.bus.trip(ridesAuth(), trip.uuid).data }.getOrNull()?.let { fresh ->
            trip = fresh.copy(leg = trip.leg)
            // A seat someone else just took leaves my choice.
            outbound.removeAll(fresh.taken?.outbound.orEmpty())
            back.removeAll(fresh.taken?.returnLeg.orEmpty())
        }
    }
    LaunchedEffect(trip.uuid) { reload() }
    // Seats booked or freed by others arrive as they happen.
    DisposableEffect(trip.uuid) {
        val channel = "bus-trips.${trip.uuid}"
        com.dorr.app.chat.ChatRealtime.watchPublic(channel, listOf("bus.seats"))
        onDispose { com.dorr.app.chat.ChatRealtime.unwatchPublic(channel) }
    }
    LaunchedEffect(trip.uuid) {
        com.dorr.app.chat.ChatRealtime.events.collect { e -> if (e.name == "bus.seats" && e.data.get("trip_uuid")?.asString == trip.uuid) reload() }
    }
    LaunchedEffect(type) { leg = if (type == "return") "return" else "outbound" }

    val perSeat = when (type) { "return" -> trip.prices.returnMinor; "round" -> trip.prices.roundMinor; else -> trip.prices.outboundMinor } ?: 0
    val count = if (type == "return") back.size else outbound.size
    val ready = when (type) { "outbound" -> outbound.isNotEmpty(); "return" -> back.isNotEmpty(); else -> outbound.isNotEmpty() && back.size == outbound.size }
    val total = perSeat * count

    Column(Modifier.fillMaxSize()) {
        // Header: the route and when.
        Column(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 28.dp, bottomEnd = 28.dp)).background(Brush.verticalGradient(listOf(accent, accent.copy(alpha = 0.85f))))
                .statusBarsPadding().padding(horizontal = 16.dp).padding(top = 8.dp, bottom = 16.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                CircleIcon(Icons.AutoMirrored.Rounded.ArrowBack, Color.White.copy(alpha = 0.18f), Color.White, onBack)
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(trip.fromPlace(trip.leg).address.orEmpty() + "  ←  " + trip.toPlace(trip.leg).address.orEmpty(), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    Text(dayOf(trip.leavesAt(trip.leg)) + " · " + timeOf(trip.leavesAt(trip.leg)), color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp)
                }
            }
            Spacer(Modifier.height(12.dp))
            // Going · coming back · both.
            Row(Modifier.fillMaxWidth().clip(CircleShape).background(Color.White.copy(alpha = 0.16f)).padding(4.dp)) {
                listOfNotNull(
                    "outbound" to stringResource(R.string.bus_type_outbound),
                    if (canReturn) "return" to stringResource(R.string.bus_type_return) else null,
                    if (canReturn) "round" to stringResource(R.string.bus_type_round) else null,
                ).forEach { (key, label) ->
                    val on = type == key
                    val bg by animateColorAsState(if (on) Color.White else Color.Transparent, tween(220), label = "type")
                    Box(Modifier.weight(1f).clip(CircleShape).background(bg).clickable { type = key }.padding(vertical = 9.dp), contentAlignment = Alignment.Center) {
                        Text(label, color = if (on) accent else Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                    }
                }
            }
        }

        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 12.dp)) {
            if (type == "round") {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(bottom = 10.dp)) {
                    LegTab(stringResource(R.string.bus_leg_outbound, outbound.size), leg == "outbound", Modifier.weight(1f)) { leg = "outbound" }
                    LegTab(stringResource(R.string.bus_leg_return, back.size), leg == "return", Modifier.weight(1f)) { leg = "return" }
                }
            }
            SeatLegend()
            Spacer(Modifier.height(12.dp))
            val taken = if (leg == "return") trip.taken?.returnLeg.orEmpty() else trip.taken?.outbound.orEmpty()
            val chosen = if (leg == "return") back else outbound
            BusDeck(
                seats = trip.seatsTotal, perRow = trip.seatsPerRow.coerceIn(2, 6), taken = taken.toSet(), chosen = chosen.toSet(),
            ) { n ->
                error = null
                if (n in chosen) chosen.remove(n) else chosen.add(n)
                // Going and back: the same number each way — after picking going, the coming-back tab is next.
                if (type == "round" && leg == "outbound" && outbound.size > back.size && back.isEmpty()) scope.launch { delay(350); leg = "return" }
            }
            if (type == "round" && outbound.size != back.size) {
                Text(stringResource(R.string.bus_round_same), color = AppColors.warning, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 10.dp))
            }
            trip.note?.takeIf { it.isNotBlank() }?.let { Text("📝 $it", color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 10.dp)) }
        }

        // The summary: chosen seats, the total, pay.
        Column(
            Modifier.fillMaxWidth().shadow(18.dp, RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)).clip(RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp))
                .background(settingsCard()).navigationBarsPadding().padding(16.dp),
        ) {
            AnimatedVisibility(count > 0, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(bottom = 10.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    (if (type == "return") back else outbound).sorted().forEach { Tag("💺 $it", accent) }
                    if (type == "round") back.sorted().forEach { Tag("↩ $it", AppColors.brandOrange) }
                }
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.bus_total_for, count), color = settingsMut(), fontSize = 12.sp)
                    AnimatedContent(targetState = total, label = "total") { v -> Text(money(v, trip.currency), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 22.sp) }
                    Text(stringResource(R.string.bus_per_seat, money(perSeat, trip.currency)), color = settingsMut(), fontSize = 11.sp)
                }
                Box(Modifier.width(170.dp)) {
                    AccentButton(stringResource(R.string.bus_book_pay), enabled = ready, busy = busy) {
                        busy = true
                        error = null
                        scope.launch {
                            val body = JsonObject().apply {
                                addProperty("trip_type", type)
                                add("seats_outbound", JsonArray().apply { if (type != "return") outbound.forEach { add(it) } })
                                add("seats_return", JsonArray().apply { if (type != "outbound") back.forEach { add(it) } })
                            }
                            runCatching { ApiClient.bus.book(ridesAuth(), idempotency, trip.uuid, body).data }
                                .onSuccess { b -> b?.let { onBooked(it.copy(trip = it.trip ?: trip)) } }
                                .onFailure { e ->
                                    val f = e.apiFailure()
                                    error = f.message
                                    if (f.errorCode == "rides_bus_seat_taken") reload()
                                }
                            busy = false
                        }
                    }
                }
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp)) }
        }
    }
}

@Composable
private fun LegTab(label: String, on: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val accent = settingsAccent()
    val bg by animateColorAsState(if (on) accent else settingsCard(), tween(200), label = "leg")
    Box(modifier.clip(RoundedCornerShape(14.dp)).background(bg).border(1.dp, accent.copy(alpha = 0.3f), RoundedCornerShape(14.dp)).clickable(onClick = onClick).padding(vertical = 10.dp), contentAlignment = Alignment.Center) {
        Text(label, color = if (on) Color.White else settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp)
    }
}

@Composable
private fun SeatLegend() {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
        LegendItem(SeatLook.Free, stringResource(R.string.bus_seat_free))
        LegendItem(SeatLook.Chosen, stringResource(R.string.bus_seat_chosen))
        LegendItem(SeatLook.Taken, stringResource(R.string.bus_seat_taken))
    }
}

@Composable
private fun LegendItem(look: SeatLook, label: String) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        SeatShape(look, Modifier.size(20.dp), null)
        Spacer(Modifier.width(6.dp))
        Text(label, color = settingsMut(), fontSize = 12.sp)
    }
}

private enum class SeatLook { Free, Chosen, Taken }

/** The bus from above: a rounded body with the windscreen, the captain's wheel and the door, then the rows. */
@Composable
private fun BusDeck(seats: Int, perRow: Int, taken: Set<Int>, chosen: Set<Int>, onSeat: (Int) -> Unit) {
    val accent = settingsAccent()
    val left = perRow / 2
    val rows = (seats + perRow - 1) / perRow
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(topStart = 48.dp, topEnd = 48.dp, bottomStart = 20.dp, bottomEnd = 20.dp))
            .background(settingsCard()).border(2.dp, accent.copy(alpha = 0.18f), RoundedCornerShape(topStart = 48.dp, topEnd = 48.dp, bottomStart = 20.dp, bottomEnd = 20.dp))
            .padding(horizontal = 14.dp, vertical = 16.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        // The front: windscreen arc, the wheel on the captain's side, the door on the other.
        Row(Modifier.fillMaxWidth().padding(bottom = 14.dp), verticalAlignment = Alignment.CenterVertically) {
            Canvas(Modifier.size(34.dp)) {
                drawCircle(accent.copy(alpha = 0.7f), radius = size.minDimension / 2.2f, style = Stroke(5f))
                drawCircle(accent.copy(alpha = 0.7f), radius = size.minDimension / 9f)
                drawLine(accent.copy(alpha = 0.7f), Offset(size.width * 0.12f, size.height / 2), Offset(size.width * 0.88f, size.height / 2), strokeWidth = 4f)
            }
            Canvas(Modifier.weight(1f).height(16.dp).padding(horizontal = 12.dp)) {
                drawRoundRect(accent.copy(alpha = 0.12f), cornerRadius = CornerRadius(20f, 20f))
            }
            Text(stringResource(R.string.bus_door), color = settingsMut(), fontSize = 10.sp, modifier = Modifier.clip(RoundedCornerShape(6.dp)).background(settingsBackground()).padding(horizontal = 8.dp, vertical = 3.dp))
        }
        for (r in 0 until rows) {
            Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                for (c in 0 until perRow) {
                    if (c == left) {
                        // The aisle, with the row number.
                        Box(Modifier.width(26.dp), contentAlignment = Alignment.Center) { Text("${r + 1}", color = settingsMut().copy(alpha = 0.6f), fontSize = 10.sp) }
                    }
                    val n = r * perRow + c + 1
                    Box(Modifier.weight(1f).padding(3.dp), contentAlignment = Alignment.Center) {
                        if (n <= seats) {
                            val look = when (n) { in taken -> SeatLook.Taken; in chosen -> SeatLook.Chosen; else -> SeatLook.Free }
                            SeatShape(look, Modifier.fillMaxWidth().aspectRatio(0.9f), n, onClick = if (look == SeatLook.Taken) null else ({ onSeat(n) }))
                        }
                    }
                }
            }
        }
    }
}

/** One seat: a backrest and a cushion; chosen pops with a spring and fills, taken greys out with a person. */
@Composable
private fun SeatShape(look: SeatLook, modifier: Modifier, number: Int?, onClick: (() -> Unit)? = null) {
    val accent = settingsAccent()
    val fill by animateColorAsState(
        when (look) { SeatLook.Chosen -> accent; SeatLook.Taken -> settingsInk().copy(alpha = 0.12f); SeatLook.Free -> settingsCard() }, tween(220), label = "seat-fill",
    )
    val edge = when (look) { SeatLook.Chosen -> accent; SeatLook.Taken -> Color.Transparent; SeatLook.Free -> accent.copy(alpha = 0.45f) }
    val pop by animateFloatAsState(if (look == SeatLook.Chosen) 1.08f else 1f, spring(dampingRatio = 0.35f, stiffness = Spring.StiffnessMedium), label = "seat-pop")
    Box(
        modifier.scale(pop).clip(RoundedCornerShape(topStart = 12.dp, topEnd = 12.dp, bottomStart = 6.dp, bottomEnd = 6.dp)).background(fill)
            .border(BorderStroke(1.5.dp, edge), RoundedCornerShape(topStart = 12.dp, topEnd = 12.dp, bottomStart = 6.dp, bottomEnd = 6.dp))
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier),
        contentAlignment = Alignment.Center,
    ) {
        // The backrest line along the top.
        Box(Modifier.align(Alignment.TopCenter).padding(top = 4.dp).fillMaxWidth(0.62f).height(3.dp).clip(CircleShape).background(if (look == SeatLook.Chosen) Color.White.copy(alpha = 0.6f) else accent.copy(alpha = 0.25f)))
        when {
            look == SeatLook.Taken -> Icon(Icons.Rounded.AirlineSeatReclineNormal, null, tint = settingsMut().copy(alpha = 0.6f), modifier = Modifier.fillMaxSize(0.5f))
            number != null -> Text("$number", color = if (look == SeatLook.Chosen) Color.White else settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp)
        }
    }
}

// ---------------------------------------------------------------- tickets

@Composable
private fun BusTicketsPage(onBack: () -> Unit, onOpen: (BusBookingDto) -> Unit) {
    var list by remember { mutableStateOf<List<BusBookingDto>?>(null) }
    LaunchedEffect(Unit) { list = runCatching { ApiClient.bus.bookings(ridesAuth()).data.orEmpty() }.getOrDefault(emptyList()) }
    Column(Modifier.fillMaxSize()) {
        PageBar(stringResource(R.string.bus_my_tickets), onBack)
        val rows = list
        when {
            rows == null -> Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) { androidx.compose.material3.CircularProgressIndicator(color = settingsAccent()) }
            rows.isEmpty() -> EmptyBus(stringResource(R.string.bus_no_tickets))
            else -> LazyColumn(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                itemsIndexed(rows, key = { _, b -> b.uuid }) { i, b ->
                    val trip = b.trip ?: return@itemsIndexed
                    val leg = if (b.tripType == "return") "return" else "outbound"
                    Row(
                        Modifier.padding(horizontal = 16.dp).staggerIn(i).fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsCard()).pressable { onOpen(b) }.padding(14.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.ConfirmationNumber, null, tint = settingsAccent())
                        }
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(trip.fromPlace(leg).address.orEmpty() + " ← " + trip.toPlace(leg).address.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(dayOf(trip.leavesAt(leg)) + " · " + timeOf(trip.leavesAt(leg)), color = settingsMut(), fontSize = 12.sp)
                        }
                        Tag(statusLabel(b.status), if (b.status == "confirmed") AppColors.success else settingsMut())
                    }
                }
                item { Spacer(Modifier.navigationBarsPadding().height(16.dp)) }
            }
        }
    }
}

@Composable
private fun BusTicketPage(initial: BusBookingDto, fresh: Boolean, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var booking by remember { mutableStateOf(initial) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val trip = booking.trip
    Column(Modifier.fillMaxSize()) {
        PageBar(stringResource(R.string.bus_ticket), onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (fresh) {
                var shown by remember { mutableStateOf(false) }
                LaunchedEffect(Unit) { shown = true }
                AnimatedVisibility(shown, enter = scaleIn(spring(dampingRatio = 0.45f)) + fadeIn()) {
                    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                        Icon(Icons.Rounded.CheckCircle, null, tint = AppColors.success, modifier = Modifier.size(72.dp))
                        Text(stringResource(R.string.bus_booked_done), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp)
                    }
                }
            }
            trip?.let { t ->
                Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(settingsCard()).padding(16.dp)) {
                    val leg = if (booking.tripType == "return") "return" else "outbound"
                    RouteLine(t.fromPlace(leg).address, t.toPlace(leg).address)
                    Spacer(Modifier.height(8.dp))
                    Text(dayOf(t.leavesAt(leg)) + " · " + timeOf(t.leavesAt(leg)), color = settingsInk(), fontWeight = FontWeight.Bold)
                    if (booking.tripType == "round") Text(stringResource(R.string.bus_has_return, dayOf(t.returnAt) + " " + timeOf(t.returnAt)), color = settingsMut(), fontSize = 12.sp)
                    Text(listOfNotNull(t.bus?.make, t.bus?.model, t.bus?.plate).joinToString(" · "), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
                    Text(money(booking.amountMinor, t.currency), color = settingsAccent(), fontWeight = FontWeight.Black, fontSize = 18.sp, modifier = Modifier.padding(top = 6.dp))
                    // O-24: reach the captain about the trip.
                    t.captainPhone?.let { phone ->
                        Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Tag("📞 " + stringResource(R.string.rd_call), AppColors.success, onClick = { runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))) } })
                            Tag("✉ " + stringResource(R.string.bus_message), settingsAccent(), onClick = { runCatching { context.startActivity(Intent(Intent.ACTION_SENDTO, Uri.parse("smsto:$phone"))) } })
                        }
                    }
                }
            }
            // One QR per seat per leg — what the captain scans at the door.
            booking.tickets.forEach { tk ->
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(settingsCard()).padding(14.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    tk.qr?.let { qr ->
                        val bmp = remember(qr) { qrImage(qr) }
                        Image(bmp.asImageBitmap(), null, modifier = Modifier.size(110.dp).clip(RoundedCornerShape(12.dp)))
                    }
                    Spacer(Modifier.width(14.dp))
                    Column {
                        Text(stringResource(if (tk.leg == "return") R.string.bus_type_return else R.string.bus_type_outbound), color = settingsMut(), fontSize = 12.sp)
                        Text(stringResource(R.string.bus_seat_n, tk.seat), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 22.sp)
                        if (tk.boarded) Tag(stringResource(R.string.bus_boarded), AppColors.success)
                    }
                }
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 12.sp) }
            if (booking.status == "confirmed") {
                Text(
                    stringResource(R.string.bus_cancel_ticket), color = AppColors.danger, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(enabled = !busy) {
                        busy = true
                        scope.launch {
                            runCatching { ApiClient.bus.cancel(ridesAuth(), booking.uuid).data }
                                .onSuccess { it?.let { b -> booking = b.copy(trip = b.trip ?: booking.trip) } }
                                .onFailure { error = it.apiFailure().message }
                            busy = false
                        }
                    }.padding(12.dp),
                )
            }
            Spacer(Modifier.navigationBarsPadding())
        }
    }
}

// ---------------------------------------------------------------- the place search

@Composable
private fun BusPlaceSearch(near: LatLng?, onClose: () -> Unit, onPick: (RidePoint) -> Unit) {
    val context = LocalContext.current
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<RidePoint>>(emptyList()) }
    LaunchedEffect(query) { delay(300); results = searchPlaces(context, query, near) }
    BackHandler(onBack = onClose)
    Column(Modifier.fillMaxSize().background(settingsBackground()).statusBarsPadding().padding(16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            CircleIcon(Icons.AutoMirrored.Rounded.ArrowBack, settingsCard(), settingsInk(), onClose)
            Spacer(Modifier.width(10.dp))
            com.dorr.app.ui.components.DorrTextField(
                value = query, onValueChange = { query = it }, placeholder = stringResource(R.string.rd_search_hint), icon = Icons.Rounded.Search,
                modifier = Modifier.weight(1f),
            )
        }
        Spacer(Modifier.height(10.dp))
        LazyColumn {
            itemsIndexed(results) { i, p ->
                Row(Modifier.staggerIn(i).fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { onPick(p) }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Place, null, tint = settingsAccent())
                    Spacer(Modifier.width(10.dp))
                    Text(p.address, color = settingsInk(), fontSize = 14.sp)
                }
            }
        }
    }
}

// ---------------------------------------------------------------- small pieces

@Composable
private fun PageBar(title: String, onBack: () -> Unit) {
    Row(Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        CircleIcon(Icons.AutoMirrored.Rounded.ArrowBack, settingsCard(), settingsInk(), onBack)
        Spacer(Modifier.width(12.dp))
        Text(title, color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp)
    }
}

@Composable
private fun CircleIcon(icon: androidx.compose.ui.graphics.vector.ImageVector, bg: Color, tint: Color, onClick: () -> Unit) {
    Box(Modifier.size(40.dp).clip(CircleShape).background(bg).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp))
    }
}

@Composable
private fun Tag(text: String, color: Color, onClick: (() -> Unit)? = null) {
    Text(
        text, color = color, fontSize = 12.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier).padding(horizontal = 10.dp, vertical = 5.dp),
    )
}

@Composable
private fun EmptyBus(text: String) {
    Column(Modifier.fillMaxWidth().padding(36.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Icon(Icons.Rounded.DirectionsBus, null, tint = settingsMut().copy(alpha = 0.5f), modifier = Modifier.size(56.dp))
        Spacer(Modifier.height(8.dp))
        Text(text, color = settingsMut(), textAlign = TextAlign.Center)
    }
}

@Composable
private fun statusLabel(status: String): String = stringResource(
    when (status) { "canceled" -> R.string.bus_status_canceled; "completed" -> R.string.bus_status_completed; else -> R.string.bus_status_confirmed },
)

private fun zoned(iso: String?) = iso?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()) }.getOrNull() }

private fun timeOf(iso: String?): String = zoned(iso)?.format(DateTimeFormatter.ofPattern("h:mm a", AppLocale.java())) ?: "—"

private fun dayOf(iso: String?): String = zoned(iso)?.format(DateTimeFormatter.ofPattern("EEEE d MMM", AppLocale.java())) ?: ""

/** A ticket's QR (dark modules on white, a small quiet zone). */
private fun qrImage(text: String, size: Int = 480): Bitmap {
    val matrix = QRCodeWriter().encode(text, BarcodeFormat.QR_CODE, size, size, mapOf(EncodeHintType.MARGIN to 1))
    val bmp = Bitmap.createBitmap(matrix.width, matrix.height, Bitmap.Config.ARGB_8888)
    for (x in 0 until matrix.width) for (y in 0 until matrix.height) bmp.setPixel(x, y, if (matrix[x, y]) android.graphics.Color.parseColor("#111928") else android.graphics.Color.WHITE)
    return bmp
}
