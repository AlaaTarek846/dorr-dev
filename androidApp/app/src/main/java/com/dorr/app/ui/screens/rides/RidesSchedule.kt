package com.dorr.app.ui.screens.rides

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.KeyboardArrowUp
import androidx.compose.material.icons.rounded.LocalTaxi
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
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
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideCurrencyDto
import com.dorr.app.network.RideDto
import com.dorr.app.network.RideHistoryItemDto
import com.dorr.app.network.RideSchedulingDto
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.LocalDate
import java.time.LocalTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale
import java.time.format.TextStyle as DateText

/*
 * Booking ahead (R-12, R-105..108) and "My rides" (R-163..165): the time picker (a day strip, then
 * the hour and minutes that roll like a wheel), the "your ride is booked" card with its calendar
 * badge, the free-cancel limit and the captain who reserved it, and the list of current / upcoming /
 * past rides. The policy limits come from the quote; the server checks them again.
 */

private const val MINUTE_STEP = 5L

internal fun parseTime(iso: String?): ZonedDateTime? =
    iso?.let { runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()) }.getOrNull() }

/** "Today · 9:30 AM", "Tomorrow · …", or "Thursday 16 Oct · …" — in the phone's language. */
internal fun whenText(t: ZonedDateTime, today: String, tomorrow: String): String {
    val locale = Locale.getDefault()
    val date = t.toLocalDate()
    val now = LocalDate.now(t.zone)
    val day = when (date) {
        now -> today
        now.plusDays(1) -> tomorrow
        else -> "${date.dayOfWeek.getDisplayName(DateText.FULL, locale)} ${date.dayOfMonth} ${date.month.getDisplayName(DateText.SHORT, locale)}"
    }
    return "$day · ${clock(t)}"
}

internal fun clock(t: ZonedDateTime): String = t.format(DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(Locale.getDefault()))

/** The earliest time the policy allows, rounded up to the next 5 minutes. */
private fun earliest(minAheadS: Long): ZonedDateTime {
    val t = ZonedDateTime.now().plusSeconds(minAheadS).withSecond(0).withNano(0)
    val extra = (MINUTE_STEP - t.minute % MINUTE_STEP) % MINUTE_STEP
    return t.plusMinutes(extra)
}

@Composable
internal fun rememberWhenLabels(): Pair<String, String> =
    stringResource(R.string.rd_schedule_today) to stringResource(R.string.rd_schedule_tomorrow)

/** "Now" | "📅 Book ahead" — on the first card and above the prices. */
@Composable
internal fun WhenChips(state: RidesState) {
    val (today, tomorrow) = rememberWhenLabels()
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        WhenChip(Icons.Rounded.LocalTaxi, stringResource(R.string.rd_now), state.scheduledAt == null) { state.scheduledAt = null }
        val at = state.scheduledAt
        WhenChip(Icons.Rounded.Event, at?.let { whenText(it, today, tomorrow) } ?: stringResource(R.string.rd_schedule), at != null) { state.scheduleOpen = true }
    }
}

@Composable
private fun WhenChip(icon: ImageVector, label: String, selected: Boolean, onClick: () -> Unit) {
    val accent = settingsAccent()
    val bg by animateColorAsState(if (selected) accent else settingsBackground(), tween(220), label = "when-bg")
    val fg by animateColorAsState(if (selected) Color.White else settingsInk(), tween(220), label = "when-fg")
    Row(
        Modifier.clip(CircleShape).background(bg).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = fg, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(label, color = fg, fontSize = 13.sp, fontWeight = if (selected) FontWeight.Bold else FontWeight.SemiBold, maxLines = 1)
    }
}

