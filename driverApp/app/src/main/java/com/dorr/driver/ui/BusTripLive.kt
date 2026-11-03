package com.dorr.driver.ui

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.animateIntAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.scaleIn
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.Chat
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.DirectionsBus
import androidx.compose.material.icons.rounded.EventSeat
import androidx.compose.material.icons.rounded.HowToReg
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.QrCodeScanner
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.BusPassengerDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.DriverBusTripDto
import com.dorr.driver.net.MessageDto
import com.dorr.driver.net.QuickDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.BannerKind
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import com.google.gson.JsonObject
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.util.UUID

/*
 * One bus trip, for its captain (docs/rides_tasks.md O-24, O-68, O-69): the route and its time with a
 * countdown on the phone's clock; «start» only from its time (a little before — the server's
 * `can_depart_at`); booked / on board / free; the bus from above with its seats coming alive; and each
 * passenger as a card — their seats to board them, and an in-app chat and call (no numbers exchanged).
 * Everything follows the trip's events: a booking, a message, a ring — no timer asks the server.
 */
@Composable
fun BusTripDetailScreen(uuid: String, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var trip by remember { mutableStateOf<DriverBusTripDto?>(null) }
    var leg by remember { mutableStateOf("outbound") }
    var message by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var chatWith by remember { mutableStateOf<BusPassengerDto?>(null) }

    suspend fun reload() { (apiCall { DriverBackend.bus.trip(uuid) } as? ApiResult.Ok)?.let { trip = it.data } }
    LaunchedEffect(uuid) { reload() }
    // A booking, a message or a ring on this trip arrives as an event: one reload.
    LaunchedEffect(uuid) {
        com.dorr.driver.live.DriverLive.events.collect { e ->
            if (e.name.startsWith("bus.")) reload()
            if (e.name == "bus.call_changed") {
                val status = e.data.get("status")?.takeIf { !it.isJsonNull }?.asString
                if (status != "ringing" && status != "active") RideCallSession.endedElsewhere(e.data.get("call_uuid")?.takeIf { !it.isJsonNull }?.asString)
            }
        }
    }
    // A passenger ringing (caught up on open, or the event above): the call screen rings.
    LaunchedEffect(trip) {
        val t = trip ?: return@LaunchedEffect
        t.passengers.firstOrNull { it.call?.status == "ringing" && it.call.caller == "user" }?.let { p ->
            RideCallSession.incoming(context, p.bookingUuid, p.call!!.uuid, p.name.orEmpty(), end = { b, c -> apiCall { DriverBackend.bus.endCall(b, c) } }, peerVerified = p.isVerified)
        }
    }

    fun board(body: JsonObject) {
        scope.launch {
            when (val r = apiCall { DriverBackend.bus.board(uuid, body) }) {
                is ApiResult.Ok -> { message = context.getString(R.string.bus_boarded_n, r.data.name.orEmpty(), r.data.seat); reload() }
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
        }
    }
    val scan = androidx.activity.compose.rememberLauncherForActivityResult(ScanContract()) { result -> result.contents?.let { board(JsonObject().apply { addProperty("qr", it) }) } }
    fun act(call: suspend () -> retrofit2.Response<com.dorr.core.network.ApiEnvelope<DriverBusTripDto>>) {
        busy = true
        error = null
        scope.launch {
            when (val r = apiCall { call() }) {
                is ApiResult.Ok -> reload()
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }
    fun move(to: String) = act { DriverBackend.bus.move(uuid, JsonObject().apply { addProperty("to", to) }) }

    // The phone's clock, for the countdown and for when «start» opens.
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(Unit) { while (true) { delay(1000); now = System.currentTimeMillis() } }

    Column(Modifier.fillMaxSize().background(Pal.bg)) {
        val t = trip
        if (t == null) {
            Box(Modifier.fillMaxWidth().background(Pal.accent).statusBarsPadding().padding(16.dp)) {
                RoundIcon(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
            }
            return@Column
        }
        BusHero(t, now, onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            message?.let { Banner(it, kind = BannerKind.Success) }
            Banner(error)

            // Booked / on board / free, for the leg shown.
            val takenSeats = (if (leg == "return") t.taken?.returnLeg else t.taken?.outbound).orEmpty().toSet()
            val boardedSeats = t.passengers.flatMap { p -> p.seats.filter { it.leg == leg && it.boarded }.map { it.seat } }.toSet()
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Stat(Icons.Rounded.EventSeat, takenSeats.size, stringResource(R.string.bus_stat_booked), Pal.accent, Modifier.weight(1f))
                Stat(Icons.Rounded.HowToReg, boardedSeats.size, stringResource(R.string.bus_stat_boarded), Pal.success, Modifier.weight(1f))
                Stat(Icons.Rounded.EventSeat, (t.seatsTotal - takenSeats.size).coerceAtLeast(0), stringResource(R.string.bus_stat_free), Pal.mut, Modifier.weight(1f))
            }

            // The action of the moment — «start» only from its time.
            when (t.status) {
                "scheduled" -> StartButton(stringResource(R.string.bus_depart), t.canDepartAt, now, busy, Pal.success) { move("departed") }
                "departed" -> {
                    if (t.returnAt != null) StartButton(stringResource(R.string.bus_start_return), t.canReturnAt, now, busy, Pal.orange) { move("returning") }
                    PrimaryButton(stringResource(R.string.bus_finish), busy = busy) { move("completed") }
                }
                "returning" -> PrimaryButton(stringResource(R.string.bus_finish), busy = busy) { move("completed") }
            }
            if (t.status == "scheduled" || t.status == "departed" || t.status == "returning") {
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Pal.accent.copy(alpha = 0.1f))
                        .clickable { scan.launch(ScanOptions().setPrompt(context.getString(R.string.bus_scan_prompt)).setBeepEnabled(true).setOrientationLocked(true)) }
                        .padding(14.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center,
                ) {
                    Icon(Icons.Rounded.QrCodeScanner, null, tint = Pal.accent)
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.bus_scan_ticket), color = Pal.accent, fontWeight = FontWeight.Bold)
                }
            }

            // The seats, per leg.
            if (t.returnAt != null) {
                Row(Modifier.fillMaxWidth().clip(CircleShape).background(Pal.card).border(1.dp, Pal.line, CircleShape).padding(4.dp)) {
                    LegTab(stringResource(R.string.bus_leg_out), leg == "outbound", Modifier.weight(1f)) { leg = "outbound" }
                    LegTab(stringResource(R.string.bus_leg_back), leg == "return", Modifier.weight(1f)) { leg = "return" }
                }
            }
            BusDeckView(t.seatsTotal, t.seatsPerRow.coerceIn(2, 6), takenSeats, boardedSeats)
            SeatKey()

            // O-24, O-68: who booked — board them, chat or call them inside the app.
            Text(stringResource(R.string.bus_passengers, t.passengers.size), fontWeight = FontWeight.Black, color = Pal.ink, fontSize = 17.sp)
            if (t.passengers.isEmpty()) {
                Column(Modifier.fillMaxWidth().surface().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(Icons.Rounded.DirectionsBus, null, tint = Pal.faint, modifier = Modifier.size(40.dp))
                    Text(stringResource(R.string.bus_no_passengers), color = Pal.mut, fontSize = 13.sp)
                }
            }
            val open = t.status in setOf("scheduled", "departed", "returning")
            t.passengers.forEachIndexed { i, p ->
                PassengerCard(
                    p, i, open,
                    onBoard = { l, s -> board(JsonObject().apply { addProperty("leg", l); addProperty("seat", s) }) },
                    onChat = { chatWith = p },
                    onCall = {
                        RideCallSession.call(
                            context, p.bookingUuid, p.name.orEmpty(),
                            start = { callJoin(apiCall { DriverBackend.bus.startCall(p.bookingUuid) }) },
                            end = { b, c -> apiCall { DriverBackend.bus.endCall(b, c) } },
                            peerVerified = p.isVerified,
                        )
                    },
                )
            }
            if (t.status == "scheduled") {
                Text(
                    stringResource(R.string.bus_cancel_trip), color = Pal.danger, fontWeight = FontWeight.Bold, fontSize = 14.sp,
                    modifier = Modifier.align(Alignment.CenterHorizontally).clip(CircleShape).clickable { act { DriverBackend.bus.cancel(uuid) } }.padding(horizontal = 18.dp, vertical = 10.dp),
                )
            }
            Spacer(Modifier.navigationBarsPadding())
        }
    }

    chatWith?.let { p -> BusPassengerChat(p, trip?.status in setOf("scheduled", "departed", "returning"), onClose = { chatWith = null; scope.launch { reload() } }) }
    RideCallOverlay(onAccept = {
        val booking = RideCallSession.rideUuid
        val call = RideCallSession.callUuid
        if (booking != null && call != null) RideCallSession.accept(context) { callJoin(apiCall { DriverBackend.bus.acceptCall(booking, call) }) }
    })
}

