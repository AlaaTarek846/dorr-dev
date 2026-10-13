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
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
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
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Public
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
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppLocale
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
import java.time.format.DecimalStyle
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

/**
 * The app's language, not the phone's: the in-app language is a wrapped Context (see LocalizedApp), so
 * `Locale.getDefault()` is still the device's — and the calendar would name days, months and times in one language
 * under texts in another, with Latin digits next to Arabic ones.
 */
internal fun calLocale(): Locale = Locale.forLanguageTag(AppLocale.current)

private fun DateTimeFormatter.inLocale(locale: Locale): DateTimeFormatter = withLocale(locale).withDecimalStyle(DecimalStyle.of(locale))

internal fun timeFormatter(locale: Locale = calLocale()): DateTimeFormatter = DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).inLocale(locale)

internal fun dateFormatter(locale: Locale = calLocale()): DateTimeFormatter = DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).inLocale(locale)

internal fun timeText(iso: String?): String = localTime(iso)?.format(timeFormatter()).orEmpty()

internal fun dayTitle(date: LocalDate, locale: Locale = calLocale()): String =
    date.format(DateTimeFormatter.ofPattern(if (locale.language == "ar") "EEEE، d MMMM" else "EEEE, d MMMM", locale).inLocale(locale))

internal fun shortDay(date: LocalDate, locale: Locale = calLocale()): String =
    date.format(DateTimeFormatter.ofPattern("d MMM", locale).inLocale(locale))

/** The day number in the app's digits. */
internal fun dayNumber(day: LocalDate, locale: Locale = calLocale()): String = day.format(DateTimeFormatter.ofPattern("d", locale).inLocale(locale))

internal fun editorDay(item: CalItemDto): LocalDate = runCatching { LocalDate.parse(item.date) }.getOrDefault(LocalDate.now())

internal fun monthTitle(month: YearMonth, locale: Locale = calLocale()): String =
    month.format(DateTimeFormatter.ofPattern("MMMM yyyy", locale).inLocale(locale))

internal fun weekdayShort(day: java.time.DayOfWeek, locale: Locale = calLocale()): String = day.getDisplayName(TextStyle.SHORT, locale)

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

/** The icon of one item: an appointment shows its kind (a match, a trip…), the rest their type. */
internal fun itemIcon(item: CalItemDto): ImageVector = if (item.type == "event") categoryIcon(item.category) else typeIcon(item.type)

internal fun typeIcon(type: String): ImageVector = when (type) {
    "task" -> Icons.Rounded.CheckCircle
    "reminder" -> Icons.Rounded.Alarm
    "capsule" -> Icons.Rounded.Inventory2
    "discover" -> Icons.Rounded.Place
    "sports" -> Icons.Rounded.SportsSoccer
    else -> Icons.Rounded.Event
}

/** The kinds Today's list is grouped by, in their default order (the person can reorder or hide them in Settings). */
internal val DefaultSections = listOf("events", "tasks", "reminders", "moments")

/** Which of those kinds an item is: appointments, Discover events and matches are "events"; own dates are "moments". */
internal fun sectionOf(item: CalItemDto): String = when (item.type) {
    "task" -> "tasks"
    "reminder" -> "reminders"
    "moment", "personal" -> "moments"
    else -> "events"
}

/**
 * A kind shows only while one of its sources is on (Settings → what shows in my calendar): with my tasks off there are
 * no tasks on Today at all.
 */
