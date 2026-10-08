package com.dorr.app.ui.screens.events

import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ContentTransform
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
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
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
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Explore
import androidx.compose.material.icons.rounded.FavoriteBorder
import androidx.compose.material.icons.rounded.Flight
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
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
import com.dorr.app.network.DiscAskDto
import com.dorr.app.network.DiscCityDto
import com.dorr.app.network.DiscEventDto
import com.dorr.app.network.DiscHomeDto
import com.dorr.app.network.DiscOrganizerHomeDto
import com.dorr.app.network.DiscPrefsDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaCircleButton
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.DayOfWeek
import java.time.Instant
import java.time.LocalDate
import java.time.LocalTime
import java.time.ZoneId
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.time.temporal.TemporalAdjusters
import java.util.Locale

/*
 * DORR Discover (spec 169–182): public events from trusted sources — the admin and verified
 * organizers — around me, in places I follow and where I'm travelling. Each event shows its own
 * local time and mine; "interested" puts it in my calendar and tells me when it changes.
 */

internal fun evAuth() = "Bearer ${AuthSession.token.orEmpty()}"

internal fun evZone(): String = ZoneId.systemDefault().id

/** Opening Discover from anywhere: the home banner, a card in a chat, a notification, the calendar. */
@Stable
object EventsLink {
    var open by mutableStateOf(false)
    var eventId by mutableStateOf<String?>(null)

    fun show(id: String? = null) {
        eventId = id
        open = true
    }

    fun close() {
        open = false
        eventId = null
    }
}

/** Discover's home, kept between visits; `version` bumps when something changes (interested…). */
@Stable
object EventsStore {
    var home by mutableStateOf<DiscHomeDto?>(null)
    var off by mutableStateOf(false)
    var version by mutableIntStateOf(0)
        private set
    private var cities: List<DiscCityDto>? = null

    suspend fun refresh() {
        runCatching { ApiClient.events.home(evAuth(), evZone()).data }
            .onSuccess { off = false; if (it != null) home = it }
            .onFailure { off = (it as? retrofit2.HttpException)?.code() == 403 }
    }

    /** Every city Discover covers (for travelling and following). */
    suspend fun allCities(): List<DiscCityDto> =
        cities ?: runCatching { ApiClient.events.cities(evAuth()).data }.getOrNull().orEmpty().also { if (it.isNotEmpty()) cities = it }

    suspend fun changed() {
        version++
        refresh()
        com.dorr.app.ui.screens.calendar.CalendarStore.changed()
    }
}

internal sealed interface EvPage {
    data object Home : EvPage
    data class Browse(val cityId: Int? = null, val categoryId: Int? = null) : EvPage
    data object Travel : EvPage
    data object Ask : EvPage
    data object Saved : EvPage
    data object Settings : EvPage
    data object Organizer : EvPage
    data object Submit : EvPage
    data class Event(val id: String) : EvPage
}

@Composable
fun EventsScreen(onExit: () -> Unit) {
    var stack by remember { mutableStateOf(listOfNotNull<EvPage>(EvPage.Home, EventsLink.eventId?.let { EvPage.Event(it) })) }
    LaunchedEffect(EventsLink.eventId) {
        val id = EventsLink.eventId ?: return@LaunchedEffect
        if ((stack.lastOrNull() as? EvPage.Event)?.id != id) stack = stack + EvPage.Event(id)
        EventsLink.eventId = null
    }
    val back: () -> Unit = { if (stack.size > 1) stack = stack.dropLast(1) else onExit() }
    val go: (EvPage) -> Unit = { stack = stack + it }
    BackHandler(onBack = back)
    val sign = if (LocalLayoutDirection.current == LayoutDirection.Rtl) -1 else 1

    Box(Modifier.fillMaxSize().background(Wa.Bg).systemBarsPadding().imePadding()) {
        AnimatedContent(
            targetState = stack.size to stack.last(),
            transitionSpec = {
                val forward = targetState.first >= initialState.first
                ContentTransform(
                    slideInHorizontally(tween(320)) { (if (forward) sign else -sign) * it / 6 } + fadeIn(tween(280)),
                    slideOutHorizontally(tween(280)) { (if (forward) -sign else sign) * it / 10 } + fadeOut(tween(200)),
                )
            },
            label = "eventsPages",
        ) { (_, page) ->
            when (page) {
                EvPage.Home -> EvHome(back, go)
                is EvPage.Browse -> EvBrowse(page, back, go)
                EvPage.Travel -> EvTravel(back, go)
                EvPage.Ask -> EvAsk(back, go)
                EvPage.Saved -> EvSaved(back, go)
                EvPage.Settings -> EvSettings(back)
                EvPage.Organizer -> EvOrganizer(back, go)
                EvPage.Submit -> EvSubmit(back)
                is EvPage.Event -> EventDetail(page.id, back)
            }
        }
    }
}

// =============================================================================== home