/** The route, the day and the time, and how long until it leaves — on the phone's clock. */
@Composable
private fun BusHero(t: DriverBusTripDto, now: Long, onBack: () -> Unit) {
    val leaves = millis(t.departAt)
    val drive by rememberInfiniteTransition(label = "bus").animateFloat(0f, 1f, infiniteRepeatable(tween(2600, easing = LinearEasing), RepeatMode.Restart), label = "bus-drive")
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 30.dp, bottomEnd = 30.dp))
            .background(Brush.linearGradient(listOf(Pal.accent, Pal.accent.copy(alpha = 0.78f), Pal.orange.copy(alpha = 0.85f))))
            .statusBarsPadding().padding(horizontal = 16.dp, vertical = 12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            RoundIcon(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.bus_trip), color = Color.White, fontWeight = FontWeight.Black, fontSize = 19.sp, modifier = Modifier.weight(1f))
            Text(
                busStatusText(t.status), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 12.sp,
                modifier = Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.2f)).padding(horizontal = 12.dp, vertical = 5.dp),
            )
        }
        Spacer(Modifier.height(16.dp))
        // From → to, the bus driving along the line.
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(busTime(t.departAt), color = Color.White, fontWeight = FontWeight.Black, fontSize = 22.sp)
                Text(t.origin.address.orEmpty(), color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            BoxWithConstraints(Modifier.width(110.dp).height(34.dp), contentAlignment = Alignment.CenterStart) {
                Canvas(Modifier.fillMaxWidth().height(2.dp)) {
                    drawLine(Color.White.copy(alpha = 0.6f), Offset(0f, 0f), Offset(size.width, 0f), 3f, StrokeCap.Round, PathEffect.dashPathEffect(floatArrayOf(10f, 8f)))
                }
                Icon(
                    Icons.Rounded.DirectionsBus, null, tint = Color.White,
                    modifier = Modifier.size(24.dp).offset(x = (maxWidth - 24.dp) * (if (t.status == "scheduled") 0f else drive)),
                )
            }
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.End) {
                Text(busDay(t.departAt), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Text(t.destination.address.orEmpty(), color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
        }
        t.returnAt?.let {
            Text(stringResource(R.string.bus_back_at, busDay(it) + " " + busTime(it)), color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp))
        }
        if (t.status == "scheduled" && leaves != null) {
            Spacer(Modifier.height(10.dp))
            Row(
                Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.18f)).padding(horizontal = 12.dp, vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Schedule, null, tint = Color.White, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(6.dp))
                Text(
                    if (leaves > now) stringResource(R.string.bus_leaves_in, span(leaves - now)) else stringResource(R.string.bus_time_to_go),
                    color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                )
            }
        }
    }
}

