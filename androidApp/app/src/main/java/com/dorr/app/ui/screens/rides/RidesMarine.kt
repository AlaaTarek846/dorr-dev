package com.dorr.app.ui.screens.rides

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
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
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Anchor
import androidx.compose.material.icons.rounded.BeachAccess
import androidx.compose.material.icons.rounded.Bed
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ConfirmationNumber
import androidx.compose.material.icons.rounded.DirectionsBoat
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.HealthAndSafety
import androidx.compose.material.icons.rounded.LocalBar
import androidx.compose.material.icons.rounded.MusicNote
import androidx.compose.material.icons.rounded.PhotoCamera
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Pool
import androidx.compose.material.icons.rounded.Remove
import androidx.compose.material.icons.rounded.Restaurant
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.SetMeal
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Waves
import androidx.compose.material.icons.rounded.Wc
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
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
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
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
import com.dorr.app.network.MarineBookingDto
import com.dorr.app.network.MarineListingDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.qrImage
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.LocalTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.UUID
import kotlin.math.PI
import kotlin.math.sin

/*
 * Boats & yachts (docs/rides_tasks.md O-25; R-29, R-41, R-42): a service of its own inside rides, in sea
 * colours. Two kinds — a sea trip at a set time (seats, or the whole boat) and a yacht by the hour (a day,
 * a start hour, how many hours; the boat's taken hours are greyed). Paid from the wallet, a boarding QR,
 * the captain one tap away, and on a hire «more time» that the captain accepts or not (the answer arrives
 * as a notification — nothing asks the server on a timer).
 */

private val Deep = Color(0xFF01579B)
private val Sea = Color(0xFF0288D1)
private val Foam = Color(0xFF26C6DA)

private sealed interface MarinePage {
    data object Browse : MarinePage
    data class Details(val listing: MarineListingDto) : MarinePage
    data object Bookings : MarinePage
    data class Booking(val booking: MarineBookingDto, val fresh: Boolean = false) : MarinePage
}

@Composable
internal fun MarineHost(state: RidesState, onClose: () -> Unit) {
    var page by remember { mutableStateOf<MarinePage>(MarinePage.Browse) }
    var forward by remember { mutableStateOf(true) }
    fun go(to: MarinePage, ahead: Boolean = true) { forward = ahead; page = to }
    var kind by remember { mutableStateOf("trip") }

    BackHandler {
        when (page) {
            MarinePage.Browse -> onClose()
            is MarinePage.Details, MarinePage.Bookings -> go(MarinePage.Browse, false)
            is MarinePage.Booking -> go(MarinePage.Bookings, false)
        }
    }
    Box(Modifier.fillMaxSize().background(settingsBackground())) {
        AnimatedContent(
            targetState = page,
            transitionSpec = {
                val sign = if (forward) 1 else -1
                (slideInHorizontally(tween(320)) { sign * it / 4 } + fadeIn(tween(260))) togetherWith fadeOut(tween(180))
            },
            label = "marine-pages",
        ) { p ->
            when (p) {
                MarinePage.Browse -> MarineBrowsePage(state, kind, { kind = it }, onBack = onClose, onBookings = { go(MarinePage.Bookings) }, onPick = { go(MarinePage.Details(it)) })
                is MarinePage.Details -> MarineDetailsPage(p.listing, onBack = { go(MarinePage.Browse, false) }, onBooked = { go(MarinePage.Booking(it, fresh = true)) })
                MarinePage.Bookings -> MarineBookingsPage(onBack = { go(MarinePage.Browse, false) }, onOpen = { go(MarinePage.Booking(it)) })
                is MarinePage.Booking -> MarineBookingPage(p.booking, p.fresh, onBack = { go(MarinePage.Bookings, false) })
            }
        }
    }
}

// ---------------------------------------------------------------- browse

