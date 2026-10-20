package com.dorr.app.ui.screens.rides

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.PersonSearch
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Remove
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
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
import androidx.compose.ui.graphics.drawscope.Stroke
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
import com.dorr.app.network.HireDto
import com.dorr.app.network.HireQuoteDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.LocalDate
import java.time.LocalTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.UUID

/*
 * Find a driver (docs/rides_tasks.md O-26c; LeeTaxi's «hire a driver»): I have the car, I need someone to
 * drive it — where, when (now or a time), for how many hours or days, automatic or manual, which car. The
 * price shows as I choose (one request per change), it's paid from the wallet, and the nearby captains hear
 * about it at once. The request page follows it by notifications (`hire.*`) and counts the time down on the
 * device clock — nothing asks the server on a timer.
 */

private val Deep = Color(0xFF00695C)
private val Mint = Color(0xFF4DB6AC)

private sealed interface HirePage {
    data object Form : HirePage
    data object List : HirePage
    data class One(val hire: HireDto) : HirePage
}

@Composable
internal fun HireHost(state: RidesState, onClose: () -> Unit) {
    var page by remember { mutableStateOf<HirePage>(HirePage.Form) }
    var forward by remember { mutableStateOf(true) }
    fun go(to: HirePage, ahead: Boolean = true) { forward = ahead; page = to }
    // A request already going opens straight on it.
    LaunchedEffect(Unit) {
        runCatching { ApiClient.hire.mine(ridesAuth()).data.orEmpty() }.getOrNull()
            ?.firstOrNull { it.status in setOf("open", "accepted", "arrived", "started") }?.let { go(HirePage.One(it)) }
    }
    BackHandler {
        when (page) {
            HirePage.Form -> onClose()
            HirePage.List -> go(HirePage.Form, false)
            is HirePage.One -> go(HirePage.Form, false)
        }
    }
    Box(Modifier.fillMaxSize().background(settingsBackground())) {
        AnimatedContent(
            targetState = page,
            transitionSpec = { val s = if (forward) 1 else -1; (slideInHorizontally(tween(320)) { s * it / 4 } + fadeIn(tween(260))) togetherWith fadeOut(tween(180)) },
            label = "hire-pages",
        ) { p ->
            when (p) {
                HirePage.Form -> HireForm(state, onBack = onClose, onHistory = { go(HirePage.List) }, onSent = { go(HirePage.One(it)) })
                HirePage.List -> HireList(onBack = { go(HirePage.Form, false) }, onOpen = { go(HirePage.One(it)) })
                is HirePage.One -> HireStatus(p.hire, onBack = { go(HirePage.Form, false) })
            }
        }
    }
}

// ---------------------------------------------------------------- the form