/** «Start» — locked, with the time it opens, until then (the phone's clock; the server checks it too). */
@Composable
private fun StartButton(text: String, opensAt: String?, now: Long, busy: Boolean, color: Color, onClick: () -> Unit) {
    val opens = millis(opensAt)
    val ready = opens == null || now >= opens
    PrimaryButton(text, enabled = ready, busy = busy, color = color, icon = if (ready) null else Icons.Rounded.Lock, onClick = onClick)
    if (!ready) {
        Text(
            stringResource(R.string.bus_start_opens_at, busTime(opensAt), span(opens!! - now)), color = Pal.mut, fontSize = 12.sp,
            modifier = Modifier.fillMaxWidth().padding(top = 2.dp), textAlign = androidx.compose.ui.text.style.TextAlign.Center,
        )
    }
}

@Composable
private fun Stat(icon: androidx.compose.ui.graphics.vector.ImageVector, value: Int, label: String, tint: Color, modifier: Modifier) {
    val shown by animateIntAsState(value, tween(600), label = "stat")
    Column(modifier.surface(RoundedCornerShape(18.dp)).padding(vertical = 12.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(34.dp).clip(CircleShape).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(18.dp))
        }
        Text("$shown", color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 20.sp, modifier = Modifier.padding(top = 4.dp))
        Text(label, color = Pal.mut, fontSize = 11.sp)
    }
}