internal fun sectionSourcesOn(section: String, sources: List<String>?): Boolean {
    if (sources == null) return true
    val needs = when (section) {
        "events" -> listOf("events", "discover", "sports")
        "tasks" -> listOf("tasks")
        "reminders" -> listOf("reminders")
        "moments" -> listOf("moments", "personal")
        else -> return true
    }
    return needs.any { it in sources }
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
    LaunchedEffect(com.dorr.app.network.collectReconnectTick()) { CalendarStore.refresh() }
    val today = CalendarStore.today
    val hidden = today?.sections?.hidden.orEmpty()
    val count = if (today == null || !today.enabled) {
        0
    } else {
        (if ("events" in hidden) 0 else today.events.size) +
            (if ("tasks" in hidden) 0 else today.tasks.count { !it.done }) +
            (if ("reminders" in hidden) 0 else today.reminders.size)
    }
    val night = settingsNight()
    val accent = settingsAccent()
    val cardShape = RoundedCornerShape(20.dp)
    val mut = if (night) AccountDark.mut else AppColors.textSecondary
    val nextLine = today?.takeIf { it.enabled }?.let { day ->
        day.next?.takeIf { "events" !in hidden }?.let { listOfNotNull(timeText(it.startsAt).takeIf { t -> t.isNotEmpty() }, it.title).joinToString("  ·  ") }
            ?: day.moments.takeIf { "moments" !in hidden }?.firstOrNull()?.let { "${it.emoji ?: "✨"}  ${it.title}" }
    }

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
                    dayTitle(today?.date?.let { runCatching { LocalDate.parse(it) }.getOrNull() } ?: LocalDate.now()),
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
            // The next thing today, when there is one (no "tap to plan your day" line otherwise).
            nextLine?.let { line ->
                Text(
                    line,
                    fontSize = 12.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = mut,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
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
    /** The appointment form: a new one on [day], or [existing] changed. */
    data class Editor(val existing: CalItemDto?, val day: LocalDate) : CalPage
}

@Composable
fun CalendarScreen(onExit: () -> Unit, onOpenMoments: () -> Unit = {}) {
    var page by remember { mutableStateOf<CalPage>(CalPage.Main) }
    // Kept here, not in the main page: coming back from settings or search lands on the same tab and day.
    var tab by remember { mutableStateOf(CalTab.Today) }
    var selected by remember { mutableStateOf(LocalDate.now()) }
    var filter by remember { mutableStateOf("all") }
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
                CalPage.Main -> CalendarMain(
                    tab = tab, onTab = { tab = it }, selected = selected, onSelect = { selected = it }, filter = filter, onFilter = { filter = it },
                    onBack = onExit, onSettings = { page = CalPage.Settings }, onSearch = { page = CalPage.Search }, onOpenMoments = onOpenMoments,
                    onAdd = { day -> page = CalPage.Editor(null, day) }, onEdit = { item -> page = CalPage.Editor(item, editorDay(item)) },
                )
                CalPage.Settings -> CalendarSettings(onBack = { page = CalPage.Main })
                CalPage.Search -> CalendarSearch(onBack = { page = CalPage.Main }, onOpenMoments = onOpenMoments, onEdit = { item -> page = CalPage.Editor(item, editorDay(item)) })
                is CalPage.Editor -> EventEditorScreen(existing = p.existing, day = p.day, onBack = { page = CalPage.Main })
            }
        }
    }
}

private enum class CalTab { Today, Week, Month }

@Composable
private fun CalendarMain(
    tab: CalTab,
    onTab: (CalTab) -> Unit,
    selected: LocalDate,
    onSelect: (LocalDate) -> Unit,
    filter: String,
    onFilter: (String) -> Unit,
    onBack: () -> Unit,
    onSettings: () -> Unit,
    onSearch: () -> Unit,
    onOpenMoments: () -> Unit,
    onAdd: (LocalDate) -> Unit,
    onEdit: (CalItemDto) -> Unit,
) {
    var open by remember { mutableStateOf<CalItemDto?>(null) }

    WaPage(
        title = stringResource(R.string.cal_title),
        onBack = onBack,
        actions = {
            WaCircleButton(Icons.Rounded.Search, onSearch, contentDescription = stringResource(R.string.cal_search))
            WaCircleButton(Icons.Rounded.Tune, onSettings, contentDescription = stringResource(R.string.cal_settings))
        },
        cta = { WaButton(stringResource(R.string.cal_add), { onAdd(if (tab == CalTab.Today) LocalDate.now() else selected) }, icon = Icons.Rounded.Add) },
    ) {
        Tabs(tab, onTab)
        Spacer(Modifier.height(12.dp))
        when (tab) {
            CalTab.Today -> TodayTab(filter = filter, onFilter = onFilter, onOpen = { open = it })
            CalTab.Week -> RangeTab(month = false, selected = selected, filter = filter, onFilter = onFilter, onSelect = onSelect, onOpen = { open = it })
            CalTab.Month -> RangeTab(month = true, selected = selected, filter = filter, onFilter = onFilter, onSelect = onSelect, onOpen = { open = it })
        }
    }

    open?.let { item ->
        if (item.type == "sports") {
            LaunchedEffect(item.id) { com.dorr.app.ui.screens.sports.SportsLink.show(item.ref); open = null }
        } else if (item.type == "discover") {
            LaunchedEffect(item.id) { com.dorr.app.ui.screens.events.EventsLink.show(item.ref); open = null }
        } else {
            ItemSheet(item, onDismiss = { open = null }, onOpenMoments = onOpenMoments, onEdit = onEdit)
        }
    }
}

