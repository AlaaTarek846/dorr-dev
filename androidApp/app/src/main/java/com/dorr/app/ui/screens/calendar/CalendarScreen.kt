package com.dorr.app.ui.screens.calendar

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Alarm
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.SportsSoccer
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.WbSunny
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.CalAroundDto
import com.dorr.app.network.CalItemDto
import com.dorr.app.network.CalTodayDto
import com.dorr.app.ui.screens.moments.momentBrush
import com.dorr.app.ui.screens.moments.momentColor
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.theme.AppColors
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaCircleButton
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.YearMonth
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.time.format.TextStyle
import java.time.temporal.WeekFields
import java.util.Locale

/*
 * DORR Calendar & DORR Today (spec 201–207): the home page's "my day" card and the calendar —
 * Today (in my order), the week and the month — from only the sources I keep on. Times are shown
 * where I am now; an appointment set elsewhere also shows its own time there.
 */

internal fun calAuth() = "Bearer ${AuthSession.token.orEmpty()}"

internal fun zoneId(): String = ZoneId.systemDefault().id

internal fun localTime(iso: String?): ZonedDateTime? = runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()) }.getOrNull()

internal fun timeText(iso: String?): String =
    localTime(iso)?.format(DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(Locale.getDefault())).orEmpty()

internal fun dayTitle(date: LocalDate): String =
    date.format(DateTimeFormatter.ofPattern(if (Locale.getDefault().language == "ar") "EEEE، d MMMM" else "EEEE, d MMMM", Locale.getDefault()))

internal fun shortDay(date: LocalDate): String = date.format(DateTimeFormatter.ofPattern("d MMM", Locale.getDefault()))

/** The colour of each kind of item — the same on Home, the lists, the grid's dots. */
@Composable
internal fun typeColor(item: CalItemDto): Color = when (item.type) {
    "event" -> momentColor(item.color, Wa.Red)
    "task" -> Color(0xFF16A34A)
    "reminder" -> Color(0xFFD97706)
    "moment", "personal" -> momentColor(item.color, Color(0xFFDB2777))
    "discover" -> Color(0xFF7C3AED)
    "sports" -> Color(0xFF0E9F6E)
    else -> Wa.Mut
}

internal fun typeIcon(type: String): ImageVector = when (type) {
    "task" -> Icons.Rounded.CheckCircle
    "reminder" -> Icons.Rounded.Alarm
    "capsule" -> Icons.Rounded.Inventory2
    "discover" -> Icons.Rounded.Place
    "sports" -> Icons.Rounded.SportsSoccer
    else -> Icons.Rounded.Event
}

/** Today's page, shared by the home card and the calendar (one load, kept fresh). */
@Stable
object CalendarStore {
    var today by mutableStateOf<CalTodayDto?>(null)
    var version by mutableStateOf(0)
        private set

    suspend fun refresh() {
        runCatching { ApiClient.calendar.today(calAuth(), zoneId()).data }.getOrNull()?.let { today = it }
    }

    /** Something changed (added, edited, ticked): every view reloads. */
    suspend fun changed() {
        version++
        refresh()
    }
}

// =============================================================================== home: my day

/**
 * DORR Today on the home page — same card as the wallet row: icon, two lines, accent pill.
 * Hidden when the calendar is off.
 */