@Composable
private fun LegTab(label: String, on: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val bg by animateColorAsState(if (on) Pal.accent else Color.Transparent, tween(220), label = "leg")
    Box(modifier.clip(CircleShape).background(bg).clickable(onClick = onClick).padding(vertical = 10.dp), contentAlignment = Alignment.Center) {
        Text(label, color = if (on) Color.White else Pal.mut, fontWeight = FontWeight.Bold, fontSize = 13.sp)
    }
}

/**
 * O-69: the bus from above — the windscreen and the wheel at the front, the door, the aisle; each seat a
 * seat (free / booked / on board with a tick). Seats arrive row by row, and a seat that changes pops.
 */
@Composable
internal fun BusDeckView(seats: Int, perRow: Int, taken: Set<Int>, boarded: Set<Int>) {
    val left = perRow / 2
    val rows = (seats + perRow - 1) / perRow
    val body = RoundedCornerShape(topStart = 46.dp, topEnd = 46.dp, bottomStart = 22.dp, bottomEnd = 22.dp)
    Column(
        Modifier.fillMaxWidth().padding(horizontal = 6.dp).clip(body)
            .background(Brush.verticalGradient(listOf(Pal.card, Pal.bg))).border(2.dp, Pal.line, body).padding(horizontal = 12.dp, vertical = 14.dp),
    ) {
        // The front: windscreen, the wheel on the captain's side, the door across.
        Row(Modifier.fillMaxWidth().padding(bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
            SteeringWheel(Modifier.size(34.dp))
            Spacer(Modifier.weight(1f))
            Box(
                Modifier.width(70.dp).height(8.dp).clip(CircleShape)
                    .background(Brush.horizontalGradient(listOf(Pal.info.copy(alpha = 0.25f), Pal.info.copy(alpha = 0.6f), Pal.info.copy(alpha = 0.25f)))),
            )
            Spacer(Modifier.weight(1f))
            Box(Modifier.width(30.dp).height(34.dp).clip(RoundedCornerShape(6.dp)).border(2.dp, Pal.line, RoundedCornerShape(6.dp)), contentAlignment = Alignment.Center) {
                Box(Modifier.width(2.dp).height(22.dp).background(Pal.line))
            }
        }
        Box(Modifier.fillMaxWidth().height(1.dp).background(Pal.line))
        Spacer(Modifier.height(8.dp))
        for (r in 0 until rows) {
            Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                for (c in 0 until perRow) {
                    if (c == left) {
                        // The aisle.
                        Box(Modifier.width(22.dp), contentAlignment = Alignment.Center) { Box(Modifier.width(2.dp).height(18.dp).background(Pal.line.copy(alpha = 0.6f))) }
                    }
                    val n = r * perRow + c + 1
                    Box(Modifier.weight(1f).padding(3.dp)) {
                        if (n <= seats) Seat(n, r, when (n) { in boarded -> SeatState.Boarded; in taken -> SeatState.Booked; else -> SeatState.Free })
                    }
                }
            }
        }
    }
}

private enum class SeatState { Free, Booked, Boarded }

@Composable
private fun Seat(n: Int, row: Int, state: SeatState) {
    // Row by row on first show.
    val appear = remember { Animatable(0f) }
    LaunchedEffect(Unit) { delay(row * 45L); appear.animateTo(1f, spring(dampingRatio = 0.6f, stiffness = Spring.StiffnessMediumLow)) }
    // A change (booked, on board) pops.
    var pop by remember { mutableStateOf(false) }
    LaunchedEffect(state) { pop = true; delay(160); pop = false }
    val scale by animateFloatAsState(if (pop) 1.15f else 1f, spring(dampingRatio = 0.4f), label = "seat-pop")
    val tint by animateColorAsState(
        when (state) { SeatState.Boarded -> Pal.success; SeatState.Booked -> Pal.accent; SeatState.Free -> Pal.faint.copy(alpha = 0.55f) }, tween(260), label = "seat-tint",
    )
    Box(
        Modifier.fillMaxWidth().aspectRatio(0.92f).graphicsLayer { alpha = appear.value; scaleX = appear.value * scale; scaleY = appear.value * scale }
            .clip(RoundedCornerShape(12.dp)).background(tint.copy(alpha = if (state == SeatState.Free) 0.08f else 0.14f)),
        contentAlignment = Alignment.Center,
    ) {
        Icon(Icons.Rounded.EventSeat, null, tint = tint, modifier = Modifier.fillMaxSize(0.62f).offset(y = (-4).dp))
        Text("$n", color = if (state == SeatState.Free) Pal.mut else tint, fontWeight = FontWeight.Black, fontSize = 10.sp, modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = 2.dp))
        AnimatedVisibility(state == SeatState.Boarded, Modifier.align(Alignment.TopEnd), enter = scaleIn(spring(dampingRatio = 0.4f)) + fadeIn()) {
            Box(Modifier.padding(3.dp).size(14.dp).clip(CircleShape).background(Pal.success), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(10.dp))
            }
        }
    }
}