@Composable
private fun MarineBrowsePage(rides: RidesState, kind: String, onKind: (String) -> Unit, onBack: () -> Unit, onBookings: () -> Unit, onPick: (MarineListingDto) -> Unit) {
    var list by remember(kind) { mutableStateOf<List<MarineListingDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(kind) {
        error = null
        val near = rides.me ?: rides.pickup?.latLng
        runCatching { ApiClient.marine.listings(ridesAuth(), kind, near?.latitude, near?.longitude).data.orEmpty() }
            .onSuccess { list = it }.onFailure { error = it.apiFailure().message; list = emptyList() }
    }
    LazyColumn(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item { SeaHero(onBack, onBookings) }
        item {
            // Sea trips | yacht hire — a sliding pill.
            Row(
                Modifier.padding(horizontal = 16.dp).fillMaxWidth().clip(CircleShape).background(settingsCard()).padding(4.dp),
            ) {
                listOf("trip" to R.string.mar_trips, "rental" to R.string.mar_rentals).forEach { (k, label) ->
                    val on = kind == k
                    val bg by animateColorAsState(if (on) Sea else Color.Transparent, tween(220), label = "kind")
                    Text(
                        stringResource(label), color = if (on) Color.White else settingsInk(), fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                        modifier = Modifier.weight(1f).clip(CircleShape).background(bg).clickable { onKind(k) }.padding(vertical = 11.dp),
                    )
                }
            }
        }
        val rows = list
        when {
            rows == null -> item { Box(Modifier.fillMaxWidth().padding(36.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Sea) } }
            error != null && rows.isEmpty() -> item { Text(error.orEmpty(), color = AppColors.danger, modifier = Modifier.padding(horizontal = 20.dp)) }
            rows.isEmpty() -> item { EmptySea(stringResource(if (kind == "trip") R.string.mar_no_trips else R.string.mar_no_rentals)) }
            else -> itemsIndexed(rows, key = { _, l -> l.uuid }) { i, l -> Box(Modifier.padding(horizontal = 16.dp).staggerIn(i)) { ListingCard(l) { onPick(l) } } }
        }
        item { Spacer(Modifier.navigationBarsPadding().height(24.dp)) }
    }
}

/** The page's one big moment: deep sea, moving waves, a boat riding them. */
@Composable
private fun SeaHero(onBack: () -> Unit, onBookings: () -> Unit) {
    val waves = rememberInfiniteTransition(label = "waves")
    val phase by waves.animateFloat(0f, (2 * PI).toFloat(), infiniteRepeatable(tween(4200, easing = LinearEasing)), label = "phase")
    val bob by waves.animateFloat(-1f, 1f, infiniteRepeatable(tween(1600), RepeatMode.Reverse), label = "bob")
    Box(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 34.dp, bottomEnd = 34.dp))
            .background(Brush.verticalGradient(listOf(Deep, Sea, Foam))),
    ) {
        Canvas(Modifier.matchParentSize()) {
            listOf(0.62f to 0.18f, 0.74f to 0.28f, 0.86f to 0.4f).forEachIndexed { i, (level, alpha) ->
                val path = Path()
                val base = size.height * level
                val amp = size.height * 0.035f
                path.moveTo(0f, size.height)
                var x = 0f
                while (x <= size.width) {
                    path.lineTo(x, base + amp * sin(x / size.width * 2 * PI.toFloat() * 1.5f + phase + i * 1.3f))
                    x += 8f
                }
                path.lineTo(size.width, size.height)
                path.close()
                drawPath(path, Color.White.copy(alpha = alpha))
            }
            // A little sun.
            drawCircle(Color(0xFFFFF59D).copy(alpha = 0.55f), radius = size.height * 0.11f, center = Offset(size.width * 0.16f, size.height * 0.36f))
        }
        Column(Modifier.statusBarsPadding().padding(horizontal = 18.dp).padding(top = 10.dp, bottom = 34.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                SeaCircle(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
                Spacer(Modifier.weight(1f))
                SeaCircle(Icons.Rounded.ConfirmationNumber, onBookings)
            }
            Spacer(Modifier.height(14.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.mar_title), color = Color.White, fontWeight = FontWeight.Black, fontSize = 27.sp)
                    Text(stringResource(R.string.mar_subtitle), color = Color.White.copy(alpha = 0.88f), fontSize = 13.sp)
                }
                Icon(
                    Icons.Rounded.DirectionsBoat, null, tint = Color.White,
                    modifier = Modifier.size(66.dp).graphicsLayer { translationY = bob * 7f; rotationZ = bob * 4f },
                )
            }
        }
    }
}

