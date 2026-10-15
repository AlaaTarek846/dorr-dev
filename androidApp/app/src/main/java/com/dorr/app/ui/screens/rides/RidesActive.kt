package com.dorr.app.ui.screens.rides

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.BorderStroke
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
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.rounded.LocalTaxi
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.StarBorder
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
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
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideDto
import com.dorr.app.network.RideLineDto
import com.dorr.app.network.RideMessageDto
import com.dorr.app.network.RideQuickReplyDto
import com.dorr.app.network.RideReasonDto
import com.dorr.app.network.RideTagDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.maps.android.compose.CameraPositionState
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.Instant
import java.util.UUID

private val FINAL = setOf("completed", "canceled", "no_show", "expired")

/** The ride after booking: searching, the captain coming, on the way, and the end (receipt + rating). */
@Composable
internal fun RideActivePanel(state: RidesState, camera: CameraPositionState, onExit: () -> Unit) {
    val ride = state.ride ?: return
    var chatOpen by remember { mutableStateOf(false) }
    var cancelOpen by remember { mutableStateOf(false) }
    var sosOpen by remember { mutableStateOf(false) }
    var shareOpen by remember { mutableStateOf(false) }
    var reportOpen by remember { mutableStateOf(false) }

    // The server is the truth, and it tells us when it changes (real-time on the ride's channel) —
    // nothing asks it on a timer. A reload on open and after a lost connection catches up on what was missed.
    LaunchedEffect(ride.uuid) {
        com.dorr.app.chat.ChatRealtime.watchRide(ride.uuid)
        suspend fun reload() {
            runCatching { ApiClient.rides.ride(ridesAuth(), ride.uuid).data }.getOrNull()?.let { fresh ->
                if (fresh.version != state.ride?.version) state.ride = fresh
            }
        }
        launch { com.dorr.app.chat.ChatRealtime.connected.collect { up -> if (up) reload() } }
        com.dorr.app.chat.ChatRealtime.events.collect { e ->
            when (e.name) {
                "ride.changed" -> reload()
                // The car moves on the map from the event itself, without a reload.
                "ride.driver_location" -> state.ride?.let { current ->
                    val d = current.driver ?: return@let
                    state.ride = current.copy(driver = d.copy(location = com.dorr.app.network.RideDriverLocationDto(
                        lat = e.data.get("lat").asDouble, lng = e.data.get("lng").asDouble,
                        heading = e.data.get("heading")?.takeIf { it.isJsonPrimitive }?.asInt, at = e.data.get("at")?.takeIf { it.isJsonPrimitive }?.asString,
                    )))
                }
            }
        }
    }
    androidx.compose.runtime.DisposableEffect(ride.uuid) { onDispose { com.dorr.app.chat.ChatRealtime.unwatchRide(ride.uuid) } }

    PanelCard {
        when (ride.status) {
            "awaiting_payment" -> AwaitingPayment(state)
            "scheduled" -> ScheduledPanel(ride, onDone = { state.ride = null; state.resetBooking() }, onCancel = { cancelOpen = true })
            "searching" -> Searching(state, ride, onCancel = { cancelOpen = true })
            "bidding" -> BiddingPanel(state, onCancel = { cancelOpen = true })
            "accepted", "arrived", "in_progress" -> OnTheWay(state, ride, onChat = { chatOpen = true }, onCancel = { cancelOpen = true }, onSos = { sosOpen = true }, onShare = { shareOpen = true })
            // The receipt, the rating and the tip: taller than the screen on small phones — it scrolls.
            "completed" -> Column(Modifier.heightIn(max = 620.dp).verticalScroll(rememberScrollState())) {
                Completed(ride, onTipped = { state.ride = it }, onDone = {
                    val fromHistory = state.fromHistory
                    state.ride = null
                    state.resetBooking()
                    if (!fromHistory) onExit()
                })
            }
            else -> Ended(ride, onAgain = { state.ride = null; state.resetBooking() })
        }
    }

    if (chatOpen) RideChatSheet(ride, onClose = { chatOpen = false })
    if (cancelOpen) CancelSheet(state, onClose = { cancelOpen = false })
    if (sosOpen) SosSheet(ride, onClose = { sosOpen = false }, onReport = { sosOpen = false; reportOpen = true })
    if (shareOpen) ShareRideSheet(ride, onClose = { shareOpen = false })
    if (reportOpen) ReportSheet(ride, onClose = { reportOpen = false })
}