@Composable
private fun HireForm(rides: RidesState, onBack: () -> Unit, onHistory: () -> Unit, onSent: (HireDto) -> Unit) {
    val scope = rememberCoroutineScope()
    var pickup by remember { mutableStateOf(rides.pickup) }
    var searching by remember { mutableStateOf(false) }
    var now by remember { mutableStateOf(true) }
    var day by remember { mutableStateOf(LocalDate.now()) }
    var hour by remember { mutableStateOf<Int?>(null) }
    var unit by remember { mutableStateOf("hour") }
    var quantity by remember { mutableIntStateOf(2) }
    var transmission by remember { mutableStateOf("automatic") }
    var car by remember { mutableStateOf("") }
    var note by remember { mutableStateOf("") }
    var quote by remember { mutableStateOf<HireQuoteDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val key = remember { UUID.randomUUID().toString() }

    // The price for what is chosen — one request per change (after a short pause), never on a timer.
    LaunchedEffect(pickup, unit, quantity) {
        val p = pickup ?: return@LaunchedEffect
        delay(250)
        runCatching { ApiClient.hire.quote(ridesAuth(), p.lat, p.lng, unit, quantity).data }
            .onSuccess { quote = it; error = null }
            .onFailure { error = it.apiFailure().message }
    }
    val limits = quote?.limits
    val range = if (unit == "day") 1..(limits?.maxDays ?: 7) else (limits?.minHours ?: 1)..(limits?.maxHours ?: 12)
    LaunchedEffect(unit, limits) { quantity = quantity.coerceIn(range) }
    val start: ZonedDateTime? = if (now) null else hour?.let { ZonedDateTime.of(day, LocalTime.of(it, 0), ZoneId.systemDefault()) }
    val ready = pickup != null && quote != null && !busy && (now || start != null)

    Column(Modifier.fillMaxSize()) {
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            HireHero(onBack, onHistory)
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                HireCard {
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { searching = true }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                        Dot(Icons.Rounded.Place)
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.hire_where), color = settingsMut(), fontSize = 11.sp)
                            Text(pickup?.address ?: stringResource(R.string.hire_pick_place), color = if (pickup == null) settingsMut() else settingsInk(), fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                        }
                        Icon(Icons.Rounded.Search, null, tint = Deep)
                    }
                }
                HireCard {
                    Row(verticalAlignment = Alignment.CenterVertically) { Dot(Icons.Rounded.Schedule); Spacer(Modifier.width(10.dp)); Text(stringResource(R.string.hire_when), color = settingsInk(), fontWeight = FontWeight.Bold) }
                    Spacer(Modifier.height(10.dp))
                    Segmented(listOf(true to R.string.hire_now, false to R.string.hire_later), now) { now = it }
                    if (!now) {
                        Spacer(Modifier.height(10.dp))
                        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            (0L..13L).map { LocalDate.now().plusDays(it) }.forEach { d -> HireChip(d.format(DateTimeFormatter.ofPattern("EEE d", AppLocale.java())), d == day) { day = d; hour = null } }
                        }
                        Spacer(Modifier.height(8.dp))
                        val n = ZonedDateTime.now()
                        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            (0..23).forEach { h ->
                                val at = ZonedDateTime.of(day, LocalTime.of(h, 0), ZoneId.systemDefault())
                                HireChip(at.format(DateTimeFormatter.ofPattern("h a", AppLocale.java())), hour == h, enabled = at.isAfter(n)) { hour = h }
                            }
                        }
                    }
                }
                HireCard {
                    Segmented(listOf("hour" to R.string.hire_by_hours, "day" to R.string.hire_by_days), unit) { unit = it }
                    Spacer(Modifier.height(10.dp))
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(if (unit == "day") R.string.hire_days else R.string.hire_hours), color = settingsInk(), fontWeight = FontWeight.Bold)
                            limits?.let { l ->
                                val each = if (unit == "day") l.dayPriceMinor else l.hourPriceMinor
                                if (each > 0) Text(stringResource(if (unit == "day") R.string.hire_per_day else R.string.hire_per_hour, money(each, quote?.currency)), color = settingsMut(), fontSize = 12.sp)
                            }
                        }
                        Step(Icons.Rounded.Remove, quantity > range.first) { quantity-- }
                        Text("$quantity", color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp, textAlign = TextAlign.Center, modifier = Modifier.width(48.dp))
                        Step(Icons.Rounded.Add, quantity < range.last) { quantity++ }
                    }
                }
                HireCard {
                    Row(verticalAlignment = Alignment.CenterVertically) { Dot(Icons.Rounded.DirectionsCar); Spacer(Modifier.width(10.dp)); Text(stringResource(R.string.hire_your_car), color = settingsInk(), fontWeight = FontWeight.Bold) }
                    Spacer(Modifier.height(10.dp))
                    Segmented(listOf("automatic" to R.string.hire_automatic, "manual" to R.string.hire_manual), transmission) { transmission = it }
                    Spacer(Modifier.height(10.dp))
                    com.dorr.app.ui.components.DorrTextField(value = car, onValueChange = { car = it.take(120) }, placeholder = stringResource(R.string.hire_car_hint), icon = Icons.Rounded.DirectionsCar, modifier = Modifier.fillMaxWidth())
                    Spacer(Modifier.height(8.dp))
                    com.dorr.app.ui.components.DorrTextField(value = note, onValueChange = { note = it.take(255) }, placeholder = stringResource(R.string.hire_note_hint), icon = Icons.Rounded.Notes, modifier = Modifier.fillMaxWidth())
                }
                limits?.takeIf { it.overtimePerMinMinor > 0 }?.let { Text(stringResource(R.string.hire_overtime, money(it.overtimePerMinMinor, quote?.currency)), color = settingsMut(), fontSize = 12.sp) }
                error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp) }
            }
        }
        Row(Modifier.fillMaxWidth().shadow(18.dp).background(settingsCard()).navigationBarsPadding().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.hire_total), color = settingsMut(), fontSize = 12.sp)
                Text(quote?.let { money(it.amountMinor, it.currency) } ?: "—", color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp)
            }
            Box(
                Modifier.clip(CircleShape).background(if (ready) Brush.horizontalGradient(listOf(Deep, Mint)) else Brush.horizontalGradient(listOf(settingsMut(), settingsMut())))
                    .clickable(enabled = ready) {
                        val p = pickup ?: return@clickable
                        busy = true; error = null
                        scope.launch {
                            val body = JsonObject().apply {
                                add("pickup", JsonObject().apply { addProperty("lat", p.lat); addProperty("lng", p.lng); addProperty("address", p.address) })
                                start?.let { addProperty("starts_at", it.toOffsetDateTime().toString()) }
                                addProperty("unit", unit); addProperty("quantity", quantity); addProperty("transmission", transmission)
                                if (car.isNotBlank()) addProperty("car", car.trim())
                                if (note.isNotBlank()) addProperty("note", note.trim())
                            }
                            runCatching { ApiClient.hire.request(ridesAuth(), key, body).data }
                                .onSuccess { it?.let(onSent) }
                                .onFailure { error = it.apiFailure().message }
                            busy = false
                        }
                    }.padding(horizontal = 24.dp, vertical = 14.dp),
            ) {
                if (busy) CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
                else Text(stringResource(R.string.hire_request), color = Color.White, fontWeight = FontWeight.Bold)
            }
        }
    }
    if (searching) {
        HirePlaceSearch(near = rides.me ?: rides.pickup?.latLng, onClose = { searching = false }) { pickup = it; searching = false }
    }
}