/** Picking the day and the time; the limits are the category's (min / max ahead, free cancel). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun SchedulePickerSheet(state: RidesState, onClose: () -> Unit) {
    val rules = state.selected?.scheduling ?: state.quotes.firstNotNullOfOrNull { it.scheduling } ?: RideSchedulingDto()
    val first = remember(rules.minAheadS) { earliest(rules.minAheadS) }
    val last = remember(rules.maxAheadS) { ZonedDateTime.now().plusSeconds(rules.maxAheadS) }
    var picked by remember { mutableStateOf(state.scheduledAt?.takeIf { it >= first && it <= last } ?: first) }
    val days = remember(first, last) {
        generateSequence(first.toLocalDate()) { it.plusDays(1) }.takeWhile { !it.isAfter(last.toLocalDate()) }.toList()
    }
    val (today, tomorrow) = rememberWhenLabels()
    val locale = Locale.getDefault()

    fun clamp(t: ZonedDateTime): ZonedDateTime = when {
        t.isBefore(first) -> first
        t.isAfter(last) -> last
        else -> t
    }

    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = settingsCard()) {
        Column(Modifier.padding(horizontal = 20.dp).padding(bottom = 28.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Event, null, tint = settingsAccent())
                }
                Spacer(Modifier.width(10.dp))
                Text(stringResource(R.string.rd_schedule_title), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            }
            Spacer(Modifier.height(16.dp))

            // The days, as a strip of cards.
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(days) { day ->
                    val on = picked.toLocalDate() == day
                    val bg by animateColorAsState(if (on) settingsAccent() else settingsBackground(), tween(220), label = "day")
                    val scale by animateFloatAsState(if (on) 1.06f else 1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy), label = "day-scale")
                    Column(
                        Modifier.scale(scale).width(64.dp).clip(RoundedCornerShape(16.dp)).background(bg)
                            .clickable { picked = clamp(ZonedDateTime.of(day, picked.toLocalTime(), picked.zone)) }.padding(vertical = 10.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        val top = when (day) {
                            LocalDate.now() -> today
                            LocalDate.now().plusDays(1) -> tomorrow
                            else -> day.dayOfWeek.getDisplayName(DateText.SHORT, locale)
                        }
                        Text(top, color = if (on) Color.White.copy(alpha = 0.85f) else settingsMut(), fontSize = 11.sp, maxLines = 1)
                        Text(day.dayOfMonth.toString(), color = if (on) Color.White else settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
                        Text(day.month.getDisplayName(DateText.SHORT, locale), color = if (on) Color.White.copy(alpha = 0.85f) else settingsMut(), fontSize = 11.sp)
                    }
                }
            }
            Spacer(Modifier.height(20.dp))

            // The time: hour and minutes roll up and down.
            Text(stringResource(R.string.rd_schedule_time), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center)
            Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
                WheelColumn(picked.format(DateTimeFormatter.ofPattern("HH")), onUp = { picked = clamp(picked.plusHours(1)) }, onDown = { picked = clamp(picked.minusHours(1)) })
                Text(":", color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 40.sp, modifier = Modifier.padding(horizontal = 6.dp))
                WheelColumn(picked.format(DateTimeFormatter.ofPattern("mm")), onUp = { picked = clamp(picked.plusMinutes(MINUTE_STEP)) }, onDown = { picked = clamp(picked.minusMinutes(MINUTE_STEP)) })
            }
            Text(
                "${clock(picked)} · ${stringResource(R.string.rd_schedule_local)}",
                color = settingsMut(), fontSize = 12.sp, modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center,
            )
            if (picked == first) {
                Text(stringResource(R.string.rd_schedule_earliest, whenText(first, today, tomorrow)), color = AppColors.warning, fontSize = 12.sp, modifier = Modifier.fillMaxWidth().padding(top = 4.dp), textAlign = TextAlign.Center)
            }
            Spacer(Modifier.height(16.dp))

            // The cancellation rule, before confirming (R-105).
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(AppColors.success.copy(alpha = 0.08f)).padding(12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Info, null, tint = AppColors.success, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.rd_schedule_policy, (rules.freeCancelBeforeS / 60).toInt()), color = settingsInk(), fontSize = 12.sp)
            }
            Spacer(Modifier.height(16.dp))
            AccentButton(stringResource(R.string.rd_schedule_confirm)) {
                state.scheduledAt = picked
                onClose()
            }
        }
    }
}

/** One wheel: ▲ / the number (rolls in the direction moved) / ▼. */
@Composable
private fun WheelColumn(value: String, onUp: () -> Unit, onDown: () -> Unit) {
    var direction by remember { mutableStateOf(1) }
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        WheelArrow(Icons.Rounded.KeyboardArrowUp) { direction = 1; onUp() }
        AnimatedContent(
            targetState = value,
            transitionSpec = {
                (slideInVertically(tween(200)) { h -> direction * h } + fadeIn(tween(200))) togetherWith
                    (slideOutVertically(tween(200)) { h -> -direction * h } + fadeOut(tween(160)))
            },
            label = "wheel",
        ) { v ->
            Box(Modifier.size(width = 84.dp, height = 64.dp).clip(RoundedCornerShape(16.dp)).background(settingsBackground()), contentAlignment = Alignment.Center) {
                Text(v, color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 36.sp)
            }
        }
        WheelArrow(Icons.Rounded.KeyboardArrowDown) { direction = -1; onDown() }
    }
}

