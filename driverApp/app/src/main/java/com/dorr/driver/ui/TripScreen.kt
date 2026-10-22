package com.dorr.driver.ui

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Send
import androidx.compose.material.icons.filled.Call
import androidx.compose.material.icons.filled.Headset
import androidx.compose.material.icons.filled.Chat
import androidx.compose.material.icons.filled.Navigation
import androidx.compose.material.icons.filled.Share
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.StarBorder
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.BottomSheetScaffold
import androidx.compose.material3.rememberBottomSheetScaffoldState
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
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
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.CoreHttp
import com.dorr.core.network.apiCall
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.ui.kit.DorrField
import com.dorr.driver.R
import com.dorr.driver.duty.Duty
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.MessageDto
import com.dorr.driver.net.QuickDto
import com.dorr.driver.net.ReasonDto
import com.dorr.driver.net.TagDto
import com.dorr.driver.net.TripDto
import com.google.gson.Gson
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.Instant
import java.util.UUID

/** The status band at the top of the ride: rounded at the bottom, like the cards under it. */
private val BandShape = RoundedCornerShape(bottomStart = 28.dp, bottomEnd = 28.dp)

/**
 * The ride, one big action at a time: to the rider (navigation opens in Google Maps), "I've
 * arrived", the code, start, each stop, end — then the cash (with the change going to the rider's
 * wallet if they want) and the rider's rating.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TripScreen(onDone: () -> Unit, rideUuid: String? = null) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var trip by remember { mutableStateOf<TripDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var pinOpen by remember { mutableStateOf(false) }
    var chatOpen by remember { mutableStateOf(false) }
    var cancelOpen by remember { mutableStateOf(false) }
    var endConfirm by remember { mutableStateOf(false) }
    var sosOpen by remember { mutableStateOf(false) }
    var reportOpen by remember { mutableStateOf(false) }
    var sharing by remember { mutableStateOf(false) }

    suspend fun refresh() {
        // O-30: with riders sharing the car, the screen is about one of them (opened from the With Me list).
        if (rideUuid != null) {
            (apiCall { DriverBackend.api.ride(rideUuid) } as? ApiResult.Ok)?.let { trip = it.data }
            return
        }
        val result = apiCall { DriverBackend.api.currentRide() }
        if (result is ApiResult.Ok && result.data.isJsonObject && result.data.asJsonObject.has("uuid")) {
            trip = Gson().fromJson(result.data, TripDto::class.java)
        } else if (result is ApiResult.Ok) {
            Duty.setOnTrip(false)
            if (trip?.status != "completed") onDone()
        }
    }
    // The trip follows the server through real-time: one reload now, then whenever it changes for me.
    LaunchedEffect(Unit) {
        refresh()
        com.dorr.driver.live.DriverLive.events.collect { e ->
            // O-10: a message counts too — the chat button's badge follows it.
            // O-09: "ride.call" is the rider ringing (the push, when the app is open).
            if (e.name == com.dorr.driver.live.DriverLive.DRIVER_CHANGED || e.name == "ride.changed" || e.name == "ride.message" || e.name == "ride.call") refresh()
        }
    }
    // O-09: the rider's call rings here; a call gone from the ride is over on this phone too.
    val callContext = LocalContext.current
    LaunchedEffect(trip?.call?.uuid, trip?.call?.status) {
        val current = trip ?: return@LaunchedEffect
        val call = current.call
        when {
            call != null && call.status == "ringing" && call.caller == "user" ->
                RideCallSession.incoming(callContext, current.uuid, call.uuid, current.rider?.name.orEmpty(), end = { r, c -> com.dorr.core.network.apiCall { DriverBackend.api.endCall(r, c) } })
            call == null && RideCallSession.rideUuid == current.uuid && RideCallSession.phase != RideCallPhase.Calling -> RideCallSession.endedElsewhere(null)
        }
    }
    val micForCall = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.RequestPermission()) { ok ->
        if (ok) trip?.let { startRideCall(callContext, it) }
    }
    // The ride's own channel for the whole ride (its status, the chat's messages), not only while the chat is open.
    val watched = trip?.uuid
    androidx.compose.runtime.DisposableEffect(watched) {
        watched?.let { com.dorr.driver.live.DriverLive.watchRide(it) }
        onDispose { watched?.let { com.dorr.driver.live.DriverLive.unwatchRide(it) } }
    }
    BackHandler { onDone() }

    fun act(call: suspend (String) -> ApiResult<TripDto>) {
        busy = true
        error = null
        scope.launch {
            val here = lastPosition(context)
            if (here == null) { error = context.getString(R.string.home_permissions); busy = false; return@launch }
            when (val result = call(JsonObject().apply { addProperty("lat", here.first); addProperty("lng", here.second) }.toString())) {
                is ApiResult.Ok -> trip = result.data
                is ApiResult.Failed -> error = result.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }
    fun body(json: String) = Gson().fromJson(json, JsonObject::class.java)

    val t = trip ?: run {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { androidx.compose.material3.CircularProgressIndicator() }
        return
    }

    // Ending: the server refuses it away from the destination (O-39); the captain then confirms the rider is
    // getting off sooner, and the fare is worked out on the distance driven (O-40).
    var earlyEnd by remember { mutableStateOf<String?>(null) }
    fun endRide(early: Boolean) {
        busy = true
        error = null
        scope.launch {
            val here = lastPosition(context)
            if (here == null) { error = context.getString(R.string.home_permissions); busy = false; return@launch }
            val body = JsonObject().apply {
                addProperty("lat", here.first)
                addProperty("lng", here.second)
                if (early) addProperty("early", true)
            }
            when (val result = apiCall { DriverBackend.api.end(CoreHttp.newIdempotencyKey(), t.uuid, body) }) {
                is ApiResult.Ok -> trip = result.data
                is ApiResult.Failed ->
                    if (result.error.errorCode == "rides_not_at_destination") earlyEnd = result.error.displayMessage
                    else error = result.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    // O-34: the map is the whole screen — the route drawn on it — and the ride's details sit in a sheet
    // the captain pulls down to see the road, or up for everything. Driving here or in Google Maps: his choice.
    val statusBand: @Composable () -> Unit = {
        // Status band.
        val (title, band) = when (t.status) {
            "accepted" -> stringResource(R.string.trip_to_pickup) to DorrBrand.navy
            "arrived" -> stringResource(R.string.trip_at_pickup) to DorrBrand.warning
            "in_progress" -> stringResource(R.string.trip_on_ride) to DorrBrand.success
            else -> stringResource(R.string.trip_done) to DorrBrand.success
        }
        Column(Modifier.fillMaxWidth().shadow(10.dp, BandShape).clip(BandShape).background(Brush.verticalGradient(listOf(band, band.copy(alpha = 0.8f)))).statusBarsPadding().padding(20.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(title, color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
                    Text(t.number.orEmpty(), color = Color.White.copy(alpha = 0.75f), fontSize = 12.sp)
                }
                // Safety, always within reach (D-141, D-142).
                if (t.status != "completed") {
                    Box(
                        Modifier.size(44.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f))
                            .clickable(enabled = !sharing) { sharing = true; scope.launch { error = shareTrip(context, t.uuid); sharing = false } },
                        contentAlignment = Alignment.Center,
                    ) { Icon(Icons.Filled.Share, stringResource(R.string.trip_share), tint = Color.White) }
                    Spacer(Modifier.width(10.dp))
                    Box(
                        Modifier.size(width = 64.dp, height = 44.dp).clip(RoundedCornerShape(22.dp)).background(Color(0xFFA32D2D))
                            .border(2.dp, Color.White.copy(alpha = 0.7f), RoundedCornerShape(22.dp)).clickable { sosOpen = true },
                        contentAlignment = Alignment.Center,
                    ) { Text("SOS", color = Color.White, fontWeight = FontWeight.Black, fontSize = 15.sp) }
                }
            }
            if (t.status == "arrived") ArrivalClock(t)
        }
    }

    val details: @Composable () -> Unit = {
        Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(48.dp).clip(CircleShape).background(DorrBrand.orange.copy(alpha = 0.15f)), contentAlignment = Alignment.Center) {
                        Text(t.rider?.name?.take(1).orEmpty(), color = DorrBrand.orange, fontWeight = FontWeight.Bold, fontSize = 20.sp)
                    }
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(t.rider?.name.orEmpty(), fontWeight = FontWeight.Bold, fontSize = 17.sp)
                        Text(t.rider?.rating?.let { stringResource(R.string.trip_rider_rating, String.format("%.1f", it)) } ?: stringResource(R.string.offer_new_rider), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                    }
                    if (t.status != "completed") {
                        // O-09: a free call inside the app (LiveKit) — no numbers shown either way.
                        Box(
                            Modifier.size(46.dp).clip(CircleShape).background(DorrBrand.navy).clickable {
                                if (androidx.core.content.ContextCompat.checkSelfPermission(context, android.Manifest.permission.RECORD_AUDIO) == android.content.pm.PackageManager.PERMISSION_GRANTED) startRideCall(context, t)
                                else micForCall.launch(android.Manifest.permission.RECORD_AUDIO)
                            },
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Filled.Headset, stringResource(R.string.rc_in_app), tint = Color.White) }
                        Spacer(Modifier.width(8.dp))
                        // O-09: call the rider from outside the app (their number, while the ride is on).
                        t.callPhone?.let { phone ->
                            Box(
                                Modifier.size(46.dp).clip(CircleShape).background(DorrBrand.success)
                                    .clickable { runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))) } },
                                contentAlignment = Alignment.Center,
                            ) { Icon(Icons.Filled.Call, stringResource(R.string.trip_call), tint = Color.White) }
                            Spacer(Modifier.width(8.dp))
                        }
                        // O-10: the chat, with how many messages wait unread.
                        Box(Modifier.size(50.dp), contentAlignment = Alignment.Center) {
                            Box(Modifier.size(46.dp).clip(CircleShape).background(DorrBrand.navy).clickable { chatOpen = true }, contentAlignment = Alignment.Center) {
                                Icon(Icons.Filled.Chat, stringResource(R.string.trip_chat), tint = Color.White)
                            }
                            if (t.unreadMessages > 0) {
                                Box(
                                    Modifier.align(Alignment.TopEnd).size(20.dp).clip(CircleShape).background(DorrBrand.danger),
                                    contentAlignment = Alignment.Center,
                                ) { Text(if (t.unreadMessages > 9) "9+" else t.unreadMessages.toString(), color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold) }
                            }
                        }
                    }
                }
                Spacer(Modifier.height(12.dp))
                Text(stringResource(R.string.offer_pickup), fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.55f))
                Text(t.origin?.address ?: "—", fontWeight = FontWeight.SemiBold)
                Spacer(Modifier.height(6.dp))
                Text(stringResource(R.string.offer_dropoff), fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.55f))
                Text(t.destination?.address ?: stringResource(R.string.offer_open_destination), fontWeight = FontWeight.SemiBold)
                RiderDetails(t.passengers, t.pickupNote.takeIf { t.status == "accepted" || t.status == "arrived" }, t.beneficiary?.name, t.pickupPoint, t.dropoffPoint, t.fromCircle, t.options)
                // «راحتي»: the rider's requests, and "can't do this" (with why) — the rider is told.
                TripComfortCard(t) { trip = it }
                // Block 06: the next trip taken while driving this one / this trip waits for the one before it.
                NextTripCard(t)
                Spacer(Modifier.height(8.dp))
                Row {
                    Text(stringResource(R.string.trip_earned, money(t.earningMinor, t.currency)), color = DorrBrand.success, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    Text(if (t.paymentMethod == "cash") stringResource(R.string.offer_cash) else stringResource(R.string.offer_paid), fontSize = 13.sp)
                }
            }

            // D-129: the time and distance left to the next point.
            ProgressChip(t.progress)
            // Navigation outside the app (Google Maps), to the next point.
            t.navigation?.let { nav ->
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).border(1.dp, DorrBrand.navy.copy(alpha = 0.3f), RoundedCornerShape(16.dp))
                        .clickable {
                            // D-341: never stuck without a way there.
                            openNavigation(context, nav.lat, nav.lng, nav.googleMapsUrl)
                        }.padding(16.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Filled.Navigation, null, tint = DorrBrand.navy)
                    Spacer(Modifier.width(10.dp))
                    Text(stringResource(R.string.trip_navigate), fontWeight = FontWeight.Bold, color = DorrBrand.navy, fontSize = 16.sp)
                }
            }

            if (t.status == "in_progress") DriverSeatbeltBanner(t.uuid)
            // O-17: a ride by the hour — it ends wherever the rider gets off; minutes past the hours are added.
            t.hourly?.let { h ->
                val until = h.endsAt?.let { busTime(it) }
                com.dorr.driver.ui.kit.Banner(
                    if (until != null) stringResource(R.string.hr_trip_until, h.hours, until) else stringResource(R.string.hr_trip_hours, h.hours),
                    kind = com.dorr.driver.ui.kit.BannerKind.Success,
                )
            }
            if (t.status == "completed") {
                Completed(t, onNext = { Duty.setOnTrip(false); onDone() }, onUpdate = { trip = it })
                BlockRiderButton(t)
            }
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        }
    }

    val actions: @Composable () -> Unit = {
        // The one big action — first in the sheet, so it is there even pulled down.
        if (t.status != "completed") {
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                when (t.status) {
                    "accepted" -> BigButton(stringResource(R.string.trip_arrived), color = DorrBrand.navy, busy = busy) {
                        act { b -> apiCall { DriverBackend.api.arrive(CoreHttp.newIdempotencyKey(), t.uuid, body(b)) } }
                    }
                    "arrived" -> BigButton(stringResource(R.string.trip_start), color = DorrBrand.success, busy = busy) {
                        if (t.needsPin) pinOpen = true else act { b -> apiCall { DriverBackend.api.start(CoreHttp.newIdempotencyKey(), t.uuid, body(b)) } }
                    }
                    "in_progress" -> {
                        val stop = t.stops.firstOrNull { it.status != "done" }
                        if (stop != null) {
                            val arrivedAtStop = stop.status == "arrived"
                            BigButton(stringResource(if (arrivedAtStop) R.string.trip_stop_departed else R.string.trip_stop_arrived, stop.seq), color = DorrBrand.warning, busy = busy) {
                                busy = true
                                scope.launch {
                                    (apiCall { DriverBackend.api.stop(t.uuid, stop.seq, JsonObject().apply { addProperty("action", if (arrivedAtStop) "departed" else "arrived") }) } as? ApiResult.Ok)?.let { trip = it.data }
                                    busy = false
                                }
                            }
                        }
                        BigButton(stringResource(R.string.trip_end), color = DorrBrand.danger, busy = busy) { endConfirm = true }
                    }
                }
                if (t.status == "accepted") {
                    Text(stringResource(R.string.trip_cancel), color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth().clickable { cancelOpen = true }.padding(6.dp))
                }
                if (t.status == "arrived") NoShowButton(t) {
                    busy = true
                    scope.launch {
                        when (val r = apiCall { DriverBackend.api.noShow(CoreHttp.newIdempotencyKey(), t.uuid) }) {
                            is ApiResult.Ok -> { Duty.setOnTrip(false); onDone() }
                            is ApiResult.Failed -> error = r.error.displayMessage
                            else -> {}
                        }
                        busy = false
                    }
                }
            }
        }
    }

    val sheet = rememberBottomSheetScaffoldState()
    val peek = if (t.status == "completed") 560.dp else if (t.status == "in_progress" && t.stops.any { it.status != "done" }) 250.dp else 190.dp
    BottomSheetScaffold(
        scaffoldState = sheet,
        sheetPeekHeight = peek,
        sheetContainerColor = MaterialTheme.colorScheme.surface,
        sheetShadowElevation = 16.dp,
        sheetShape = RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp),
        sheetDragHandle = {
            Box(Modifier.padding(top = 10.dp, bottom = 6.dp).size(width = 40.dp, height = 4.dp).clip(CircleShape).background(MaterialTheme.colorScheme.onSurface.copy(alpha = 0.15f)))
        },
        containerColor = MaterialTheme.colorScheme.background,
        sheetContent = {
            Column(
                Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 18.dp).navigationBarsPadding(),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                actions()
                details()
            }
        },
    ) {
        Box(Modifier.fillMaxSize()) {
            TripMap(t, Modifier.fillMaxSize(), bottomPadding = peek)
            statusBand()
        }
    }

    if (pinOpen) PinDialog(onDismiss = { pinOpen = false }) { pin ->
        pinOpen = false
        act { b -> apiCall { DriverBackend.api.start(CoreHttp.newIdempotencyKey(), t.uuid, body(b).apply { addProperty("pin", pin) }) } }
    }
    if (endConfirm) AlertDialog(
        onDismissRequest = { endConfirm = false },
        title = { Text(stringResource(R.string.trip_end_confirm)) },
        confirmButton = { TextButton(onClick = { endConfirm = false; endRide(early = false) }) { Text(stringResource(R.string.trip_end)) } },
        dismissButton = { TextButton(onClick = { endConfirm = false }) { Text(stringResource(R.string.offer_decline)) } },
    )
    earlyEnd?.let { message ->
        AlertDialog(
            onDismissRequest = { earlyEnd = null },
            title = { Text(stringResource(R.string.trip_end_early_title)) },
            text = { Text(message) },
            confirmButton = {
                TextButton(onClick = { earlyEnd = null; endRide(early = true) }) {
                    Text(stringResource(R.string.trip_end_early_confirm), color = MaterialTheme.colorScheme.error, fontWeight = FontWeight.Bold)
                }
            },
            dismissButton = { TextButton(onClick = { earlyEnd = null }) { Text(stringResource(R.string.trip_end_early_keep)) } },
        )
    }
    if (chatOpen) TripChat(t, onClose = { chatOpen = false; scope.launch { refresh() } })
    if (sosOpen) SosSheet(t, onClose = { sosOpen = false }, onReport = { sosOpen = false; reportOpen = true })
    if (reportOpen) DriverReportSheet(t, onClose = { reportOpen = false })
    t.safetyCheck?.let { check -> DriverSafetyCheckDialog(t, check, onDone = { trip = t.copy(safetyCheck = null) }) }
    // The rider asks for a new destination / stop: answer within 30 s (wireflow 6ب).
    if (t.changeRequest?.status == "pending") ChangeRequestCard(t, onAnswered = { scope.launch { refresh() } })
    if (cancelOpen) CancelRide(t, onClose = { cancelOpen = false }, onReleased = { Duty.setOnTrip(false); onDone() })
    // O-09: the in-app call with the rider, over the trip.
    RideCallOverlay(onAccept = {
        val ride = RideCallSession.rideUuid
        val call = RideCallSession.callUuid
        if (ride != null && call != null) RideCallSession.accept(context) { callJoin(com.dorr.core.network.apiCall { DriverBackend.api.acceptCall(ride, call) }) }
    })
}

/** The free waiting at the pickup, counting down. */
@Composable
private fun ArrivalClock(t: TripDto) {
    var now by remember { mutableStateOf(Instant.now()) }
    LaunchedEffect(Unit) { while (true) { delay(1000); now = Instant.now() } }
    val from = waitingFrom(t) ?: return
    // A booked ride: the waiting starts at its time, however early the captain came (D-119).
    if (from.isAfter(now)) {
        Text(stringResource(R.string.trip_waiting_starts, timeOfDay(from)), color = Color.White, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 6.dp))
        return
    }
    val left = (t.waiting?.freeS ?: 180) - Duration.between(from, now).seconds.toInt()
    WaitClock(left, t.waiting?.warnBeforeS ?: 60)
}