/** The hero: a steering wheel turning slowly in a mint glow. */
@Composable
private fun HireHero(onBack: () -> Unit, onHistory: () -> Unit) {
    val spin = rememberInfiniteTransition(label = "wheel")
    val turn by spin.animateFloat(-18f, 18f, infiniteRepeatable(tween(1800), RepeatMode.Reverse), label = "turn")
    val glow by spin.animateFloat(0.2f, 0.45f, infiniteRepeatable(tween(1400), RepeatMode.Reverse), label = "glow")
    Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 34.dp, bottomEnd = 34.dp)).background(Brush.linearGradient(listOf(Deep, Mint)))) {
        Column(Modifier.statusBarsPadding().padding(horizontal = 18.dp).padding(top = 10.dp, bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Round(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
                Spacer(Modifier.weight(1f))
                Round(Icons.Rounded.History, onHistory)
            }
            Spacer(Modifier.height(12.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.hire_title), color = Color.White, fontWeight = FontWeight.Black, fontSize = 26.sp)
                    Text(stringResource(R.string.hire_subtitle), color = Color.White.copy(alpha = 0.88f), fontSize = 13.sp)
                }
                Box(Modifier.size(78.dp), contentAlignment = Alignment.Center) {
                    Box(Modifier.size(78.dp).clip(CircleShape).background(Color.White.copy(alpha = glow)))
                    Canvas(Modifier.size(54.dp).graphicsLayer { rotationZ = turn }) {
                        val r = size.minDimension / 2
                        drawCircle(Color.White, radius = r * 0.9f, style = Stroke(width = r * 0.18f))
                        drawCircle(Color.White, radius = r * 0.22f)
                        drawLine(Color.White, center, center.copy(x = center.x - r * 0.82f), strokeWidth = r * 0.16f)
                        drawLine(Color.White, center, center.copy(x = center.x + r * 0.82f), strokeWidth = r * 0.16f)
                        drawLine(Color.White, center, center.copy(y = center.y + r * 0.82f), strokeWidth = r * 0.16f)
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------- a request

@Composable
private fun HireStatus(initial: HireDto, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var hire by remember { mutableStateOf(initial) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    suspend fun reload() { runCatching { ApiClient.hire.show(ridesAuth(), initial.uuid).data }.getOrNull()?.let { hire = it } }
    // One catch-up on open, then each `hire.*` notification reloads once.
    LaunchedEffect(initial.uuid) {
        reload()
        com.dorr.app.chat.ChatRealtime.events.collect { e -> if (e.name.startsWith("hire.")) reload() }
    }
    // The time left, from the device clock.
    var nowMs by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(hire.status) { while (hire.status == "started") { delay(1000); nowMs = System.currentTimeMillis() } }

    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().background(Brush.horizontalGradient(listOf(Deep, Mint))).statusBarsPadding().padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            Round(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
            Spacer(Modifier.width(12.dp))
            Text(stringResource(R.string.hire_title), color = Color.White, fontWeight = FontWeight.Black, fontSize = 20.sp)
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            when (hire.status) {
                "open" -> Radar()
                "started" -> {
                    val end = hire.endsAt?.let { runCatching { OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() }
                    val left = end?.let { Duration.ofMillis(it - nowMs) }
                    Text(
                        if (left == null) "—" else if (left.isNegative) "+" + clock(left.negated()) else clock(left),
                        color = if (left?.isNegative == true) AppColors.warning else Deep, fontWeight = FontWeight.Black, fontSize = 44.sp,
                    )
                    Text(stringResource(if (left?.isNegative == true) R.string.hire_overtime_running else R.string.hire_time_left), color = settingsMut(), fontSize = 13.sp)
                }
                else -> Icon(Icons.Rounded.PersonSearch, null, tint = Deep, modifier = Modifier.size(72.dp))
            }
            Text(stringResource(statusText(hire.status)), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 20.sp, textAlign = TextAlign.Center)
            hire.captain?.let { c ->
                HireCard {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(46.dp).clip(CircleShape).background(Brush.linearGradient(listOf(Deep, Mint))), contentAlignment = Alignment.Center) {
                            Text(c.name?.take(1) ?: "?", color = Color.White, fontWeight = FontWeight.Black, fontSize = 18.sp)
                        }
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(c.name.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold)
                            Text(stringResource(R.string.hire_your_driver), color = settingsMut(), fontSize = 12.sp)
                        }
                        c.phone?.let { phone ->
                            Text("📞", fontSize = 22.sp, modifier = Modifier.clip(CircleShape).clickable { runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))) } }.padding(8.dp))
                        }
                    }
                }
            }
            HireCard {
                Line(stringResource(R.string.hire_where), hire.pickup.address.orEmpty())
                Line(stringResource(R.string.hire_when), dayTime(hire.startsAt))
                Line(stringResource(R.string.hire_length), "${hire.quantity} " + stringResource(if (hire.unit == "day") R.string.hire_days else R.string.hire_hours))
                Line(stringResource(R.string.hire_your_car), stringResource(if (hire.transmission == "manual") R.string.hire_manual else R.string.hire_automatic) + (hire.car?.let { " · $it" } ?: ""))
                Line(stringResource(R.string.hire_total), money(hire.amountMinor + hire.extraMinor, hire.currency))
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp) }
            if (hire.status == "open" || hire.status == "accepted") {
                Text(
                    stringResource(R.string.hire_cancel), color = AppColors.danger, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(enabled = !busy) {
                        busy = true; error = null
                        scope.launch {
                            runCatching { ApiClient.hire.cancel(ridesAuth(), hire.uuid).data }.onSuccess { it?.let { h -> hire = h } }.onFailure { error = it.apiFailure().message }
                            busy = false
                        }
                    }.padding(12.dp),
                )
            }
            Spacer(Modifier.navigationBarsPadding())
        }
    }
}