@Composable
private fun EvHome(onBack: () -> Unit, go: (EvPage) -> Unit) {
    LaunchedEffect(Unit) { EventsStore.refresh() }
    val home = EventsStore.home

    WaPage(
        title = stringResource(R.string.ev_title),
        onBack = onBack,
        actions = {
            WaCircleButton(Icons.Rounded.FavoriteBorder, { go(EvPage.Saved) }, contentDescription = stringResource(R.string.ev_saved))
            WaCircleButton(Icons.Rounded.Tune, { go(EvPage.Settings) }, contentDescription = stringResource(R.string.ev_settings))
        },
    ) {
        when {
            EventsStore.off -> WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.Explore, Tone.Gray, stringResource(R.string.ev_off_title), stringResource(R.string.ev_off_text)) }
            home == null -> {
                WaSkeleton(Modifier.fillMaxWidth().height(150.dp), RoundedCornerShape(26.dp))
                Spacer(Modifier.height(16.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) { repeat(2) { WaSkeleton(Modifier.width(230.dp).height(220.dp), RoundedCornerShape(22.dp)) } }
            }
            else -> HomeContent(home, go)
        }
    }
}

@Composable
private fun HomeContent(home: DiscHomeDto, go: (EvPage) -> Unit) {
    // Hero: what's on around me, and "ask DORR AI".
    Column(Modifier.fillMaxWidth().waRise(0).clip(RoundedCornerShape(26.dp)).background(EvHeroBrush).padding(18.dp)) {
        Text(stringResource(R.string.ev_hero_title), color = Color.White, fontSize = 21.sp, fontWeight = FontWeight.ExtraBold)
        Text(stringResource(R.string.ev_hero_sub), color = Color.White.copy(alpha = 0.85f), fontSize = 13.sp)
        if (home.ai) {
            Spacer(Modifier.height(14.dp))
            Row(
                Modifier.fillMaxWidth().clip(CircleShape).background(Color.White).clickable { go(EvPage.Ask) }.padding(horizontal = 14.dp, vertical = 11.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.AutoAwesome, null, tint = EvPurple, modifier = Modifier.size(19.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.ev_ask_hint), color = Wa.Mut, fontSize = 13.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            }
        }
    }

    // Quick ways in.
    Row(Modifier.fillMaxWidth().padding(top = 14.dp).waRise(1), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        QuickTile("✈️", stringResource(R.string.ev_travel), Modifier.weight(1f)) { go(EvPage.Travel) }
        QuickTile("🔎", stringResource(R.string.ev_browse), Modifier.weight(1f)) { go(EvPage.Browse()) }
        QuickTile("❤️", stringResource(R.string.ev_saved), Modifier.weight(1f)) { go(EvPage.Saved) }
        if (home.canSubmit) QuickTile("🎤", stringResource(R.string.ev_organizer), Modifier.weight(1f)) { go(EvPage.Organizer) }
    }

    if (home.cities.isNotEmpty()) {
        WaSectionTitle(stringResource(R.string.ev_cities), Modifier.waRise(2))
        LazyRow(Modifier.waRise(2), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            items(home.cities, key = { it.id }) { c -> EvChip(c.name.orEmpty(), selected = false, icon = "📍") { go(EvPage.Browse(cityId = c.id)) } }
        }
    }
    if (home.categories.isNotEmpty()) {
        WaSectionTitle(stringResource(R.string.ev_categories), Modifier.waRise(3))
        LazyRow(Modifier.waRise(3), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            items(home.categories, key = { it.id }) { c -> CategoryTile(c) { go(EvPage.Browse(categoryId = c.id)) } }
        }
    }

    home.sections.forEachIndexed { i, section ->
        WaSectionTitle(sectionTitle(section.key), Modifier.waRise(4 + i))
        LazyRow(Modifier.waRise(4 + i), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            items(section.items, key = { section.key + it.id }) { e -> EventCard(e) { go(EvPage.Event(e.id)) } }
        }
    }
    if (home.sections.isEmpty()) {
        Spacer(Modifier.height(18.dp))
        WaCard(Modifier.fillMaxWidth()) {
            WaEmpty(Icons.Rounded.Event, Tone.Blue, stringResource(R.string.ev_empty_title), stringResource(R.string.ev_empty_text)) {
                WaButton(stringResource(R.string.ev_travel), { go(EvPage.Travel) }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Flight)
            }
        }
    }
    Spacer(Modifier.height(24.dp))
}

@Composable
private fun sectionTitle(key: String): String = when (key) {
    "for_you" -> stringResource(R.string.ev_sec_for_you)
    "this_week" -> stringResource(R.string.ev_sec_this_week)
    "weekend" -> stringResource(R.string.ev_sec_weekend)
    "free" -> stringResource(R.string.ev_sec_free)
    "popular" -> stringResource(R.string.ev_sec_popular)
    "followed" -> stringResource(R.string.ev_sec_followed)
    else -> key
}

@Composable
private fun QuickTile(emoji: String, label: String, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Column(
        modifier.clip(RoundedCornerShape(20.dp)).background(Wa.Surface).clickable(onClick = onClick).padding(vertical = 12.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(emoji, fontSize = 22.sp)
        Spacer(Modifier.height(4.dp))
        Text(label, color = Wa.Ink, fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center)
    }
}

// =============================================================================== browse (169–171, 173)

private enum class When { Any, Today, Weekend, Week, Month }

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun EvBrowse(start: EvPage.Browse, onBack: () -> Unit, go: (EvPage) -> Unit) {
    val home = EventsStore.home
    var cityId by remember { mutableStateOf(start.cityId) }
    var categoryId by remember { mutableStateOf(start.categoryId) }
    var whenPick by remember { mutableStateOf(When.Any) }
    var free by remember { mutableStateOf(false) }
    var family by remember { mutableStateOf(home?.preferences?.familyOnly == true) }
    var popular by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    val items = remember { mutableStateListOf<DiscEventDto>() }
    var page by remember { mutableIntStateOf(1) }
    var more by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(true) }
    val scope = rememberCoroutineScope()

    suspend fun load(next: Int) {
        loading = true
        val (from, to) = whenPick.range()
        runCatching {
            ApiClient.events.events(
                evAuth(), evZone(), cityId = cityId, categories = categoryId?.let { listOf(it) }, from = from, to = to,
                free = if (free) 1 else null, family = if (family) 1 else null, q = query.trim().ifEmpty { null }, sort = if (popular) "popular" else null, page = next,
            )
        }.onSuccess { r ->
            if (next == 1) items.clear()
            items.addAll(r.data.orEmpty())
            page = next
            more = r.pagination?.hasMorePages == true
        }.onFailure { if (next == 1) items.clear(); more = false }
        loading = false
    }
    LaunchedEffect(cityId, categoryId, whenPick, free, family, popular, query) { load(1) }

    WaPage(title = home?.cities?.firstOrNull { it.id == cityId }?.name ?: stringResource(R.string.ev_browse), onBack = onBack) {
        DorrTextField(query, { query = it.take(100) }, placeholder = stringResource(R.string.ev_search_hint), icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth().waRise(0))
        Spacer(Modifier.height(10.dp))
        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            item { EvChip(stringResource(R.string.ev_all_cities), cityId == null) { cityId = null } }
            items(home?.cities.orEmpty(), key = { it.id }) { c -> EvChip(c.name.orEmpty(), cityId == c.id) { cityId = c.id } }
        }
        Spacer(Modifier.height(6.dp))
        LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            item { EvChip(stringResource(R.string.ev_all_kinds), categoryId == null) { categoryId = null } }
            items(home?.categories.orEmpty(), key = { it.id }) { c -> EvChip(c.name.orEmpty(), categoryId == c.id, icon = c.emoji) { categoryId = c.id } }
        }
        Spacer(Modifier.height(6.dp))
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            listOf(When.Any to R.string.ev_when_any, When.Today to R.string.ev_when_today, When.Weekend to R.string.ev_when_weekend, When.Week to R.string.ev_when_week, When.Month to R.string.ev_when_month)
                .forEach { (w, label) -> EvChip(stringResource(label), whenPick == w) { whenPick = w } }
            EvChip(stringResource(R.string.ev_free), free, icon = "🎟️") { free = !free }
            EvChip(stringResource(R.string.ev_family), family, icon = "👨‍👩‍👧") { family = !family }
            EvChip(stringResource(R.string.ev_popular), popular, icon = "🔥") { popular = !popular }
        }
        Spacer(Modifier.height(14.dp))
        EventList(items, loading && items.isEmpty(), onOpen = { go(EvPage.Event(it.id)) })
        if (more) {
            WaButton(stringResource(R.string.ev_more), { scope.launch { load(page + 1) } }, style = WaButtonStyle.Ghost, loading = loading, modifier = Modifier.fillMaxWidth().padding(top = 6.dp))
        }
        Spacer(Modifier.height(24.dp))
    }
}