@Composable
private fun WheelArrow(icon: ImageVector, onClick: () -> Unit) {
    Box(Modifier.size(40.dp).clip(CircleShape).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(30.dp))
    }
}

/** The booked ride, until its time comes: when, where, the captain who took it, cancelling. */
@Composable
internal fun ScheduledPanel(ride: RideDto, onDone: () -> Unit, onCancel: () -> Unit) {
    val at = parseTime(ride.scheduled?.at) ?: return
    val freeUntil = parseTime(ride.scheduled?.freeCancelUntil)
    val startsSearch = parseTime(ride.scheduled?.startsSearchAt)
    val (today, tomorrow) = rememberWhenLabels()
    val locale = Locale.getDefault()

    // The calendar badge pops in once.
    var shown by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { shown = true }
    val pop by animateFloatAsState(if (shown) 1f else 0.6f, spring(dampingRatio = Spring.DampingRatioMediumBouncy, stiffness = Spring.StiffnessLow), label = "badge")

    Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
        Column(
            Modifier.scale(pop).size(width = 74.dp, height = 82.dp).clip(RoundedCornerShape(18.dp)).background(settingsCard())
                .border(BorderStroke(1.dp, settingsInk().copy(alpha = 0.08f)), RoundedCornerShape(18.dp)),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(Modifier.fillMaxWidth().height(24.dp).background(settingsAccent()), contentAlignment = Alignment.Center) {
                Text(at.month.getDisplayName(DateText.SHORT, locale), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 12.sp)
            }
            Text(at.dayOfMonth.toString(), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 30.sp)
            Text(at.dayOfWeek.getDisplayName(DateText.SHORT, locale), color = settingsMut(), fontSize = 10.sp)
        }
        Spacer(Modifier.height(10.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.EventAvailable, null, tint = AppColors.success, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.rd_scheduled_title), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
        }
        Text(whenText(at, today, tomorrow), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 16.sp)
    }
    Spacer(Modifier.height(12.dp))

    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(settingsBackground()).padding(12.dp)) {
        TripLine(AppColors.brandNavy, ride.origin?.address)
        ride.stops.forEach { TripLine(AppColors.warning, it.address) }
        ride.destination?.let { TripLine(AppColors.brandOrange, it.address) }
        val price = ride.agreedPriceMinor ?: ride.estimate?.maxMinor
        if (price != null) {
            Row(Modifier.fillMaxWidth().padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(ride.category?.name.orEmpty(), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.weight(1f))
                Text(money(price, ride.currency), color = settingsInk(), fontWeight = FontWeight.ExtraBold)
            }
        }
    }
    Spacer(Modifier.height(10.dp))

    // The captain who reserved it, or when the search starts.
    val reserved = ride.reservedDriver
    if (reserved != null) {
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(AppColors.success.copy(alpha = 0.08f)).padding(10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.size(42.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                if (reserved.avatarUrl != null) AsyncImage(ApiClient.mediaUrl(reserved.avatarUrl), null, Modifier.size(42.dp).clip(CircleShape))
                else Text(reserved.name?.take(1).orEmpty(), color = settingsAccent(), fontWeight = FontWeight.Bold)
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.rd_scheduled_reserved, reserved.name.orEmpty()), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Star, null, tint = AppColors.warning, modifier = Modifier.size(14.dp))
                    Text(reserved.rating?.let { String.format("%.1f", it) } ?: stringResource(R.string.rd_new_rating), color = settingsMut(), fontSize = 12.sp)
                }
            }
        }
    } else {
        val lead = if (startsSearch != null) Duration.between(startsSearch, at).toMinutes().toInt() else 30
        InfoLine(Icons.Rounded.Schedule, settingsAccent(), stringResource(R.string.rd_scheduled_searching_at, lead))
    }
    if (freeUntil != null && freeUntil.isAfter(ZonedDateTime.now())) {
        InfoLine(Icons.Rounded.CheckCircle, AppColors.success, stringResource(R.string.rd_scheduled_free_until, whenText(freeUntil, today, tomorrow)))
    } else {
        InfoLine(Icons.Rounded.WarningAmber, AppColors.warning, stringResource(R.string.rd_scheduled_free_over))
    }
    Text(stringResource(R.string.rd_scheduled_waiting_note), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(top = 4.dp))

    Spacer(Modifier.height(14.dp))
    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Box(Modifier.weight(1f)) { AccentButton(stringResource(R.string.rd_done), onClick = onDone) }
        Box(
            Modifier.weight(1f).height(54.dp).clip(RoundedCornerShape(16.dp)).border(BorderStroke(1.dp, AppColors.danger.copy(alpha = 0.4f)), RoundedCornerShape(16.dp)).clickable(onClick = onCancel),
            contentAlignment = Alignment.Center,
        ) { Text(stringResource(R.string.rd_scheduled_cancel), color = AppColors.danger, fontWeight = FontWeight.Bold) }
    }
}