@Composable
private fun SteeringWheel(modifier: Modifier) {
    val ink = Pal.mut
    Canvas(modifier) {
        val r = size.minDimension / 2f
        drawCircle(ink, r - 3f, style = Stroke(5f))
        drawCircle(ink, r * 0.22f)
        drawLine(ink, Offset(center.x - r + 4f, center.y), Offset(center.x + r - 4f, center.y), 4f)
        drawLine(ink, center, Offset(center.x, center.y + r - 4f), 4f)
    }
}

@Composable
private fun SeatKey() {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
        KeyItem(Pal.faint.copy(alpha = 0.55f), stringResource(R.string.bus_stat_free))
        KeyItem(Pal.accent, stringResource(R.string.bus_stat_booked))
        KeyItem(Pal.success, stringResource(R.string.bus_stat_boarded))
    }
}

@Composable
private fun KeyItem(tint: Color, label: String) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Rounded.EventSeat, null, tint = tint, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(4.dp))
        Text(label, color = Pal.mut, fontSize = 12.sp)
    }
}

/** A passenger: who, their seats (tap one to board it), and chat / call inside the app. */
@Composable
private fun PassengerCard(p: BusPassengerDto, index: Int, open: Boolean, onBoard: (String, Int) -> Unit, onChat: () -> Unit, onCall: () -> Unit) {
    val appear = remember { Animatable(0f) }
    LaunchedEffect(Unit) { delay(index * 60L); appear.animateTo(1f, tween(320)) }
    val allOn = p.seats.isNotEmpty() && p.seats.all { it.boarded }
    Column(
        Modifier.fillMaxWidth().graphicsLayer { alpha = appear.value; translationY = (1f - appear.value) * 40f }
            .surface(RoundedCornerShape(20.dp)).padding(14.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.size(46.dp).clip(CircleShape).background(Brush.linearGradient(listOf(Pal.accent, Pal.orange))),
                contentAlignment = Alignment.Center,
            ) {
                Text((p.name ?: "?").take(1), color = Color.White, fontWeight = FontWeight.Black, fontSize = 18.sp)
                if (allOn) Box(Modifier.align(Alignment.BottomEnd).size(16.dp).clip(CircleShape).background(Pal.success), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(11.dp))
                }
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(p.name ?: "—", fontWeight = FontWeight.Bold, color = Pal.ink, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                    RiderTick(p.isVerified, 14.dp)
                }
                Text(
                    stringResource(if (p.tripType == "round") R.string.bus_price_round else if (p.tripType == "return") R.string.bus_leg_back else R.string.bus_leg_out),
                    color = Pal.mut, fontSize = 12.sp,
                )
            }
            if (open) {
                ActionCircle(Icons.AutoMirrored.Rounded.Chat, Pal.accent, p.unread, onChat)
                Spacer(Modifier.width(8.dp))
                ActionCircle(Icons.Rounded.Call, Pal.success, 0, onCall)
            }
        }
        Spacer(Modifier.height(10.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            p.seats.forEach { s ->
                val bg by animateColorAsState(if (s.boarded) Pal.success else Pal.accent.copy(alpha = 0.1f), tween(250), label = "chip")
                Row(
                    Modifier.clip(CircleShape).background(bg).clickable(enabled = !s.boarded && open) { onBoard(s.leg, s.seat) }.padding(horizontal = 10.dp, vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(if (s.boarded) Icons.Rounded.Check else Icons.Rounded.EventSeat, null, tint = if (s.boarded) Color.White else Pal.accent, modifier = Modifier.size(14.dp))
                    Spacer(Modifier.width(4.dp))
                    Text(
                        (if (s.leg == "return") "↩ " else "") + s.seat, color = if (s.boarded) Color.White else Pal.accent, fontWeight = FontWeight.Bold, fontSize = 12.sp,
                    )
                }
            }
        }
        if (open && !allOn) Text(stringResource(R.string.bus_tap_to_board), color = Pal.faint, fontSize = 11.sp, modifier = Modifier.padding(top = 6.dp))
    }
}

