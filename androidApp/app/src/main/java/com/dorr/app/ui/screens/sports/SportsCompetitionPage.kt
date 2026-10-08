package com.dorr.app.ui.screens.sports

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.SpCompetitionPageDto
import com.dorr.app.network.SpLeaderDto
import com.dorr.app.network.SpLeadersDto
import com.dorr.app.network.SpRoundsDto
import com.dorr.app.network.SpStandingRowDto
import com.dorr.app.network.SpTeamDto
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * A competition, complete (spec 192, docs/sports-plan.md §10.3.2): overview, the table (all ·
 * home · away, form, zones), every round's matches, the leaders (goals, assists, cards) and the
 * teams — each part from our server's copy of the provider's data.
 */

private val CompTabs = listOf(R.string.spx_tab_overview, R.string.sp_tab_table, R.string.sp_tab_matches, R.string.spx_tab_stats, R.string.sp_tab_teams)

@Composable
internal fun CompetitionPage(id: Int, onBack: () -> Unit, go: (SpPage) -> Unit) {
    var page by remember { mutableStateOf<SpCompetitionPageDto?>(null) }
    var rounds by remember { mutableStateOf<SpRoundsDto?>(null) }
    val leaders = remember { mutableStateMapOf<String, SpLeadersDto>() }
    var tab by remember { mutableIntStateOf(0) }
    val now = rememberTicker()
    val scope = rememberCoroutineScope()
    LaunchedEffect(id) {
        page = runCatching { ApiClient.sports.competition(spAuth(), id, spZone()).data }.getOrNull()
        rounds = runCatching { ApiClient.sports.rounds(spAuth(), id, spZone()).data }.getOrNull()
    }
    val loadLeaders: (String) -> Unit = { type ->
        if (leaders[type] == null) scope.launch { runCatching { ApiClient.sports.leaders(spAuth(), id, type).data }.getOrNull()?.let { leaders[type] = it } }
    }
    val p = page
    var followed by remember(p?.following) { mutableStateOf(p?.following == true) }
    val football = p?.sport == null || p.sport == "football"

    WaPage(title = p?.name ?: stringResource(R.string.sp_title), onBack = onBack, actions = {
        Text(if (followed) "★" else "☆", color = if (followed) Color(0xFFF59E0B) else Wa.Mut, fontSize = 26.sp, modifier = Modifier.clip(CircleShape).clickable {
            followed = !followed
            scope.launch { toggleFollow("competition", id, followed) }
        }.padding(8.dp))
    }) {
        if (p == null) {
            WaSkeleton(Modifier.fillMaxWidth().height(150.dp), RoundedCornerShape(26.dp))
            Spacer(Modifier.height(12.dp))
            SkeletonRows()
            return@WaPage
        }
        CompetitionHeader(p, rounds?.current)
        Spacer(Modifier.height(14.dp))
        val tabs = if (football) CompTabs else listOf(R.string.sp_tab_table, R.string.sp_tab_matches, R.string.sp_tab_teams)
        ScrollTabs(tabs.map { stringResource(it) }, tab.coerceIn(0, tabs.lastIndex)) { tab = it }
        Spacer(Modifier.height(12.dp))
        val current = tabs[tab.coerceIn(0, tabs.lastIndex)]
        LaunchedEffect(current) {
            if (current == R.string.spx_tab_overview) { loadLeaders("goals"); loadLeaders("assists") }
            if (current == R.string.spx_tab_stats) loadLeaders("goals")
        }
        AnimatedContent(current, transitionSpec = { fadeIn(tween(220)) togetherWith fadeOut(tween(120)) }, label = "compTab") { t ->
            Column {
                when (t) {
                    R.string.spx_tab_overview -> CompOverview(p, rounds, leaders, now, go) { tab = tabs.indexOf(it) }
                    R.string.sp_tab_table -> CompTable(p, go)
                    R.string.sp_tab_matches -> CompRounds(id, p, rounds, now, go) { rounds = it }
                    R.string.spx_tab_stats -> CompLeaders(leaders, loadLeaders, go)
                    else -> CompTeams(p, go)
                }
            }
        }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
private fun CompetitionHeader(p: SpCompetitionPageDto, round: String?) {
    Box(
        Modifier.fillMaxWidth().waRise(0).clip(RoundedCornerShape(26.dp))
            .background(Brush.linearGradient(listOf(Color(0xFF0B1220), Color(0xFF1E3A8A), Color(0xFF0F766E)))),
    ) {
        // A big faint logo behind, like a watermark.
        p.logo?.let { AsyncImage(model = ApiClient.mediaUrl(it), contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.size(170.dp).align(Alignment.CenterEnd).offset(x = 40.dp).padding(8.dp), alpha = 0.10f) }
        Row(Modifier.fillMaxWidth().padding(18.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(68.dp).clip(RoundedCornerShape(20.dp)).background(Color.White), contentAlignment = Alignment.Center) {
                if (p.logo != null) AsyncImage(model = ApiClient.mediaUrl(p.logo), contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.size(52.dp)) else Text("🏆", fontSize = 30.sp)
            }
            Spacer(Modifier.width(14.dp))
            Column(Modifier.weight(1f)) {
                Text(p.name.orEmpty(), color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2)
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = 4.dp)) {
                    p.flag?.let { AsyncImage(model = ApiClient.mediaUrl(it), contentDescription = null, modifier = Modifier.size(width = 18.dp, height = 13.dp).clip(RoundedCornerShape(2.dp))) ; Spacer(Modifier.width(6.dp)) }
                    Text(listOfNotNull(p.country, p.season?.let { seasonLabel(it) }).joinToString(" · "), color = Color.White.copy(alpha = 0.8f), fontSize = 12.5.sp)
                }
                round?.let {
                    Text(
                        roundLabel(it), color = Color.White, fontSize = 11.5.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.padding(top = 8.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.16f)).padding(horizontal = 10.dp, vertical = 4.dp),
                    )
                }
            }
        }
    }
}