@Composable
private fun TripLine(dot: Color, address: String?) {
    Row(Modifier.padding(vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(9.dp).clip(CircleShape).background(dot))
        Spacer(Modifier.width(10.dp))
        Text(address ?: "—", color = settingsInk(), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun InfoLine(icon: ImageVector, tint: Color, text: String) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(8.dp))
        Text(text, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** My rides: current, upcoming (booked ahead) and past — tap one to open it. */
@Composable
internal fun MyRidesPage(state: RidesState, onClose: () -> Unit) {
    val scope = rememberCoroutineScope()
    val tabs = listOf("current" to stringResource(R.string.rd_tab_current), "scheduled" to stringResource(R.string.rd_tab_scheduled), "past" to stringResource(R.string.rd_tab_past))
    var tab by remember { mutableStateOf(if (state.ride == null) "scheduled" else "current") }
    var items by remember { mutableStateOf<List<RideHistoryItemDto>?>(null) }
    val (today, tomorrow) = rememberWhenLabels()

    LaunchedEffect(tab) {
        items = null
        items = runCatching { ApiClient.rides.history(ridesAuth(), tab).data?.items.orEmpty() }.getOrDefault(emptyList())
    }

    Column(Modifier.fillMaxSize().background(settingsBackground()).statusBarsPadding()) {
        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            RoundButton(Icons.AutoMirrored.Rounded.ArrowBack, onClick = onClose)
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.rd_my_rides), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp, modifier = Modifier.weight(1f))
            // R-133..R-140: my complaints and disputes.
            Text(
                stringResource(R.string.rd_cases_mine), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 13.sp,
                modifier = Modifier.clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)).clickable { state.caseRideUuid = null; state.caseStartNew = false; state.casesOpen = true }.padding(horizontal = 14.dp, vertical = 8.dp),
            )
        }
        // The tabs, a pill that slides to the chosen one.
        Row(Modifier.padding(horizontal = 16.dp).fillMaxWidth().clip(CircleShape).background(settingsCard()).padding(4.dp)) {
            tabs.forEach { (key, label) ->
                val on = key == tab
                val bg by animateColorAsState(if (on) settingsAccent() else Color.Transparent, tween(240), label = "tab")
                Box(Modifier.weight(1f).clip(CircleShape).background(bg).clickable { tab = key }.padding(vertical = 10.dp), contentAlignment = Alignment.Center) {
                    Text(label, color = if (on) Color.White else settingsInk(), fontWeight = if (on) FontWeight.Bold else FontWeight.SemiBold, fontSize = 14.sp)
                }
            }
        }
        Spacer(Modifier.height(10.dp))
        AnimatedContent(targetState = items, transitionSpec = { fadeIn(tween(220)) togetherWith fadeOut(tween(120)) }, label = "my-rides") { list ->
            when {
                list == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { androidx.compose.material3.CircularProgressIndicator(color = settingsAccent()) }
                list.isEmpty() -> Column(Modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                    Box(Modifier.size(72.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                        Icon(if (tab == "scheduled") Icons.Rounded.Event else Icons.Rounded.LocalTaxi, null, tint = settingsAccent(), modifier = Modifier.size(34.dp))
                    }
                    Spacer(Modifier.height(10.dp))
                    Text(stringResource(R.string.rd_my_rides_empty), color = settingsMut())
                }
                else -> LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(list, key = { it.uuid }) { item ->
                        HistoryCard(item, today, tomorrow, onReport = if (tab == "past" && item.status in setOf("completed", "canceled", "no_show")) {
                            { state.caseRideUuid = item.uuid; state.caseStartNew = true; state.casesOpen = true }
                        } else null) {
                            scope.launch {
                                runCatching { ApiClient.rides.ride(ridesAuth(), item.uuid).data }.getOrNull()?.let {
                                    state.ride = it
                                    state.fromHistory = true
                                    onClose()
                                }
                            }
                        }
                    }
                    item { Spacer(Modifier.height(16.dp)) }
                }
            }
        }
    }
}