/** «Looking for a driver»: rings spreading out from the pin. */
@Composable
private fun Radar() {
    val t = rememberInfiniteTransition(label = "radar")
    val p by t.animateFloat(0f, 1f, infiniteRepeatable(tween(2200, easing = LinearEasing)), label = "ring")
    Box(Modifier.size(170.dp), contentAlignment = Alignment.Center) {
        Canvas(Modifier.fillMaxSize()) {
            for (i in 0..2) {
                val f = (p + i / 3f) % 1f
                drawCircle(Mint.copy(alpha = (1f - f) * 0.5f), radius = size.minDimension / 2 * f, style = Stroke(width = 6f))
            }
        }
        Box(Modifier.size(62.dp).clip(CircleShape).background(Brush.linearGradient(listOf(Deep, Mint))), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.PersonSearch, null, tint = Color.White, modifier = Modifier.size(32.dp))
        }
    }
}

@Composable
private fun HireList(onBack: () -> Unit, onOpen: (HireDto) -> Unit) {
    var list by remember { mutableStateOf<List<HireDto>?>(null) }
    LaunchedEffect(Unit) { list = runCatching { ApiClient.hire.mine(ridesAuth()).data.orEmpty() }.getOrDefault(emptyList()) }
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().background(Brush.horizontalGradient(listOf(Deep, Mint))).statusBarsPadding().padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            Round(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
            Spacer(Modifier.width(12.dp))
            Text(stringResource(R.string.hire_history), color = Color.White, fontWeight = FontWeight.Black, fontSize = 20.sp)
        }
        val rows = list
        when {
            rows == null -> Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Deep) }
            rows.isEmpty() -> Text(stringResource(R.string.hire_none), color = settingsMut(), textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(36.dp))
            else -> Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                rows.forEachIndexed { i, h ->
                    Row(Modifier.staggerIn(i).fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsCard()).pressable { onOpen(h) }.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                        Dot(Icons.Rounded.PersonSearch)
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(h.pickup.address.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(dayTime(h.startsAt) + " · ${h.quantity} " + stringResource(if (h.unit == "day") R.string.hire_days else R.string.hire_hours), color = settingsMut(), fontSize = 12.sp)
                        }
                        Text(stringResource(statusText(h.status)), color = Deep, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------------- small pieces

@Composable
private fun HirePlaceSearch(near: com.google.android.gms.maps.model.LatLng?, onClose: () -> Unit, onPick: (RidePoint) -> Unit) {
    val context = LocalContext.current
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<RidePoint>>(emptyList()) }
    LaunchedEffect(query) { delay(300); results = searchPlaces(context, query, near) }
    BackHandler(onBack = onClose)
    Column(Modifier.fillMaxSize().background(settingsBackground()).statusBarsPadding().padding(16.dp)) {
        com.dorr.app.ui.components.DorrTextField(value = query, onValueChange = { query = it }, placeholder = stringResource(R.string.rd_search_hint), icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(10.dp))
        Column(Modifier.verticalScroll(rememberScrollState())) {
            results.forEachIndexed { i, p ->
                Row(Modifier.staggerIn(i).fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { onPick(p) }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Place, null, tint = Deep)
                    Spacer(Modifier.width(10.dp))
                    Text(p.address, color = settingsInk(), fontSize = 14.sp)
                }
            }
        }
    }
}