@Composable
private fun SeaCircle(icon: ImageVector, onClick: () -> Unit) {
    Box(Modifier.size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = Color.White, modifier = Modifier.size(20.dp))
    }
}

@Composable
private fun ListingCard(l: MarineListingDto, onClick: () -> Unit) {
    Column(
        Modifier.fillMaxWidth().shadow(10.dp, RoundedCornerShape(24.dp), spotColor = Sea.copy(alpha = 0.25f)).clip(RoundedCornerShape(24.dp))
            .background(settingsCard()).pressable(onClick = onClick),
    ) {
        // A sea band with the boat, the kind and the price.
        Row(
            Modifier.fillMaxWidth().background(Brush.horizontalGradient(listOf(Deep, Sea, Foam))).padding(horizontal = 16.dp, vertical = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.size(42.dp).clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
                Icon(if (l.boat?.type == "yacht") Icons.Rounded.Anchor else Icons.Rounded.DirectionsBoat, null, tint = Color.White)
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(l.title, color = Color.White, fontWeight = FontWeight.Black, fontSize = 16.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(listOfNotNull(l.boat?.make, l.boat?.model).joinToString(" ").ifBlank { stringResource(if (l.boat?.type == "yacht") R.string.mar_yacht else R.string.mar_boat) }, color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp, maxLines = 1)
            }
            Column(horizontalAlignment = Alignment.End) {
                Text(money(priceFrom(l), l.currency), color = Color.White, fontWeight = FontWeight.Black, fontSize = 17.sp)
                Text(stringResource(if (l.kind == "rental") R.string.mar_per_hour else R.string.mar_per_seat), color = Color.White.copy(alpha = 0.85f), fontSize = 11.sp)
            }
        }
        Column(Modifier.padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Place, null, tint = Sea, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(4.dp))
                Text(l.marina.address.orEmpty(), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            Spacer(Modifier.height(6.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Schedule, null, tint = settingsMut(), modifier = Modifier.size(15.dp))
                Spacer(Modifier.width(4.dp))
                Text(
                    if (l.kind == "trip") dayOf(l.departAt) + " · " + timeOf(l.departAt) + " · " + durationText(l.durationMin)
                    else stringResource(R.string.mar_min_hours, l.prices.minHours ?: 1),
                    color = settingsMut(), fontSize = 12.sp, modifier = Modifier.weight(1f),
                )
                Icon(Icons.Rounded.Groups, null, tint = if ((l.seatsLeft ?: 1) > 0) AppColors.success else AppColors.danger, modifier = Modifier.size(15.dp))
                Text(
                    " " + (l.seatsLeft?.let { stringResource(R.string.mar_left, it) } ?: stringResource(R.string.mar_up_to, l.capacity)),
                    color = if ((l.seatsLeft ?: 1) > 0) AppColors.success else AppColors.danger, fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
                )
            }
            if (l.amenities.isNotEmpty()) {
                Row(Modifier.padding(top = 10.dp).horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    l.amenities.forEach { a ->
                        Box(Modifier.size(30.dp).clip(CircleShape).background(Sea.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                            Icon(amenityIcon(a), null, tint = Sea, modifier = Modifier.size(16.dp))
                        }
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------- details and booking

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun MarineDetailsPage(initial: MarineListingDto, onBack: () -> Unit, onBooked: (MarineBookingDto) -> Unit) {
    val scope = rememberCoroutineScope()
    var l by remember { mutableStateOf(initial) }
    LaunchedEffect(initial.uuid) { runCatching { ApiClient.marine.listing(ridesAuth(), initial.uuid).data }.getOrNull()?.let { l = it } }

    val trip = l.kind == "trip"
    var mode by remember { mutableStateOf(if (trip) (if (l.prices.seatMinor != null) "shared" else "private") else "rental") }
    var guests by remember { mutableIntStateOf(1) }
    var day by remember { mutableStateOf(LocalDate.now()) }
    var hour by remember { mutableStateOf<Int?>(null) }
    var hours by remember { mutableIntStateOf(l.prices.minHours ?: 1) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val key = remember { UUID.randomUUID().toString() }

    val maxGuests = if (trip && mode == "shared") (l.seatsLeft ?: l.capacity) else l.capacity
    val total: Long? = when (mode) {
        "shared" -> l.prices.seatMinor?.times(guests)
        "private" -> l.prices.privateMinor
        else -> l.prices.hourMinor?.times(hours)
    }
    val start: ZonedDateTime? = hour?.let { ZonedDateTime.of(day, LocalTime.of(it, 0), ZoneId.systemDefault()) }
    val clash = start != null && l.busy.orEmpty().any { slot ->
        val f = zoned(slot.from); val t = zoned(slot.to)
        f != null && t != null && f.isBefore(start.plusHours(hours.toLong())) && t.isAfter(start)
    }
    val canBook = !busy && total != null && guests in 1..maxGuests && (trip || (start != null && !clash))

    Column(Modifier.fillMaxSize()) {
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 30.dp, bottomEnd = 30.dp)).background(Brush.verticalGradient(listOf(Deep, Sea, Foam)))) {
                Column(Modifier.statusBarsPadding().padding(18.dp)) {
                    SeaCircle(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
                    Spacer(Modifier.height(12.dp))
                    Text(l.title, color = Color.White, fontWeight = FontWeight.Black, fontSize = 24.sp)
                    Text(
                        listOfNotNull(stringResource(if (l.boat?.type == "yacht") R.string.mar_yacht else R.string.mar_boat), l.boat?.make, l.boat?.model, l.captain?.name?.let { "⚓ $it" }).joinToString(" · "),
                        color = Color.White.copy(alpha = 0.88f), fontSize = 13.sp,
                    )
                }
            }
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                SeaCard {
                    InfoLine(Icons.Rounded.Place, stringResource(R.string.mar_marina), l.marina.address.orEmpty())
                    l.routeNote?.let { InfoLine(Icons.Rounded.Waves, stringResource(R.string.mar_route), it) }
                    if (trip) InfoLine(Icons.Rounded.Schedule, stringResource(R.string.mar_when), dayOf(l.departAt) + " · " + timeOf(l.departAt) + " · " + durationText(l.durationMin))
                    InfoLine(Icons.Rounded.Groups, stringResource(R.string.mar_capacity), stringResource(R.string.mar_up_to, l.capacity))
                    l.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
                }
                if (l.amenities.isNotEmpty()) {
                    SeaCard {
                        Text(stringResource(R.string.mar_amenities), color = settingsInk(), fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(8.dp))
                        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            l.amenities.forEach { a ->
                                Row(Modifier.clip(CircleShape).background(Sea.copy(alpha = 0.1f)).padding(horizontal = 10.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                                    Icon(amenityIcon(a), null, tint = Sea, modifier = Modifier.size(15.dp))
                                    Spacer(Modifier.width(4.dp))
                                    Text(amenityLabel(a), color = settingsInk(), fontSize = 12.sp)
                                }
                            }
                        }
                    }
                }

                SeaCard {
                    if (trip) {
                        // Seats or the whole boat.
                        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            if (l.prices.seatMinor != null) ModeTile(stringResource(R.string.mar_shared), money(l.prices.seatMinor, l.currency) + " / " + stringResource(R.string.mar_seat), mode == "shared", Modifier.weight(1f)) { mode = "shared" }
                            if (l.prices.privateMinor != null) ModeTile(stringResource(R.string.mar_private), money(l.prices.privateMinor, l.currency), mode == "private", Modifier.weight(1f), enabled = (l.seatsLeft ?: 0) >= l.capacity) { mode = "private" }
                        }
                        Spacer(Modifier.height(12.dp))
                    } else {
                        Text(stringResource(R.string.mar_pick_day), color = settingsInk(), fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(8.dp))
                        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            (0L..13L).map { LocalDate.now().plusDays(it) }.forEach { d ->
                                Chip(d.format(DateTimeFormatter.ofPattern("EEE d", AppLocale.java())), d == day) { day = d; hour = null }
                            }
                        }
                        Spacer(Modifier.height(12.dp))
                        Text(stringResource(R.string.mar_pick_hour), color = settingsInk(), fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(8.dp))
                        val now = ZonedDateTime.now()
                        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            (6..23).forEach { h ->
                                val at = ZonedDateTime.of(day, LocalTime.of(h, 0), ZoneId.systemDefault())
                                val taken = l.busy.orEmpty().any { s -> val f = zoned(s.from); val t = zoned(s.to); f != null && t != null && !at.isBefore(f) && at.isBefore(t) }
                                Chip(at.format(DateTimeFormatter.ofPattern("h a", AppLocale.java())), hour == h, enabled = at.isAfter(now) && !taken) { hour = h }
                            }
                        }
                        Spacer(Modifier.height(12.dp))
                        Stepper(stringResource(R.string.mar_hours), hours, (l.prices.minHours ?: 1)..12) { hours = it }
                        if (clash) Text(stringResource(R.string.mar_clash), color = AppColors.danger, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
                        Spacer(Modifier.height(4.dp))
                    }
                    Stepper(stringResource(R.string.mar_guests), guests, 1..maxOf(1, maxGuests)) { guests = it }
                }
                error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp) }
                Text(stringResource(R.string.mar_pay_note), color = settingsMut(), fontSize = 12.sp)
            }
        }
        // The total and the one button.
        Row(
            Modifier.fillMaxWidth().shadow(18.dp).background(settingsCard()).navigationBarsPadding().padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.mar_total), color = settingsMut(), fontSize = 12.sp)
                Text(money(total, l.currency), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp)
            }
            Box(
                Modifier.clip(CircleShape).background(if (canBook) Brush.horizontalGradient(listOf(Deep, Sea)) else Brush.horizontalGradient(listOf(settingsMut(), settingsMut())))
                    .clickable(enabled = canBook) {
                        busy = true; error = null
                        scope.launch {
                            val body = JsonObject().apply {
                                addProperty("mode", mode)
                                addProperty("guests", guests)
                                if (!trip) { addProperty("starts_at", start?.toOffsetDateTime()?.toString()); addProperty("hours", hours) }
                            }
                            runCatching { ApiClient.marine.book(ridesAuth(), key, l.uuid, body).data }
                                .onSuccess { it?.let(onBooked) }
                                .onFailure { error = it.apiFailure().message }
                            busy = false
                        }
                    }.padding(horizontal = 26.dp, vertical = 14.dp),
            ) {
                if (busy) CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
                else Text(stringResource(R.string.mar_book), color = Color.White, fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun ModeTile(title: String, sub: String, on: Boolean, modifier: Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    val bg by animateColorAsState(if (on) Sea.copy(alpha = 0.12f) else settingsBackground(), tween(200), label = "mode")
    Column(
        modifier.clip(RoundedCornerShape(16.dp)).background(bg).clickable(enabled = enabled, onClick = onClick).padding(12.dp)
            .graphicsLayer { alpha = if (enabled) 1f else 0.45f },
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(title, color = if (on) Sea else settingsInk(), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            if (on) Icon(Icons.Rounded.CheckCircle, null, tint = Sea, modifier = Modifier.size(18.dp))
        }
        Text(sub, color = settingsMut(), fontSize = 12.sp)
    }
}

@Composable
private fun Chip(text: String, on: Boolean, enabled: Boolean = true, onClick: () -> Unit) {
    val bg by animateColorAsState(if (on) Sea else settingsBackground(), tween(200), label = "chip")
    Text(
        text, color = if (on) Color.White else if (enabled) settingsInk() else settingsMut().copy(alpha = 0.5f), fontSize = 13.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Medium,
        modifier = Modifier.clip(CircleShape).background(bg).clickable(enabled = enabled, onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
    )
}

@Composable
private fun Stepper(label: String, value: Int, range: IntRange, onChange: (Int) -> Unit) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = settingsInk(), fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
        StepButton(Icons.Rounded.Remove, value > range.first) { onChange(value - 1) }
        Text("$value", color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 18.sp, textAlign = TextAlign.Center, modifier = Modifier.width(44.dp))
        StepButton(Icons.Rounded.Add, value < range.last) { onChange(value + 1) }
    }
}

@Composable
private fun StepButton(icon: ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(36.dp).clip(CircleShape).background(if (enabled) Sea.copy(alpha = 0.12f) else settingsBackground()).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = if (enabled) Sea else settingsMut(), modifier = Modifier.size(18.dp)) }
}

