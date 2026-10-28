package com.dorr.app.ui.screens.events

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.LocationCity
import androidx.compose.material.icons.rounded.Map
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.ui.draw.alpha
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.Schedule
import com.dorr.app.network.DiscCategoryDto
import androidx.compose.material.icons.rounded.ConfirmationNumber
import androidx.compose.material.icons.rounded.FamilyRestroom
import androidx.compose.material.icons.rounded.LocalFireDepartment
import androidx.compose.material.icons.rounded.Search
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.DiscCityDto
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.network.DiscCountryDto
import com.dorr.app.network.DiscRegionDto
import com.dorr.app.network.DiscRegionsDto
import com.dorr.app.ui.locale.LocaleAwareBottomSheet
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.filter

/*
 * Where events are (Discover): the regions of my country on the home, a batch at a time as the
 * strip scrolls, and the browse page's place filter — country → region → city, each step opening
 * the next.
 */

/** The place a browse is narrowed to; null = not chosen (the country defaults to where I am). */
internal data class EvPlace(
    val countryId: Int? = null,
    val countryName: String? = null,
    val regionId: Int? = null,
    val regionName: String? = null,
    val cityId: Int? = null,
    val cityName: String? = null,
) {
    val isSet: Boolean get() = countryId != null || regionId != null || cityId != null
}

/** Countries don't change while Discover is open: loaded once. */
internal object EvPlaces {
    private var countries: List<DiscCountryDto>? = null

    suspend fun countries(): List<DiscCountryDto> =
        countries ?: runCatching { ApiClient.events.countries(evAuth()).data }.getOrNull().orEmpty().also { if (it.isNotEmpty()) countries = it }

    /** All of a country's dashboard regions (the filter); `withEvents` is the home strip. */
    suspend fun regions(countryId: Int, withEvents: Boolean = false): List<DiscRegionDto> {
        val all = mutableListOf<DiscRegionDto>()
        var page = 1
        while (page <= 10) {
            val r = runCatching { ApiClient.events.regions(evAuth(), countryId, page, 50, withEvents = if (withEvents) 1 else 0) }.getOrNull() ?: break
            all += r.data.orEmpty()
            if (r.pagination?.hasMorePages != true) break
            page++
        }
        return all
    }

    suspend fun cities(regionId: Int, withEvents: Boolean = false): List<DiscCityDto> =
        runCatching { ApiClient.events.cities(evAuth(), regionId = regionId, withEvents = if (withEvents) 1 else 0).data }.getOrNull().orEmpty()
}

/**
 * The home's regions: the first batch comes with the home, the next ones load as the strip nears its
 * end (a small spinner at the tail while they come).
 */