@Composable
private fun ActionCircle(icon: androidx.compose.ui.graphics.vector.ImageVector, tint: Color, badge: Int, onClick: () -> Unit) {
    Box {
        Box(Modifier.size(42.dp).clip(CircleShape).background(tint.copy(alpha = 0.14f)).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp))
        }
        if (badge > 0) {
            val pulse by rememberInfiniteTransition(label = "badge").animateFloat(1f, 1.2f, infiniteRepeatable(tween(600), RepeatMode.Reverse), label = "badge-pulse")
            Box(Modifier.align(Alignment.TopEnd).scale(pulse).size(18.dp).clip(CircleShape).background(Pal.danger), contentAlignment = Alignment.Center) {
                Text("$badge", color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun RoundIcon(icon: androidx.compose.ui.graphics.vector.ImageVector, onClick: () -> Unit) {
    Box(Modifier.size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = Color.White)
    }
}

/** The chat with one passenger — the same conversation look as a ride's (O-61). */
@Composable
private fun BusPassengerChat(p: BusPassengerDto, open: Boolean, onClose: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var messages by remember { mutableStateOf<List<MessageDto>>(emptyList()) }
    var quick by remember { mutableStateOf<List<QuickDto>>(emptyList()) }
    var canWrite by remember { mutableStateOf(open) }
    suspend fun load() { (apiCall { DriverBackend.bus.messages(p.bookingUuid) } as? ApiResult.Ok)?.data?.let { messages = it.messages; quick = it.quickReplies; canWrite = it.open } }
    LaunchedEffect(p.bookingUuid) {
        load()
        com.dorr.driver.live.DriverLive.events.collect { e ->
            if (e.name == "bus.message") load()
        }
    }
    fun send(body: String?, key: String?) {
        scope.launch {
            val payload = JsonObject().apply { body?.let { addProperty("body", it) }; key?.let { addProperty("quick", it) }; addProperty("client_id", UUID.randomUUID().toString()) }
            (apiCall { DriverBackend.bus.send(p.bookingUuid, payload) } as? ApiResult.Ok)?.let { messages = messages + it.data }
        }
    }
    TripChatScreen(
        title = p.name.orEmpty(),
        titleVerified = p.isVerified,
        subtitle = stringResource(R.string.bus_chat_subtitle, p.seats.joinToString(" · ") { it.seat.toString() }),
        messages = messages, quick = quick, open = canWrite,
        onSend = { body, key -> send(body, key) },
        onCall = if (canWrite) ({
            RideCallSession.call(
                context, p.bookingUuid, p.name.orEmpty(),
                start = { callJoin(apiCall { DriverBackend.bus.startCall(p.bookingUuid) }) },
                end = { b, c -> apiCall { DriverBackend.bus.endCall(b, c) } },
                peerVerified = p.isVerified,
            )
        }) else null,
        onClose = onClose,
    )
}

@Composable
private fun busStatusText(status: String): String = stringResource(
    when (status) {
        "departed" -> R.string.bus_st_departed
        "returning" -> R.string.bus_st_returning
        "completed" -> R.string.bus_st_completed
        "canceled" -> R.string.bus_st_canceled
        else -> R.string.bus_st_scheduled
    },
)

private fun millis(iso: String?): Long? = iso?.let { runCatching { OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() }

/** 2d 3h · 3h 20m · 12:05 */
private fun span(ms: Long): String {
    val s = (ms / 1000).coerceAtLeast(0)
    val d = s / 86_400
    val h = s % 86_400 / 3600
    val m = s % 3600 / 60
    return when {
        d > 0 -> "${d}d ${h}h"
        h > 0 -> "${h}h ${m}m"
        else -> String.format("%d:%02d", m, s % 60)
    }
}
