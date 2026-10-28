package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.FilterList
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.StarBorder
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
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.ProjectBudgetDto
import com.dorr.freelance.net.ProjectDto
import com.dorr.freelance.net.SpecialtyNodeDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.SettingsScreenTitle
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.OffsetDateTime

private const val PAGE_SIZE = 15

/** What the filter sheet holds. Money is typed in the currency's main unit and sent in minor units. */
private data class ProjectFilter(
    val specialty: SpecialtyNodeDto? = null,
    val budgetMin: String = "",
    val budgetMax: String = "",
    val durationMax: String = "",
) {
    val active get() = listOf(specialty != null, budgetMin.isNotBlank(), budgetMax.isNotBlank(), durationMax.isNotBlank()).count { it }
}

private fun minor(text: String): Long? = text.takeIf { it.isNotBlank() }?.let { parseMinor(it) }

/** "5 days ago" in the notification wording (the notices' time strings are reused). */
@Composable
private fun publishedAgo(iso: String?): String? {
    val minutes = runCatching { Duration.between(OffsetDateTime.parse(iso), OffsetDateTime.now()).toMinutes().toInt() }.getOrNull()?.coerceAtLeast(0) ?: return null
    return when {
        minutes < 1 -> stringResource(R.string.notif_time_now)
        minutes < 60 -> stringResource(R.string.notif_time_minutes_ago, minutes)
        minutes < 24 * 60 -> stringResource(R.string.notif_time_hours_ago, minutes / 60)
        minutes < 2 * 24 * 60 -> stringResource(R.string.notif_time_yesterday)
        minutes < 7 * 24 * 60 -> stringResource(R.string.notif_time_days_ago, minutes / (24 * 60))
        else -> stringResource(R.string.notif_time_weeks_ago, minutes / (7 * 24 * 60))
    }
}

private fun budgetText(b: ProjectBudgetDto): String? {
    val low = b.minMinor ?: return null
    val range = if (b.type == "range" && b.maxMinor != null) "${formatMinor(low, null)} – ${formatMinor(b.maxMinor, null)}" else formatMinor(low, null)
    return "‎$range ${b.currency}"
}

/** "N days left" while offers are open, "closed for offers" once they are not. */
@Composable
private fun offersLine(p: ProjectDto): Pair<String, Boolean>? {
    if (p.offers.closeReason == "chosen") return stringResource(R.string.projects_offer_chosen) to false
    if (!p.offers.accepting) return stringResource(R.string.projects_offers_closed) to false
    val left = runCatching { Duration.between(OffsetDateTime.now(), OffsetDateTime.parse(p.offers.deadlineAt)).toDays().toInt() }.getOrNull() ?: return null
    return stringResource(if (left <= 0) R.string.projects_offers_last_day else R.string.projects_offers_left, left) to true
}

// ---------------------------------------------------------------- the list (a tab)

/**
 * Projects (FL-206): the published projects of my country, newest first — a search box, a filter sheet (specialty, budget, duration),
 * the saved ones, 15 at a time with infinite scroll. Read when the page opens and when the connection returns; nothing on a timer.
 */