/** "2025" → "2025/26" for seasons that cross the new year (most leagues). */
internal fun seasonLabel(season: String): String = season.toIntOrNull()?.let { "$it/${((it + 1) % 100).toString().padStart(2, '0')}" } ?: season

/** "Regular Season - 8" → "الجولة 8" (the provider writes rounds in English). */
@Composable
internal fun roundLabel(round: String): String {
    val n = Regex("""(\d+)\s*$""").find(round)?.groupValues?.get(1)
    return when {
        round.startsWith("Regular Season") && n != null -> stringResource(R.string.spx_round_n, n)
        else -> round
    }
}

// ------------------------------------------------------------------ overview

@Composable
private fun CompOverview(p: SpCompetitionPageDto, rounds: SpRoundsDto?, leaders: Map<String, SpLeadersDto>, now: Long, go: (SpPage) -> Unit, open: (Int) -> Unit) {
    if (p.live.isNotEmpty()) {
        WaSectionTitle(stringResource(R.string.sp_live), topPadding = 0.dp)
        p.live.forEach { m -> LiveCard(m, now, Modifier.fillMaxWidth().padding(bottom = 8.dp)) { go(SpPage.Match(m.id)) } }
    }
    val roundMatches = rounds?.matches.orEmpty().ifEmpty { p.next.take(6) }
    if (roundMatches.isNotEmpty()) {
        WaSectionTitle(rounds?.round?.let { roundLabel(it) } ?: stringResource(R.string.sp_next), topPadding = if (p.live.isEmpty()) 0.dp else 22.dp, trailing = {
            Text(stringResource(R.string.spx_all), color = Wa.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { open(R.string.sp_tab_matches) })
        })
        roundMatches.forEach { MatchDayRow(it, now) { go(SpPage.Match(it.id)) } }
    }
    val table = p.standings.firstOrNull()?.rows.orEmpty()
    if (table.isNotEmpty()) {
        WaSectionTitle(stringResource(R.string.sp_tab_table), trailing = {
            Text(stringResource(R.string.spx_full_table), color = Wa.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { open(R.string.sp_tab_table) })
        })
        StandingsTable(table.take(6)) { t -> t.id.takeIf { it > 0 }?.let { go(SpPage.Team(it)) } }
    }
    val best = listOfNotNull(
        leaders["goals"]?.rows?.firstOrNull()?.let { Triple(it, R.string.spx_top_scorer, "⚽") },
        leaders["assists"]?.rows?.firstOrNull()?.let { Triple(it, R.string.spx_top_assist, "🎯") },
    )
    if (best.isNotEmpty()) {
        WaSectionTitle(stringResource(R.string.spx_stars))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            best.forEachIndexed { i, (l, label, emoji) -> StarCard(l, stringResource(label), emoji, Modifier.weight(1f).waRise(i), go) }
        }
    }
    if (p.live.isEmpty() && roundMatches.isEmpty() && table.isEmpty()) StateNote(p.standingsState, empty = R.string.sp_no_comp_matches)
}