@Composable
private fun AwaitingPayment(state: RidesState) {
    Text(stringResource(R.string.rd_awaiting_payment), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 17.sp)
    Spacer(Modifier.height(14.dp))
    AccentButton(stringResource(R.string.rd_pay_now)) { openRidePayment(state) }
}

/** A calm radar while the nearest captains are asked one by one. */
@Composable
private fun Searching(state: RidesState, ride: RideDto, onCancel: () -> Unit) {
    val pulse = rememberInfiniteTransition(label = "radar")
    val wave by pulse.animateFloat(0f, 1f, infiniteRepeatable(tween(1800, easing = LinearEasing), RepeatMode.Restart), label = "wave")
    val accent = settingsAccent()
    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(110.dp), contentAlignment = Alignment.Center) {
            listOf(0f, 0.33f, 0.66f).forEach { offset ->
                val p = (wave + offset) % 1f
                Box(Modifier.size(110.dp).scale(0.3f + p * 0.7f).clip(CircleShape).background(accent.copy(alpha = (1f - p) * 0.25f)))
            }
            Box(Modifier.size(46.dp).clip(CircleShape).background(accent), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.LocalTaxi, null, tint = Color.White)
            }
        }
        Text(stringResource(R.string.rd_searching), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
        Text(stringResource(R.string.rd_searching_sub), color = settingsMut(), fontSize = 13.sp)
        BeneficiaryChip(ride)
        PickupPointCard(ride)
        // R-61: still time to switch how to pay.
        PaymentSwitch(state, ride)
        Spacer(Modifier.height(16.dp))
        Text(stringResource(R.string.rd_cancel), color = AppColors.danger, fontWeight = FontWeight.Bold, modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable(onClick = onCancel).padding(10.dp))
    }
}

