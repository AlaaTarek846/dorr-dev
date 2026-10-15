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
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.LocationCity
import androidx.compose.material.icons.rounded.Map
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
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
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.DiscCityDto
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

    /** All of a country's regions with something on (the sheet shows them at once). */
    suspend fun regions(countryId: Int): List<DiscRegionDto> {
        val all = mutableListOf<DiscRegionDto>()
        var page = 1
        while (page <= 10) {
            val r = runCatching { ApiClient.events.regions(evAuth(), countryId, page, 50) }.getOrNull() ?: break
            all += r.data.orEmpty()
            if (r.pagination?.hasMorePages != true) break
            page++
        }
        return all
    }

    suspend fun cities(regionId: Int): List<DiscCityDto> =
        runCatching { ApiClient.events.cities(evAuth(), regionId = regionId, withEvents = 1).data }.getOrNull().orEmpty()
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

/** The chosen place over the results — each part can be dropped with its ✕ (what's under it goes too). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun PlaceChips(place: EvPlace, onChange: (EvPlace) -> Unit) {
    if (!place.isSet) return
    FlowRow(Modifier.fillMaxWidth().animateContentSize(), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        place.countryName?.let { SetChip(Icons.Rounded.Public, it) { onChange(EvPlace()) } }
        place.regionName?.let { SetChip(Icons.Rounded.Map, it) { onChange(place.copy(regionId = null, regionName = null, cityId = null, cityName = null)) } }
        place.cityName?.let { SetChip(Icons.Rounded.LocationCity, it) { onChange(place.copy(cityId = null, cityName = null)) } }
    }
}

@Composable
private fun SetChip(icon: ImageVector, text: String, onRemove: () -> Unit) {
    Row(
        Modifier.clip(CircleShape).background(Wa.Red.copy(alpha = 0.1f)).padding(start = 10.dp, end = 4.dp, top = 4.dp, bottom = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = Wa.Red, modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(5.dp))
        Text(text, color = Wa.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        Icon(
            Icons.Rounded.Close, stringResource(R.string.ev_place_clear), tint = Wa.Red,
            modifier = Modifier.padding(start = 2.dp).size(22.dp).clip(CircleShape).clickable(onClick = onRemove).padding(3.dp),
        )
    }
}

/**
 * The place filter: country → region → city in one sheet. Each step opens once the one before is
 * picked, and changing a step clears what's under it. "Apply" narrows the list; "Clear" goes back
 * to everything where I am.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun EvPlaceSheet(current: EvPlace, myCountryCode: String?, onApply: (EvPlace) -> Unit, onClose: () -> Unit) {
    var place by remember { mutableStateOf(current) }
    var countries by remember { mutableStateOf<List<DiscCountryDto>?>(null) }
    var regions by remember { mutableStateOf<List<DiscRegionDto>?>(null) }
    var cities by remember { mutableStateOf<List<DiscCityDto>?>(null) }

    LaunchedEffect(Unit) {
        val list = EvPlaces.countries()
        countries = list
        // Nothing chosen yet: start from the country I'm in.
        if (place.countryId == null) list.firstOrNull { it.code == myCountryCode }?.let { place = place.copy(countryId = it.id, countryName = it.name?.takeIf { n -> n.isNotBlank() } ?: it.code) }
    }
    LaunchedEffect(place.countryId) {
        regions = null
        place.countryId?.let { regions = EvPlaces.regions(it) }
    }
    LaunchedEffect(place.regionId) {
        cities = null
        place.regionId?.let { cities = EvPlaces.cities(it) }
    }

    LocaleAwareBottomSheet(onDismissRequest = onClose, containerColor = Wa.Bg, shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp), fullHeight = true) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 22.dp)) {
            Text(stringResource(R.string.ev_place_title), color = Wa.Ink, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(4.dp))
            Column(Modifier.fillMaxWidth().heightIn(max = 560.dp).verticalScroll(rememberScrollState())) {
                PlaceStep(Icons.Rounded.Public, stringResource(R.string.ev_country), true, null) {
                    ChoiceFlow(countries, place.countryId, { it.id }, { it.name?.takeIf { n -> n.isNotBlank() } ?: it.code.orEmpty() }) { c ->
                        place = EvPlace(countryId = c.id, countryName = c.name?.takeIf { n -> n.isNotBlank() } ?: c.code)
                    }
                }
                PlaceStep(Icons.Rounded.Map, stringResource(R.string.ev_region), place.countryId != null, stringResource(R.string.ev_pick_country_first)) {
                    ChoiceFlow(regions, place.regionId, { it.id }, { "${it.name.orEmpty()} · ${it.eventsCount}" }, all = stringResource(R.string.ev_all_regions), onAll = {
                        place = place.copy(regionId = null, regionName = null, cityId = null, cityName = null)
                    }) { r -> place = place.copy(regionId = r.id, regionName = r.name, cityId = null, cityName = null) }
                }
                PlaceStep(Icons.Rounded.LocationCity, stringResource(R.string.ev_city), place.regionId != null, stringResource(R.string.ev_pick_region_first)) {
                    ChoiceFlow(cities, place.cityId, { it.id }, { it.name.orEmpty() }, all = stringResource(R.string.ev_all_cities), onAll = {
                        place = place.copy(cityId = null, cityName = null)
                    }) { c -> place = place.copy(cityId = c.id, cityName = c.name) }
                }
            }
            Spacer(Modifier.height(16.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                WaButton(stringResource(R.string.ev_place_clear), { onApply(EvPlace()) }, style = WaButtonStyle.Ghost, modifier = Modifier.weight(1f))
                WaButton(stringResource(R.string.ev_place_apply), { onApply(place) }, modifier = Modifier.weight(1f))
            }
        }
    }
}

@Composable
private fun PlaceStep(icon: ImageVector, title: String, open: Boolean, locked: String?, content: @Composable () -> Unit) {
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
private fun <T> ChoiceFlow(
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