@Composable
fun TodayCard(onOpen: () -> Unit, modifier: Modifier = Modifier) {
    LaunchedEffect(Unit) { CalendarStore.refresh() }
    val today = CalendarStore.today ?: return
    if (!today.enabled) return
    val count = today.events.size + today.tasks.count { !it.done } + today.reminders.size
    val night = settingsNight()
    val accent = settingsAccent()
    val cardShape = RoundedCornerShape(20.dp)
    val mut = if (night) AccountDark.mut else AppColors.textSecondary
    val nextLine = today.next?.let { listOfNotNull(timeText(it.startsAt).takeIf { t -> t.isNotEmpty() }, it.title).joinToString("  ·  ") }
        ?: today.moments.firstOrNull()?.let { "${it.emoji ?: "✨"}  ${it.title}" }

    Row(
        modifier = modifier
            .fillMaxWidth()
            .padding(vertical = 12.dp)
            .then(if (night) Modifier else Modifier.shadow(10.dp, cardShape, spotColor = accent.copy(alpha = 0.08f)))
            .clip(cardShape)
            .background(settingsCard())
            .then(if (night) Modifier.border(1.dp, AccountDark.line, cardShape) else Modifier)
            .clickable(onClick = onOpen)
            .padding(12.dp, 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(42.dp)
                .clip(RoundedCornerShape(15.dp))
                .background(if (night) AccountDark.well else accent.copy(alpha = 0.14f)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Rounded.CalendarMonth, contentDescription = null, tint = if (night) AccountDark.accent else accent, modifier = Modifier.size(22.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(modifier = Modifier.weight(1f)) {
            if (count == 0) {
                Text(
                    stringResource(R.string.cal_home_free),
                    fontSize = 12.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = mut,
                )
                Text(
                    dayTitle(runCatching { LocalDate.parse(today.date) }.getOrNull() ?: LocalDate.now()),
                    fontSize = 16.sp,
                    fontWeight = FontWeight.ExtraBold,
                    color = settingsInk(),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            } else {
                Text(
                    stringResource(R.string.cal_home_label),
                    fontSize = 12.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = mut,
                )
                Row(verticalAlignment = Alignment.Bottom) {
                    Text(
                        count.toString(),
                        fontSize = 20.sp,
                        fontWeight = FontWeight.ExtraBold,
                        color = settingsInk(),
                        modifier = Modifier.alignByBaseline(),
                    )
                    Spacer(Modifier.width(4.dp))
                    Text(
                        stringResource(R.string.cal_home_things),
                        fontSize = 12.sp,
                        fontWeight = FontWeight.Bold,
                        color = mut,
                        modifier = Modifier.alignByBaseline(),
                    )
                }
            }
            Text(
                nextLine ?: stringResource(R.string.cal_home_add),
                fontSize = 12.sp,
                fontWeight = FontWeight.SemiBold,
                color = mut,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
        Box(
            modifier = Modifier
                .shadow(8.dp, RoundedCornerShape(50), spotColor = accent.copy(alpha = 0.25f))
                .clip(RoundedCornerShape(50))
                .background(accent)
                .clickable(onClick = onOpen)
                .padding(horizontal = 14.dp, vertical = 8.dp),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                stringResource(R.string.cal_home_open),
                color = Color.White,
                fontSize = 12.sp,
                fontWeight = FontWeight.ExtraBold,
            )
        }
    }
}

// =============================================================================== the screen

private sealed interface CalPage {
    data object Main : CalPage
    data object Settings : CalPage
    data object Search : CalPage
}

@Composable
fun CalendarScreen(onExit: () -> Unit, onOpenMoments: () -> Unit = {}) {
    var page by remember { mutableStateOf<CalPage>(CalPage.Main) }
    BackHandler { if (page != CalPage.Main) page = CalPage.Main else onExit() }
    val sign = if (LocalLayoutDirection.current == LayoutDirection.Rtl) -1 else 1

    Box(Modifier.fillMaxSize().background(Wa.Bg).systemBarsPadding().imePadding()) {
        AnimatedContent(
            targetState = page,
            transitionSpec = {
                val forward = targetState != CalPage.Main
                ContentTransform(
                    slideInHorizontally(tween(320)) { (if (forward) sign else -sign) * it / 6 } + fadeIn(tween(280)),
                    slideOutHorizontally(tween(280)) { (if (forward) -sign else sign) * it / 10 } + fadeOut(tween(200)),
                )
            },
            label = "calendarPages",
        ) { p ->
            when (p) {
                CalPage.Main -> CalendarMain(onBack = onExit, onSettings = { page = CalPage.Settings }, onSearch = { page = CalPage.Search }, onOpenMoments = onOpenMoments)
                CalPage.Settings -> CalendarSettings(onBack = { page = CalPage.Main })
                CalPage.Search -> CalendarSearch(onBack = { page = CalPage.Main }, onOpenMoments = onOpenMoments)
            }
        }
    }
}

private enum class CalTab { Today, Week, Month }

@Composable
private fun CalendarMain(onBack: () -> Unit, onSettings: () -> Unit, onSearch: () -> Unit, onOpenMoments: () -> Unit) {
    var tab by remember { mutableStateOf(CalTab.Today) }
    var adding by remember { mutableStateOf<LocalDate?>(null) }
    var open by remember { mutableStateOf<CalItemDto?>(null) }
    var selected by remember { mutableStateOf(LocalDate.now()) }

    WaPage(
        title = stringResource(R.string.cal_title),
        onBack = onBack,
        actions = {
            WaCircleButton(Icons.Rounded.Search, onSearch, contentDescription = stringResource(R.string.cal_search))
            WaCircleButton(Icons.Rounded.Tune, onSettings, contentDescription = stringResource(R.string.cal_settings))
        },
        cta = { WaButton(stringResource(R.string.cal_add), { adding = if (tab == CalTab.Today) LocalDate.now() else selected }, icon = Icons.Rounded.Add) },
    ) {
        Tabs(tab) { tab = it }
        Spacer(Modifier.height(14.dp))
        when (tab) {
            CalTab.Today -> TodayTab(onOpen = { open = it }, onSettings = onSettings)
            CalTab.Week -> RangeTab(month = false, selected = selected, onSelect = { selected = it }, onOpen = { open = it })
            CalTab.Month -> RangeTab(month = true, selected = selected, onSelect = { selected = it }, onOpen = { open = it })
        }
    }

    adding?.let { day -> EventEditorSheet(existing = null, day = day) { adding = null } }
    open?.let { item ->
        if (item.type == "sports") {
            LaunchedEffect(item.id) { com.dorr.app.ui.screens.sports.SportsLink.show(item.ref); open = null }
        } else if (item.type == "discover") {
            LaunchedEffect(item.id) { com.dorr.app.ui.screens.events.EventsLink.show(item.ref); open = null }
        } else {
            ItemSheet(item, onDismiss = { open = null }, onOpenMoments = onOpenMoments)
        }
    }
}

@Composable
private fun Tabs(tab: CalTab, onPick: (CalTab) -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Wa.Surface).padding(4.dp)) {
        listOf(CalTab.Today to R.string.cal_today, CalTab.Week to R.string.cal_week, CalTab.Month to R.string.cal_month).forEach { (t, label) ->
            val on = t == tab
            val bg by animateColorAsState(if (on) Wa.Red else Color.Transparent, label = "tab")
            Text(
                stringResource(label), color = if (on) Color.White else Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                modifier = Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(bg).clickable { onPick(t) }.padding(vertical = 10.dp),
            )
        }
    }
}

// =============================================================================== today (202, 207)

@Composable
private fun TodayTab(onOpen: (CalItemDto) -> Unit, onSettings: () -> Unit) {
    val version = CalendarStore.version
    LaunchedEffect(version) { CalendarStore.refresh() }
    val today = CalendarStore.today
    val scope = rememberCoroutineScope()

    if (today == null) {
        repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(80.dp).padding(bottom = 10.dp), RoundedCornerShape(22.dp)) }
        return
    }
    if (!today.enabled) {
        WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.CalendarMonth, Tone.Gray, stringResource(R.string.cal_off_title), stringResource(R.string.cal_off_text)) }
        return
    }

    val date = runCatching { LocalDate.parse(today.date) }.getOrDefault(LocalDate.now())
    Text(dayTitle(date), color = Wa.Ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.waRise(0))
    val empty = today.next == null && today.events.isEmpty() && today.tasks.isEmpty() && today.reminders.isEmpty() && today.moments.isEmpty()
    Text(stringResource(if (empty) R.string.cal_today_free else R.string.cal_today_sub), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.waRise(0))

    val order = today.sections?.order?.takeIf { it.isNotEmpty() } ?: listOf("next", "events", "tasks", "reminders", "moments", "around")
    val hidden = today.sections?.hidden.orEmpty()
    order.filterNot { it in hidden }.forEachIndexed { i, section ->
        val rise = Modifier.waRise(1 + i)
        when (section) {
            "next" -> today.next?.let { NextCard(it, rise.padding(top = 14.dp)) { onOpen(it) } }
            "events" -> if (today.events.isNotEmpty()) {
                WaSectionTitle(stringResource(R.string.cal_sec_events), rise)
                today.events.forEach { ItemRow(it, rise.padding(bottom = 8.dp)) { onOpen(it) } }
            }
            "tasks" -> if (today.tasks.isNotEmpty()) {
                WaSectionTitle(stringResource(R.string.cal_sec_tasks), rise)
                today.tasks.forEach { t ->
                    ItemRow(t, rise.padding(bottom = 8.dp), onToggle = {
                        scope.launch {
                            runCatching { ApiClient.aiTools.updateTask(calAuth(), t.ref, JsonObject().apply { addProperty("done", !t.done) }) }
                            CalendarStore.changed()
                        }
                    }) { onOpen(t) }
                }
            }
            "reminders" -> if (today.reminders.isNotEmpty()) {
                WaSectionTitle(stringResource(R.string.cal_sec_reminders), rise)
                today.reminders.forEach { ItemRow(it, rise.padding(bottom = 8.dp)) { onOpen(it) } }
            }
            "moments" -> if (today.moments.isNotEmpty()) {
                WaSectionTitle(stringResource(R.string.cal_sec_moments), rise)
                LazyRow(rise, horizontalArrangement = Arrangement.spacedBy(10.dp)) { items(today.moments, key = { it.id }) { m -> OccasionTile(m) { onOpen(m) } } }
            }
            "around" -> today.around?.let { AroundCard(it, rise.padding(top = 16.dp), onOpen = onOpen, onEdit = onSettings) }
        }
    }
    if (empty && today.around == null) {
        Spacer(Modifier.height(20.dp))
        WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.WbSunny, Tone.Amber, stringResource(R.string.cal_empty_title), stringResource(R.string.cal_empty_text)) }
    }
}