@Composable
private fun OnTheWay(state: RidesState, ride: RideDto, onChat: () -> Unit, onCancel: () -> Unit, onSos: () -> Unit, onShare: () -> Unit) {
    val driver = ride.driver
    val context = androidx.compose.ui.platform.LocalContext.current
    val scope = rememberCoroutineScope()
    var sharing by remember { mutableStateOf(false) }
    var shareError by remember { mutableStateOf<String?>(null) }
    val minute = stringResource(R.string.rd_minutes)
    val hour = stringResource(R.string.rd_hours)
    Text(
        when (ride.status) {
            "arrived" -> stringResource(R.string.rd_status_arrived)
            "in_progress" -> stringResource(R.string.rd_status_in_progress)
            else -> stringResource(R.string.rd_status_accepted)
        },
        color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp,
    )
    if (ride.status == "arrived") ArrivedWaiting(ride, minute, hour)
    if (ride.status == "in_progress") ride.destination?.address?.let { Text(it, color = settingsMut(), fontSize = 13.sp, maxLines = 1) }
    // D-129: the time and distance left — to the pickup, then to the next stop / the destination.
    EtaChip(ride)
    // R-13: booked for someone else.
    BeneficiaryChip(ride)
    // R-16..R-18: the approved point to meet at, and how to find it.
    PickupPointCard(ride)
    // A new destination / one more stop: waiting for the captain, or the answer (wireflow 6ج).
    ChangeBanner(ride)
    Spacer(Modifier.height(12.dp))

    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(54.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            if (driver?.avatarUrl != null) AsyncImage(ApiClient.mediaUrl(driver.avatarUrl), null, Modifier.size(54.dp).clip(CircleShape))
            else Text(driver?.name?.take(1).orEmpty(), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 20.sp)
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(driver?.name.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 16.sp)
                Spacer(Modifier.width(6.dp))
                TierBadge(driver)
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Star, null, tint = AppColors.warning, modifier = Modifier.size(15.dp))
                Text(driver?.rating?.let { String.format("%.1f", it) } ?: stringResource(R.string.rd_new_rating), color = settingsInk(), fontSize = 13.sp)
                if ((driver?.tripsCount ?: 0) > 0) Text(" · " + stringResource(R.string.rd_trips, driver!!.tripsCount), color = settingsMut(), fontSize = 12.sp)
            }
            driver?.vehicle?.let { v -> Text(listOfNotNull(v.make, v.model, v.color).joinToString(" "), color = settingsMut(), fontSize = 12.sp) }
        }
        driver?.vehicle?.let { v -> Plate(v.plateLetters, v.plateNumber, v.colorHex) }
    }
    // Before getting in: check the car (R-224); once on the way: the seatbelt (R-230).
    if (ride.status == "accepted" || ride.status == "arrived") VehicleCheckCard(ride, onMismatch = { scope.launch { reportVehicleMismatch(state) } })
    if (ride.status == "in_progress") SeatbeltBanner(ride.uuid)

    ride.pin?.let { pin ->
        Spacer(Modifier.height(12.dp))
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.08f)).padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.rd_pin), color = settingsInk(), fontWeight = FontWeight.Bold)
                Text(stringResource(R.string.rd_pin_hint), color = settingsMut(), fontSize = 12.sp)
            }
            pin.forEach { digit ->
                Box(Modifier.padding(start = 4.dp).size(width = 30.dp, height = 38.dp).clip(RoundedCornerShape(8.dp)).background(settingsBackground()), contentAlignment = Alignment.Center) {
                    Text(digit.toString(), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                }
            }
        }
    }

    ChangeTripLinks(state, ride)
    if (ride.status == "accepted" || ride.status == "arrived") PaymentSwitch(state, ride)

    // The wireflow's row: 💬 Message · 📤 Share · 🚨 SOS.
    Spacer(Modifier.height(14.dp))
    SafetyActions(
        onChat = onChat,
        onShare = onShare,
        onSos = onSos,
        sharing = sharing,
    )
    shareError?.let { Text(it, color = AppColors.danger, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp)) }
    if (ride.status != "in_progress") {
        Text(
            stringResource(R.string.rd_cancel), color = AppColors.danger, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
            modifier = Modifier.fillMaxWidth().padding(top = 8.dp).clip(RoundedCornerShape(10.dp)).clickable(onClick = onCancel).padding(10.dp),
        )
    }
}

/** The free waiting left at the pickup, counting down (then the minutes are charged). */
@Composable
private fun ArrivedWaiting(ride: RideDto, minute: String, hour: String) {
    var now by remember { mutableStateOf(Instant.now()) }
    LaunchedEffect(Unit) { while (true) { delay(1000); now = Instant.now() } }
    // A booked ride: the waiting starts at its time, however early the captain came (R-108).
    val from = parseTime(ride.times?.waitingFrom ?: ride.times?.arrivedAt)?.toInstant() ?: return
    if (from.isAfter(now)) {
        Text(stringResource(R.string.rd_waiting_starts, clock(from.atZone(java.time.ZoneId.systemDefault()))), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
        return
    }
    val left = (ride.waiting?.freeS ?: 180) - Duration.between(from, now).seconds.toInt()
    // D-122: green, then amber and pulsing near the end, then the paid minutes.
    WaitingCountdown(left, ride.waiting?.warnBeforeS ?: 60)
}

/** A Saudi-style plate: letters | numbers, with the car's colour as a strip. */
@Composable
private fun Plate(letters: String?, numbers: String?, colorHex: String?) {
    val strip = runCatching { Color(android.graphics.Color.parseColor(colorHex ?: "#CCCCCC")) }.getOrDefault(Color.LightGray)
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Row(Modifier.clip(RoundedCornerShape(6.dp)).border(2.dp, Color(0xFF1F2937), RoundedCornerShape(6.dp)).background(Color.White)) {
            Text(letters.orEmpty(), color = Color(0xFF111827), fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 6.dp, vertical = 3.dp))
            Box(Modifier.width(2.dp).height(24.dp).background(Color(0xFF1F2937)))
            Text(numbers.orEmpty(), color = Color(0xFF111827), fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 6.dp, vertical = 3.dp))
        }
        Box(Modifier.padding(top = 4.dp).size(width = 40.dp, height = 4.dp).clip(CircleShape).background(strip))
    }
}