@Composable
internal fun RegionsStrip(first: DiscRegionsDto, rise: Int, onOpen: (DiscRegionDto) -> Unit) {
    val items = remember(first) { mutableStateListOf<DiscRegionDto>().apply { addAll(first.items) } }
    var page by remember(first) { mutableIntStateOf(first.meta.currentPage) }
    var more by remember(first) { mutableStateOf(first.meta.hasMorePages) }
    var loading by remember { mutableStateOf(false) }
    val state = rememberLazyListState()
    if (items.isEmpty()) return

    LaunchedEffect(state, first) {
        snapshotFlow { (state.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0) >= state.layoutInfo.totalItemsCount - 3 }
            .distinctUntilChanged().filter { it }
            .collect {
                if (!more || loading) return@collect
                loading = true
                runCatching { ApiClient.events.regions(evAuth(), page = page + 1) }.getOrNull()?.let { r ->
                    val known = items.map { it.id }.toSet()
                    items.addAll(r.data.orEmpty().filter { it.id !in known })
                    page += 1
                    more = r.pagination?.hasMorePages == true
                } ?: run { more = false }
                loading = false
            }
    }

    WaSectionTitle(stringResource(R.string.ev_regions), Modifier.waRise(rise))
    LazyRow(Modifier.waRise(rise), state = state, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(items, key = { it.id }) { r -> RegionChip(r) { onOpen(r) } }
        if (loading) item("loading") {
            Box(Modifier.height(38.dp).width(44.dp), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(color = Wa.Red, strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
            }
        }
    }
}

/** "📍 Riyadh  12" — the region and how many events it has. */
@Composable
private fun RegionChip(region: DiscRegionDto, onClick: () -> Unit) {
    Row(
        Modifier.clip(CircleShape).background(Wa.Surface).border(1.dp, Wa.Line, CircleShape).clickable(onClick = onClick)
            .padding(start = 12.dp, end = 6.dp, top = 6.dp, bottom = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text("📍", fontSize = 13.sp)
        Spacer(Modifier.width(5.dp))
        Text(region.name.orEmpty(), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        Spacer(Modifier.width(6.dp))
        Text(
            region.eventsCount.toString(), color = Wa.Red, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold,
            modifier = Modifier.clip(CircleShape).background(Wa.Red.copy(alpha = 0.1f)).padding(horizontal = 7.dp, vertical = 2.dp),
        )
    }
}

/** What the list is narrowed to, under the search — one row, each chip drops that part with ✕. */
@Composable
internal fun FilterChips(filter: EvFilter, kind: DiscCategoryDto?, onChange: (EvFilter) -> Unit) {
    val place = filter.place
    if (!place.isSet && kind == null && filter.whenPick == When.Any && !filter.free && !filter.family && !filter.popular) return
    data class Chip(val icon: ImageVector, val text: String, val onRemove: () -> Unit)
    val chips = buildList {
        place.countryName?.let { add(Chip(Icons.Rounded.Public, it) { onChange(filter.copy(place = EvPlace())) }) }
        if (place.cityId == null) {
            place.regionName?.let { add(Chip(Icons.Rounded.Map, it) { onChange(filter.copy(place = place.copy(regionId = null, regionName = null, cityId = null, cityName = null))) }) }
        }
        place.cityName?.let { add(Chip(Icons.Rounded.LocationCity, it) { onChange(filter.copy(place = place.copy(cityId = null, cityName = null))) }) }
        kind?.let { c -> add(Chip(Icons.Rounded.Category, listOfNotNull(c.emoji, c.name).joinToString(" ")) { onChange(filter.copy(categoryId = null)) }) }
        if (filter.whenPick != When.Any) {
            val label = stringResource(when (filter.whenPick) {
                When.Today -> R.string.ev_when_today
                When.Weekend -> R.string.ev_when_weekend
                When.Week -> R.string.ev_when_week
                else -> R.string.ev_when_month
            })
            add(Chip(Icons.Rounded.Schedule, label) { onChange(filter.copy(whenPick = When.Any)) })
        }
        if (filter.free) add(Chip(Icons.Rounded.ConfirmationNumber, stringResource(R.string.ev_free)) { onChange(filter.copy(free = false)) })
        if (filter.family) add(Chip(Icons.Rounded.FamilyRestroom, stringResource(R.string.ev_family)) { onChange(filter.copy(family = false)) })
        if (filter.popular) add(Chip(Icons.Rounded.LocalFireDepartment, stringResource(R.string.ev_popular)) { onChange(filter.copy(popular = false)) })
    }
    LazyRow(
        Modifier.fillMaxWidth().padding(top = 10.dp).animateContentSize(),
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        items(chips.size) { i ->
            val chip = chips[i]
            FilterChip(chip.icon, chip.text, chip.onRemove)
        }
    }
}

@Composable
private fun FilterChip(icon: ImageVector, text: String, onRemove: () -> Unit) {
    Row(
        Modifier
            .height(34.dp)
            .clip(CircleShape)
            .background(Wa.Red.copy(alpha = 0.12f))
            .border(1.dp, Wa.Red.copy(alpha = 0.28f), CircleShape)
            .padding(start = 10.dp, end = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = Wa.Red, modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = Wa.Red, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.widthIn(max = 148.dp))
        Box(
            Modifier.padding(start = 2.dp).size(24.dp).clip(CircleShape).clickable(onClick = onRemove),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Rounded.Close, stringResource(R.string.ev_place_clear), tint = Wa.Red, modifier = Modifier.size(14.dp))
        }
    }
}

/** What the browse page is narrowed to besides the place: the kind and the time. */
internal data class EvFilter(
    val place: EvPlace = EvPlace(),
    val categoryId: Int? = null,
    val whenPick: When = When.Any,
    val free: Boolean = false,
    val family: Boolean = false,
    val popular: Boolean = false,
) {
    fun count(): Int = place.count() + listOf(categoryId != null, whenPick != When.Any, free, family, popular).count { it }
}

/**
 * The browse filter, one select for each thing: country → region → city (each opens once the one
 * before is picked, changing one clears what is under it), then the kind and the time. "Apply"
 * narrows the list; "Clear" goes back to everything where I am.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun EvFilterSheet(current: EvFilter, categories: List<DiscCategoryDto>, myCountryCode: String?, onApply: (EvFilter) -> Unit, onClose: () -> Unit) {
    var filter by remember { mutableStateOf(current) }
    var countries by remember { mutableStateOf<List<DiscCountryDto>?>(null) }
    var regions by remember { mutableStateOf<List<DiscRegionDto>?>(null) }
    var cities by remember { mutableStateOf<List<DiscCityDto>?>(null) }
    val place = filter.place
    fun setPlace(next: EvPlace) { filter = filter.copy(place = next) }

    LaunchedEffect(Unit) {
        val mine = AuthSession.user?.country?.code ?: myCountryCode
        val list = EvPlaces.countries().withMineFirst(mine)
        countries = list
        // Nothing chosen yet: the country I signed up with, else where I am.
        if (place.countryId == null) {
            val start = list.firstOrNull { it.code.equals(mine, ignoreCase = true) } ?: list.firstOrNull()
            start?.let { setPlace(place.copy(countryId = it.id, countryName = it.name?.takeIf { n -> n.isNotBlank() } ?: it.code)) }
        }
    }
    LaunchedEffect(place.countryId) {
        regions = null
        place.countryId?.let { regions = EvPlaces.regions(it) }
    }
    LaunchedEffect(place.regionId) {
        cities = null
        place.regionId?.let { cities = EvPlaces.cities(it) }
    }
    val whens = listOf(When.Any to R.string.ev_when_any, When.Today to R.string.ev_when_today, When.Weekend to R.string.ev_when_weekend, When.Week to R.string.ev_when_week, When.Month to R.string.ev_when_month)
    val all = stringResource(R.string.ev_all_kinds)

    LocaleAwareBottomSheet(onDismissRequest = onClose, containerColor = Wa.Bg, shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp), fullHeight = true) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 22.dp)) {
            Text(stringResource(R.string.ev_filter_title), color = Wa.Ink, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            Column(Modifier.fillMaxWidth().heightIn(max = 560.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(FieldGap)) {
                // The quick ones first, as the chips they were on the page.
                androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    EvChip(stringResource(R.string.ev_free), filter.free, icon = "🎟️") { filter = filter.copy(free = !filter.free) }
                    EvChip(stringResource(R.string.ev_family), filter.family, icon = "👨‍👩‍👧") { filter = filter.copy(family = !filter.family) }
                    EvChip(stringResource(R.string.ev_popular), filter.popular, icon = "🔥") { filter = filter.copy(popular = !filter.popular) }
                }
                FilterSelect(
                    stringResource(R.string.ev_f_category), Icons.Rounded.Category,
                    categories.firstOrNull { it.id == filter.categoryId }?.let { listOfNotNull(it.emoji, it.name).joinToString(" ") } ?: all,
                    options = listOf(all to { filter = filter.copy(categoryId = null) }) +
                        categories.map { c -> listOfNotNull(c.emoji, c.name).joinToString(" ") to { filter = filter.copy(categoryId = c.id) } },
                    selected = categories.firstOrNull { it.id == filter.categoryId }?.let { listOfNotNull(it.emoji, it.name).joinToString(" ") },
                )
                val whenLabels = whens.map { (w, label) -> w to stringResource(label) }
                FilterSelect(
                    stringResource(R.string.ev_when), Icons.Rounded.Schedule, whenLabels.first { it.first == filter.whenPick }.second,
                    options = whenLabels.map { (w, label) -> label to { filter = filter.copy(whenPick = w) } },
                    selected = whenLabels.first { it.first == filter.whenPick }.second,
                )
                FilterSelect(
                    stringResource(R.string.ev_country), Icons.Rounded.Public, place.countryName ?: stringResource(R.string.ev_country),
                    options = countries.orEmpty().map { c -> (c.name?.takeIf { n -> n.isNotBlank() } ?: c.code.orEmpty()) to { setPlace(EvPlace(countryId = c.id, countryName = c.name?.takeIf { n -> n.isNotBlank() } ?: c.code)) } },
                    selected = place.countryName,
                )
                FilterSelect(
                    stringResource(R.string.ev_region), Icons.Rounded.Map, place.regionName ?: stringResource(R.string.ev_all_regions),
                    enabled = place.countryId != null,
                    options = listOf(stringResource(R.string.ev_all_regions) to { setPlace(place.copy(regionId = null, regionName = null, cityId = null, cityName = null)) }) +
                        regions.orEmpty().map { r -> r.name.orEmpty() to { setPlace(place.copy(regionId = r.id, regionName = r.name, cityId = null, cityName = null)) } },
                    selected = place.regionName,
                )
                FilterSelect(
                    stringResource(R.string.ev_city), Icons.Rounded.LocationCity, place.cityName ?: stringResource(R.string.ev_all_cities),
                    enabled = place.regionId != null,
                    options = listOf(stringResource(R.string.ev_all_cities) to { setPlace(place.copy(cityId = null, cityName = null)) }) +
                        cities.orEmpty().map { c -> c.name.orEmpty() to { setPlace(place.copy(cityId = c.id, cityName = c.name)) } },
                    selected = place.cityName,
                )
            }
            Spacer(Modifier.height(16.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                WaButton(stringResource(R.string.ev_place_clear), { onApply(EvFilter()) }, style = WaButtonStyle.Ghost, modifier = Modifier.weight(1f))
                WaButton(stringResource(R.string.ev_place_apply), { onApply(filter) }, modifier = Modifier.weight(1f))
            }
        }
    }
}

/** A select with a label above; locked (dimmed, doesn't open) until `enabled`; searchable; a ✓ on the chosen one. */
@Composable
internal fun FilterSelect(label: String, icon: ImageVector, value: String, options: List<Pair<String, () -> Unit>>, selected: String?, enabled: Boolean = true) {
    var open by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    LaunchedEffect(open) { if (!open) query = "" }
    val shown = options.filter { query.isBlank() || it.first.contains(query.trim(), ignoreCase = true) }
    val searchHint = stringResource(R.string.ev_filter_search)
    val none = stringResource(R.string.ev_place_none)
    Column(Modifier.fillMaxWidth().alpha(if (enabled) 1f else 0.5f)) {
        if (label.isNotBlank()) FieldLabel(label)
        com.dorr.app.ui.screens.calendar.SelectRow(icon, value, open && enabled, { if (enabled) open = it }) {
            DorrTextField(
                query, { query = it.take(60) },
                placeholder = searchHint,
                icon = Icons.Rounded.Search,
                modifier = Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 6.dp),
            )
            if (shown.isEmpty()) {
                androidx.compose.material3.DropdownMenuItem(text = { Text(none, color = Wa.Mut) }, onClick = { open = false }, enabled = false)
            }
            shown.forEach { (text, pick) ->
                val on = text == selected
                androidx.compose.material3.DropdownMenuItem(
                    text = { Text(text, color = if (on) Wa.Red else Wa.Ink, fontWeight = if (on) FontWeight.Bold else FontWeight.Medium) },
                    trailingIcon = { if (on) Icon(Icons.Rounded.Check, null, tint = Wa.Red, modifier = Modifier.size(16.dp)) },
                    onClick = { pick(); open = false },
                )
            }
        }
    }
}

private fun List<DiscCountryDto>.withMineFirst(code: String?): List<DiscCountryDto> {
    val mine = code?.lowercase()
    return sortedWith(compareByDescending<DiscCountryDto> { it.code?.lowercase() == mine }.thenBy { it.name.orEmpty() })
}

@Composable
internal fun PlaceStep(icon: ImageVector, title: String, open: Boolean, locked: String?, content: @Composable () -> Unit) {
    Row(Modifier.padding(top = 14.dp, bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(28.dp).clip(CircleShape).background(if (open) Wa.Red.copy(alpha = 0.12f) else Wa.Line), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = if (open) Wa.Red else Wa.Mut, modifier = Modifier.size(16.dp))
        }
        Spacer(Modifier.width(8.dp))
        Text(title, color = if (open) Wa.Ink else Wa.Mut, fontSize = 15.sp, fontWeight = FontWeight.Bold)
    }
    AnimatedVisibility(open, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) { content() }
    if (!open && locked != null) Text(locked, color = Wa.Mut, fontSize = 12.sp)
}