/** The Arab weekend: Friday and Saturday. */
private fun When.range(): Pair<String?, String?> {
    val today = LocalDate.now()
    return when (this) {
        When.Any -> null to null
        When.Today -> today.toString() to today.toString()
        When.Weekend -> {
            val fri = if (today.dayOfWeek == DayOfWeek.SATURDAY) today.minusDays(1) else today.with(TemporalAdjusters.nextOrSame(DayOfWeek.FRIDAY))
            maxOf(fri, today).toString() to fri.plusDays(1).toString()
        }
        When.Week -> today.toString() to today.plusDays(6).toString()
        When.Month -> today.toString() to today.plusDays(30).toString()
    }
}

@Composable
internal fun EventList(items: List<DiscEventDto>, loading: Boolean, onOpen: (DiscEventDto) -> Unit, empty: String? = null) {
    when {
        loading -> repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(86.dp).padding(bottom = 10.dp), RoundedCornerShape(20.dp)) }
        items.isEmpty() -> WaCard(Modifier.fillMaxWidth()) {
            WaEmpty(Icons.Rounded.CalendarMonth, Tone.Gray, stringResource(R.string.ev_none_title), empty ?: stringResource(R.string.ev_none_text))
        }
        else -> items.forEachIndexed { i, e -> EventRow(e, Modifier.padding(bottom = 10.dp).waRise(i.coerceAtMost(6))) { onOpen(e) } }
    }
}