@Composable
fun ProjectsTab(onOpenProject: (String) -> Unit, onOpenMyOffers: () -> Unit = {}, onOpenInvitations: () -> Unit = {}) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val night = settingsNight()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)

    var query by rememberSaveable { mutableStateOf("") }
    var savedOnly by rememberSaveable { mutableStateOf(false) }
    // My specialties by default when I have any (read once); "all" shows the rest too.
    var hasSpecialties by remember { mutableStateOf<Boolean?>(null) }
    var reach by rememberSaveable { mutableStateOf("mine") }
    var filter by remember { mutableStateOf(ProjectFilter()) }
    var sheet by remember { mutableStateOf(false) }

    val rows = remember { mutableStateListOf<ProjectDto>() }
    val listState = rememberLazyListState()
    var page by remember { mutableIntStateOf(0) }
    var hasMore by remember { mutableStateOf(true) }
    var loading by remember { mutableStateOf(false) }
    var loaded by remember { mutableStateOf(false) }
    var failed by remember { mutableStateOf<String?>(null) }

    suspend fun loadPage(next: Int) {
        if (loading) return
        loading = true
        failed = null
        val result = apiCall {
            ApiClient.projects.list(
                page = next, perPage = PAGE_SIZE, q = query.trim().ifBlank { null }, specialtyId = filter.specialty?.id,
                budgetMinMinor = minor(filter.budgetMin), budgetMaxMinor = minor(filter.budgetMax),
                durationMaxDays = filter.durationMax.toIntOrNull(), favorites = if (savedOnly) 1 else null,
                scope = if (hasSpecialties == true) reach else "all",
            )
        }
        if (result is ApiResult.Ok) {
            if (next == 1) rows.clear()
            rows.addAll(result.data.filter { r -> rows.none { it.uuid == r.uuid } })
            page = next
            hasMore = result.data.size >= PAGE_SIZE
        } else failed = result.errorText(offlineText) ?: genericError
        loading = false
        loaded = true
    }

    // A new search / filter / reconnect starts again from the first page (a search waits 400 ms after the last key).
    LaunchedEffect(reconnect) {
        (apiCall { ApiClient.lists.mySpecialties() } as? ApiResult.Ok)?.data?.let { hasSpecialties = it.isNotEmpty() }
    }
    LaunchedEffect(query, filter, savedOnly, reach, hasSpecialties, reconnect) {
        // The first load waits until it is known whether I have specialties.
        if (hasSpecialties == null) return@LaunchedEffect
        if (query.isNotBlank()) delay(400)
        page = 0
        hasMore = true
        loadPage(1)
    }
    val nearEnd by remember {
        derivedStateOf {
            val info = listState.layoutInfo
            val last = info.visibleItemsInfo.lastOrNull()?.index ?: -1
            info.totalItemsCount > 0 && last >= info.totalItemsCount - 3
        }
    }
    LaunchedEffect(nearEnd, rows.size, hasMore, loaded) {
        if (loaded && nearEnd && hasMore && !loading && failed == null) loadPage(page + 1)
    }

    Column(Modifier.fillMaxSize()) {
        Column(Modifier.padding(horizontal = 14.dp)) {
            Column(Modifier.padding(top = 14.dp, bottom = 10.dp)) { SettingsScreenTitle(stringResource(R.string.projects_title)) }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                DorrTextField(query, { query = it.take(100) }, icon = Icons.Rounded.Search, placeholder = stringResource(R.string.projects_search), modifier = Modifier.weight(1f))
                Box(
                    Modifier.size(46.dp).clip(RoundedCornerShape(16.dp)).background(if (filter.active > 0) settingsAccent() else settingsAccent().copy(alpha = 0.10f)).clickable { sheet = true },
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Rounded.FilterList, contentDescription = stringResource(R.string.projects_filter), tint = if (filter.active > 0) androidx.compose.ui.graphics.Color.White else settingsAccent(), modifier = Modifier.size(22.dp))
                }
            }
            Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (hasSpecialties == true) {
                    ProjectChip(stringResource(R.string.projects_mine), !savedOnly && reach == "mine") { savedOnly = false; reach = "mine" }
                    ProjectChip(stringResource(R.string.projects_all_specialties), !savedOnly && reach == "all") { savedOnly = false; reach = "all" }
                } else {
                    ProjectChip(stringResource(R.string.projects_all), !savedOnly) { savedOnly = false }
                }
                ProjectChip(stringResource(R.string.projects_saved), savedOnly) { savedOnly = true }
                ProjectChip(stringResource(R.string.offer_my_offers), false, onClick = onOpenMyOffers)
                ProjectChip(stringResource(R.string.inv_title), false, onClick = onOpenInvitations)
                if (filter.active > 0) ProjectChip(stringResource(R.string.projects_clear_filter, filter.active), false) { filter = ProjectFilter() }
            }
        }
        LazyColumn(
            state = listState, modifier = Modifier.fillMaxSize().weight(1f),
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 12.dp, bottom = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            if (loaded && rows.isEmpty() && failed == null) item(key = "empty") {
                Column(Modifier.fillMaxWidth().padding(top = 60.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Box(Modifier.size(88.dp).background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.08f), RoundedCornerShape(50)), contentAlignment = Alignment.Center) {
                        Icon(if (savedOnly) Icons.Rounded.StarBorder else Icons.Rounded.Search, null, tint = settingsMut(), modifier = Modifier.size(40.dp))
                    }
                    Spacer(Modifier.height(16.dp))
                    Text(stringResource(if (savedOnly) R.string.projects_no_saved else R.string.projects_none), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.SemiBold)
                    Text(stringResource(if (savedOnly) R.string.projects_no_saved_text else R.string.projects_none_text), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp))
                    if (!savedOnly && hasSpecialties == true && reach == "mine") {
                        Text(
                            stringResource(R.string.projects_show_all), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 14.sp,
                            modifier = Modifier.padding(top = 10.dp).clip(RoundedCornerShape(12.dp)).clickable { reach = "all" }.padding(10.dp),
                        )
                    }
                }
            }
            items(rows, key = { it.uuid }) { p ->
                ProjectCard(p, onOpen = { onOpenProject(p.uuid) }, onToggleSaved = {
                    val index = rows.indexOfFirst { it.uuid == p.uuid }
                    if (index >= 0) rows[index] = p.copy(isFavorite = !p.isFavorite)
                    scope.launch {
                        val result = apiCall { if (p.isFavorite) ApiClient.projects.unfavorite(p.uuid) else ApiClient.projects.favorite(p.uuid) }
                        if (result is ApiResult.Ok) {
                            if (savedOnly && p.isFavorite) rows.removeAll { it.uuid == p.uuid }
                        } else {
                            // The server did not take it: put the star back.
                            val at = rows.indexOfFirst { it.uuid == p.uuid }
                            if (at >= 0) rows[at] = p
                            failed = result.errorText(offlineText) ?: genericError
                        }
                    }
                })
            }
            if (loading) item(key = "spinner") {
                Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(24.dp)) }
            }
            if (failed != null) item(key = "failed") {
                Text(
                    failed.orEmpty() + "  ·  " + stringResource(R.string.support_ticket_more), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 13.sp,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { scope.launch { loadPage(if (page == 0) 1 else page + 1) } }.padding(12.dp),
                )
            }
        }
    }

    if (sheet) FilterSheet(filter, onDismiss = { sheet = false }, onApply = { filter = it; sheet = false })
}