/** The choices of one step as chips; skeleton chips while they load, a line when there are none. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun <T> ChoiceFlow(
    options: List<T>?,
    selected: Int?,
    id: (T) -> Int,
    label: (T) -> String,
    all: String? = null,
    onAll: () -> Unit = {},
    onPick: (T) -> Unit,
) {
    when {
        options == null -> Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            repeat(3) { WaSkeleton(Modifier.width(78.dp).height(34.dp), CircleShape) }
        }
        options.isEmpty() -> Text(stringResource(R.string.ev_place_none), color = Wa.Mut, fontSize = 12.5.sp)
        else -> FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            if (all != null) EvChip(all, selected == null, onClick = onAll)
            options.forEach { o -> EvChip(label(o), selected == id(o)) { onPick(o) } }
        }
    }
}

/** How many place parts are chosen — the badge on the filter button. */
internal fun EvPlace.count(): Int = listOf(countryId, regionId, cityId).count { it != null }

@Composable
internal fun FilterBadgeDot(count: Int) {
    if (count <= 0) return
    Text(
        count.toString(), color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.ExtraBold,
        modifier = Modifier.clip(CircleShape).background(Wa.Red).padding(horizontal = 6.dp, vertical = 1.dp),
    )
}

/**
 * Pick a region to follow: the country first (mine is chosen), then its regions with something on, as
 * rows — tap one to follow it.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun RegionPickerSheet(myCountryCode: String?, followed: Set<Int>, onPick: (DiscRegionDto) -> Unit, onClose: () -> Unit) {
    var countries by remember { mutableStateOf<List<DiscCountryDto>?>(null) }
    var countryId by remember { mutableStateOf<Int?>(null) }
    var regions by remember { mutableStateOf<List<DiscRegionDto>?>(null) }

    LaunchedEffect(Unit) {
        val list = EvPlaces.countries()
        countries = list
        countryId = (list.firstOrNull { it.code.equals(AuthSession.user?.country?.code ?: myCountryCode, ignoreCase = true) } ?: list.firstOrNull())?.id
    }
    LaunchedEffect(countryId) {
        regions = null
        countryId?.let { regions = EvPlaces.regions(it) }
    }

    LocaleAwareBottomSheet(onDismissRequest = onClose, containerColor = Wa.Bg, shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp), fullHeight = true) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 22.dp)) {
            Text(stringResource(R.string.ev_pick_region), color = Wa.Ink, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
            PlaceStep(Icons.Rounded.Public, stringResource(R.string.ev_country), true, null) {
                ChoiceFlow(countries, countryId, { it.id }, { it.name?.takeIf { n -> n.isNotBlank() } ?: it.code.orEmpty() }) { countryId = it.id }
            }
            Spacer(Modifier.height(10.dp))
            Column(Modifier.fillMaxWidth().heightIn(max = 420.dp).verticalScroll(rememberScrollState())) {
                when {
                    regions == null -> repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(46.dp).padding(bottom = 8.dp), RoundedCornerShape(14.dp)) }
                    regions.orEmpty().isEmpty() -> Text(stringResource(R.string.ev_place_none), color = Wa.Mut, fontSize = 12.5.sp)
                    else -> regions.orEmpty().forEach { r ->
                        val done = r.id in followed
                        Row(
                            Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(14.dp)).background(Wa.Surface)
                                .clickable(enabled = !done) { onPick(r) }.padding(horizontal = 14.dp, vertical = 12.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Rounded.Map, null, tint = Wa.Red, modifier = Modifier.size(20.dp))
                            Spacer(Modifier.width(10.dp))
                            Text(r.name.orEmpty(), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                            Text(r.eventsCount.toString(), color = Wa.Mut, fontSize = 12.sp)
                            if (done) Icon(Icons.Rounded.Check, null, tint = Wa.Red, modifier = Modifier.padding(start = 8.dp).size(18.dp))
                        }
                    }
                }
            }
        }
    }
}