// ---------------------------------------------------------------- my bookings

@Composable
private fun MarineBookingsPage(onBack: () -> Unit, onOpen: (MarineBookingDto) -> Unit) {
    var list by remember { mutableStateOf<List<MarineBookingDto>?>(null) }
    LaunchedEffect(Unit) { list = runCatching { ApiClient.marine.bookings(ridesAuth()).data.orEmpty() }.getOrDefault(emptyList()) }
    Column(Modifier.fillMaxSize()) {
        SeaBar(stringResource(R.string.mar_my_bookings), onBack)
        val rows = list
        when {
            rows == null -> Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Sea) }
            rows.isEmpty() -> EmptySea(stringResource(R.string.mar_no_bookings))
            else -> LazyColumn(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                itemsIndexed(rows, key = { _, b -> b.uuid }) { i, b ->
                    Row(
                        Modifier.padding(horizontal = 16.dp).staggerIn(i).fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsCard()).pressable { onOpen(b) }.padding(14.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(Brush.linearGradient(listOf(Deep, Foam))), contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.DirectionsBoat, null, tint = Color.White)
                        }
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(b.listing?.title.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(dayOf(b.startsAt) + " · " + timeOf(b.startsAt), color = settingsMut(), fontSize = 12.sp)
                        }
                        StatusTag(b.status)
                    }
                }
                item { Spacer(Modifier.navigationBarsPadding().height(16.dp)) }
            }
        }
    }
}