@Composable
private fun ProjectChip(text: String, selected: Boolean, onClick: () -> Unit) {
    val accent = settingsAccent()
    Text(
        text, color = if (selected) androidx.compose.ui.graphics.Color.White else settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (selected) accent else accent.copy(alpha = 0.08f)).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 7.dp),
    )
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ProjectCard(p: ProjectDto, onOpen: () -> Unit, onToggleSaved: () -> Unit) {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).clickable(onClick = onOpen).padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.Top) {
            Text(p.title.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Icon(
                if (p.isFavorite) Icons.Rounded.Star else Icons.Rounded.StarBorder, contentDescription = stringResource(R.string.projects_save),
                tint = if (p.isFavorite) androidx.compose.ui.graphics.Color(0xFFF59E0B) else settingsMut(),
                modifier = Modifier.size(30.dp).clip(RoundedCornerShape(50)).clickable(onClick = onToggleSaved).padding(3.dp),
            )
        }
        p.summary?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp, maxLines = 3, overflow = TextOverflow.Ellipsis) }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
            budgetText(p.budget)?.let { Text(it, color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold) }
            p.durationDays?.let { Text(stringResource(R.string.projects_days, it), color = settingsMut(), fontSize = 12.sp) }
        }
        if (p.skills.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            p.skills.take(4).forEach { s ->
                Text(s.name, color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).padding(horizontal = 10.dp, vertical = 3.dp))
            }
        }
        offersLine(p)?.let { (text, open) -> Text(text, color = if (open) androidx.compose.ui.graphics.Color(0xFF16A34A) else androidx.compose.ui.graphics.Color(0xFFD97706), fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            p.specialty?.let { Text(it.name, color = settingsMut(), fontSize = 11.sp) }
            publishedAgo(p.publishedAt)?.let { Text("·  $it", color = settingsMut(), fontSize = 11.sp) }
            if (p.offered) Text("·  " + stringResource(R.string.offer_sent_badge), color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
        }
    }
}

