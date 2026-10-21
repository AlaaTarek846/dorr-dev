package com.dorr.driver.ui

import android.app.TimePickerDialog
import android.content.Intent
import android.graphics.Bitmap
import android.location.Geocoder
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.DirectionsBus
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.QrCodeScanner
import androidx.compose.material.icons.rounded.Sms
import androidx.compose.material3.Icon
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
import androidx.compose.ui.graphics.asImageBitmap
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
import com.dorr.driver.net.BusPassengerDto
import com.dorr.driver.net.BusVehicleDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.DriverBusTripDto
import com.dorr.driver.net.PlaceDto
import com.dorr.driver.ui.kit.Banner
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
import com.google.gson.JsonObject
import com.google.zxing.BarcodeFormat
import com.google.zxing.EncodeHintType
import com.google.zxing.qrcode.QRCodeWriter
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.LocalDate
import java.time.LocalTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * The captain's buses (docs/rides_tasks.md O-20, O-21, O-24): his bus and a QR label for each seat; his
 * trips — from, to, leaving and coming back, a price going, coming back and both; and on a trip, the seat
 * map, who booked (call or message them), boarding by the ticket's QR or the list, leaving and ending.
 * New bookings arrive as notifications (`bus.booked`) — the open trip reloads on them; nothing polls.
 */

@Composable
fun BusTripsScreen(onBack: () -> Unit, onOpen: (String) -> Unit) {
    var bus by remember { mutableStateOf<BusVehicleDto?>(null) }
    var trips by remember { mutableStateOf<List<DriverBusTripDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var formOpen by remember { mutableStateOf(false) }
    var labelsOpen by remember { mutableStateOf(false) }
    var tick by remember { mutableStateOf(0) }

    LaunchedEffect(tick) {
        when (val r = apiCall { DriverBackend.bus.vehicle() }) {
            is ApiResult.Ok -> bus = r.data
            is ApiResult.Failed -> error = r.error.displayMessage
            else -> {}
        }
        (apiCall { DriverBackend.bus.trips() } as? ApiResult.Ok)?.let { trips = it.data }
    }
    LaunchedEffect(Unit) { com.dorr.driver.live.DriverLive.events.collect { e -> if (e.name.startsWith("bus.")) tick++ } }

    Column(Modifier.fillMaxSize().background(Pal.bg)) {
        ScreenHeader(stringResource(R.string.bus_my_trips), onBack = onBack, modifier = Modifier.statusBarsPadding())
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Banner(error)
            bus?.let { b ->
                Row(Modifier.fillMaxWidth().surface().padding(14.dp).staggerIn(0), verticalAlignment = Alignment.CenterVertically) {
                    TileIcon(Icons.Rounded.DirectionsBus, Pal.accent, 46.dp)
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(b.name ?: "—", fontWeight = FontWeight.Bold, color = Pal.ink)
                        Text(listOfNotNull(b.plate, stringResource(R.string.bus_seats_n, b.seats)).joinToString(" · "), color = Pal.mut, fontSize = 12.sp)
                    }
                    Chip(stringResource(R.string.bus_seat_labels), onClick = { labelsOpen = true })
                }
                PrimaryButton(stringResource(R.string.bus_new_trip), icon = Icons.Rounded.Add) { formOpen = true }
            }
            val list = trips
            when {
                list == null -> {}
                list.isEmpty() -> Text(stringResource(R.string.bus_no_trips), color = Pal.mut, modifier = Modifier.padding(20.dp))
                else -> list.forEachIndexed { i, t ->
                    Column(Modifier.fillMaxWidth().surface().clickable { onOpen(t.uuid) }.padding(14.dp).staggerIn(i + 1)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(busTime(t.departAt), fontWeight = FontWeight.Black, fontSize = 18.sp, color = Pal.ink)
                            Spacer(Modifier.width(8.dp))
                            Text(busDay(t.departAt), color = Pal.mut, fontSize = 12.sp, modifier = Modifier.weight(1f))
                            StatusChip(t.status)
                        }
                        Text(t.origin.address.orEmpty() + "  ←  " + t.destination.address.orEmpty(), color = Pal.ink, fontSize = 13.sp, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 6.dp))
                        Text(
                            stringResource(R.string.bus_booked_of, t.seatsTotal - t.free.outbound, t.seatsTotal) + (t.returnAt?.let { " · " + stringResource(R.string.bus_back_at, busDay(it) + " " + busTime(it)) } ?: ""),
                            color = Pal.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp),
                        )
                    }
                }
            }
            Spacer(Modifier.navigationBarsPadding())
        }
    }
    if (formOpen) bus?.let { BusTripForm(it, onClose = { formOpen = false }, onDone = { formOpen = false; tick++ }) }
    if (labelsOpen) bus?.let { SeatLabels(it, onClose = { labelsOpen = false }) }
}