@Composable
private fun HireCard(content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(settingsCard()).padding(16.dp)) { content() }
}

@Composable
private fun <T> Segmented(options: List<Pair<T, Int>>, value: T, onPick: (T) -> Unit) {
    Row(Modifier.fillMaxWidth().clip(CircleShape).background(settingsBackground()).padding(3.dp)) {
        options.forEach { (v, label) ->
            val on = v == value
            val bg by animateColorAsState(if (on) Deep else Color.Transparent, tween(200), label = "seg")
            Text(
                stringResource(label), color = if (on) Color.White else settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp, textAlign = TextAlign.Center,
                modifier = Modifier.weight(1f).clip(CircleShape).background(bg).clickable { onPick(v) }.padding(vertical = 10.dp),
            )
        }
    }
}

@Composable
private fun HireChip(text: String, on: Boolean, enabled: Boolean = true, onClick: () -> Unit) {
    val bg by animateColorAsState(if (on) Deep else settingsBackground(), tween(200), label = "chip")
    Text(
        text, color = if (on) Color.White else if (enabled) settingsInk() else settingsMut().copy(alpha = 0.5f), fontSize = 13.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Medium,
        modifier = Modifier.clip(CircleShape).background(bg).clickable(enabled = enabled, onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
    )
}

@Composable
private fun Step(icon: ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(Modifier.size(38.dp).clip(CircleShape).background(if (enabled) Deep.copy(alpha = 0.12f) else settingsBackground()).clickable(enabled = enabled, onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = if (enabled) Deep else settingsMut(), modifier = Modifier.size(18.dp))
    }
}

@Composable
private fun Dot(icon: ImageVector) {
    Box(Modifier.size(34.dp).clip(CircleShape).background(Deep.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) { Icon(icon, null, tint = Deep, modifier = Modifier.size(18.dp)) }
}

@Composable
private fun Round(icon: ImageVector, onClick: () -> Unit) {
    Box(Modifier.size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = Color.White, modifier = Modifier.size(20.dp))
    }
}

@Composable
private fun Line(label: String, value: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 5.dp)) {
        Text(label, color = settingsMut(), fontSize = 13.sp, modifier = Modifier.weight(0.4f))
        Text(value, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(0.6f))
    }
}

private fun statusText(status: String): Int = when (status) {
    "open" -> R.string.hire_st_open
    "accepted" -> R.string.hire_st_accepted
    "arrived" -> R.string.hire_st_arrived
    "started" -> R.string.hire_st_started
    "completed" -> R.string.hire_st_completed
    "expired" -> R.string.hire_st_expired
    else -> R.string.hire_st_canceled
}

private fun clock(d: Duration): String = d.seconds.let { s -> String.format("%d:%02d:%02d", s / 3600, s % 3600 / 60, s % 60) }

private fun dayTime(iso: String?): String = iso?.let {
    runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("EEE d MMM · h:mm a", AppLocale.java())) }.getOrNull()
} ?: "—"