/** The receipt and the rating, right when the ride ends. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun Completed(ride: RideDto, onTipped: (RideDto) -> Unit, onDone: () -> Unit) {
    val scope = rememberCoroutineScope()
    var stars by remember { mutableIntStateOf(0) }
    var tags by remember { mutableStateOf<List<RideTagDto>>(emptyList()) }
    val chosen = remember { mutableStateOf(setOf<Int>()) }
    var comment by remember { mutableStateOf("") }
    var sent by remember { mutableStateOf(ride.rated == true) }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { tags = runCatching { ApiClient.rides.ratingTags(ridesAuth()).data.orEmpty() }.getOrDefault(emptyList()) }

    Text(stringResource(R.string.rd_status_completed), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
    Spacer(Modifier.height(8.dp))
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsBackground()).padding(12.dp)) {
        ride.lines.filter { it.code != "total" }.forEach { PriceLineRow(it, ride.currency) }
        Row(Modifier.fillMaxWidth().padding(top = 6.dp)) {
            Text(stringResource(R.string.rd_total), color = settingsInk(), fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            Text(money(ride.finalTotalMinor, ride.currency), color = settingsInk(), fontWeight = FontWeight.ExtraBold)
        }
        // R-144: the tip stays on its own line, after the ride's total.
        ride.tip?.amountMinor?.let { PriceLineRow(RideLineDto("tip", stringResource(R.string.rd_tip_line), it), ride.currency) }
        if (ride.paymentStatus == "cash_due") {
            Text(stringResource(R.string.rd_cash_due, money(ride.finalTotalMinor, ride.currency)), color = AppColors.warning, fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp))
        }
    }
    Spacer(Modifier.height(14.dp))
    if (sent) {
        Text(stringResource(R.string.rd_rate_thanks), color = AppColors.success, fontWeight = FontWeight.Bold, modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center)
        TipSection(ride, onTipped)
        Spacer(Modifier.height(12.dp))
        AccentButton(stringResource(R.string.rd_done), onClick = onDone)
        return
    }
    Text(stringResource(R.string.rd_rate_title, ride.driver?.name.orEmpty()), color = settingsInk(), fontWeight = FontWeight.Bold, modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center)
    // ⭐ Keep this captain / 🚫 never again (R-225, R-226).
    AfterRideChoices(ride)
    Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), horizontalArrangement = Arrangement.Center) {
        (1..5).forEach { i ->
            Icon(
                if (i <= stars) Icons.Rounded.Star else Icons.Rounded.StarBorder, null,
                tint = if (i <= stars) AppColors.warning else settingsMut(),
                modifier = Modifier.size(40.dp).clip(CircleShape).clickable { stars = i },
            )
        }
    }
    if (stars > 0) {
        val visible = tags.filter { if (stars >= 4) it.sentiment == "positive" else it.sentiment != "positive" }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            visible.forEach { tag ->
                val on = tag.id in chosen.value
                Text(
                    tag.label.orEmpty(), fontSize = 12.sp, color = if (on) Color.White else settingsInk(),
                    modifier = Modifier.clip(CircleShape).background(if (on) settingsAccent() else settingsBackground())
                        .clickable { chosen.value = if (on) chosen.value - tag.id else chosen.value + tag.id }.padding(horizontal = 12.dp, vertical = 7.dp),
                )
            }
        }
        Spacer(Modifier.height(8.dp))
        Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsBackground()).padding(12.dp)) {
            if (comment.isEmpty()) Text(stringResource(R.string.rd_rate_comment), color = settingsMut(), fontSize = 13.sp)
            BasicTextField(comment, { comment = it.take(500) }, textStyle = TextStyle(color = settingsInk(), fontSize = 14.sp), cursorBrush = SolidColor(settingsAccent()), modifier = Modifier.fillMaxWidth())
        }
        // Wireflow step 7: "Tip (optional)" under the note, before sending the rating.
        TipSection(ride, onTipped)
        Spacer(Modifier.height(12.dp))
        AccentButton(stringResource(R.string.rd_rate_send), busy = busy) {
            busy = true
            scope.launch {
                val body = JsonObject().apply {
                    addProperty("stars", stars)
                    add("tags", JsonArray().apply { chosen.value.forEach { add(it) } })
                    if (comment.isNotBlank()) addProperty("comment", comment.trim())
                }
                if (runCatching { ApiClient.rides.rate(ridesAuth(), ride.uuid, body) }.isSuccess) sent = true
                busy = false
            }
        }
    } else {
        Text(stringResource(R.string.rd_done), color = settingsMut(), modifier = Modifier.fillMaxWidth().clickable(onClick = onDone).padding(8.dp), textAlign = TextAlign.Center)
    }
}

@Composable
private fun Ended(ride: RideDto, onAgain: () -> Unit) {
    Text(
        when (ride.status) {
            "expired" -> stringResource(R.string.rd_status_expired)
            "no_show" -> stringResource(R.string.rd_status_no_show)
            else -> stringResource(R.string.rd_status_canceled)
        },
        color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp,
    )
    Spacer(Modifier.height(14.dp))
    AccentButton(stringResource(R.string.rd_book_again), onClick = onAgain)
}

/** Cancelling: the fee (if any) is shown before, with the reasons to pick from. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CancelSheet(state: RidesState, onClose: () -> Unit) {
    val ride = state.ride ?: return
    val scope = rememberCoroutineScope()
    var fee by remember { mutableStateOf<Long?>(null) }
    var reasons by remember { mutableStateOf<List<RideReasonDto>>(emptyList()) }
    var reason by remember { mutableStateOf<Int?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) {
        fee = runCatching { ApiClient.rides.cancelPreview(ridesAuth(), ride.uuid).data?.feeMinor }.getOrNull() ?: 0
        reasons = runCatching { ApiClient.rides.cancelReasons(ridesAuth()).data.orEmpty() }.getOrDefault(emptyList())
    }
    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.padding(horizontal = 20.dp).padding(bottom = 28.dp)) {
            Text(stringResource(R.string.rd_cancel), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            fee?.let {
                Text(
                    if (it > 0) stringResource(R.string.rd_cancel_fee, money(it, ride.currency)) else stringResource(R.string.rd_cancel_free),
                    color = if (it > 0) AppColors.danger else AppColors.success, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(vertical = 6.dp),
                )
            }
            if (reasons.isNotEmpty()) {
                Text(stringResource(R.string.rd_cancel_reason), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp, bottom = 4.dp))
                reasons.forEach { r ->
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { reason = r.id }.padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(18.dp).clip(CircleShape).border(2.dp, if (reason == r.id) settingsAccent() else settingsMut(), CircleShape).padding(4.dp)) {
                            if (reason == r.id) Box(Modifier.size(10.dp).clip(CircleShape).background(settingsAccent()))
                        }
                        Spacer(Modifier.width(10.dp))
                        Text(r.label.orEmpty(), color = settingsInk())
                    }
                }
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp) }
            Spacer(Modifier.height(12.dp))
            AccentButton(stringResource(R.string.rd_cancel_confirm), busy = busy, color = AppColors.danger) {
                busy = true
                scope.launch {
                    val body = JsonObject().apply { reason?.let { addProperty("reason_id", it) } }
                    runCatching { ApiClient.rides.cancel(ridesAuth(), UUID.randomUUID().toString(), ride.uuid, body).data }
                        .onSuccess { state.ride = it; onClose() }
                        .onFailure { error = it.apiFailure().message }
                    busy = false
                }
            }
            Text(stringResource(R.string.rd_keep), color = settingsInk(), fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().clickable(onClick = onClose).padding(12.dp))
        }
    }
}

/** The chat with the captain: ready-made messages and text, open while the captain is on the ride. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun RideChatSheet(ride: RideDto, onClose: () -> Unit) {
    val scope = rememberCoroutineScope()
    var messages by remember { mutableStateOf<List<RideMessageDto>>(emptyList()) }
    var quick by remember { mutableStateOf<List<RideQuickReplyDto>>(emptyList()) }
    var open by remember { mutableStateOf(true) }
    var text by remember { mutableStateOf("") }
    val list = rememberLazyListState()

    suspend fun refresh() {
        runCatching { ApiClient.rides.messages(ridesAuth(), ride.uuid).data }.getOrNull()?.let {
            messages = it.messages
            quick = it.quickReplies
            open = it.open
        }
    }
    // Loaded once, then each message arrives on the ride's channel as it is sent.
    LaunchedEffect(ride.uuid) {
        refresh()
        com.dorr.app.chat.ChatRealtime.events.collect { e -> if (e.name == "ride.message" || e.name == "ride.changed") refresh() }
    }
    LaunchedEffect(messages.size) { if (messages.isNotEmpty()) list.animateScrollToItem(messages.size - 1) }

    fun send(body: String?, quickKey: String?) {
        scope.launch {
            val payload = JsonObject().apply {
                body?.let { addProperty("body", it) }
                quickKey?.let { addProperty("quick", it) }
                addProperty("client_id", UUID.randomUUID().toString())
            }
            runCatching { ApiClient.rides.send(ridesAuth(), ride.uuid, payload).data }.getOrNull()?.let { messages = messages + it }
        }
    }

    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.padding(horizontal = 16.dp).padding(bottom = 16.dp).imePadding()) {
            Text(stringResource(R.string.rd_chat_title, ride.driver?.name.orEmpty()), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
            Spacer(Modifier.height(8.dp))
            LazyColumn(Modifier.fillMaxWidth().heightIn(min = 160.dp, max = 380.dp), state = list, verticalArrangement = Arrangement.spacedBy(6.dp)) {
                if (messages.isEmpty()) item { Text(stringResource(R.string.rd_chat_empty), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(vertical = 20.dp)) }
                items(messages, key = { it.id }) { m ->
                    val mine = m.mine ?: (m.from == "user")
                    Box(Modifier.fillMaxWidth(), contentAlignment = if (mine) Alignment.CenterEnd else Alignment.CenterStart) {
                        Text(
                            m.body, color = if (mine) Color.White else settingsInk(), fontSize = 14.sp,
                            modifier = Modifier.clip(RoundedCornerShape(16.dp)).background(if (mine) settingsAccent() else settingsBackground()).padding(horizontal = 12.dp, vertical = 8.dp),
                        )
                    }
                }
            }
            if (!open) {
                Text(stringResource(R.string.rd_chat_closed), color = settingsMut(), fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(12.dp))
                return@Column
            }
            LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.padding(vertical = 8.dp)) {
                items(quick) { q ->
                    Text(
                        q.text, fontSize = 12.sp, color = settingsInk(),
                        modifier = Modifier.clip(CircleShape).border(1.dp, settingsInk().copy(alpha = 0.12f), CircleShape).clickable { send(null, q.key) }.padding(horizontal = 12.dp, vertical = 7.dp),
                    )
                }
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.weight(1f).clip(RoundedCornerShape(22.dp)).background(settingsBackground()).padding(horizontal = 14.dp, vertical = 12.dp)) {
                    if (text.isEmpty()) Text(stringResource(R.string.rd_chat_hint), color = settingsMut())
                    BasicTextField(text, { text = it.take(1000) }, textStyle = TextStyle(color = settingsInk(), fontSize = 15.sp), cursorBrush = SolidColor(settingsAccent()), modifier = Modifier.fillMaxWidth())
                }
                Spacer(Modifier.width(8.dp))
                Box(
                    Modifier.size(46.dp).clip(CircleShape).background(if (text.isBlank()) settingsMut() else settingsAccent())
                        .clickable(enabled = text.isNotBlank()) { send(text.trim(), null); text = "" },
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.AutoMirrored.Rounded.Send, null, tint = Color.White) }
            }
        }
    }
}