/** What's next today: a bold card with how long until it starts. */
@Composable
private fun NextCard(item: CalItemDto, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val start = localTime(item.startsAt)
    val now = ZonedDateTime.now()
    val minutes = start?.let { Duration.between(now, it).toMinutes() } ?: 0
    Column(
        modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(Brush.linearGradient(listOf(typeColor(item), typeColor(item).copy(alpha = 0.7f))))
            .clickable(onClick = onClick).padding(18.dp),
    ) {
        Text(stringResource(R.string.cal_next), color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp, fontWeight = FontWeight.Bold)
        Text(item.title, color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
        Spacer(Modifier.height(6.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                if (minutes <= 0) stringResource(R.string.cal_now) else if (minutes < 60) stringResource(R.string.cal_in_minutes, minutes) else stringResource(R.string.cal_in_hours, minutes / 60, minutes % 60),
                color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(CircleShape).background(Color.White).padding(horizontal = 10.dp, vertical = 4.dp),
            )
            Spacer(Modifier.width(8.dp))
            Text(timeText(item.startsAt), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.Bold)
            item.location?.let {
                Spacer(Modifier.width(8.dp))
                Icon(Icons.Rounded.Place, null, tint = Color.White.copy(alpha = 0.9f), modifier = Modifier.size(15.dp))
                Text(it, color = Color.White.copy(alpha = 0.9f), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

/** One item: its time, a colour bar for its kind, title and where it's from. */
@Composable
internal fun ItemRow(item: CalItemDto, modifier: Modifier = Modifier, onToggle: (() -> Unit)? = null, onClick: () -> Unit) {
    val color = typeColor(item)
    Row(
        modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Wa.Surface).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 11.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.width(52.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            if (item.allDay || item.startsAt == null) Text(stringResource(R.string.cal_all_day_short), color = Wa.Mut, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
            else Text(timeText(item.startsAt), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
            item.endsAt?.let { Text(timeText(it), color = Wa.Soft, fontSize = 11.sp) }
        }
        Box(Modifier.padding(horizontal = 8.dp).width(4.dp).height(36.dp).clip(CircleShape).background(color))
        Column(Modifier.weight(1f)) {
            Text(
                listOfNotNull(item.emoji, item.title).joinToString(" "), color = if (item.done) Wa.Soft else Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Bold,
                maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
            val sub = listOfNotNull(
                if (item.overdue) stringResource(R.string.cal_overdue) else null,
                item.originTime?.let { stringResource(R.string.cal_origin_time, it, item.timezone.orEmpty().substringAfterLast('/').replace('_', ' ')) },
                item.location,
                item.turns?.let { stringResource(R.string.mo_turns, it) },
                when (item.type) { "task" -> stringResource(R.string.cal_kind_task); "reminder" -> stringResource(R.string.cal_kind_reminder); else -> null },
            )
            if (sub.isNotEmpty()) Text(sub.joinToString("  ·  "), color = if (item.overdue) Wa.Danger else Wa.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        if (onToggle != null) {
            val tick by animateFloatAsState(if (item.done) 1f else 0.9f, spring(dampingRatio = 0.4f), label = "tick")
            Icon(
                if (item.done) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (item.done) Color(0xFF16A34A) else Wa.Soft,
                modifier = Modifier.size(26.dp).scale(tick).clip(CircleShape).clickable(onClick = onToggle),
            )
        } else {
            Icon(typeIcon(item.type), null, tint = color.copy(alpha = 0.8f), modifier = Modifier.size(20.dp))
        }
    }
}

@Composable
private fun OccasionTile(item: CalItemDto, onClick: () -> Unit) {
    Column(
        Modifier.width(132.dp).height(104.dp).clip(RoundedCornerShape(20.dp)).background(momentBrush(item.color, item.secondaryColor ?: item.color)).clickable(onClick = onClick).padding(12.dp),
        verticalArrangement = Arrangement.SpaceBetween,
    ) {
        Text(item.emoji ?: "✨", fontSize = 28.sp)
        Text(item.title, color = Color.White, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
    }
}

/** "Around my interests" (207): this week, what I might miss, and what it's based on. */
@Composable
private fun AroundCard(around: CalAroundDto, modifier: Modifier = Modifier, onOpen: (CalItemDto) -> Unit, onEdit: () -> Unit) {
    WaCard(modifier.fillMaxWidth(), padding = 16.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("🧭", fontSize = 22.sp)
            Spacer(Modifier.width(8.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.cal_around), color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.cal_around_week, around.weekCount), color = Wa.Mut, fontSize = 12.5.sp)
            }
            Text(stringResource(R.string.cal_edit_interests), color = Wa.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).clickable(onClick = onEdit).padding(6.dp))
        }
        if (around.mightMiss.isNotEmpty()) {
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.cal_might_miss), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
            around.mightMiss.forEach { m ->
                Row(Modifier.padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(7.dp).clip(CircleShape).background(if (m.kind == "overdue_tasks") Wa.Danger else Color(0xFFF59E0B)))
                    Spacer(Modifier.width(8.dp))
                    Text(
                        when (m.kind) {
                            "overdue_tasks" -> stringResource(R.string.cal_miss_overdue, m.count ?: 0)
                            "personal_soon" -> stringResource(R.string.cal_miss_personal, (m.emoji ?: "🎉") + " " + m.title.orEmpty(), m.date?.let { runCatching { shortDay(LocalDate.parse(it)) }.getOrNull() }.orEmpty())
                            "early_tomorrow" -> stringResource(R.string.cal_miss_early, m.title.orEmpty(), timeText(m.startsAt))
                            else -> m.title.orEmpty()
                        },
                        color = Wa.Ink, fontSize = 13.5.sp,
                    )
                }
            }
        }
        if (around.coming.isNotEmpty()) {
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.cal_coming), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
            around.coming.take(4).forEach { c ->
                Row(Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(12.dp)).clickable { onOpen(c) }.padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(c.date?.let { runCatching { shortDay(LocalDate.parse(it)) }.getOrNull() }.orEmpty(), color = typeColor(c), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.width(56.dp))
                    Text(listOfNotNull(c.emoji, c.title).joinToString(" "), color = Wa.Ink, fontSize = 13.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
        }
        around.basedOn?.let { b ->
            Spacer(Modifier.height(10.dp))
            Text(
                stringResource(R.string.cal_based_on, listOfNotNull(b.country, if (b.pickedOccasions > 0) stringResource(R.string.cal_picked_n, b.pickedOccasions) else null).joinToString(" · ").ifEmpty { "—" }),
                color = Wa.Soft, fontSize = 11.5.sp,
            )
        }
    }
}

// =============================================================================== week & month (203)

private val Filters = listOf("all" to R.string.cal_f_all, "event" to R.string.cal_f_events, "task" to R.string.cal_f_tasks, "reminder" to R.string.cal_f_reminders, "occasion" to R.string.cal_f_occasions)

@Composable
private fun RangeTab(month: Boolean, selected: LocalDate, onSelect: (LocalDate) -> Unit, onOpen: (CalItemDto) -> Unit) {
    val firstDay = WeekFields.of(Locale.getDefault()).firstDayOfWeek
    var anchor by remember(month) { mutableStateOf(selected) }
    var filter by remember { mutableStateOf("all") }
    var items by remember { mutableStateOf<List<CalItemDto>?>(null) }
    var enabled by remember { mutableStateOf(true) }
    val version = CalendarStore.version

    // The visible days: one week, or the month's grid (whole weeks).
    val days: List<LocalDate> = remember(anchor, month, firstDay) {
        val start = if (month) YearMonth.from(anchor).atDay(1) else anchor
        var first = start
        while (first.dayOfWeek != firstDay) first = first.minusDays(1)
        val count = if (month) {
            var last = YearMonth.from(anchor).atEndOfMonth()
            while (last.plusDays(1).dayOfWeek != firstDay) last = last.plusDays(1)
            (java.time.temporal.ChronoUnit.DAYS.between(first, last) + 1).toInt()
        } else 7
        List(count) { first.plusDays(it.toLong()) }
    }
    LaunchedEffect(days.first(), days.last(), version) {
        items = null
        runCatching { ApiClient.calendar.feed(calAuth(), days.first().toString(), days.last().toString(), zoneId()).data }
            .onSuccess { enabled = it?.enabled != false; items = it?.items.orEmpty() }
            .onFailure { items = emptyList() }
    }
    val shown = items.orEmpty().filter { f ->
        when (filter) {
            "all" -> true
            "occasion" -> f.type == "moment" || f.type == "personal"
            else -> f.type == filter
        }
    }
    val byDay = remember(shown) {
        shown.flatMap { i ->
            val start = runCatching { LocalDate.parse(i.date) }.getOrNull() ?: return@flatMap emptyList()
            val end = i.endDate?.let { runCatching { LocalDate.parse(it) }.getOrNull() } ?: start
            generateSequence(start) { d -> d.plusDays(1).takeIf { !it.isAfter(end) } }.map { it to i }.toList()
        }.groupBy({ it.first }, { it.second })
    }

    if (!enabled) {
        WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.CalendarMonth, Tone.Gray, stringResource(R.string.cal_off_title), stringResource(R.string.cal_off_text)) }
        return
    }

    // Header: ‹ range ›
    Row(Modifier.fillMaxWidth().waRise(0), verticalAlignment = Alignment.CenterVertically) {
        NavArrow(Icons.AutoMirrored.Rounded.KeyboardArrowLeft) { anchor = if (month) anchor.minusMonths(1) else anchor.minusWeeks(1) }
        Text(
            if (month) YearMonth.from(anchor).format(DateTimeFormatter.ofPattern("MMMM yyyy", Locale.getDefault()))
            else shortDay(days.first()) + " – " + shortDay(days.last()),
            color = Wa.Ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, modifier = Modifier.weight(1f),
        )
        NavArrow(Icons.AutoMirrored.Rounded.KeyboardArrowRight) { anchor = if (month) anchor.plusMonths(1) else anchor.plusWeeks(1) }
    }
    Spacer(Modifier.height(10.dp))

    // Weekday names.
    Row(Modifier.fillMaxWidth()) {
        days.take(7).forEach { d ->
            Text(d.dayOfWeek.getDisplayName(TextStyle.SHORT, Locale.getDefault()), color = Wa.Mut, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, modifier = Modifier.weight(1f))
        }
    }
    Spacer(Modifier.height(6.dp))
    days.chunked(7).forEach { week ->
        Row(Modifier.fillMaxWidth().padding(bottom = 4.dp)) {
            week.forEach { d ->
                DayCell(
                    d, selected = d == selected, today = d == LocalDate.now(), outside = month && d.month != YearMonth.from(anchor).month,
                    dots = byDay[d].orEmpty().map { typeColor(it) }.distinct().take(3), tall = !month, modifier = Modifier.weight(1f),
                ) { onSelect(d) }
            }
        }
    }

    // Filters.
    LazyRow(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        items(Filters, key = { it.first }) { (key, label) ->
            val on = filter == key
            Text(
                stringResource(label), color = if (on) Color.White else Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(CircleShape).background(if (on) Wa.Red else Wa.Surface).border(1.dp, if (on) Color.Transparent else Wa.Line, CircleShape)
                    .clickable { filter = key }.padding(horizontal = 12.dp, vertical = 7.dp),
            )
        }
    }

    WaSectionTitle(dayTitle(selected))
    when {
        items == null -> repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(62.dp).padding(bottom = 8.dp), RoundedCornerShape(18.dp)) }
        byDay[selected].isNullOrEmpty() -> Text(stringResource(R.string.cal_day_empty), color = Wa.Mut, fontSize = 13.5.sp, modifier = Modifier.padding(vertical = 8.dp))
        else -> byDay[selected].orEmpty().forEachIndexed { i, item -> ItemRow(item, Modifier.padding(bottom = 8.dp).waRise(i)) { onOpen(item) } }
    }
}

@Composable
private fun NavArrow(icon: ImageVector, onClick: () -> Unit) {
    Box(Modifier.size(36.dp).clip(CircleShape).background(Wa.Surface).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = Wa.Red, modifier = Modifier.size(22.dp))
    }
}

@Composable
private fun DayCell(day: LocalDate, selected: Boolean, today: Boolean, outside: Boolean, dots: List<Color>, tall: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) Wa.Red else Color.Transparent, label = "day")
    Column(
        modifier.padding(2.dp).then(if (tall) Modifier.height(62.dp) else Modifier.aspectRatio(0.9f)).clip(RoundedCornerShape(14.dp)).background(bg)
            .then(if (today && !selected) Modifier.border(1.5.dp, Wa.Red, RoundedCornerShape(14.dp)) else Modifier)
            .clickable(onClick = onClick),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center,
    ) {
        Text(
            day.dayOfMonth.toString(), fontSize = 15.sp, fontWeight = if (today || selected) FontWeight.ExtraBold else FontWeight.SemiBold,
            color = when { selected -> Color.White; outside -> Wa.Soft; else -> Wa.Ink },
        )
        Row(Modifier.height(8.dp).padding(top = 3.dp), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
            dots.forEach { c -> Box(Modifier.size(5.dp).clip(CircleShape).background(if (selected) Color.White else c)) }
        }
    }
}
