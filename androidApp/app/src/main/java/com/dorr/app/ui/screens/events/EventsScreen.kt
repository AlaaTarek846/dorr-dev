package com.dorr.app.ui.screens.events

import android.net.Uri

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
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Interests
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.FamilyRestroom
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.Title
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Map
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.ConfirmationNumber
import androidx.compose.material.icons.rounded.Link
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Explore
import androidx.compose.material.icons.rounded.FavoriteBorder
import androidx.compose.material.icons.rounded.Flight
import androidx.compose.material.icons.rounded.Public
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import com.dorr.app.ui.screens.profile.settingsSurface
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
import androidx.compose.runtime.snapshotFlow
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.filter
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
import com.dorr.app.network.DiscCountryDto
import com.dorr.app.network.DiscRegionDto
import com.dorr.app.network.DiscTravelDto
import com.dorr.app.network.DiscEventDto
import com.dorr.app.network.DiscHomeDto
import com.dorr.app.network.DiscOrganizerHomeDto
import com.dorr.app.network.DiscPrefsDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.wallet.WaPage
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.RowScope
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import com.dorr.app.ui.components.DarkHeroButton
import com.dorr.app.ui.components.DarkHeroPanel
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaCircleButton
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaNote
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
import com.dorr.app.network.AppLocale
import com.dorr.app.ui.locale.rememberAppLocaleFrame

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
    data class Browse(val categoryId: Int? = null, val place: EvPlace = EvPlace()) : EvPage
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

    EvFrame(
        title = stringResource(R.string.ev_title),
        onBack = onBack,
        actions = {
            DarkHeroButton(Icons.Rounded.FavoriteBorder, { go(EvPage.Saved) }, stringResource(R.string.ev_saved))
            DarkHeroButton(Icons.Rounded.Settings, { go(EvPage.Settings) }, stringResource(R.string.ev_settings))
        },
        hero = if (!EventsStore.off && home != null) ({ HomeHero(home, go) }) else null,
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

/** What's on around me, and "ask DORR AI" — inside the dark header panel, like the calendar's. */
@Composable
private fun ColumnScope.HomeHero(home: DiscHomeDto, go: (EvPage) -> Unit) {
    Spacer(Modifier.height(14.dp))
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
    Spacer(Modifier.height(4.dp))
}

@Composable
private fun HomeContent(home: DiscHomeDto, go: (EvPage) -> Unit) {
    // Quick ways in.
    Row(Modifier.fillMaxWidth().padding(top = 14.dp).waRise(1), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        QuickTile("✈️", stringResource(R.string.ev_travel), Modifier.weight(1f)) { go(EvPage.Travel) }
        QuickTile("🔎", stringResource(R.string.ev_browse), Modifier.weight(1f)) { go(EvPage.Browse()) }
        QuickTile("❤️", stringResource(R.string.ev_saved), Modifier.weight(1f)) { go(EvPage.Saved) }
        if (home.canSubmit) QuickTile("🎤", stringResource(R.string.ev_organizer), Modifier.weight(1f)) { go(EvPage.Organizer) }
    }

    // The regions of my country with something on — more load as the strip scrolls.
    RegionsStrip(home.regions, rise = 2) { r -> go(EvPage.Browse(place = EvPlace(regionId = r.id, regionName = r.name))) }
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

internal enum class When { Any, Today, Weekend, Week, Month }

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun EvBrowse(start: EvPage.Browse, onBack: () -> Unit, go: (EvPage) -> Unit) {
    val home = EventsStore.home
    var place by remember { mutableStateOf(start.place) }
    var placeOpen by remember { mutableStateOf(false) }
    var categoryId by remember { mutableStateOf(start.categoryId) }
    var whenPick by remember { mutableStateOf(When.Any) }
    var free by remember { mutableStateOf(false) }
    var family by remember { mutableStateOf(home?.preferences?.familyOnly == true) }
    var popular by remember { mutableStateOf(false) }
    val filter = EvFilter(place, categoryId, whenPick, free, family, popular)
    var query by remember { mutableStateOf("") }
    val items = remember { mutableStateListOf<DiscEventDto>() }
    var page by remember { mutableIntStateOf(1) }
    var more by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(true) }
    val list = rememberLazyListState()

    suspend fun load(next: Int) {
        loading = true
        val (from, to) = whenPick.range()
        runCatching {
            ApiClient.events.events(
                evAuth(), evZone(), cityId = place.cityId, categories = categoryId?.let { listOf(it) },
                regionId = place.regionId.takeIf { place.cityId == null }, countryId = place.countryId.takeIf { place.cityId == null && place.regionId == null },
                from = from, to = to, free = if (free) 1 else null, family = if (family) 1 else null, q = query.trim().ifEmpty { null },
                sort = if (popular) "popular" else null, page = next,
            )
        }.onSuccess { r ->
            if (next == 1) items.clear()
            val known = items.map { it.id }.toSet()
            items.addAll(r.data.orEmpty().filter { it.id !in known })
            page = next
            more = r.pagination?.hasMorePages == true
        }.onFailure { if (next == 1) items.clear(); more = false }
        loading = false
    }
    // A new filter starts over from the first page, back at the top.
    LaunchedEffect(place, categoryId, whenPick, free, family, popular, query) {
        load(1)
        list.scrollToItem(0)
    }
    // Infinite scroll: near the end of what's loaded, the next page comes.
    LaunchedEffect(list) {
        snapshotFlow { (list.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0) >= list.layoutInfo.totalItemsCount - 3 }
            .distinctUntilChanged().filter { it }
            .collect { if (more && !loading) load(page + 1) }
    }

    val title = place.cityName ?: place.regionName ?: stringResource(R.string.ev_browse)
    EvFrame(title = title, onBack = onBack, scroll = false) {
        LazyColumn(state = list, modifier = Modifier.fillMaxSize(), contentPadding = PaddingValues(bottom = 28.dp)) {
            item("search") {
                Row(Modifier.fillMaxWidth().waRise(0), verticalAlignment = Alignment.CenterVertically) {
                    DorrTextField(query, { query = it.take(100) }, placeholder = stringResource(R.string.ev_search_hint), icon = Icons.Rounded.Search, modifier = Modifier.weight(1f))
                    Spacer(Modifier.width(8.dp))
                    // The place filter: country → region → city.
                    Box {
                        WaCircleButton(Icons.Rounded.Tune, { placeOpen = true }, contentDescription = stringResource(R.string.ev_place_title))
                        Box(Modifier.align(Alignment.TopEnd)) { FilterBadgeDot(filter.count()) }
                    }
                }
            }
            item("place") {
                Column(Modifier.padding(top = 10.dp)) {
                    PlaceChips(place, home?.categories.orEmpty().firstOrNull { it.id == categoryId }, whenPick, onPlace = { place = it }, onKind = { categoryId = null }, onWhen = { whenPick = When.Any })
                }
            }
            item("gap") { Spacer(Modifier.height(14.dp)) }
            when {
                loading && items.isEmpty() -> items(4) { WaSkeleton(Modifier.fillMaxWidth().height(86.dp).padding(bottom = 10.dp), RoundedCornerShape(20.dp)) }
                items.isEmpty() -> item("empty") {
                    WaCard(Modifier.fillMaxWidth()) {
                        WaEmpty(Icons.Rounded.CalendarMonth, Tone.Gray, stringResource(R.string.ev_none_title), stringResource(R.string.ev_none_text)) {
                            if (place.isSet) WaButton(stringResource(R.string.ev_place_clear), { place = EvPlace() }, style = WaButtonStyle.Ghost)
                        }
                    }
                }
                else -> {
                    itemsIndexed(items, key = { _, e -> e.id }) { i, e -> EventRow(e, Modifier.padding(bottom = 10.dp).waRise(i.coerceAtMost(6))) { go(EvPage.Event(e.id)) } }
                    if (more || loading) item("more") {
                        Box(Modifier.fillMaxWidth().padding(vertical = 12.dp), contentAlignment = Alignment.Center) {
                            androidx.compose.material3.CircularProgressIndicator(color = Wa.Red, strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
                        }
                    } else item("end") {
                        Text(stringResource(R.string.ev_list_end), color = Wa.Mut, fontSize = 12.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(vertical = 10.dp))
                    }
                }
            }
        }
    }
    if (placeOpen) EvFilterSheet(filter, home?.categories.orEmpty(), home?.country, onApply = { f -> place = f.place; categoryId = f.categoryId; whenPick = f.whenPick; free = f.free; family = f.family; popular = f.popular; placeOpen = false }, onClose = { placeOpen = false })
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
    var country by remember { mutableStateOf<DiscCountryDto?>(null) }
    var region by remember { mutableStateOf<DiscRegionDto?>(null) }
    var countries by remember { mutableStateOf<List<DiscCountryDto>>(emptyList()) }
    var regions by remember { mutableStateOf<List<DiscRegionDto>>(emptyList()) }
    var from by remember { mutableStateOf(LocalDate.now().plusDays(7)) }
    var to by remember { mutableStateOf(LocalDate.now().plusDays(10)) }
    var picking by remember { mutableStateOf<String?>(null) }
    var result by remember { mutableStateOf<DiscTravelDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) { countries = EvPlaces.countries() }
    // The regions of the chosen country only; changing the country clears the region.
    LaunchedEffect(country?.id) {
        region = null
        regions = emptyList()
        country?.id?.let { regions = EvPlaces.regions(it) }
    }

    EvFrame(
        title = stringResource(R.string.ev_travel_title),
        onBack = onBack,
        cta = {
            WaButton(stringResource(R.string.ev_travel_go), {
                val c = country ?: return@WaButton
                busy = true
                error = null
                scope.launch {
                    runCatching {
                        ApiClient.events.travel(
                            evAuth(), from.toString(), to.toString(), evZone(),
                            regionId = region?.id, countryId = if (region == null) c.id else null,
                        ).data
                    }
                        .onSuccess { result = it }
                        .onFailure { error = it.apiFailure().message; result = null }
                    busy = false
                }
            }, icon = Icons.Rounded.Search, loading = busy, enabled = country != null)
        },
    ) {
        Text(stringResource(R.string.ev_travel_sub), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.waRise(0))
        Spacer(Modifier.height(14.dp))
        Column(Modifier.fillMaxWidth().waRise(1), verticalArrangement = Arrangement.spacedBy(FieldGap)) {
            FilterSelect(
                stringResource(R.string.ev_country), Icons.Rounded.Public, country?.let { it.name?.takeIf { n -> n.isNotBlank() } ?: it.code.orEmpty() } ?: stringResource(R.string.ev_pick_country),
                options = countries.map { c -> (c.name?.takeIf { n -> n.isNotBlank() } ?: c.code.orEmpty()) to { country = c; result = null } },
                selected = country?.let { it.name?.takeIf { n -> n.isNotBlank() } ?: it.code },
            )
            FilterSelect(
                stringResource(R.string.ev_region), Icons.Rounded.Map, region?.name ?: stringResource(R.string.ev_all_regions),
                enabled = country != null,
                options = listOf(stringResource(R.string.ev_all_regions) to { region = null; result = null }) +
                    regions.map { r -> r.name.orEmpty() to { region = r; result = null } },
                selected = region?.name,
            )
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                PickRow("🛫", stringResource(R.string.ev_from), dayText(from), Modifier.weight(1f)) { picking = "from" }
                PickRow("🛬", stringResource(R.string.ev_to), dayText(to), Modifier.weight(1f)) { picking = "to" }
            }
        }
        error?.let { Text(it, color = Wa.Danger, fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp)) }
        Spacer(Modifier.height(14.dp))
        val found = result
        if (found == null) {
            // Before a search: the empty state, asking where to.
            WaCard(Modifier.fillMaxWidth()) {
                WaEmpty(Icons.Rounded.Flight, Tone.Gray, stringResource(R.string.ev_travel_start_title), stringResource(R.string.ev_travel_start_text))
            }
        } else {
            (found.place?.timezone ?: found.city?.timezone)?.let { z ->
                WaNote(stringResource(R.string.ev_travel_zone_note, z.substringAfterLast('/').replace('_', ' ')), Modifier.padding(bottom = 4.dp))
            }
            WaSectionTitle(stringResource(R.string.ev_travel_found, found.items.size))
            EventList(found.items, false, onOpen = { go(EvPage.Event(it.id)) }, empty = stringResource(R.string.ev_travel_none))
        }
        Spacer(Modifier.height(24.dp))
    }

    val locale = rememberAppLocaleFrame()
    when (picking) {
        "from", "to" -> {
            val initial = if (picking == "from") from else to
            val state = rememberDatePickerState(initialSelectedDateMillis = initial.atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli())
            DatePickerDialog(
                onDismissRequest = { picking = null },
                confirmButton = {
                    locale.Provide {
                        TextButton({
                            state.selectedDateMillis?.let {
                                val d = Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate()
                                if (picking == "from") { from = d; if (to.isBefore(d)) to = d } else to = if (d.isBefore(from)) from else d
                            }
                            picking = null
                        }) { Text(stringResource(R.string.cal_ok), color = Wa.Red, fontWeight = FontWeight.Bold) }
                    }
                },
                dismissButton = { locale.Provide { TextButton({ picking = null }) { Text(stringResource(R.string.cal_cancel), color = Wa.Mut) } } },
            ) { locale.Provide { DatePicker(state) } }
        }
    }
}