// ---------------------------------------------------------------- the filter sheet

@Composable
private fun FilterSheet(initial: ProjectFilter, onDismiss: () -> Unit, onApply: (ProjectFilter) -> Unit) {
    val night = settingsNight()
    var draft by remember { mutableStateOf(initial) }
    var tree by remember { mutableStateOf<List<SpecialtyNodeDto>?>(null) }
    LaunchedEffect(Unit) { (apiCall { ApiClient.lists.specialtyTree() } as? ApiResult.Ok)?.data?.let { tree = it } }

    LocaleAwareBottomSheet(onDismissRequest = onDismiss, containerColor = if (night) AccountDark.card else androidx.compose.ui.graphics.Color.White, shape = RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp), fullHeight = true) {
        Column(Modifier.padding(horizontal = 20.dp).padding(bottom = 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(stringResource(R.string.projects_filter), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.projects_specialty), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            val nodes = tree
            if (nodes == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
            else Column(Modifier.heightIn(max = 220.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                SpecialtyOption(stringResource(R.string.projects_any), draft.specialty == null) { draft = draft.copy(specialty = null) }
                nodes.forEach { parent ->
                    SpecialtyOption(parent.name, draft.specialty?.id == parent.id, bold = true) { draft = draft.copy(specialty = parent) }
                    parent.children.forEach { c -> SpecialtyOption(c.name, draft.specialty?.id == c.id, indent = true) { draft = draft.copy(specialty = c) } }
                }
            }
            Text(stringResource(R.string.projects_budget), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(draft.budgetMin, { draft = draft.copy(budgetMin = it) }, placeholder = stringResource(R.string.projects_from), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.weight(1f))
                DorrTextField(draft.budgetMax, { draft = draft.copy(budgetMax = it) }, placeholder = stringResource(R.string.projects_to), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.weight(1f))
            }
            DorrTextField(draft.durationMax, { draft = draft.copy(durationMax = it.filter(Char::isDigit).take(3)) }, label = stringResource(R.string.projects_duration_max), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
            SettingsPrimaryButton(text = stringResource(R.string.projects_apply), onClick = { onApply(draft) })
            Text(
                stringResource(R.string.projects_reset), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 14.sp,
                modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { onApply(ProjectFilter()) }.padding(10.dp),
            )
        }
    }
}

@Composable
private fun SpecialtyOption(text: String, selected: Boolean, bold: Boolean = false, indent: Boolean = false, onClick: () -> Unit) {
    Text(
        (if (selected) "✓  " else "") + text, color = if (selected) settingsAccent() else settingsInk(), fontSize = 14.sp, fontWeight = if (bold || selected) FontWeight.Bold else FontWeight.Normal,
        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable(onClick = onClick).padding(start = if (indent) 22.dp else 6.dp, top = 8.dp, bottom = 8.dp, end = 6.dp),
    )
}

// ---------------------------------------------------------------- one project

/** One project's page (FL-207): everything the client wrote, the budget and the time, and the star to save it. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ProjectDetailScreen(uuid: String, onBack: () -> Unit, onOffer: () -> Unit = {}, onEditOffer: () -> Unit = {}, onChat: (String) -> Unit = {}, onAgreement: () -> Unit = {}) {
    var myOffer by remember { mutableStateOf<com.dorr.freelance.net.OfferDto?>(null) }
    var offerChecked by remember { mutableStateOf(false) }
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var project by remember { mutableStateOf<ProjectDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(uuid, reconnect) {
        val result = apiCall { ApiClient.projects.show(uuid) }
        if (result is ApiResult.Ok) {
            project = result.data
            error = null
        } else error = result.errorText(offlineText) ?: genericError
    }

    Page(title = stringResource(R.string.projects_project), onBack = onBack) {
        val p = project
        if (p == null) {
            if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            ErrorBanner(error, Modifier.fillMaxWidth())
            return@Page
        }
        Card {
            Row(verticalAlignment = Alignment.Top) {
                Text(p.title.orEmpty(), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                Icon(
                    if (p.isFavorite) Icons.Rounded.Star else Icons.Rounded.StarBorder, contentDescription = stringResource(R.string.projects_save),
                    tint = if (p.isFavorite) androidx.compose.ui.graphics.Color(0xFFF59E0B) else settingsMut(),
                    modifier = Modifier.size(34.dp).clip(RoundedCornerShape(50)).clickable {
                        val before = p
                        project = p.copy(isFavorite = !p.isFavorite)
                        scope.launch {
                            val result = apiCall { if (before.isFavorite) ApiClient.projects.unfavorite(uuid) else ApiClient.projects.favorite(uuid) }
                            if (result is ApiResult.Ok) project = result.data else {
                                project = before
                                error = result.errorText(offlineText) ?: genericError
                            }
                        }
                    }.padding(4.dp),
                )
            }
            publishedAgo(p.publishedAt)?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
        }
        Card {
            DetailField(stringResource(R.string.projects_budget), budgetText(p.budget))
            DetailField(stringResource(R.string.projects_duration), p.durationDays?.let { stringResource(R.string.projects_days, it) })
            DetailField(stringResource(R.string.projects_specialty), p.specialty?.name)
            offersLine(p)?.let { (text, _) -> DetailField(stringResource(R.string.projects_offers), text) }
        }
        Card {
            Text(stringResource(R.string.projects_description), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            Text(p.description.orEmpty(), color = settingsInk(), fontSize = 14.sp, lineHeight = 22.sp)
        }
        if (p.skills.isNotEmpty()) Card {
            Text(stringResource(R.string.projects_skills), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                p.skills.forEach { s ->
                    Text(s.name, color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).padding(horizontal = 12.dp, vertical = 5.dp))
                }
            }
        }
        if (p.questions.isNotEmpty()) {
            Text(stringResource(R.string.offer_questions), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            p.questions.forEachIndexed { i, q -> Text("${i + 1}. $q", color = settingsMut(), fontSize = 13.sp) }
        }
        if (p.attachmentsCount > 0) Text(stringResource(R.string.projects_files_note, p.attachmentsCount), color = settingsMut(), fontSize = 12.sp)
        // My offer, or the button to send one (FL-301): the form itself says why not when I can't.
        LaunchedEffect(uuid, p.offered) {
            (apiCall { ApiClient.offers.mine(uuid) } as? ApiResult.Ok)?.data?.let { myOffer = it.offer }
            offerChecked = true
            // A notice arrived (the client saw, shortlisted, decided): read it once more — nothing on a timer.
            com.dorr.freelance.net.Realtime.changed.collect {
                (apiCall { ApiClient.offers.mine(uuid) } as? ApiResult.Ok)?.data?.let { myOffer = it.offer }
            }
        }
        // FL-205: an invitation to a private project waits for my yes or no.
        var invitation by remember(p.invitation) { mutableStateOf(p.invitation) }
        invitation?.let { inv ->
            Card {
                Text(stringResource(R.string.inv_banner, invitationStatusLabel(inv.status)), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                if (inv.status == "sent") InvitationAnswer(inv.uuid, onAnswered = { invitation = inv.copy(status = it) }, onError = { error = it })
            }
        }
        val sent = myOffer
        when {
            sent != null -> {
                Text(stringResource(R.string.offer_mine), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                MyOfferCard(sent, onEdit = onEditOffer, onChanged = { myOffer = it }, onChat = onChat, onAgreement = onAgreement)
            }
            offerChecked && p.offers.accepting -> SettingsPrimaryButton(text = stringResource(R.string.offer_send_one), onClick = onOffer)
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

@Composable
private fun DetailField(label: String, value: String?) {
    Column {
        Text(label, color = settingsMut(), fontSize = 12.sp)
        Text(value?.takeIf { it.isNotBlank() } ?: "—", color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
    }
}