// =============================================================================== travel (173, AT-DISC-03)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EvTravel(onBack: () -> Unit, go: (EvPage) -> Unit) {
    var city by remember { mutableStateOf<DiscCityDto?>(null) }
    var from by remember { mutableStateOf(LocalDate.now().plusDays(7)) }
    var to by remember { mutableStateOf(LocalDate.now().plusDays(10)) }
    var picking by remember { mutableStateOf<String?>(null) }
    var result by remember { mutableStateOf<List<DiscEventDto>?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    WaPage(
        title = stringResource(R.string.ev_travel_title),
        onBack = onBack,
        cta = {
            WaButton(stringResource(R.string.ev_travel_go), {
                val c = city ?: run { picking = "city"; return@WaButton }
                busy = true
                error = null
                scope.launch {
                    runCatching { ApiClient.events.travel(evAuth(), c.id, from.toString(), to.toString(), evZone()).data }
                        .onSuccess { result = it?.items.orEmpty() }
                        .onFailure { error = it.apiFailure().message; result = null }
                    busy = false
                }
            }, icon = Icons.Rounded.Search, loading = busy)
        },
    ) {
        Text(stringResource(R.string.ev_travel_sub), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.waRise(0))
        Spacer(Modifier.height(14.dp))
        PickRow("🌍", stringResource(R.string.ev_travel_city), city?.let { listOfNotNull(it.name, it.country).joinToString(" · ") } ?: stringResource(R.string.ev_pick_city), Modifier.waRise(1)) { picking = "city" }
        Spacer(Modifier.height(8.dp))
        Row(Modifier.fillMaxWidth().waRise(2), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            PickRow("🛫", stringResource(R.string.ev_from), dayText(from), Modifier.weight(1f)) { picking = "from" }
            PickRow("🛬", stringResource(R.string.ev_to), dayText(to), Modifier.weight(1f)) { picking = "to" }
        }
        error?.let { Text(it, color = Wa.Danger, fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp)) }
        result?.let { list ->
            city?.timezone?.let { z ->
                WaNote(stringResource(R.string.ev_travel_zone_note, z.substringAfterLast('/').replace('_', ' ')), Modifier.padding(top = 14.dp))
            }
            WaSectionTitle(stringResource(R.string.ev_travel_found, list.size))
            EventList(list, false, onOpen = { go(EvPage.Event(it.id)) }, empty = stringResource(R.string.ev_travel_none))
        }
        Spacer(Modifier.height(24.dp))
    }

    when (picking) {
        "city" -> CityPickerSheet(onDismiss = { picking = null }) { city = it; picking = null; result = null }
        "from", "to" -> {
            val initial = if (picking == "from") from else to
            val state = rememberDatePickerState(initialSelectedDateMillis = initial.atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli())
            DatePickerDialog(
                onDismissRequest = { picking = null },
                confirmButton = {
                    TextButton({
                        state.selectedDateMillis?.let {
                            val d = Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate()
                            if (picking == "from") { from = d; if (to.isBefore(d)) to = d } else to = if (d.isBefore(from)) from else d
                        }
                        picking = null
                    }) { Text(stringResource(R.string.cal_ok), color = Wa.Red, fontWeight = FontWeight.Bold) }
                },
                dismissButton = { TextButton({ picking = null }) { Text(stringResource(R.string.cal_cancel), color = Wa.Mut) } },
            ) { DatePicker(state) }
        }
    }
}