@Composable
private fun NoShowButton(t: TripDto, onClick: () -> Unit) {
    var now by remember { mutableStateOf(Instant.now()) }
    LaunchedEffect(Unit) { while (true) { delay(1000); now = Instant.now() } }
    val from = waitingFrom(t) ?: return
    val left = (t.waiting?.noShowAfterS ?: 300) - Duration.between(from, now).seconds.toInt()
    Text(
        if (left > 0) stringResource(R.string.trip_no_show_in, clock(left)) else stringResource(R.string.trip_no_show),
        color = if (left > 0) MaterialTheme.colorScheme.onSurface.copy(alpha = 0.5f) else MaterialTheme.colorScheme.error,
        fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center,
        modifier = Modifier.fillMaxWidth().clickable(enabled = left <= 0, onClick = onClick).padding(6.dp),
    )
}

@Composable
private fun PinDialog(onDismiss: () -> Unit, onPin: (String) -> Unit) {
    var pin by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.trip_pin_title)) },
        text = {
            Column {
                Text(stringResource(R.string.trip_pin_hint), fontSize = 13.sp)
                Spacer(Modifier.height(10.dp))
                DorrField(pin, { pin = it.filter(Char::isDigit).take(4) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword))
            }
        },
        confirmButton = { TextButton(enabled = pin.length == 4, onClick = { onPin(pin) }) { Text(stringResource(R.string.trip_start)) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text(stringResource(R.string.offer_decline)) } },
    )
}