@Composable
private fun Tabs(tab: CalTab, onPick: (CalTab) -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Wa.Surface).padding(3.dp)) {
        listOf(CalTab.Today to R.string.cal_today, CalTab.Week to R.string.cal_week, CalTab.Month to R.string.cal_month).forEach { (t, label) ->
            val on = t == tab
            val bg by animateColorAsState(if (on) Wa.Red else Color.Transparent, label = "tab")
            Text(
                stringResource(label), color = if (on) Color.White else Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                modifier = Modifier.weight(1f).clip(RoundedCornerShape(11.dp)).background(bg).clickable { onPick(t) }.padding(vertical = 7.dp),
            )
        }
    }
}

// =============================================================================== today (202, 207)

@Composable
private fun TodayTab(filter: String, onFilter: (String) -> Unit, onOpen: (CalItemDto) -> Unit) {
    val version = CalendarStore.version
    LaunchedEffect(version) { CalendarStore.refresh() }
    val loaded = CalendarStore.today
    val scope = rememberCoroutineScope()

    if (loaded == null) {
        repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(74.dp).padding(bottom = 10.dp), RoundedCornerShape(20.dp)) }
        return
    }
    if (!loaded.enabled) {
        WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.CalendarMonth, Tone.Gray, stringResource(R.string.cal_off_title), stringResource(R.string.cal_off_text)) }
        return
    }

    // Everything on today, in one list — all-day first, then by time (as the week and month lists), under the chosen filter.
    val date = runCatching { LocalDate.parse(loaded.date) }.getOrDefault(LocalDate.now())
    val saved = loaded.sections?.order.orEmpty().filter { it in DefaultSections }
    val order = saved + (DefaultSections - saved.toSet())
    val hidden = loaded.sections?.hidden.orEmpty()
    val items = (loaded.events + loaded.tasks + loaded.reminders + loaded.moments)
        .distinctBy { it.id }
        .filter { sectionOf(it) !in hidden && sectionSourcesOn(sectionOf(it), loaded.sources) && matchesFilter(it, filter) }
        // Grouped in the order set in Settings; within a kind all-day first, then by time.
        .sortedWith(compareBy<CalItemDto>({ order.indexOf(sectionOf(it)) }, { !(it.allDay || it.startsAt == null) }, { it.startsAt.orEmpty() }))

    DayHeader(date, Modifier.waRise(0))
    FilterChips(filter, onFilter)
    Spacer(Modifier.height(10.dp))
    if (items.isEmpty()) {
        DayEmpty(filtered = filter != "all")
    } else {
        items.forEachIndexed { i, item ->
            ItemRow(
                item, Modifier.padding(bottom = 10.dp).waRise(i),
                onToggle = if (item.type == "task") ({
                    scope.launch {
                        runCatching { ApiClient.aiTools.updateTask(calAuth(), item.ref, JsonObject().apply { addProperty("done", !item.done) }) }
                        CalendarStore.changed()
                    }
                }) else null,
            ) { onOpen(item) }
        }
    }
}