@Composable
internal fun PickRow(emoji: String, label: String, value: String, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Row(modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Wa.Surface).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(emoji, fontSize = 20.sp)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(label, color = Wa.Mut, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
            Text(value, color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

internal fun dayText(d: LocalDate): String = d.format(DateTimeFormatter.ofPattern("EEE d MMM", Locale.getDefault()))

// =============================================================================== ask DORR AI (181)

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun EvAsk(onBack: () -> Unit, go: (EvPage) -> Unit) {
    var text by remember { mutableStateOf("") }
    var answer by remember { mutableStateOf<DiscAskDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val ask: (String) -> Unit = { q ->
        if (q.trim().length >= 2 && !busy) {
            busy = true
            error = null
            scope.launch {
                runCatching { ApiClient.events.ask(evAuth(), JsonObject().apply { addProperty("text", q.trim()) }, evZone()).data }
                    .onSuccess { answer = it }
                    .onFailure { error = it.apiFailure().message }
                busy = false
            }
        }
    }

    WaPage(title = stringResource(R.string.ev_ask_title), onBack = onBack) {
        Row(Modifier.fillMaxWidth().waRise(0), verticalAlignment = Alignment.CenterVertically) {
            DorrTextField(text, { text = it.take(500) }, placeholder = stringResource(R.string.ev_ask_hint), icon = Icons.Rounded.AutoAwesome, modifier = Modifier.weight(1f))
            Spacer(Modifier.width(8.dp))
            WaCircleButton(Icons.AutoMirrored.Rounded.Send, { ask(text) }, tint = Color.White, background = EvPurple, contentDescription = stringResource(R.string.ev_ask_send))
        }
        if (answer == null && !busy) {
            Text(stringResource(R.string.ev_ask_try), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 16.dp, bottom = 6.dp))
            listOf(R.string.ev_ask_ex1, R.string.ev_ask_ex2, R.string.ev_ask_ex3).forEach { res ->
                val example = stringResource(res)
                Text(
                    "✨  $example", color = Wa.Ink, fontSize = 14.sp,
                    modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(16.dp)).background(Wa.Surface).clickable { text = example; ask(example) }.padding(14.dp),
                )
            }
            WaNote(stringResource(R.string.ev_ask_note), Modifier.padding(top = 6.dp))
        }
        error?.let { Text(it, color = Wa.Danger, fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp)) }
        if (busy) {
            Spacer(Modifier.height(14.dp))
            repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(86.dp).padding(bottom = 10.dp), RoundedCornerShape(20.dp)) }
        }
        answer?.takeIf { !busy }?.let { a ->
            val f = a.filters
            val chips = listOfNotNull(
                f?.city?.let { "📍 $it" },
                f?.categories?.takeIf { it.isNotEmpty() }?.joinToString(" · "),
                if (f?.from != null) "📅 " + listOfNotNull(f.from, f.to?.takeIf { it != f.from }).joinToString(" → ") else null,
                if (f?.free == true) "🎟️ " + stringResource(R.string.ev_free) else null,
                if (f?.family == true) "👨‍👩‍👧 " + stringResource(R.string.ev_family) else null,
            )
            if (chips.isNotEmpty()) {
                Text(stringResource(R.string.ev_ask_understood), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) { chips.forEach { EvChip(it, selected = true) {} } }
            }
            Spacer(Modifier.height(12.dp))
            EventList(a.items, false, onOpen = { go(EvPage.Event(it.id)) }, empty = stringResource(R.string.ev_ask_none))
        }
        Spacer(Modifier.height(24.dp))
    }
}

// =============================================================================== saved (177)

@Composable
private fun EvSaved(onBack: () -> Unit, go: (EvPage) -> Unit) {
    var past by remember { mutableStateOf(false) }
    var items by remember { mutableStateOf<List<DiscEventDto>?>(null) }
    LaunchedEffect(past, EventsStore.version) {
        items = null
        items = runCatching { ApiClient.events.interests(evAuth(), evZone(), if (past) 1 else 0).data }.getOrNull().orEmpty()
    }
    WaPage(title = stringResource(R.string.ev_saved), onBack = onBack) {
        Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Wa.Surface).padding(4.dp)) {
            listOf(false to R.string.ev_upcoming, true to R.string.ev_past).forEach { (p, label) ->
                Text(
                    stringResource(label), color = if (p == past) Color.White else Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                    modifier = Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(if (p == past) Wa.Red else Color.Transparent).clickable { past = p }.padding(vertical = 10.dp),
                )
            }
        }
        Spacer(Modifier.height(14.dp))
        EventList(items.orEmpty(), items == null, onOpen = { go(EvPage.Event(it.id)) }, empty = stringResource(R.string.ev_saved_empty))
        Spacer(Modifier.height(24.dp))
    }
}

