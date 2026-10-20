package com.dorr.driver.ui

import android.app.TimePickerDialog
import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.foundation.background
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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.DirectionsBoat
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
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
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.DriverMarineListingDto
import com.dorr.driver.net.MarineBoatInfoDto
import com.dorr.driver.net.MarineGuestBookingDto
import com.dorr.driver.net.PlaceDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.BannerKind
import com.dorr.driver.ui.kit.Chip
import com.dorr.driver.ui.kit.DorrDatePickerDialog
import com.dorr.driver.ui.kit.DorrField
import com.dorr.driver.ui.kit.ListRow
import com.dorr.driver.ui.kit.OutlineButton
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.ScreenHeader
import com.dorr.driver.ui.kit.TileIcon
import com.dorr.driver.ui.kit.staggerIn
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.LocalTime
import java.time.ZoneId
import java.time.ZonedDateTime

/*
 * The captain's boat (docs/rides_tasks.md O-25; D-80, D-100..D-102): a sea trip (the marina, where it goes,
 * when it leaves and how long, a price per guest and / or for the whole boat) or the yacht for hire by the
 * hour; on each: who booked (call them), boarding by their QR, leaving and ending (then paid), and a guest's
 * «more time» to accept or decline. Bookings and requests arrive as notifications (`marine.*`) — the open
 * page reloads once on them; nothing polls.
 */

private val Sea = Color(0xFF0288D1)

@Composable
fun MarineListingsScreen(onBack: () -> Unit, onOpen: (String) -> Unit) {
    var boat by remember { mutableStateOf<MarineBoatInfoDto?>(null) }
    var list by remember { mutableStateOf<List<DriverMarineListingDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var formOpen by remember { mutableStateOf(false) }
    var tick by remember { mutableStateOf(0) }

    LaunchedEffect(tick) {
        when (val r = apiCall { DriverBackend.marine.boat() }) {
            is ApiResult.Ok -> boat = r.data
            is ApiResult.Failed -> error = r.error.displayMessage
            else -> {}
        }
        (apiCall { DriverBackend.marine.listings() } as? ApiResult.Ok)?.let { list = it.data }
    }
    LaunchedEffect(Unit) { com.dorr.driver.live.DriverLive.events.collect { e -> if (e.name.startsWith("marine.")) tick++ } }

    Column(Modifier.fillMaxSize().background(Pal.bg)) {
        ScreenHeader(stringResource(R.string.mr_title), onBack = onBack, modifier = Modifier.statusBarsPadding())
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Banner(error)
            boat?.let { b ->
                Row(Modifier.fillMaxWidth().surface().padding(14.dp).staggerIn(0), verticalAlignment = Alignment.CenterVertically) {
                    TileIcon(Icons.Rounded.DirectionsBoat, Sea, 46.dp)
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(b.name?.takeIf { it.isNotBlank() } ?: stringResource(if (b.type == "yacht") R.string.mr_yacht else R.string.mr_boat), fontWeight = FontWeight.Bold, color = Pal.ink)
                        Text(stringResource(R.string.mr_up_to, b.seats), color = Pal.mut, fontSize = 12.sp)
                    }
                }
                PrimaryButton(stringResource(R.string.mr_new), icon = Icons.Rounded.Add, color = Sea) { formOpen = true }
            }
            val rows = list
            when {
                rows == null -> {}
                rows.isEmpty() -> Text(stringResource(R.string.mr_none), color = Pal.mut, modifier = Modifier.padding(20.dp))
                else -> rows.forEachIndexed { i, l ->
                    Column(Modifier.fillMaxWidth().surface().clickable { onOpen(l.uuid) }.padding(14.dp).staggerIn(i + 1)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(l.title, fontWeight = FontWeight.Black, color = Pal.ink, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                            MarineStatus(l.status)
                        }
                        Text(
                            if (l.kind == "trip") stringResource(R.string.mr_kind_trip) + " · " + busDay(l.departAt) + " " + busTime(l.departAt)
                            else stringResource(R.string.mr_kind_rental) + " · " + money(l.prices.hourMinor, l.currency) + " / " + stringResource(R.string.mr_hour),
                            color = Pal.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp),
                        )
                        Text(l.marina.address.orEmpty(), color = Pal.ink, fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 2.dp))
                    }
                }
            }
            Spacer(Modifier.navigationBarsPadding())
        }
    }
    if (formOpen) boat?.let { MarineForm(it, onClose = { formOpen = false }, onDone = { formOpen = false; tick++ }) }
}