@Composable
private fun StarCard(l: SpLeaderDto, label: String, emoji: String, modifier: Modifier, go: (SpPage) -> Unit) {
    Column(
        modifier.clip(RoundedCornerShape(22.dp)).background(Brush.verticalGradient(listOf(Color(0xFF1E293B), Color(0xFF0B1220))))
            .clickable { l.player?.id?.let { go(SpPage.Player(it)) } }.padding(14.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text("$emoji  $label", color = Color.White.copy(alpha = 0.75f), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
        PhotoAvatar(l.player?.photo, l.player?.name, 64.dp, Modifier.padding(vertical = 10.dp), ring = Color(0xFFFACC15))
        Text(l.player?.name.orEmpty(), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        Text(l.team?.name.orEmpty(), color = Color.White.copy(alpha = 0.65f), fontSize = 11.5.sp, maxLines = 1)
        Text("${l.value}", color = Color(0xFFFACC15), fontSize = 28.sp, fontWeight = FontWeight.Black, modifier = Modifier.padding(top = 4.dp))
    }
}

// ------------------------------------------------------------------ the table

@Composable
private fun CompTable(p: SpCompetitionPageDto, go: (SpPage) -> Unit) {
    if (p.standings.isEmpty()) {
        StateNote(p.standingsState, empty = R.string.sp_no_table)
        return
    }
    var mode by remember { mutableStateOf("all") }
    ChoiceChips(listOf("all" to stringResource(R.string.spx_all_games), "home" to stringResource(R.string.spx_at_home), "away" to stringResource(R.string.spx_away), "form" to stringResource(R.string.spx_form)), mode) { mode = it }
    p.standings.forEach { g ->
        if (p.standings.size > 1 && g.group.isNotBlank()) Text(g.group, color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
        else Spacer(Modifier.height(10.dp))
        FullTable(splitRows(g.rows, mode), mode) { t -> t.id.takeIf { it > 0 }?.let { go(SpPage.Team(it)) } }
    }
    // What the colours mean (the provider's own words).
    val zones = p.standings.flatMap { it.rows }.mapNotNull { r -> r.description?.takeIf { it.isNotBlank() } }.distinct()
    if (zones.isNotEmpty()) {
        Column(Modifier.fillMaxWidth().padding(top = 12.dp).clip(RoundedCornerShape(18.dp)).background(Wa.Surface).padding(12.dp)) {
            zones.forEach { d ->
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(vertical = 3.dp)) {
                    Box(Modifier.size(10.dp).clip(CircleShape).background(zoneColorOf(d) ?: Wa.Soft))
                    Spacer(Modifier.width(8.dp))
                    Text(d, color = Wa.Mut, fontSize = 12.sp)
                }
            }
        }
    }
    p.standingsUpdatedAt?.let { Text(stringResource(R.string.sp_updated_at, it.take(16).replace('T', ' ')), color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.padding(top = 8.dp)) }
}

/** Home or away only: the same rows, re-ranked from the provider's split. */
private fun splitRows(rows: List<SpStandingRowDto>, mode: String): List<SpStandingRowDto> {
    if (mode != "home" && mode != "away") return rows
    return rows.mapNotNull { r ->
        val s = (if (mode == "home") r.home else r.away) ?: return@mapNotNull null
        r.copy(played = s.played, win = s.win, draw = s.draw, lose = s.lose, goalsFor = s.goalsFor, goalsAgainst = s.goalsAgainst, goalDiff = s.goalsFor - s.goalsAgainst, points = s.win * 3 + s.draw, trend = null)
    }.sortedWith(compareByDescending<SpStandingRowDto> { it.points }.thenByDescending { it.goalDiff }.thenByDescending { it.goalsFor })
        .mapIndexed { i, r -> r.copy(rank = i + 1) }
}

@Composable
private fun FullTable(rows: List<SpStandingRowDto>, mode: String, onTeam: (SpTeamDto) -> Unit) {
    val cols = if (mode == "form") listOf(R.string.sp_col_pts) else listOf(R.string.sp_col_p, R.string.spx_col_w, R.string.spx_col_d, R.string.spx_col_l, R.string.sp_col_gd, R.string.sp_col_pts)
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(Wa.Surface).padding(6.dp)) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 6.dp, vertical = 6.dp)) {
            Text("#", color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.width(28.dp))
            Text(stringResource(R.string.sp_team), color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            if (mode == "form") Text(stringResource(R.string.spx_form), color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.width(118.dp), textAlign = TextAlign.Center)
            cols.forEach { Text(stringResource(it), color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.width(27.dp), textAlign = TextAlign.Center) }
        }
        rows.forEachIndexed { i, r ->
            Row(
                Modifier.fillMaxWidth().waRise((i / 2).coerceAtMost(8)).clip(RoundedCornerShape(12.dp)).clickable { r.team?.let(onTeam) }.padding(horizontal = 6.dp, vertical = 7.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Row(Modifier.width(28.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.width(3.dp).height(18.dp).clip(CircleShape).background(zoneColorOf(r.description) ?: Color.Transparent))
                    Spacer(Modifier.width(4.dp))
                    Text("${r.rank}", color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                }
                Crest(r.team, 22.dp)
                Spacer(Modifier.width(6.dp))
                Text(r.team?.code ?: r.team?.name.orEmpty(), color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                if (mode == "form") {
                    Box(Modifier.width(118.dp), contentAlignment = Alignment.Center) { FormStrip(r.form, size = 19.dp) }
                    Text("${r.points}", color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Black, modifier = Modifier.width(27.dp), textAlign = TextAlign.Center)
                } else {
                    listOf(r.played, r.win, r.draw, r.lose).forEach { Text("$it", color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.width(27.dp), textAlign = TextAlign.Center) }
                    Text((if (r.goalDiff > 0) "+" else "") + r.goalDiff, color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.width(27.dp), textAlign = TextAlign.Center)
                    Text("${r.points}", color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Black, modifier = Modifier.width(27.dp), textAlign = TextAlign.Center)
                }
            }
        }
    }
}

internal fun zoneColorOf(description: String?): Color? {
    val d = description?.lowercase() ?: return null
    return when {
        "relegation" in d -> Color(0xFFDC2626)
        "champions" in d || "promotion" in d && "play" !in d -> Color(0xFF2563EB)
        "europa" in d || "conference" in d || "afc" in d || "caf" in d -> Color(0xFFF59E0B)
        "play" in d -> Color(0xFF7C3AED)
        else -> Color(0xFF16A34A)
    }
}

// ------------------------------------------------------------------ rounds

@Composable
private fun CompRounds(id: Int, p: SpCompetitionPageDto, rounds: SpRoundsDto?, now: Long, go: (SpPage) -> Unit, onRounds: (SpRoundsDto) -> Unit) {
    val scope = rememberCoroutineScope()
    var loading by remember { mutableStateOf(false) }
    val list = rounds?.rounds.orEmpty()
    if (list.isEmpty()) {
        // No rounds from the provider: the matches we know.
        if (p.next.isNotEmpty()) { WaSectionTitle(stringResource(R.string.sp_next), topPadding = 0.dp); p.next.forEach { MatchDayRow(it, now) { go(SpPage.Match(it.id)) } } }
        if (p.last.isNotEmpty()) { WaSectionTitle(stringResource(R.string.sp_last)); p.last.forEach { MatchDayRow(it, now) { go(SpPage.Match(it.id)) } } }
        if (p.next.isEmpty() && p.last.isEmpty()) StateNote(null, empty = R.string.sp_no_comp_matches)
        return
    }
    val picked = rounds?.round
    val state = rememberLazyListState()
    LaunchedEffect(picked) { list.indexOfFirst { it.name == picked }.takeIf { it >= 0 }?.let { state.animateScrollToItem(maxOf(0, it - 1)) } }
    LazyRow(state = state, horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
        itemsIndexed(list) { _, r ->
            val on = r.name == picked
            val isCurrent = r.name == rounds?.current
            Column(
                Modifier.clip(RoundedCornerShape(16.dp)).background(if (on) Wa.Red else Wa.Surface)
                    .then(if (isCurrent && !on) Modifier.border(1.5.dp, Wa.Red, RoundedCornerShape(16.dp)) else Modifier)
                    .clickable {
                        if (!on && !loading) scope.launch {
                            loading = true
                            runCatching { ApiClient.sports.rounds(spAuth(), id, spZone(), r.name).data }.getOrNull()?.let(onRounds)
                            loading = false
                        }
                    }.padding(horizontal = 14.dp, vertical = 8.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text(roundLabel(r.name.orEmpty()), color = if (on) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                r.dates.firstOrNull()?.let { d -> Text(shortDate(d), color = if (on) Color.White.copy(alpha = 0.8f) else Wa.Soft, fontSize = 10.5.sp) }
            }
        }
    }
    Spacer(Modifier.height(12.dp))
    if (loading) SkeletonRows(4)
    else if (rounds?.matches.isNullOrEmpty()) StateNote(null, empty = R.string.sp_no_comp_matches)
    else rounds?.matches?.forEach { MatchDayRow(it, now) { go(SpPage.Match(it.id)) } }
}

internal fun shortDate(iso: String): String =
    runCatching { LocalDate.parse(iso.take(10)).format(DateTimeFormatter.ofPattern("d MMM", Locale.getDefault())) }.getOrDefault(iso)

// ------------------------------------------------------------------ leaders

private val LeaderKinds = listOf("goals" to R.string.spx_goals, "assists" to R.string.spx_assists, "yellow" to R.string.spx_yellow, "red" to R.string.spx_red)

@Composable
private fun CompLeaders(leaders: Map<String, SpLeadersDto>, load: (String) -> Unit, go: (SpPage) -> Unit) {
    var kind by remember { mutableStateOf("goals") }
    LaunchedEffect(kind) { load(kind) }
    ChoiceChips(LeaderKinds.map { it.first to stringResource(it.second) }, kind) { kind = it }
    Spacer(Modifier.height(12.dp))
    val l = leaders[kind]
    when {
        l == null -> SkeletonRows(5)
        l.rows.isEmpty() -> StateNote(l.state, empty = R.string.sp_no_scorers)
        else -> {
            val emoji = when (kind) { "assists" -> "🎯"; "yellow" -> "🟨"; "red" -> "🟥"; else -> "⚽" }
            Podium(l.rows.take(3), emoji, go)
            val top = l.rows.first().value.coerceAtLeast(1)
            l.rows.drop(3).forEachIndexed { i, r ->
                Row(
                    Modifier.fillMaxWidth().padding(bottom = 6.dp).waRise(i.coerceAtMost(8)).clip(RoundedCornerShape(16.dp)).background(Wa.Surface)
                        .clickable { r.player?.id?.let { go(SpPage.Player(it)) } }.padding(10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text("${r.rank}", color = Wa.Mut, fontSize = 14.sp, fontWeight = FontWeight.Black, modifier = Modifier.width(26.dp), textAlign = TextAlign.Center)
                    PhotoAvatar(r.player?.photo, r.player?.name, 38.dp)
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(r.player?.name.orEmpty(), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Crest(r.team, 16.dp)
                            Spacer(Modifier.width(4.dp))
                            Text(r.team?.name.orEmpty(), color = Wa.Mut, fontSize = 11.5.sp, maxLines = 1)
                        }
                        // How close to the leader.
                        Box(Modifier.padding(top = 5.dp).fillMaxWidth().height(4.dp).clip(CircleShape).background(Wa.Field)) {
                            Box(Modifier.fillMaxWidth(r.value.toFloat() / top).height(4.dp).clip(CircleShape).background(Wa.Red))
                        }
                    }
                    Spacer(Modifier.width(10.dp))
                    Text("${r.value}", color = Wa.Ink, fontSize = 19.sp, fontWeight = FontWeight.Black)
                }
            }
            l.fetchedAt?.let { Text(stringResource(R.string.sp_updated_at, it.take(16).replace('T', ' ')), color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.padding(top = 6.dp)) }
        }
    }
}

/** The top three on steps: the first in the middle, higher. */
@Composable
private fun Podium(top: List<SpLeaderDto>, emoji: String, go: (SpPage) -> Unit) {
    val order = listOfNotNull(top.getOrNull(1), top.getOrNull(0), top.getOrNull(2))
    Row(
        Modifier.fillMaxWidth().padding(bottom = 12.dp).clip(RoundedCornerShape(24.dp)).background(Brush.verticalGradient(listOf(Color(0xFF0B1220), Color(0xFF1E3A8A)))).padding(horizontal = 10.dp, vertical = 14.dp),
        verticalAlignment = Alignment.Bottom,
    ) {
        order.forEach { r ->
            val first = r.rank == 1
            val medal = when (r.rank) { 1 -> Color(0xFFFACC15); 2 -> Color(0xFFCBD5E1); else -> Color(0xFFD97706) }
            Column(Modifier.weight(1f).waRise(r.rank).clickable { r.player?.id?.let { go(SpPage.Player(it)) } }, horizontalAlignment = Alignment.CenterHorizontally) {
                PhotoAvatar(r.player?.photo, r.player?.name, if (first) 72.dp else 56.dp, ring = medal)
                Text(r.player?.name.orEmpty(), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 6.dp))
                Text(r.team?.name.orEmpty(), color = Color.White.copy(alpha = 0.6f), fontSize = 10.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Box(
                    Modifier.padding(top = 8.dp).fillMaxWidth(0.82f).height(if (first) 62.dp else 44.dp).clip(RoundedCornerShape(topStart = 12.dp, topEnd = 12.dp)).background(medal.copy(alpha = 0.22f)),
                    contentAlignment = Alignment.Center,
                ) {
                    Column(horizontalAlignment = Alignment.CenterHorizontally) {
                        Text("${r.value}", color = medal, fontSize = if (first) 24.sp else 19.sp, fontWeight = FontWeight.Black)
                        Text(emoji, fontSize = 11.sp)
                    }
                }
            }
        }
    }
}

// ------------------------------------------------------------------ teams

@Composable
private fun CompTeams(p: SpCompetitionPageDto, go: (SpPage) -> Unit) {
    if (p.teams.isEmpty()) {
        StateNote(null, empty = R.string.sp_no_teams)
        return
    }
    val scope = rememberCoroutineScope()
    var mine by remember(p.teams) { mutableStateOf(p.teams.filter { it.following }.map { it.id }.toSet()) }
    p.teams.chunked(3).forEachIndexed { r, row ->
        Row(Modifier.fillMaxWidth().padding(bottom = 8.dp).waRise(r.coerceAtMost(8)), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            row.forEach { t ->
                val on = t.id in mine
                Column(
                    Modifier.weight(1f).clip(RoundedCornerShape(20.dp)).background(Wa.Surface).clickable { go(SpPage.Team(t.id)) }.padding(vertical = 14.dp, horizontal = 6.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Crest(SpTeamDto(id = t.id, name = t.name, logo = t.logo, color = t.color), 50.dp)
                    Text(t.name.orEmpty(), color = Wa.Ink, fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 2, textAlign = TextAlign.Center, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 8.dp).height(32.dp))
                    Text(
                        if (on) "★" else "☆", color = if (on) Color(0xFFF59E0B) else Wa.Soft, fontSize = 18.sp,
                        modifier = Modifier.clip(CircleShape).clickable {
                            mine = if (on) mine - t.id else mine + t.id
                            scope.launch { toggleFollow("team", t.id, !on) }
                        }.padding(horizontal = 10.dp),
                    )
                }
            }
            repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
        }
    }
}