// =============================================================================== settings (170–172)

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun EvSettings(onBack: () -> Unit) {
    val home = EventsStore.home
    var prefs by remember { mutableStateOf(home?.preferences ?: DiscPrefsDto()) }
    var follows by remember { mutableStateOf(home?.follows.orEmpty()) }
    var adding by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val save: (DiscPrefsDto) -> Unit = { p ->
        prefs = p
        scope.launch {
            val body = JsonObject().apply {
                add("categories", JsonArray().apply { p.categories.forEach { add(it) } })
                addProperty("alerts", p.alerts)
                addProperty("alert_days", p.alertDays)
                addProperty("family_only", p.familyOnly)
            }
            runCatching { ApiClient.events.savePreferences(evAuth(), body).data }.getOrNull()?.let { prefs = it }
            EventsStore.refresh()
        }
    }

    WaPage(title = stringResource(R.string.ev_settings), onBack = onBack) {
        WaSectionTitle(stringResource(R.string.ev_my_interests), topPadding = 4.dp)
        Text(stringResource(R.string.ev_my_interests_sub), color = Wa.Mut, fontSize = 12.5.sp)
        Spacer(Modifier.height(8.dp))
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            home?.categories.orEmpty().forEach { c ->
                val on = c.id in prefs.categories
                EvChip(c.name.orEmpty(), on, icon = c.emoji) { save(prefs.copy(categories = if (on) prefs.categories - c.id else prefs.categories + c.id)) }
            }
        }

        WaSectionTitle(stringResource(R.string.ev_alerts))
        WaCard(Modifier.fillMaxWidth(), padding = 8.dp) {
            ToggleLine(stringResource(R.string.ev_alerts_on), prefs.alerts, stringResource(R.string.ev_alerts_sub)) { save(prefs.copy(alerts = it)) }
            if (prefs.alerts) {
                Row(Modifier.padding(horizontal = 10.dp, vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf(3, 7, 14, 30).forEach { d -> EvChip(stringResource(R.string.ev_days_ahead, d), prefs.alertDays == d) { save(prefs.copy(alertDays = d)) } }
                }
            }
            ToggleLine(stringResource(R.string.ev_family_only), prefs.familyOnly, null) { save(prefs.copy(familyOnly = it)) }
        }

        WaSectionTitle(stringResource(R.string.ev_follows), trailing = {
            Text(stringResource(R.string.ev_follow_add), color = Wa.Red, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).clickable { adding = true }.padding(6.dp))
        })
        Text(stringResource(R.string.ev_follows_sub), color = Wa.Mut, fontSize = 12.5.sp)
        Spacer(Modifier.height(8.dp))
        follows.forEach { f ->
            Row(Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(16.dp)).background(Wa.Surface).padding(horizontal = 14.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                com.dorr.app.ui.screens.wallet.WalletFlag(f.country)
                Spacer(Modifier.width(10.dp))
                Text(f.name.orEmpty(), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                Icon(Icons.Rounded.Close, stringResource(R.string.ev_unfollow), tint = Wa.Mut, modifier = Modifier.size(22.dp).clip(CircleShape).clickable {
                    scope.launch { runCatching { ApiClient.events.unfollow(evAuth(), f.id).data }.getOrNull()?.let { follows = it }; EventsStore.refresh() }
                })
            }
        }
        if (follows.isEmpty()) Text(stringResource(R.string.ev_follows_empty), color = Wa.Soft, fontSize = 13.sp)
        Spacer(Modifier.height(24.dp))
    }

    if (adding) {
        CityPickerSheet(onDismiss = { adding = false }) { c ->
            adding = false
            scope.launch {
                val body = JsonObject().apply { addProperty("kind", "city"); addProperty("target_id", c.id) }
                runCatching { ApiClient.events.follow(evAuth(), body).data }.getOrNull()?.let { follows = it }
                EventsStore.refresh()
            }
        }
    }
}

@Composable
internal fun ToggleLine(title: String, on: Boolean, sub: String?, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(title, color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold)
            sub?.let { Text(it, color = Wa.Mut, fontSize = 12.sp) }
        }
        Switch(on, onChange, colors = SwitchDefaults.colors(checkedTrackColor = Wa.Red))
    }
}

// =============================================================================== organizers (180)

@Composable
private fun EvOrganizer(onBack: () -> Unit, go: (EvPage) -> Unit) {
    var data by remember { mutableStateOf<DiscOrganizerHomeDto?>(null) }
    var editing by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    LaunchedEffect(reload, EventsStore.version) { data = runCatching { ApiClient.events.organizer(evAuth(), evZone()).data }.getOrNull() ?: DiscOrganizerHomeDto() }
    val org = data?.organizer
    val canAdd = data?.canSubmit == true && org != null && org.status != "rejected" && org.status != "suspended"

    WaPage(
        title = stringResource(R.string.ev_organizer),
        onBack = onBack,
        cta = if (canAdd && !editing) ({ WaButton(stringResource(R.string.ev_org_add_event), { go(EvPage.Submit) }, icon = Icons.Rounded.Add) }) else null,
    ) {
        when {
            data == null -> WaSkeleton(Modifier.fillMaxWidth().height(160.dp), RoundedCornerShape(22.dp))
            org == null || editing -> OrganizerForm(org) { editing = false; reload++ }
            else -> {
                val (tone, label) = when (org.status) {
                    "verified" -> Tone.Green to stringResource(R.string.ev_org_verified)
                    "rejected" -> Tone.Red to stringResource(R.string.ev_org_rejected)
                    "suspended" -> Tone.Gray to stringResource(R.string.ev_org_suspended)
                    else -> Tone.Amber to stringResource(R.string.ev_org_pending)
                }
                WaCard(Modifier.fillMaxWidth().waRise(0), padding = 16.dp) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("🎤", fontSize = 26.sp)
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(org.name, color = Wa.Ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
                            Text(label, color = tone.fg, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 3.dp).clip(CircleShape).background(tone.bg).padding(horizontal = 10.dp, vertical = 3.dp))
                        }
                        Text(stringResource(R.string.ev_org_edit), color = Wa.Red, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).clickable { editing = true }.padding(6.dp))
                    }
                    org.reviewNote?.takeIf { it.isNotBlank() }?.let { Text(it, color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp)) }
                    Text(
                        stringResource(if (org.verified) R.string.ev_org_verified_note else R.string.ev_org_pending_note),
                        color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(top = 8.dp),
                    )
                }
                if (data?.canSubmit == false) WaNote(stringResource(R.string.ev_org_closed), Modifier.padding(top = 12.dp))
                WaSectionTitle(stringResource(R.string.ev_org_my_events))
                val events = data?.events.orEmpty()
                if (events.isEmpty()) Text(stringResource(R.string.ev_org_no_events), color = Wa.Soft, fontSize = 13.sp)
                events.forEachIndexed { i, e -> EventRow(e, Modifier.padding(bottom = 10.dp).waRise(i.coerceAtMost(6)), review = e.reviewStatus) { go(EvPage.Event(e.id)) } }
            }
        }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