@Composable
private fun MarineStatus(status: String) {
    val (label, color) = when (status) {
        "paused" -> stringResource(R.string.mr_st_paused) to Pal.warning
        "departed" -> stringResource(R.string.mr_st_departed) to Pal.success
        "completed" -> stringResource(R.string.mr_st_completed) to Pal.mut
        "canceled" -> stringResource(R.string.mr_st_canceled) to Pal.danger
        else -> stringResource(R.string.mr_st_active) to Sea
    }
    Text(label, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp))
}

// ---------------------------------------------------------------- publish

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun MarineForm(boat: MarineBoatInfoDto, onClose: () -> Unit, onDone: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var kind by remember { mutableStateOf("trip") }
    var title by remember { mutableStateOf("") }
    var description by remember { mutableStateOf("") }
    var marina by remember { mutableStateOf<PlaceDto?>(null) }
    var picking by remember { mutableStateOf(false) }
    var routeNote by remember { mutableStateOf("") }
    var amenities by remember { mutableStateOf(setOf<String>()) }
    var capacity by remember { mutableStateOf(boat.seats.toString()) }
    var day by remember { mutableStateOf<LocalDate?>(null) }
    var time by remember { mutableStateOf<LocalTime?>(null) }
    var dateOpen by remember { mutableStateOf(false) }
    var duration by remember { mutableStateOf("120") }
    var seatPrice by remember { mutableStateOf("") }
    var privatePrice by remember { mutableStateOf("") }
    var hourPrice by remember { mutableStateOf("") }
    var minHours by remember { mutableStateOf("1") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun minor(text: String): Long? = text.replace(',', '.').toBigDecimalOrNull()?.movePointRight(2)?.toLong()?.takeIf { it > 0 }
    val ready = title.isNotBlank() && marina != null && if (kind == "trip") {
        day != null && time != null && duration.toIntOrNull() != null && (minor(seatPrice) != null || minor(privatePrice) != null)
    } else minor(hourPrice) != null

    BackHandler(onBack = onClose)
    Column(Modifier.fillMaxSize().background(Pal.bg)) {
        ScreenHeader(stringResource(R.string.mr_new), onBack = onClose, modifier = Modifier.statusBarsPadding())
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Chip(stringResource(R.string.mr_kind_trip), selected = kind == "trip", tint = Sea) { kind = "trip" }
                Chip(stringResource(R.string.mr_kind_rental), selected = kind == "rental", tint = Sea) { kind = "rental" }
            }
            Text(stringResource(if (kind == "trip") R.string.mr_kind_trip_hint else R.string.mr_kind_rental_hint), color = Pal.mut, fontSize = 12.sp)
            DorrField(title, { title = it.take(120) }, label = { Text(stringResource(R.string.mr_name)) }, singleLine = true)
            Column(Modifier.fillMaxWidth().surface()) {
                ListRow(Icons.Rounded.Place, marina?.address ?: stringResource(R.string.mr_pick_marina), subtitle = stringResource(R.string.mr_marina), tint = Sea) { picking = true }
            }
            DorrField(routeNote, { routeNote = it.take(255) }, label = { Text(stringResource(R.string.mr_route)) }, singleLine = true)
            if (kind == "trip") {
                Text(stringResource(R.string.mr_departure), fontWeight = FontWeight.Bold, color = Pal.ink)
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Box(Modifier.weight(1f)) { OutlineButton(day?.toString() ?: stringResource(R.string.bus_day)) { dateOpen = true } }
                    Box(Modifier.weight(1f)) {
                        OutlineButton(time?.toString() ?: stringResource(R.string.bus_time)) {
                            val now = LocalTime.now()
                            TimePickerDialog(context, { _, h, m -> time = LocalTime.of(h, m) }, now.hour, now.minute, false).show()
                        }
                    }
                }
                DorrField(duration, { duration = it.filter(Char::isDigit).take(4) }, label = { Text(stringResource(R.string.mr_duration)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
                DorrField(seatPrice, { seatPrice = it }, label = { Text(stringResource(R.string.mr_seat_price)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
                DorrField(privatePrice, { privatePrice = it }, label = { Text(stringResource(R.string.mr_private_price)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
            } else {
                DorrField(hourPrice, { hourPrice = it }, label = { Text(stringResource(R.string.mr_hour_price)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
                DorrField(minHours, { minHours = it.filter(Char::isDigit).take(2) }, label = { Text(stringResource(R.string.mr_min_hours)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
            }
            DorrField(capacity, { capacity = it.filter(Char::isDigit).take(3) }, label = { Text(stringResource(R.string.mr_capacity)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
            Text(stringResource(R.string.mr_amenities), fontWeight = FontWeight.Bold, color = Pal.ink)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                boat.amenities.forEach { a ->
                    Chip(amenityText(a), selected = a in amenities, tint = Sea) { amenities = if (a in amenities) amenities - a else amenities + a }
                }
            }
            DorrField(description, { description = it.take(2000) }, label = { Text(stringResource(R.string.mr_description)) }, minLines = 3)
            Banner(error)
            PrimaryButton(stringResource(R.string.mr_publish), busy = busy, enabled = ready, color = Sea) {
                busy = true
                error = null
                scope.launch {
                    val body = JsonObject().apply {
                        addProperty("kind", kind)
                        addProperty("title", title.trim())
                        if (description.isNotBlank()) addProperty("description", description.trim())
                        add("marina", JsonObject().apply { addProperty("lat", marina!!.lat); addProperty("lng", marina!!.lng); addProperty("address", marina!!.address) })
                        if (routeNote.isNotBlank()) addProperty("route_note", routeNote.trim())
                        add("amenities", JsonArray().apply { amenities.forEach { add(it) } })
                        capacity.toIntOrNull()?.let { addProperty("capacity", it) }
                        if (kind == "trip") {
                            addProperty("depart_at", ZonedDateTime.of(day!!, time!!, ZoneId.systemDefault()).toOffsetDateTime().toString())
                            addProperty("duration_min", duration.toInt())
                            minor(seatPrice)?.let { addProperty("seat_price_minor", it) }
                            minor(privatePrice)?.let { addProperty("private_price_minor", it) }
                        } else {
                            addProperty("hour_price_minor", minor(hourPrice))
                            addProperty("min_hours", minHours.toIntOrNull() ?: 1)
                        }
                    }
                    when (val r = apiCall { DriverBackend.marine.publish(body) }) {
                        is ApiResult.Ok -> onDone()
                        is ApiResult.Failed -> error = r.error.displayMessage
                        is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                    }
                    busy = false
                }
            }
            Spacer(Modifier.navigationBarsPadding())
        }
    }
    if (dateOpen) DorrDatePickerDialog(initial = day, min = LocalDate.now(), max = LocalDate.now().plusYears(1), onDismiss = { dateOpen = false }) { day = it; dateOpen = false }
    if (picking) PlacePicker(onClose = { picking = false }) { marina = it; picking = false }
}

// ---------------------------------------------------------------- one listing

@Composable
fun MarineListingScreen(uuid: String, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var l by remember { mutableStateOf<DriverMarineListingDto?>(null) }
    var message by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }

    suspend fun reload() { (apiCall { DriverBackend.marine.listing(uuid) } as? ApiResult.Ok)?.let { l = it.data } }
    LaunchedEffect(uuid) { reload() }
    LaunchedEffect(uuid) { com.dorr.driver.live.DriverLive.events.collect { e -> if (e.name.startsWith("marine.")) reload() } }

    fun <T> call(call: suspend () -> retrofit2.Response<com.dorr.core.network.ApiEnvelope<T>>, ok: (T) -> Unit = {}) {
        busy = true
        error = null
        scope.launch {
            when (val r = apiCall { call() }) {
                is ApiResult.Ok -> { ok(r.data); reload() }
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }
    fun move(to: String) = call({ DriverBackend.marine.move(uuid, JsonObject().apply { addProperty("to", to) }) })
    val scan = rememberLauncherForActivityResult(ScanContract()) { result ->
        result.contents?.let { qr -> call({ DriverBackend.marine.board(JsonObject().apply { addProperty("qr", qr) }) }) { b -> message = context.getString(R.string.mr_boarded, b.guest?.name.orEmpty(), b.guests) } }
    }

    Column(Modifier.fillMaxSize().background(Pal.bg)) {
        ScreenHeader(stringResource(R.string.mr_listing), onBack = onBack, modifier = Modifier.statusBarsPadding())
        val t = l ?: return@Column
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Column(Modifier.fillMaxWidth().surface().padding(14.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(t.title, fontWeight = FontWeight.Black, color = Pal.ink, modifier = Modifier.weight(1f))
                    MarineStatus(t.status)
                }
                Text(t.marina.address.orEmpty(), color = Pal.ink, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp))
                if (t.kind == "trip") {
                    Text(busDay(t.departAt) + " · " + busTime(t.departAt) + " · " + stringResource(R.string.mr_minutes, t.durationMin ?: 0), color = Pal.mut, fontSize = 12.sp)
                    Text(stringResource(R.string.mr_left_of, t.seatsLeft ?: 0, t.capacity), color = Pal.mut, fontSize = 12.sp)
                } else {
                    Text(money(t.prices.hourMinor, t.currency) + " / " + stringResource(R.string.mr_hour) + " · " + stringResource(R.string.mr_min_hours_n, t.prices.minHours ?: 1), color = Pal.mut, fontSize = 12.sp)
                }
            }
            message?.let { Banner(it, kind = BannerKind.Success) }
            Banner(error)
            when (t.status) {
                "active" -> {
                    if (t.kind == "trip") PrimaryButton(stringResource(R.string.mr_depart), busy = busy, color = Pal.success) { move("departed") }
                    else OutlineButton(stringResource(R.string.mr_pause)) { move("paused") }
                    OutlineButton(stringResource(R.string.mr_cancel), color = Pal.danger) { move("canceled") }
                }
                "paused" -> {
                    PrimaryButton(stringResource(R.string.mr_resume), busy = busy, color = Sea) { move("active") }
                    OutlineButton(stringResource(R.string.mr_cancel), color = Pal.danger) { move("canceled") }
                }
                "departed" -> PrimaryButton(stringResource(R.string.mr_finish), busy = busy) { move("completed") }
            }
            if (t.status == "active" || t.status == "departed" || t.status == "paused") {
                OutlineButton(stringResource(R.string.bus_scan_ticket)) {
                    scan.launch(ScanOptions().setPrompt(context.getString(R.string.mr_scan_prompt)).setBeepEnabled(true).setOrientationLocked(true))
                }
            }
            Text(stringResource(R.string.mr_bookings, t.bookings.size), fontWeight = FontWeight.Bold, color = Pal.ink)
            t.bookings.forEach { b ->
                GuestRow(
                    b, t,
                    onFinish = { call({ DriverBackend.marine.finish(b.uuid) }) },
                    onExtension = { yes -> call({ DriverBackend.marine.extension(b.uuid, JsonObject().apply { addProperty("approve", yes) }) }) },
                )
            }
            Spacer(Modifier.navigationBarsPadding())
        }
    }
}

@Composable
private fun GuestRow(b: MarineGuestBookingDto, l: DriverMarineListingDto, onFinish: () -> Unit, onExtension: (Boolean) -> Unit) {
    val context = LocalContext.current
    Column(Modifier.fillMaxWidth().surface().padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(b.guest?.name ?: "—", fontWeight = FontWeight.Bold, color = Pal.ink)
                Text(
                    stringResource(R.string.mr_guests_n, b.guests) + " · " + stringResource(when (b.mode) { "private" -> R.string.mr_private; "rental" -> R.string.mr_kind_rental; else -> R.string.mr_shared }) +
                        " · " + money(b.amountMinor + b.extensionMinor, l.currency),
                    color = Pal.mut, fontSize = 12.sp,
                )
                if (b.mode == "rental") Text(busDay(b.startsAt) + " · " + busTime(b.startsAt) + " → " + busTime(b.endsAt), color = Pal.mut, fontSize = 12.sp)
            }
            Text(
                stringResource(when (b.status) { "boarded" -> R.string.mr_st_boarded; "completed" -> R.string.mr_st_completed; else -> R.string.mr_st_confirmed }),
                color = if (b.status == "boarded") Pal.success else Pal.mut, fontSize = 11.sp, fontWeight = FontWeight.Bold,
            )
        }
        b.guest?.phone?.let { phone ->
            Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Chip("📞 " + stringResource(R.string.mr_call), tint = Pal.success) { runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))) } }
                Chip("✉ " + stringResource(R.string.mr_sms), tint = Sea) { runCatching { context.startActivity(Intent(Intent.ACTION_SENDTO, Uri.parse("smsto:$phone"))) } }
            }
        }
        // R-42, D-102: the guest asks for more time.
        if (b.extension?.status == "pending") {
            Text(stringResource(R.string.mr_more_asked, b.extension?.hours ?: 0, money(b.extension?.amountMinor ?: 0, l.currency)), color = Pal.orange, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp))
            Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Box(Modifier.weight(1f)) { PrimaryButton(stringResource(R.string.mr_accept), color = Pal.success) { onExtension(true) } }
                Box(Modifier.weight(1f)) { OutlineButton(stringResource(R.string.mr_decline), color = Pal.danger) { onExtension(false) } }
            }
        }
        if (b.mode == "rental" && (b.status == "confirmed" || b.status == "boarded")) {
            Spacer(Modifier.padding(top = 6.dp))
            OutlineButton(stringResource(R.string.mr_finish_hire)) { onFinish() }
        }
    }
}

@Composable
private fun amenityText(key: String): String = stringResource(
    when (key) {
        "life_jackets" -> R.string.mr_am_life_jackets
        "snorkeling" -> R.string.mr_am_snorkeling
        "fishing" -> R.string.mr_am_fishing
        "food" -> R.string.mr_am_food
        "drinks" -> R.string.mr_am_drinks
        "music" -> R.string.mr_am_music
        "cabin" -> R.string.mr_am_cabin
        "toilet" -> R.string.mr_am_toilet
        "crew" -> R.string.mr_am_crew
        "swimming" -> R.string.mr_am_swimming
        "sunbeds" -> R.string.mr_am_sunbeds
        else -> R.string.mr_am_photos
    },
)