@Composable
private fun StatusChip(status: String) {
    val (label, color) = when (status) {
        "departed" -> stringResource(R.string.bus_st_departed) to Pal.success
        "returning" -> stringResource(R.string.bus_st_returning) to Pal.orange
        "completed" -> stringResource(R.string.bus_st_completed) to Pal.mut
        "canceled" -> stringResource(R.string.bus_st_canceled) to Pal.danger
        else -> stringResource(R.string.bus_st_scheduled) to Pal.accent
    }
    Text(label, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp))
}

// ---------------------------------------------------------------- publish a trip

@Composable
private fun BusTripForm(bus: BusVehicleDto, onClose: () -> Unit, onDone: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var origin by remember { mutableStateOf<PlaceDto?>(null) }
    var destination by remember { mutableStateOf<PlaceDto?>(null) }
    var picking by remember { mutableStateOf<String?>(null) }
    var departDay by remember { mutableStateOf<LocalDate?>(null) }
    var departTime by remember { mutableStateOf<LocalTime?>(null) }
    var withReturn by remember { mutableStateOf(false) }
    var returnDay by remember { mutableStateOf<LocalDate?>(null) }
    var returnTime by remember { mutableStateOf<LocalTime?>(null) }
    var priceOut by remember { mutableStateOf("") }
    var priceBack by remember { mutableStateOf("") }
    var priceRound by remember { mutableStateOf("") }
    var seats by remember { mutableStateOf(bus.seats.toString()) }
    var note by remember { mutableStateOf("") }
    var dateFor by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun at(day: LocalDate?, time: LocalTime?): String? = if (day == null || time == null) null else ZonedDateTime.of(day, time, ZoneId.systemDefault()).toOffsetDateTime().toString()
    fun minor(text: String): Long? = text.replace(',', '.').toBigDecimalOrNull()?.movePointRight(2)?.toLong()
    fun pickTime(onPick: (LocalTime) -> Unit) {
        val now = LocalTime.now()
        TimePickerDialog(context, { _, h, m -> onPick(LocalTime.of(h, m)) }, now.hour, now.minute, false).show()
    }

    BackHandler(onBack = onClose)
    Column(Modifier.fillMaxSize().background(Pal.bg)) {
        ScreenHeader(stringResource(R.string.bus_new_trip), onBack = onClose, modifier = Modifier.statusBarsPadding())
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Column(Modifier.fillMaxWidth().surface()) {
                ListRow(Icons.Rounded.Place, origin?.address ?: stringResource(R.string.bus_pick_from), subtitle = stringResource(R.string.bus_from), tint = Pal.success) { picking = "from" }
                ListRow(Icons.Rounded.Place, destination?.address ?: stringResource(R.string.bus_pick_to), subtitle = stringResource(R.string.bus_to), tint = Pal.orange) { picking = "to" }
            }
            Text(stringResource(R.string.bus_departure), fontWeight = FontWeight.Bold, color = Pal.ink)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Box(Modifier.weight(1f)) { OutlineButton(departDay?.toString() ?: stringResource(R.string.bus_day)) { dateFor = "depart" } }
                Box(Modifier.weight(1f)) { OutlineButton(departTime?.toString() ?: stringResource(R.string.bus_time)) { pickTime { departTime = it } } }
            }
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { withReturn = !withReturn }.padding(vertical = 6.dp)) {
                Text(stringResource(R.string.bus_with_return), fontWeight = FontWeight.Bold, color = Pal.ink, modifier = Modifier.weight(1f))
                com.dorr.driver.ui.kit.DorrToggle(withReturn) { withReturn = it }
            }
            if (withReturn) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Box(Modifier.weight(1f)) { OutlineButton(returnDay?.toString() ?: stringResource(R.string.bus_day)) { dateFor = "return" } }
                    Box(Modifier.weight(1f)) { OutlineButton(returnTime?.toString() ?: stringResource(R.string.bus_time)) { pickTime { returnTime = it } } }
                }
            }
            Text(stringResource(R.string.bus_prices), fontWeight = FontWeight.Bold, color = Pal.ink)
            DorrField(priceOut, { priceOut = it }, label = { Text(stringResource(R.string.bus_price_out)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
            if (withReturn) {
                DorrField(priceBack, { priceBack = it }, label = { Text(stringResource(R.string.bus_price_back)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
                DorrField(priceRound, { priceRound = it }, label = { Text(stringResource(R.string.bus_price_round)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal))
            }
            DorrField(seats, { seats = it.filter(Char::isDigit).take(3) }, label = { Text(stringResource(R.string.bus_seats_for_sale)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
            DorrField(note, { note = it.take(255) }, label = { Text(stringResource(R.string.bus_note)) }, minLines = 2)
            Banner(error)
            PrimaryButton(stringResource(R.string.bus_publish), busy = busy, enabled = origin != null && destination != null && departDay != null && departTime != null && minor(priceOut) != null) {
                busy = true
                error = null
                scope.launch {
                    val body = JsonObject().apply {
                        add("origin", JsonObject().apply { addProperty("lat", origin!!.lat); addProperty("lng", origin!!.lng); addProperty("address", origin!!.address) })
                        add("destination", JsonObject().apply { addProperty("lat", destination!!.lat); addProperty("lng", destination!!.lng); addProperty("address", destination!!.address) })
                        addProperty("depart_at", at(departDay, departTime))
                        if (withReturn) at(returnDay, returnTime)?.let { addProperty("return_at", it) }
                        addProperty("price_outbound_minor", minor(priceOut))
                        if (withReturn) { minor(priceBack)?.let { addProperty("price_return_minor", it) }; minor(priceRound)?.let { addProperty("price_round_minor", it) } }
                        seats.toIntOrNull()?.let { addProperty("seats_total", it) }
                        if (note.isNotBlank()) addProperty("note", note.trim())
                    }
                    when (val r = apiCall { DriverBackend.bus.publish(body) }) {
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
    dateFor?.let { which ->
        DorrDatePickerDialog(initial = null, min = LocalDate.now(), max = LocalDate.now().plusYears(1), onDismiss = { dateFor = null }) { d ->
            if (which == "depart") departDay = d else returnDay = d
            dateFor = null
        }
    }
    picking?.let { which ->
        PlacePicker(onClose = { picking = null }) { p -> if (which == "from") origin = p else destination = p; picking = null }
    }
}

/** Typing a place: the phone's geocoder answers (no server call). */
@Composable
internal fun PlacePicker(onClose: () -> Unit, onPick: (PlaceDto) -> Unit) {
    val context = LocalContext.current
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<PlaceDto>>(emptyList()) }
    LaunchedEffect(query) {
        delay(300)
        results = if (query.trim().length < 3) emptyList() else withContext(Dispatchers.IO) {
            runCatching {
                @Suppress("DEPRECATION")
                Geocoder(context, Locale.getDefault()).getFromLocationName(query, 8).orEmpty()
                    .map { a -> PlaceDto(a.latitude, a.longitude, a.getAddressLine(0) ?: a.featureName ?: query) }
            }.getOrDefault(emptyList())
        }
    }
    BackHandler(onBack = onClose)
    Column(Modifier.fillMaxSize().background(Pal.bg).statusBarsPadding().padding(16.dp)) {
        ScreenHeader(stringResource(R.string.bus_search_place), onBack = onClose)
        DorrField(query, { query = it }, singleLine = true, placeholder = { Text(stringResource(R.string.bus_search_hint)) })
        Spacer(Modifier.height(8.dp))
        Column(Modifier.verticalScroll(rememberScrollState())) {
            results.forEach { p -> ListRow(Icons.Rounded.Place, p.address.orEmpty()) { onPick(p) } }
        }
    }
}

// ---------------------------------------------------------------- one trip: BusTripLive.kt (O-68, O-69)

/** O-20: a QR label for each seat — its number, to stick on the seat. */
@Composable
private fun SeatLabels(bus: BusVehicleDto, onClose: () -> Unit) {
    BackHandler(onBack = onClose)
    Column(Modifier.fillMaxSize().background(Pal.bg)) {
        ScreenHeader(stringResource(R.string.bus_seat_labels), onBack = onClose, modifier = Modifier.statusBarsPadding())
        LazyVerticalGrid(GridCells.Fixed(3), Modifier.fillMaxSize().padding(12.dp), horizontalArrangement = Arrangement.spacedBy(10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            items(bus.seatLabels) { label ->
                Column(Modifier.surface().padding(10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    val bmp = remember(label.qr) { qr(label.qr) }
                    Image(bmp.asImageBitmap(), null, modifier = Modifier.fillMaxWidth().aspectRatio(1f))
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.QrCode2, null, tint = Pal.accent, modifier = Modifier.size(14.dp))
                        Text(stringResource(R.string.bus_seat_no, label.seat), fontWeight = FontWeight.Black, color = Pal.ink, fontSize = 14.sp)
                    }
                }
            }
        }
    }
}

private fun qr(text: String, size: Int = 360): Bitmap {
    val m = QRCodeWriter().encode(text, BarcodeFormat.QR_CODE, size, size, mapOf(EncodeHintType.MARGIN to 1))
    val bmp = Bitmap.createBitmap(m.width, m.height, Bitmap.Config.ARGB_8888)
    for (x in 0 until m.width) for (y in 0 until m.height) bmp.setPixel(x, y, if (m[x, y]) android.graphics.Color.BLACK else android.graphics.Color.WHITE)
    return bmp
}

private fun zonedBus(iso: String?) = iso?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()) }.getOrNull() }

internal fun busTime(iso: String?): String = zonedBus(iso)?.format(DateTimeFormatter.ofPattern("h:mm a")) ?: "—"

internal fun busDay(iso: String?): String = zonedBus(iso)?.format(DateTimeFormatter.ofPattern("EEE d MMM")) ?: ""