@Composable
private fun HistoryCard(item: RideHistoryItemDto, today: String, tomorrow: String, onReport: (() -> Unit)? = null, onClick: () -> Unit) {
    val (label, color) = statusLook(item.status)
    val time = parseTime(item.scheduledAt ?: item.requestedAt)
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsCard()).clickable(onClick = onClick).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(color.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(if (item.status == "scheduled") Icons.Rounded.Event else Icons.Rounded.LocalTaxi, null, tint = color, modifier = Modifier.size(22.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(item.category.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold)
                time?.let { Text(whenText(it, today, tomorrow), color = settingsMut(), fontSize = 12.sp) }
            }
            Text(label, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp))
        }
        Spacer(Modifier.height(8.dp))
        TripLine(AppColors.brandNavy, item.originAddress)
        item.destinationAddress?.let { TripLine(AppColors.brandOrange, it) }
        if (item.totalMinor != null || item.driverName != null) {
            Row(Modifier.fillMaxWidth().padding(top = 6.dp)) {
                Text(item.driverName.orEmpty(), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.weight(1f))
                item.totalMinor?.let { Text(money(it, RideCurrencyDto(symbol = item.currency)), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp) }
            }
        }
        onReport?.let {
            Text(
                stringResource(R.string.rd_cases_report), color = settingsAccent(), fontWeight = FontWeight.SemiBold, fontSize = 12.sp,
                modifier = Modifier.padding(top = 6.dp).clip(CircleShape).clickable(onClick = it).padding(vertical = 4.dp),
            )
        }
    }
}

@Composable
private fun statusLook(status: String): Pair<String, Color> = when (status) {
    "scheduled" -> stringResource(R.string.rd_status_scheduled) to settingsAccent()
    "searching", "bidding", "awaiting_payment" -> stringResource(R.string.rd_status_searching) to AppColors.warning
    "accepted" -> stringResource(R.string.rd_status_accepted) to AppColors.brandNavy
    "arrived" -> stringResource(R.string.rd_status_arrived) to AppColors.brandNavy
    "in_progress" -> stringResource(R.string.rd_status_in_progress) to AppColors.brandNavy
    "completed" -> stringResource(R.string.rd_status_completed) to AppColors.success
    "no_show" -> stringResource(R.string.rd_status_no_show) to AppColors.danger
    "expired" -> stringResource(R.string.rd_status_expired) to settingsMut()
    else -> stringResource(R.string.rd_status_canceled) to AppColors.danger
}