/** The gap between two fields in the forms — the same everywhere. */
internal val FieldGap = 14.dp

/** A field's label, the same as the one the app's fields draw above themselves. */
@Composable
internal fun FieldLabel(text: String) {
    Text(text, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = com.dorr.app.ui.screens.profile.settingsInk(), modifier = Modifier.padding(bottom = 8.dp))
}

@Composable
internal fun PickRow(emoji: String, label: String, value: String, modifier: Modifier = Modifier, onClick: () -> Unit) {
    // The app's field (rounded, hairline, label above) — tapped to open its picker instead of typing.
    DorrTextField(value, {}, modifier = modifier.fillMaxWidth(), label = label, leadingIcon = { Text(emoji, fontSize = 17.sp) }, onClick = onClick)
}

internal fun dayText(d: LocalDate): String = d.format(DateTimeFormatter.ofPattern("EEE d MMM", AppLocale.java()))

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

    EvFrame(title = stringResource(R.string.ev_ask_title), onBack = onBack) {
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
    EvFrame(title = stringResource(R.string.ev_saved), onBack = onBack) {
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
private enum class EvSub { Menu, Interests, Alerts, Follows }

/** Discover's settings: a menu of three rows like the calendar's, each opening its own page. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun EvSettings(onBack: () -> Unit) {
    val home = EventsStore.home
    var prefs by remember { mutableStateOf(home?.preferences ?: DiscPrefsDto()) }
    var follows by remember { mutableStateOf(home?.follows.orEmpty()) }
    var adding by remember { mutableStateOf(false) }
    var sub by remember { mutableStateOf(EvSub.Menu) }
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
    BackHandler(enabled = sub != EvSub.Menu) { sub = EvSub.Menu }
    val title = when (sub) {
        EvSub.Menu -> stringResource(R.string.ev_settings)
        EvSub.Interests -> stringResource(R.string.ev_my_interests)
        EvSub.Alerts -> stringResource(R.string.ev_alerts)
        EvSub.Follows -> stringResource(R.string.ev_follows)
    }

    Box(Modifier.fillMaxSize()) {
        com.dorr.app.ui.screens.profile.PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            com.dorr.app.ui.screens.profile.SubHeader(title) { if (sub == EvSub.Menu) onBack() else sub = EvSub.Menu }
            Column(Modifier.weight(1f).fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(top = 6.dp, bottom = 16.dp)) {
                when (sub) {
                    EvSub.Menu -> {
                        com.dorr.app.ui.screens.calendar.CalSettingsRow(Icons.Rounded.Interests, R.string.ev_my_interests, R.string.ev_menu_interests_sub) { sub = EvSub.Interests }
                        com.dorr.app.ui.screens.calendar.CalSettingsRow(Icons.Rounded.NotificationsActive, R.string.ev_alerts, R.string.ev_menu_alerts_sub) { sub = EvSub.Alerts }
                        com.dorr.app.ui.screens.calendar.CalSettingsRow(Icons.Rounded.LocationOn, R.string.ev_follows, R.string.ev_menu_follows_sub) { sub = EvSub.Follows }
                    }

                    EvSub.Interests -> {
                        Text(stringResource(R.string.ev_my_interests_sub), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(bottom = 10.dp))
                        // One row per kind, like the animation setting: an icon well, the name, a ✓ on the ones chosen.
                        val kinds = home?.categories.orEmpty()
                        val dark = com.dorr.app.ui.screens.profile.settingsNight()
                        com.dorr.app.ui.screens.calendar.SettingsToggleCard(Modifier.waRise(0)) {
                            kinds.forEachIndexed { index, c ->
                                val on = c.id in prefs.categories
                                Row(
                                    Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp))
                                        .clickable { save(prefs.copy(categories = if (on) prefs.categories - c.id else prefs.categories + c.id)) }
                                        .padding(horizontal = 4.dp, vertical = 12.dp),
                                    verticalAlignment = Alignment.CenterVertically,
                                ) {
                                    Box(
                                        Modifier.size(34.dp).clip(RoundedCornerShape(10.dp))
                                            .background(if (dark) com.dorr.app.ui.screens.AccountDark.well else com.dorr.app.ui.screens.profile.settingsAccent().copy(alpha = 0.14f)),
                                        contentAlignment = Alignment.Center,
                                    ) { Text(c.emoji ?: "🎟️", fontSize = 17.sp) }
                                    Spacer(Modifier.width(10.dp))
                                    Text(c.name.orEmpty(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = com.dorr.app.ui.screens.profile.settingsInk(), modifier = Modifier.weight(1f))
                                    if (on) Icon(Icons.Rounded.CheckCircle, null, tint = com.dorr.app.ui.screens.profile.settingsAccent(), modifier = Modifier.size(20.dp))
                                }
                                if (index != kinds.lastIndex) Box(Modifier.fillMaxWidth().height(1.dp).background(if (dark) com.dorr.app.ui.screens.AccountDark.line else Color(0xFFF6E4E8)))
                            }
                        }
                    }

                    EvSub.Alerts -> {
                        com.dorr.app.ui.screens.calendar.SettingsToggleCard(Modifier.waRise(0)) {
                            com.dorr.app.ui.screens.calendar.SettingsToggleRow(Icons.Rounded.NotificationsActive, stringResource(R.string.ev_alerts_on), stringResource(R.string.ev_alerts_sub), prefs.alerts, divider = true) { save(prefs.copy(alerts = it)) }
                            com.dorr.app.ui.screens.calendar.SettingsToggleRow(Icons.Rounded.FamilyRestroom, stringResource(R.string.ev_family_only), null, prefs.familyOnly, divider = false) { save(prefs.copy(familyOnly = it)) }
                        }
                        if (prefs.alerts) {
                            WaSectionTitle(stringResource(R.string.ev_alert_days), Modifier.waRise(1), topPadding = 16.dp)
                            // One choice, like the animation setting: an icon well, the days, a ✓ on the chosen one.
                            val dark = com.dorr.app.ui.screens.profile.settingsNight()
                            val choices = listOf(3, 7, 14, 30)
                            com.dorr.app.ui.screens.calendar.SettingsToggleCard(Modifier.waRise(1)) {
                                choices.forEachIndexed { index, d ->
                                    val on = prefs.alertDays == d
                                    Row(
                                        Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { save(prefs.copy(alertDays = d)) }.padding(horizontal = 4.dp, vertical = 12.dp),
                                        verticalAlignment = Alignment.CenterVertically,
                                    ) {
                                        com.dorr.app.ui.screens.profile.PinkIcon(Icons.Rounded.Event)
                                        Spacer(Modifier.width(10.dp))
                                        Text(stringResource(R.string.ev_days_ahead, d), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = com.dorr.app.ui.screens.profile.settingsInk(), modifier = Modifier.weight(1f))
                                        if (on) Icon(Icons.Rounded.CheckCircle, null, tint = com.dorr.app.ui.screens.profile.settingsAccent(), modifier = Modifier.size(20.dp))
                                    }
                                    if (index != choices.lastIndex) Box(Modifier.fillMaxWidth().height(1.dp).background(if (dark) com.dorr.app.ui.screens.AccountDark.line else Color(0xFFF6E4E8)))
                                }
                            }
                        }
                    }

                    EvSub.Follows -> {
                        // The add button on top, the places under it.
                        WaButton(stringResource(R.string.ev_follow_add), { adding = true }, icon = Icons.Rounded.Add, modifier = Modifier.fillMaxWidth())
                        Text(stringResource(R.string.ev_follows_sub), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 10.dp, bottom = 10.dp))
                        follows.forEach { f ->
                            Row(
                                Modifier.fillMaxWidth().padding(bottom = 8.dp).settingsSurface(RoundedCornerShape(14.dp), 6.dp).padding(horizontal = 12.dp, vertical = 10.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                com.dorr.app.ui.screens.wallet.WalletFlag(f.country)
                                Spacer(Modifier.width(10.dp))
                                Text(f.name.orEmpty(), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                                Icon(Icons.Rounded.Close, stringResource(R.string.ev_unfollow), tint = Wa.Mut, modifier = Modifier.size(22.dp).clip(CircleShape).clickable {
                                    scope.launch { runCatching { ApiClient.events.unfollow(evAuth(), f.id).data }.getOrNull()?.let { follows = it }; EventsStore.refresh() }
                                })
                            }
                        }
                        if (follows.isEmpty()) Text(stringResource(R.string.ev_follows_empty), color = Wa.Soft, fontSize = 13.sp, modifier = Modifier.padding(vertical = 8.dp))
                    }
                }
            }
        }
    }

    if (adding) {
        // Places I follow are regions now (cities followed before still show).
        RegionPickerSheet(home?.country, follows.filter { it.kind == "region" }.map { it.targetId }.toSet(), onClose = { adding = false }, onPick = { r ->
            adding = false
            scope.launch {
                val body = JsonObject().apply { addProperty("kind", "region"); addProperty("target_id", r.id) }
                runCatching { ApiClient.events.follow(evAuth(), body).data }.getOrNull()?.let { follows = it }
                EventsStore.refresh()
            }
        })
    }
}

@Composable
internal fun ToggleLine(title: String, on: Boolean, sub: String?, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(title, color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold)
            sub?.let { Text(it, color = Wa.Mut, fontSize = 12.sp) }
        }
        com.dorr.app.ui.screens.profile.RedToggle(on, com.dorr.app.ui.screens.profile.settingsNight()) { onChange(!on) }
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

    EvFrame(
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
    DorrTextField(name, { name = it.take(120) }, label = stringResource(R.string.ev_org_name), icon = Icons.Rounded.Badge, modifier = Modifier.fillMaxWidth())
    Spacer(Modifier.height(FieldGap))
    DorrTextField(about, { about = it.take(1000) }, label = stringResource(R.string.ev_org_about), icon = Icons.Rounded.Info, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
    Spacer(Modifier.height(FieldGap))
    DorrTextField(website, { website = it.take(255) }, label = stringResource(R.string.ev_org_website), icon = Icons.Rounded.Language, modifier = Modifier.fillMaxWidth())
    Spacer(Modifier.height(FieldGap))
    DorrTextField(phone, { phone = it.take(30) }, label = stringResource(R.string.ev_org_phone), icon = Icons.Rounded.Call, modifier = Modifier.fillMaxWidth())
    Spacer(Modifier.height(FieldGap))
    DorrTextField(email, { email = it.take(191) }, label = stringResource(R.string.ev_org_email), icon = Icons.Rounded.Email, modifier = Modifier.fillMaxWidth())
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
    var cover by remember { mutableStateOf<Uri?>(null) }
    val pickCover = rememberCoverPicker { cover = it }
    var picking by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val sentPending = stringResource(R.string.ev_submit_pending)
    val sentLive = stringResource(R.string.ev_submit_live)
    val hm = DateTimeFormatter.ofPattern("HH:mm")

    EvFrame(
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
                    val coverPart = cover?.let { EventCover.part(context, it) }
                    runCatching {
                        if (coverPart != null) ApiClient.events.submitWithCover(evAuth(), EventCover.fields(body), coverPart, evZone()).data
                        else ApiClient.events.submit(evAuth(), body, evZone()).data
                    }
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
        CoverField(cover, onPick = pickCover, onRemove = { cover = null })
        Spacer(Modifier.height(FieldGap))
        DorrTextField(title, { title = it.take(160) }, label = stringResource(R.string.ev_f_title), icon = Icons.Rounded.Title, modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(FieldGap))
        FieldLabel(stringResource(R.string.ev_f_category))
        var categoryOpen by remember { mutableStateOf(false) }
        val categories = home?.categories.orEmpty()
        com.dorr.app.ui.screens.calendar.SelectRow(
            Icons.Rounded.Category,
            categories.firstOrNull { it.id == categoryId }?.let { listOfNotNull(it.emoji, it.name).joinToString(" ") } ?: stringResource(R.string.ev_f_category),
            categoryOpen, { categoryOpen = it },
        ) {
            categories.forEach { c ->
                val on = c.id == categoryId
                androidx.compose.material3.DropdownMenuItem(
                    text = { Text(listOfNotNull(c.emoji, c.name).joinToString(" "), color = if (on) Wa.Red else Wa.Ink, fontWeight = if (on) FontWeight.Bold else FontWeight.Medium) },
                    trailingIcon = { if (on) Icon(Icons.Rounded.Check, null, tint = Wa.Red, modifier = Modifier.size(16.dp)) },
                    onClick = { categoryId = c.id; categoryOpen = false },
                )
            }
        }
        Spacer(Modifier.height(FieldGap))
        PickRow("📍", stringResource(R.string.ev_f_city), city?.let { listOfNotNull(it.name, it.country).joinToString(" · ") } ?: stringResource(R.string.ev_pick_city)) { picking = "city" }
        Spacer(Modifier.height(FieldGap))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            PickRow("📅", stringResource(R.string.ev_f_date), dayText(date), Modifier.weight(1.3f)) { picking = "date" }
            PickRow("🕗", stringResource(R.string.ev_f_time), time.format(DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(AppLocale.java())), Modifier.weight(1f)) { picking = "time" }
        }
        Spacer(Modifier.height(FieldGap))
        PickRow("🏁", stringResource(R.string.ev_f_end), endTime?.format(DateTimeFormatter.ofLocalizedTime(FormatStyle.SHORT).withLocale(AppLocale.java())) ?: stringResource(R.string.ev_f_end_none)) { picking = "end" }
        city?.timezone?.let { Text(stringResource(R.string.ev_f_local_hint, it.substringAfterLast('/').replace('_', ' ')), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp)) }
        Spacer(Modifier.height(FieldGap))
        DorrTextField(venue, { venue = it.take(160) }, label = stringResource(R.string.ev_f_venue), icon = Icons.Rounded.Place, modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(FieldGap))
        DorrTextField(address, { address = it.take(255) }, label = stringResource(R.string.ev_f_address), icon = Icons.Rounded.Map, modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(FieldGap))
        WaCard(Modifier.fillMaxWidth(), padding = 8.dp) {
            // Side by side: two short switches share a row.
            Row(Modifier.fillMaxWidth()) {
                Box(Modifier.weight(1f)) { ToggleLine(stringResource(R.string.ev_free), free, null) { free = it } }
                Box(Modifier.weight(1f)) { ToggleLine(stringResource(R.string.ev_family), familyOk, null) { familyOk = it } }
            }
        }
        if (!free) {
            Spacer(Modifier.height(FieldGap))
            DorrTextField(price, { price = it.take(80) }, label = stringResource(R.string.ev_f_price), icon = Icons.Rounded.Payments, modifier = Modifier.fillMaxWidth())
        }
        Spacer(Modifier.height(FieldGap))
        DorrTextField(booking, { booking = it.take(500) }, label = stringResource(R.string.ev_f_booking), icon = Icons.Rounded.ConfirmationNumber, modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(FieldGap))
        DorrTextField(source, { source = it.take(500) }, label = stringResource(R.string.ev_f_source), icon = Icons.Rounded.Link, modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(FieldGap))
        DorrTextField(description, { description = it.take(5000) }, label = stringResource(R.string.ev_f_description), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        error?.let { Text(it, color = Wa.Danger, fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp)) }
        Spacer(Modifier.height(24.dp))
    }

    val locale = rememberAppLocaleFrame()
    when (picking) {
        "city" -> CityPickerSheet(onDismiss = { picking = null }) { city = it; picking = null }
        "date" -> {
            val state = rememberDatePickerState(initialSelectedDateMillis = date.atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli())
            DatePickerDialog(
                onDismissRequest = { picking = null },
                confirmButton = {
                    locale.Provide {
                        TextButton({ state.selectedDateMillis?.let { date = Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate() }; picking = null }) {
                            Text(stringResource(R.string.cal_ok), color = Wa.Red, fontWeight = FontWeight.Bold)
                        }
                    }
                },
                dismissButton = { locale.Provide { TextButton({ picking = null }) { Text(stringResource(R.string.cal_cancel), color = Wa.Mut) } } },
            ) { locale.Provide { DatePicker(state) } }
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


/**
 * The frame of every Discover page: the dark header panel the calendar has (title in Cairo Black, round
 * translucent buttons, back last), an optional hero under the title, then the page.
 */
@Composable
internal fun EvFrame(
    title: String,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    actions: @Composable RowScope.() -> Unit = {},
    cta: (@Composable ColumnScope.() -> Unit)? = null,
    scroll: Boolean = true,
    hero: (@Composable ColumnScope.() -> Unit)? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    WaPage(title = title, onBack = onBack, modifier = modifier, showHeader = false, cta = cta, scroll = scroll) {
        DarkHeroPanel {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(
                    title,
                    color = Color.White,
                    fontSize = 22.sp,
                    fontWeight = FontWeight.Black,
                    fontFamily = com.dorr.app.ui.theme.CairoFontFamily,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f),
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    actions()
                    DarkHeroButton(Icons.AutoMirrored.Rounded.ArrowBack, onBack, stringResource(R.string.common_back), flip = true)
                }
            }
            hero?.invoke(this)
        }
        Spacer(Modifier.height(14.dp))
        content()
    }
}