private fun OrganizerForm(existing: com.dorr.app.network.DiscOrganizerDto?, onDone: () -> Unit) {
    var name by remember { mutableStateOf(existing?.name.orEmpty()) }
    var about by remember { mutableStateOf(existing?.about.orEmpty()) }
    var website by remember { mutableStateOf(existing?.website.orEmpty()) }
    var phone by remember { mutableStateOf(existing?.phone.orEmpty()) }
    var email by remember { mutableStateOf(existing?.email.orEmpty()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    if (existing == null) {
        Text("🎤", fontSize = 40.sp, modifier = Modifier.waRise(0))
        Text(stringResource(R.string.ev_org_intro_title), color = Wa.Ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.waRise(0))
        Text(stringResource(R.string.ev_org_intro), color = Wa.Mut, fontSize = 13.5.sp, modifier = Modifier.padding(top = 4.dp, bottom = 14.dp).waRise(0))
    }
    DorrTextField(name, { name = it.take(120) }, label = stringResource(R.string.ev_org_name), modifier = Modifier.fillMaxWidth())
    Spacer(Modifier.height(8.dp))
    DorrTextField(about, { about = it.take(1000) }, label = stringResource(R.string.ev_org_about), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
    Spacer(Modifier.height(8.dp))
    DorrTextField(website, { website = it.take(255) }, label = stringResource(R.string.ev_org_website), modifier = Modifier.fillMaxWidth())
    Spacer(Modifier.height(8.dp))
    DorrTextField(phone, { phone = it.take(30) }, label = stringResource(R.string.ev_org_phone), modifier = Modifier.fillMaxWidth())
    Spacer(Modifier.height(8.dp))
    DorrTextField(email, { email = it.take(191) }, label = stringResource(R.string.ev_org_email), modifier = Modifier.fillMaxWidth())
    error?.let { Text(it, color = Wa.Danger, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
    WaNote(stringResource(R.string.ev_org_review_note), Modifier.padding(top = 12.dp))
    WaButton(stringResource(if (existing == null) R.string.ev_org_apply else R.string.ev_save), {
        busy = true
        error = null
        scope.launch {
            val body = JsonObject().apply {
                addProperty("name", name.trim())
                listOf("about" to about, "website" to website, "phone" to phone, "email" to email).forEach { (k, v) -> if (v.isNotBlank()) addProperty(k, v.trim()) }
            }
            runCatching { ApiClient.events.applyOrganizer(evAuth(), body) }.onSuccess { onDone() }.onFailure { error = it.apiFailure().message }
            busy = false
        }
    }, enabled = name.trim().length >= 2, loading = busy, modifier = Modifier.fillMaxWidth().padding(top = 14.dp))
}

/** A new event from an organizer: its own local time where it happens; reviewed unless the organizer is verified. */
@OptIn(ExperimentalLayoutApi::class, ExperimentalMaterial3Api::class)
@Composable
private fun EvSubmit(onBack: () -> Unit) {
    val home = EventsStore.home
    val context = LocalContext.current
    var title by remember { mutableStateOf("") }
    var categoryId by remember { mutableStateOf<Int?>(null) }
    var city by remember { mutableStateOf<DiscCityDto?>(null) }
    var date by remember { mutableStateOf(LocalDate.now().plusDays(7)) }
    var time by remember { mutableStateOf(LocalTime.of(20, 0)) }
    var endTime by remember { mutableStateOf<LocalTime?>(null) }
    var venue by remember { mutableStateOf("") }
    var address by remember { mutableStateOf("") }
    var free by remember { mutableStateOf(true) }
    var price by remember { mutableStateOf("") }
    var booking by remember { mutableStateOf("") }
    var source by remember { mutableStateOf("") }
    var familyOk by remember { mutableStateOf(false) }
    var description by remember { mutableStateOf("") }
    var picking by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val sentPending = stringResource(R.string.ev_submit_pending)
    val sentLive = stringResource(R.string.ev_submit_live)
    val hm = DateTimeFormatter.ofPattern("HH:mm")

    WaPage(
        title = stringResource(R.string.ev_org_add_event),
        onBack = onBack,
        cta = {
            WaButton(stringResource(R.string.ev_submit), {
                busy = true
                error = null
                scope.launch {
                    val body = JsonObject().apply {
                        addProperty("title", title.trim())
                        categoryId?.let { addProperty("category_id", it) }
                        city?.let { addProperty("city_id", it.id) }
                        addProperty("starts_at", "$date ${time.format(hm)}")
                        endTime?.let { addProperty("ends_at", "${if (it.isBefore(time)) date.plusDays(1) else date} ${it.format(hm)}") }
                        addProperty("is_free", free)
                        if (!free && price.isNotBlank()) addProperty("price_text", price.trim())
                        addProperty("family_friendly", familyOk)
                        listOf("venue" to venue, "address" to address, "booking_url" to booking, "source_url" to source, "description" to description)
                            .forEach { (k, v) -> if (v.isNotBlank()) addProperty(k, v.trim()) }
                    }
                    runCatching { ApiClient.events.submit(evAuth(), body, evZone()).data }
                        .onSuccess { e ->
                            Toast.makeText(context, if (e?.reviewStatus == "approved") sentLive else sentPending, Toast.LENGTH_LONG).show()
                            EventsStore.changed()
                            onBack()
                        }
                        .onFailure { error = it.apiFailure().message }
                    busy = false
                }
            }, enabled = title.trim().length >= 3 && categoryId != null && city != null, loading = busy, icon = Icons.Rounded.Add)
        },
    ) {
        DorrTextField(title, { title = it.take(160) }, label = stringResource(R.string.ev_f_title), modifier = Modifier.fillMaxWidth())
        WaSectionTitle(stringResource(R.string.ev_f_category), topPadding = 14.dp)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            home?.categories.orEmpty().forEach { c -> EvChip(c.name.orEmpty(), categoryId == c.id, icon = c.emoji) { categoryId = c.id } }
        }
        Spacer(Modifier.height(14.dp))
        PickRow("📍", stringResource(R.string.ev_f_city), city?.let { listOfNotNull(it.name, it.country).joinToString(" · ") } ?: stringResource(R.string.ev_pick_city)) { picking = "city" }
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            PickRow("📅", stringResource(R.string.ev_f_date), dayText(date), Modifier.weight(1.3f)) { picking = "date" }
            PickRow("🕗", stringResource(R.string.ev_f_time), time.format(DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT)), Modifier.weight(1f)) { picking = "time" }
        }
        Spacer(Modifier.height(8.dp))
        PickRow("🏁", stringResource(R.string.ev_f_end), endTime?.format(DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT)) ?: stringResource(R.string.ev_f_end_none)) { picking = "end" }
        city?.timezone?.let { Text(stringResource(R.string.ev_f_local_hint, it.substringAfterLast('/').replace('_', ' ')), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp)) }
        Spacer(Modifier.height(12.dp))
        DorrTextField(venue, { venue = it.take(160) }, label = stringResource(R.string.ev_f_venue), modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(8.dp))
        DorrTextField(address, { address = it.take(255) }, label = stringResource(R.string.ev_f_address), modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(8.dp))
        WaCard(Modifier.fillMaxWidth(), padding = 8.dp) {
            ToggleLine(stringResource(R.string.ev_free), free, null) { free = it }
            ToggleLine(stringResource(R.string.ev_family), familyOk, null) { familyOk = it }
        }
        if (!free) {
            Spacer(Modifier.height(8.dp))
            DorrTextField(price, { price = it.take(80) }, label = stringResource(R.string.ev_f_price), modifier = Modifier.fillMaxWidth())
        }
        Spacer(Modifier.height(8.dp))
        DorrTextField(booking, { booking = it.take(500) }, label = stringResource(R.string.ev_f_booking), modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(8.dp))
        DorrTextField(source, { source = it.take(500) }, label = stringResource(R.string.ev_f_source), modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(8.dp))
        DorrTextField(description, { description = it.take(5000) }, label = stringResource(R.string.ev_f_description), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        error?.let { Text(it, color = Wa.Danger, fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp)) }
        Spacer(Modifier.height(24.dp))
    }

    when (picking) {
        "city" -> CityPickerSheet(onDismiss = { picking = null }) { city = it; picking = null }
        "date" -> {
            val state = rememberDatePickerState(initialSelectedDateMillis = date.atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli())
            DatePickerDialog(
                onDismissRequest = { picking = null },
                confirmButton = {
                    TextButton({ state.selectedDateMillis?.let { date = Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate() }; picking = null }) {
                        Text(stringResource(R.string.cal_ok), color = Wa.Red, fontWeight = FontWeight.Bold)
                    }
                },
                dismissButton = { TextButton({ picking = null }) { Text(stringResource(R.string.cal_cancel), color = Wa.Mut) } },
            ) { DatePicker(state) }
        }
        "time" -> com.dorr.app.ui.screens.chat.TimePickDialog(time, onDismiss = { picking = null }) { time = it; picking = null }
        "end" -> com.dorr.app.ui.screens.chat.TimePickDialog(endTime ?: time.plusHours(2), onDismiss = { picking = null }) { endTime = it; picking = null }
    }
}

// =============================================================================== shared bits

internal val EvPurple = Color(0xFF7C3AED)
internal val EvHeroBrush = Brush.linearGradient(listOf(Color(0xFF7C3AED), Color(0xFFDB2777), Color(0xFFF59E0B)))

@Composable
internal fun EvChip(text: String, selected: Boolean, icon: String? = null, onClick: () -> Unit) {
    Text(
        listOfNotNull(icon, text).joinToString(" "), color = if (selected) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1,
        modifier = Modifier.clip(CircleShape).background(if (selected) Wa.Red else Wa.Surface).border(1.dp, if (selected) Color.Transparent else Wa.Line, CircleShape)
            .clickable(onClick = onClick).padding(horizontal = 13.dp, vertical = 8.dp),
    )
}