/** An empty page: a soft icon, a line and a hint, in the middle of the space left (optionally with an action). */
@Composable
internal fun CalEmptyState(icon: ImageVector, title: String, hint: String, modifier: Modifier = Modifier, action: (@Composable () -> Unit)? = null) {
    Column(
        modifier.fillMaxWidth().heightIn(min = 300.dp).padding(horizontal = 24.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center,
    ) {
        Box(Modifier.size(132.dp).clip(CircleShape).background(Wa.Red.copy(alpha = 0.06f)), contentAlignment = Alignment.Center) {
            Box(Modifier.size(92.dp).clip(CircleShape).background(Wa.Red.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = Wa.Red, modifier = Modifier.size(44.dp))
            }
        }
        Spacer(Modifier.height(18.dp))
        Text(title, color = Wa.Ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
        Spacer(Modifier.height(6.dp))
        Text(hint, color = Wa.Mut, fontSize = 13.sp, lineHeight = 20.sp, textAlign = TextAlign.Center)
        if (action != null) {
            Spacer(Modifier.height(16.dp))
            action()
        }
    }
}

/** A day with nothing on it. */
@Composable
internal fun DayEmpty(filtered: Boolean, modifier: Modifier = Modifier) {
    CalEmptyState(
        Icons.Rounded.EventAvailable, stringResource(R.string.cal_day_empty),
        stringResource(if (filtered) R.string.cal_day_empty_filtered else R.string.cal_day_empty_sub), modifier,
    )
}

/** The day's heading: "Tuesday, 15 October" — with a "Today" badge when it is today. */
@Composable
internal fun DayHeader(date: LocalDate, modifier: Modifier = Modifier, topPadding: androidx.compose.ui.unit.Dp = 0.dp) {
    Row(modifier.fillMaxWidth().padding(top = topPadding, bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(dayTitle(date), color = Wa.Ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
        if (date == LocalDate.now()) {
            Text(
                stringResource(R.string.cal_today), color = Wa.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(CircleShape).background(Wa.Red.copy(alpha = 0.12f)).padding(horizontal = 12.dp, vertical = 5.dp),
            )
        }
    }
}

/**
 * One item as a card: a colour bar on the edge, its time (the end under it), a thin divider, the title with one line
 * under it (where it is, or what it is), and a tile with the icon of its kind. Direction follows the language.
 */
@Composable
internal fun ItemRow(item: CalItemDto, modifier: Modifier = Modifier, onToggle: (() -> Unit)? = null, showDate: Boolean = false, onClick: () -> Unit) {
    val color = typeColor(item)
    val night = settingsNight()
    val shape = RoundedCornerShape(20.dp)
    Row(
        modifier.fillMaxWidth()
            .then(if (night) Modifier.border(1.dp, Wa.Line, shape) else Modifier.shadow(8.dp, shape, ambientColor = Color(0x14111928), spotColor = Color(0x1F111928)))
            .clip(shape).background(Wa.Surface).clickable(onClick = onClick).height(androidx.compose.foundation.layout.IntrinsicSize.Min),
    ) {
        Box(Modifier.width(5.dp).fillMaxHeight().background(color))
        Row(Modifier.weight(1f).padding(horizontal = 12.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.width(58.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                // In search results there is no day heading above the card, so the card says its day.
                if (showDate) item.date?.let { d -> runCatching { LocalDate.parse(d) }.getOrNull() }?.let { day ->
                    Text(shortDay(day), color = color, fontSize = 10.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, textAlign = TextAlign.Center)
                }
                if (item.allDay || item.startsAt == null) Text(stringResource(R.string.cal_all_day_short), color = Wa.Mut, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
                else Text(timeText(item.startsAt), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
                item.endsAt?.let { Text(timeText(it), color = Wa.Soft, fontSize = 11.5.sp, textAlign = TextAlign.Center) }
            }
            Box(Modifier.padding(horizontal = 10.dp).width(1.dp).height(40.dp).background(Wa.Line))
            Column(Modifier.weight(1f)) {
                Text(
                    listOfNotNull(item.emoji, item.title).joinToString(" "), color = if (item.done) Wa.Soft else Wa.Ink, fontSize = 15.5.sp, fontWeight = FontWeight.ExtraBold,
                    maxLines = 1, overflow = TextOverflow.Ellipsis,
                )
                val sub = listOfNotNull(
                    if (item.overdue) stringResource(R.string.cal_overdue) else null,
                    item.originTime?.let { stringResource(R.string.cal_origin_time, it, item.timezone.orEmpty().substringAfterLast('/').replace('_', ' ')) },
                    item.location,
                    item.turns?.let { stringResource(R.string.mo_turns, it) },
                    when (item.type) {
                        "task" -> stringResource(R.string.cal_kind_task)
                        "reminder" -> stringResource(R.string.cal_kind_reminder)
                        // A plain appointment needs no label; a match, a trip… says what it is.
                        "event" -> item.category?.takeIf { it != EventCategories.first() }?.let { stringResource(categoryLabel(it)) }
                        else -> null
                    },
                )
                if (sub.isNotEmpty()) {
                    Row(Modifier.padding(top = 3.dp), verticalAlignment = Alignment.CenterVertically) {
                        val subIcon = when {
                            item.location != null -> Icons.Rounded.Place
                            item.originTime != null -> Icons.Rounded.Public
                            else -> null
                        }
                        subIcon?.let {
                            Icon(it, null, tint = Wa.Mut, modifier = Modifier.size(14.dp))
                            Spacer(Modifier.width(4.dp))
                        }
                        Text(sub.joinToString("  ·  "), color = if (item.overdue) Wa.Danger else Wa.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
            if (onToggle != null) {
                val tick by animateFloatAsState(if (item.done) 1f else 0.9f, spring(dampingRatio = 0.4f), label = "tick")
                Icon(
                    if (item.done) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (item.done) Color(0xFF16A34A) else Wa.Soft,
                    modifier = Modifier.padding(horizontal = 6.dp).size(26.dp).scale(tick).clip(CircleShape).clickable(onClick = onToggle),
                )
            }
            Spacer(Modifier.width(6.dp))
            Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(itemIcon(item), null, tint = color, modifier = Modifier.size(22.dp))
            }
        }
    }
}

// =============================================================================== week & month (203)

private val Filters = listOf(
    "all" to R.string.cal_f_all, "event" to R.string.cal_f_events, "task" to R.string.cal_f_tasks, "reminder" to R.string.cal_f_reminders,
    "occasion" to R.string.cal_f_occasions, "discover" to R.string.cal_f_discover, "sports" to R.string.cal_f_sports,
)

/** Does an item belong under this filter chip? */
private fun matchesFilter(item: CalItemDto, filter: String): Boolean = when (filter) {
    "all" -> true
    "occasion" -> item.type == "moment" || item.type == "personal"
    else -> item.type == filter
}

@Composable
private fun FilterChips(filter: String, onPick: (String) -> Unit) {
    LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        items(Filters, key = { it.first }) { (key, label) ->
            val on = filter == key
            Text(
                stringResource(label), color = if (on) Color.White else Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(CircleShape).background(if (on) Wa.Red else Wa.Surface).border(1.dp, if (on) Color.Transparent else Wa.Line, CircleShape)
                    .clickable { onPick(key) }.padding(horizontal = 12.dp, vertical = 7.dp),
            )
        }
    }
}

@Composable
private fun RangeTab(month: Boolean, selected: LocalDate, filter: String, onFilter: (String) -> Unit, onSelect: (LocalDate) -> Unit, onOpen: (CalItemDto) -> Unit) {
    val firstDay = WeekFields.of(calLocale()).firstDayOfWeek
    var anchor by remember(month) { mutableStateOf(selected) }
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
    val shown = items.orEmpty().filter { matchesFilter(it, filter) }
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
            if (month) monthTitle(YearMonth.from(anchor))
            else shortDay(days.first()) + " – " + shortDay(days.last()),
            color = Wa.Ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, modifier = Modifier.weight(1f),
        )
        NavArrow(Icons.AutoMirrored.Rounded.KeyboardArrowRight) { anchor = if (month) anchor.plusMonths(1) else anchor.plusWeeks(1) }
    }
    Spacer(Modifier.height(6.dp))

    // Weekday names.
    Row(Modifier.fillMaxWidth()) {
        days.take(7).forEach { d ->
            Text(weekdayShort(d.dayOfWeek), color = Wa.Mut, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, modifier = Modifier.weight(1f))
        }
    }
    Spacer(Modifier.height(2.dp))
    days.chunked(7).forEach { week ->
        Row(Modifier.fillMaxWidth()) {
            week.forEach { d ->
                DayCell(
                    d, selected = d == selected, today = d == LocalDate.now(), outside = month && d.month != YearMonth.from(anchor).month,
                    dots = byDay[d].orEmpty().map { typeColor(it) }.distinct().take(3), tall = !month, modifier = Modifier.weight(1f),
                ) { onSelect(d) }
            }
        }
    }

    DayHeader(selected, topPadding = 10.dp)
    FilterChips(filter, onFilter)
    Spacer(Modifier.height(10.dp))
    when {
        items == null -> repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(74.dp).padding(bottom = 10.dp), RoundedCornerShape(20.dp)) }
        byDay[selected].isNullOrEmpty() -> DayEmpty(filtered = filter != "all")
        else -> byDay[selected].orEmpty().forEachIndexed { i, item -> ItemRow(item, Modifier.padding(bottom = 10.dp).waRise(i)) { onOpen(item) } }
    }
}

@Composable
private fun NavArrow(icon: ImageVector, onClick: () -> Unit) {
    Box(Modifier.size(32.dp).clip(CircleShape).background(Wa.Surface).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = Wa.Red, modifier = Modifier.size(20.dp))
    }
}

@Composable
private fun DayCell(day: LocalDate, selected: Boolean, today: Boolean, outside: Boolean, dots: List<Color>, tall: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) Wa.Red else Color.Transparent, label = "day")
    // The day is a number in a round marker (filled when picked, ringed when it is today); its items are dots under it.
    Column(
        modifier.height(if (tall) 54.dp else 46.dp).clip(RoundedCornerShape(12.dp)).clickable(onClick = onClick),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center,
    ) {
        Box(
            Modifier.size(if (tall) 38.dp else 34.dp).clip(CircleShape).background(bg)
                .then(if (today && !selected) Modifier.border(1.5.dp, Wa.Red, CircleShape) else Modifier),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                dayNumber(day), fontSize = 15.sp, fontWeight = if (today || selected) FontWeight.ExtraBold else FontWeight.Medium,
                color = when { selected -> Color.White; outside -> Wa.Soft; else -> Wa.Ink },
            )
        }
        Row(Modifier.height(7.dp).padding(top = 2.dp), horizontalArrangement = Arrangement.spacedBy(3.dp)) {
            dots.forEach { c -> Box(Modifier.size(4.dp).clip(CircleShape).background(c)) }
        }
    }
}