/** After the ride: the cash (and the change as wallet credit), then rating the rider. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun Completed(t: TripDto, onNext: () -> Unit, onUpdate: (TripDto) -> Unit) {
    val scope = rememberCoroutineScope()
    var received by remember { mutableStateOf(((t.cashToCollectMinor ?: 0) / 100.0).let { if (it % 1.0 == 0.0) it.toLong().toString() else it.toString() }) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var stars by remember { mutableIntStateOf(0) }
    var tags by remember { mutableStateOf<List<TagDto>>(emptyList()) }
    var chosen by remember { mutableStateOf(setOf<Int>()) }
    var rated by remember { mutableStateOf(t.rated == true) }
    LaunchedEffect(Unit) { (apiCall { DriverBackend.api.ratingTags() } as? ApiResult.Ok)?.let { tags = it.data } }

    Card {
        Text(stringResource(R.string.trip_done), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
        Text(stringResource(R.string.trip_total, money(t.finalTotalMinor, t.currency)), fontSize = 15.sp)
        Text(stringResource(R.string.trip_earned, money(t.earningMinor, t.currency)), color = DorrBrand.success, fontWeight = FontWeight.Bold)
        // D-337: paid by card / wallet but not confirmed yet, or failed.
        when (t.paymentStatus) {
            "pending", "awaiting_topup", "held" -> if (t.paymentMethod != "cash") Text(stringResource(R.string.ss_pay_pending), color = DorrBrand.orange, fontSize = 13.sp)
            "failed" -> Text(stringResource(R.string.ss_pay_failed), color = MaterialTheme.colorScheme.error, fontSize = 13.sp)
        }
        t.tipMinor?.let {
            Spacer(Modifier.height(10.dp))
            TipBanner(it, t.currency)
        }
    }
    val due = t.cashToCollectMinor
    if (due != null) {
        Card {
            Text(stringResource(R.string.trip_collect, money(due, t.currency)), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = DorrBrand.orange)
            Spacer(Modifier.height(8.dp))
            DorrField(received, { received = it.filter { c -> c.isDigit() || c == '.' } }, label = { Text(stringResource(R.string.trip_received)) }, singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
            val receivedMinor = ((received.toDoubleOrNull() ?: 0.0) * 100).toLong()
            if (receivedMinor > due) Text(stringResource(R.string.trip_change, money(receivedMinor - due, t.currency)), fontSize = 13.sp, color = DorrBrand.info, modifier = Modifier.padding(top = 4.dp))
            error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 13.sp) }
            Spacer(Modifier.height(10.dp))
            BigButton(stringResource(R.string.trip_collected), color = DorrBrand.success, busy = busy, enabled = receivedMinor >= due) {
                busy = true
                scope.launch {
                    when (val r = apiCall { DriverBackend.api.collectCash(t.uuid, JsonObject().apply { addProperty("received_minor", receivedMinor) }) }) {
                        is ApiResult.Ok -> onUpdate(r.data)
                        is ApiResult.Failed -> error = r.error.displayMessage
                        else -> {}
                    }
                    busy = false
                }
            }
        }
        return
    }
    if (!rated) {
        Card {
            Text(stringResource(R.string.trip_rate, t.rider?.name.orEmpty()), fontWeight = FontWeight.Bold, modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center)
            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), horizontalArrangement = Arrangement.Center) {
                (1..5).forEach { i -> Icon(if (i <= stars) Icons.Filled.Star else Icons.Filled.StarBorder, null, tint = if (i <= stars) DorrBrand.warning else Color.Gray, modifier = Modifier.size(40.dp).clickable { stars = i }) }
            }
            // O-41: a row of stars per criterion, starting at the overall stars.
            val scores = remember(t.uuid) { androidx.compose.runtime.mutableStateMapOf<String, Int>() }
            if (stars > 0 && t.ratingCriteria.isNotEmpty()) {
                Column(
                    Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(16.dp))
                        .background(MaterialTheme.colorScheme.onSurface.copy(alpha = 0.05f)).padding(horizontal = 12.dp, vertical = 6.dp),
                ) {
                    t.ratingCriteria.forEach { c ->
                        val value = scores[c.key] ?: stars
                        Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text(c.label ?: c.key, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                            (1..5).forEach { i ->
                                Icon(
                                    if (i <= value) Icons.Filled.Star else Icons.Filled.StarBorder, null,
                                    tint = if (i <= value) DorrBrand.warning else Color.Gray,
                                    modifier = Modifier.size(26.dp).clickable { scores[c.key] = i },
                                )
                            }
                        }
                    }
                }
            }
            if (stars > 0) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    tags.filter { if (stars >= 4) it.sentiment == "positive" else it.sentiment != "positive" }.forEach { tag ->
                        val on = tag.id in chosen
                        Text(tag.label.orEmpty(), fontSize = 12.sp, color = if (on) Color.White else MaterialTheme.colorScheme.onSurface,
                            modifier = Modifier.clip(CircleShape).background(if (on) DorrBrand.orange else MaterialTheme.colorScheme.onSurface.copy(alpha = 0.07f))
                                .clickable { chosen = if (on) chosen - tag.id else chosen + tag.id }.padding(horizontal = 12.dp, vertical = 6.dp))
                    }
                }
                Spacer(Modifier.height(10.dp))
                BigButton(stringResource(R.string.trip_rate_send), color = DorrBrand.navy, busy = busy) {
                    busy = true
                    scope.launch {
                        apiCall {
                            DriverBackend.api.rate(t.uuid, JsonObject().apply {
                                addProperty("stars", stars)
                                if (t.ratingCriteria.isNotEmpty()) add("scores", JsonObject().apply { t.ratingCriteria.forEach { c -> addProperty(c.key, scores[c.key] ?: stars) } })
                                add("tags", JsonArray().apply { chosen.forEach { add(it) } })
                            })
                        }
                        rated = true
                        busy = false
                    }
                }
            }
        }
    }
    BigButton(stringResource(R.string.trip_next), onClick = onNext)
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CancelRide(t: TripDto, onClose: () -> Unit, onReleased: () -> Unit) {
    val scope = rememberCoroutineScope()
    var reasons by remember { mutableStateOf<List<ReasonDto>>(emptyList()) }
    var reason by remember { mutableStateOf<Int?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { (apiCall { DriverBackend.api.cancelReasons() } as? ApiResult.Ok)?.let { reasons = it.data } }
    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.padding(20.dp).navigationBarsPadding()) {
            Text(stringResource(R.string.trip_cancel_reason), fontWeight = FontWeight.Bold, fontSize = 17.sp)
            reasons.forEach { r ->
                Row(Modifier.fillMaxWidth().clickable { reason = r.id }, verticalAlignment = Alignment.CenterVertically) {
                    RadioButton(selected = reason == r.id, onClick = { reason = r.id })
                    Text(r.label.orEmpty())
                }
            }
            error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            Spacer(Modifier.height(10.dp))
            BigButton(stringResource(R.string.trip_cancel_confirm), color = DorrBrand.danger, enabled = reason != null) {
                scope.launch {
                    when (val r = apiCall { DriverBackend.api.cancel(CoreHttp.newIdempotencyKey(), t.uuid, JsonObject().apply { addProperty("reason_id", reason) }) }) {
                        is ApiResult.Ok -> onReleased()
                        is ApiResult.Failed -> error = r.error.displayMessage
                        else -> {}
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TripChat(t: TripDto, onClose: () -> Unit) {
    val scope = rememberCoroutineScope()
    var messages by remember { mutableStateOf<List<MessageDto>>(emptyList()) }
    var quick by remember { mutableStateOf<List<QuickDto>>(emptyList()) }
    var open by remember { mutableStateOf(true) }
    var text by remember { mutableStateOf("") }
    // The chat: loaded once, then each message arrives on the ride's channel as it is sent.
    LaunchedEffect(t.uuid) {
        com.dorr.driver.live.DriverLive.watchRide(t.uuid)
        suspend fun load() { (apiCall { DriverBackend.api.messages(t.uuid) } as? ApiResult.Ok)?.data?.let { messages = it.messages; quick = it.quickReplies; open = it.open } }
        load()
        com.dorr.driver.live.DriverLive.events.collect { e ->
            if (e.name == "ride.message" || e.name == "ride.changed") load()
        }
    }
    // The trip screen keeps the ride's channel for the whole ride; the chat doesn't close it.
    fun send(body: String?, key: String?) {
        scope.launch {
            val payload = JsonObject().apply { body?.let { addProperty("body", it) }; key?.let { addProperty("quick", it) }; addProperty("client_id", UUID.randomUUID().toString()) }
            (apiCall { DriverBackend.api.send(t.uuid, payload) } as? ApiResult.Ok)?.let { messages = messages + it.data }
        }
    }
    // O-61: the conversation look of the DORR app's chat.
    val context = androidx.compose.ui.platform.LocalContext.current
    TripChatScreen(
        title = t.rider?.name.orEmpty(),
        subtitle = stringResource(R.string.trip_chat_subtitle, t.number.orEmpty()),
        messages = messages, quick = quick, open = open,
        onSend = { body, key -> send(body, key) },
        onCall = if (t.status in setOf("accepted", "arrived", "in_progress")) ({ startRideCall(context, t) }) else null,
        onClose = onClose,
    )
}