@Composable
private fun MarineBookingPage(initial: MarineBookingDto, fresh: Boolean, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var booking by remember { mutableStateOf(initial) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var more by remember { mutableIntStateOf(1) }
    // One catch-up on open (the captain's answer to «more time» may have come meanwhile).
    LaunchedEffect(initial.uuid) { runCatching { ApiClient.marine.booking(ridesAuth(), initial.uuid).data }.getOrNull()?.let { booking = it } }
    val l = booking.listing
    val live = booking.status == "confirmed" || booking.status == "boarded"

    Column(Modifier.fillMaxSize()) {
        SeaBar(stringResource(R.string.mar_booking), onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (fresh) {
                var shown by remember { mutableStateOf(false) }
                LaunchedEffect(Unit) { shown = true }
                AnimatedVisibility(shown, enter = scaleIn(spring(dampingRatio = 0.45f)) + fadeIn()) {
                    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                        Icon(Icons.Rounded.CheckCircle, null, tint = AppColors.success, modifier = Modifier.size(72.dp))
                        Text(stringResource(R.string.mar_booked_done), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp)
                    }
                }
            }
            // The boarding QR, what the captain scans at the gangway.
            booking.qr?.let { qr ->
                Column(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(Brush.verticalGradient(listOf(Deep, Sea))).padding(18.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    val bmp = remember(qr) { qrImage(qr, 520) }
                    Image(bmp.asImageBitmap(), null, modifier = Modifier.size(190.dp).clip(RoundedCornerShape(16.dp)).background(Color.White).padding(8.dp))
                    Spacer(Modifier.height(8.dp))
                    Text(stringResource(R.string.mar_show_qr), color = Color.White, fontSize = 13.sp, textAlign = TextAlign.Center)
                }
            }
            l?.let {
                SeaCard {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(it.title, color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 17.sp, modifier = Modifier.weight(1f))
                        StatusTag(booking.status)
                    }
                    InfoLine(Icons.Rounded.Place, stringResource(R.string.mar_marina), it.marina.address.orEmpty())
                    InfoLine(Icons.Rounded.Schedule, stringResource(R.string.mar_when), dayOf(booking.startsAt) + " · " + timeOf(booking.startsAt) + " → " + timeOf(booking.endsAt))
                    InfoLine(Icons.Rounded.Groups, stringResource(R.string.mar_guests), booking.guests.toString() + " · " + stringResource(
                        when (booking.mode) { "private" -> R.string.mar_private; "rental" -> R.string.mar_rental; else -> R.string.mar_shared },
                    ))
                    Text(money(booking.amountMinor + booking.extensionMinor, it.currency), color = Sea, fontWeight = FontWeight.Black, fontSize = 20.sp, modifier = Modifier.padding(top = 6.dp))
                    it.captainPhone?.let { phone ->
                        Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            SeaTag("📞 " + stringResource(R.string.rd_call), AppColors.success) { runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))) } }
                            SeaTag("✉ " + stringResource(R.string.bus_message), Sea) { runCatching { context.startActivity(Intent(Intent.ACTION_SENDTO, Uri.parse("smsto:$phone"))) } }
                        }
                    }
                }
            }
            // R-42: more time on a hire — the captain is asked.
            if (booking.mode == "rental" && live && l != null) {
                SeaCard {
                    Text(stringResource(R.string.mar_more_time), color = settingsInk(), fontWeight = FontWeight.Bold)
                    when (booking.extension?.status) {
                        "pending" -> Text(stringResource(R.string.mar_more_pending, booking.extension?.hours ?: 0), color = AppColors.brandOrange, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp))
                        else -> {
                            booking.extension?.status?.let { s ->
                                Text(stringResource(if (s == "approved") R.string.mar_more_approved else R.string.mar_more_declined), color = if (s == "approved") AppColors.success else AppColors.danger, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
                            }
                            Stepper(stringResource(R.string.mar_hours), more, 1..6) { more = it }
                            Text(stringResource(R.string.mar_more_cost, money((l.prices.hourMinor ?: 0) * more, l.currency)), color = settingsMut(), fontSize = 12.sp)
                            Text(
                                stringResource(R.string.mar_ask_captain), color = Color.White, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                                modifier = Modifier.padding(top = 10.dp).fillMaxWidth().clip(CircleShape).background(Sea).clickable(enabled = !busy) {
                                    busy = true; error = null
                                    scope.launch {
                                        runCatching { ApiClient.marine.extend(ridesAuth(), booking.uuid, JsonObject().apply { addProperty("hours", more) }).data }
                                            .onSuccess { it?.let { b -> booking = b } }
                                            .onFailure { error = it.apiFailure().message }
                                        busy = false
                                    }
                                }.padding(12.dp),
                            )
                        }
                    }
                }
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 12.sp) }
            if (booking.status == "confirmed") {
                Text(
                    stringResource(R.string.mar_cancel), color = AppColors.danger, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(enabled = !busy) {
                        busy = true; error = null
                        scope.launch {
                            runCatching { ApiClient.marine.cancel(ridesAuth(), booking.uuid).data }
                                .onSuccess { it?.let { b -> booking = b } }
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

// ---------------------------------------------------------------- small pieces

@Composable
private fun SeaBar(title: String, onBack: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().background(Brush.horizontalGradient(listOf(Deep, Sea))).statusBarsPadding().padding(horizontal = 16.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        SeaCircle(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
        Spacer(Modifier.width(12.dp))
        Text(title, color = Color.White, fontWeight = FontWeight.Black, fontSize = 20.sp)
    }
}

@Composable
private fun SeaCard(content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(settingsCard()).padding(16.dp)) { content() }
}

@Composable
private fun InfoLine(icon: ImageVector, label: String, value: String) {
    Row(Modifier.padding(vertical = 5.dp), verticalAlignment = Alignment.Top) {
        Box(Modifier.size(30.dp).clip(CircleShape).background(Sea.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) { Icon(icon, null, tint = Sea, modifier = Modifier.size(16.dp)) }
        Spacer(Modifier.width(10.dp))
        Column {
            Text(label, color = settingsMut(), fontSize = 11.sp)
            Text(value, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun SeaTag(text: String, color: Color, onClick: (() -> Unit)? = null) {
    Text(
        text, color = color, fontSize = 12.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier).padding(horizontal = 10.dp, vertical = 5.dp),
    )
}

@Composable
private fun StatusTag(status: String) {
    val (label, color) = when (status) {
        "boarded" -> R.string.mar_status_boarded to Sea
        "completed" -> R.string.mar_status_completed to settingsMut()
        "canceled" -> R.string.mar_status_canceled to AppColors.danger
        else -> R.string.mar_status_confirmed to AppColors.success
    }
    SeaTag(stringResource(label), color)
}

@Composable
private fun EmptySea(text: String) {
    Column(Modifier.fillMaxWidth().padding(36.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Icon(Icons.Rounded.DirectionsBoat, null, tint = Sea.copy(alpha = 0.45f), modifier = Modifier.size(58.dp))
        Spacer(Modifier.height(8.dp))
        Text(text, color = settingsMut(), textAlign = TextAlign.Center)
    }
}

private fun amenityIcon(key: String): ImageVector = when (key) {
    "life_jackets" -> Icons.Rounded.HealthAndSafety
    "snorkeling" -> Icons.Rounded.Waves
    "fishing" -> Icons.Rounded.SetMeal
    "food" -> Icons.Rounded.Restaurant
    "drinks" -> Icons.Rounded.LocalBar
    "music" -> Icons.Rounded.MusicNote
    "cabin" -> Icons.Rounded.Bed
    "toilet" -> Icons.Rounded.Wc
    "crew" -> Icons.Rounded.Groups
    "swimming" -> Icons.Rounded.Pool
    "sunbeds" -> Icons.Rounded.BeachAccess
    "photos" -> Icons.Rounded.PhotoCamera
    else -> Icons.Rounded.Star
}

@Composable
private fun amenityLabel(key: String): String = stringResource(
    when (key) {
        "life_jackets" -> R.string.mar_am_life_jackets
        "snorkeling" -> R.string.mar_am_snorkeling
        "fishing" -> R.string.mar_am_fishing
        "food" -> R.string.mar_am_food
        "drinks" -> R.string.mar_am_drinks
        "music" -> R.string.mar_am_music
        "cabin" -> R.string.mar_am_cabin
        "toilet" -> R.string.mar_am_toilet
        "crew" -> R.string.mar_am_crew
        "swimming" -> R.string.mar_am_swimming
        "sunbeds" -> R.string.mar_am_sunbeds
        else -> R.string.mar_am_photos
    },
)

private fun priceFrom(l: MarineListingDto): Long? = if (l.kind == "rental") l.prices.hourMinor else l.prices.seatMinor ?: l.prices.privateMinor

@Composable
private fun durationText(min: Int?): String = when {
    min == null -> ""
    min % 60 == 0 -> stringResource(R.string.mar_n_hours, min / 60)
    else -> stringResource(R.string.mar_n_minutes, min)
}

private fun zoned(iso: String?) = iso?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()) }.getOrNull() }

private fun timeOf(iso: String?): String = zoned(iso)?.format(DateTimeFormatter.ofPattern("h:mm a", AppLocale.java())) ?: "—"

private fun dayOf(iso: String?): String = zoned(iso)?.format(DateTimeFormatter.ofPattern("EEEE d MMM", AppLocale.java())) ?: ""
