package com.dorr.app.ui.screens.sports

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
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
import com.dorr.app.network.SpMatchDto
import com.dorr.app.network.SpPart
import com.dorr.app.network.SpSquadLineDto
import com.dorr.app.network.SpTeamDto
import com.dorr.app.network.SpTeamPageDto
import com.dorr.app.network.SpTeamStatsDto
import com.dorr.app.network.SpTransferDto
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.Instant

/*
 * A team, complete (docs/sports-plan.md §10.3.3): its stadium behind it, the coach, the next
 * match with a countdown, form and places in the tables, every match of the season, the squad,
 * the season's numbers drawn as charts, and the transfers. Following and alerts stay here.
 */

private val TeamTabs = listOf(R.string.spx_tab_overview, R.string.sp_tab_matches, R.string.spx_tab_squad, R.string.spx_tab_stats, R.string.spx_tab_transfers)

@Composable
internal fun TeamPage(id: Int, onBack: () -> Unit, go: (SpPage) -> Unit) {
    var page by remember { mutableStateOf<SpTeamPageDto?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var tab by remember { mutableIntStateOf(0) }
    var squad by remember { mutableStateOf<SpPart<List<SpSquadLineDto>>?>(null) }
    var stats by remember { mutableStateOf<SpTeamStatsDto?>(null) }
    var transfers by remember { mutableStateOf<SpPart<List<SpTransferDto>>?>(null) }
    val now = rememberTicker()
    val scope = rememberCoroutineScope()
    LaunchedEffect(id, reload) { page = runCatching { ApiClient.sports.team(spAuth(), id, spZone()).data }.getOrNull() }
    val p = page
    val football = p?.sport == null || p.sport == "football"
    val tabs = if (football) TeamTabs else listOf(R.string.spx_tab_overview, R.string.sp_tab_matches)
    val current = tabs[tab.coerceIn(0, tabs.lastIndex)]
    LaunchedEffect(current, id) {
        when (current) {
            R.string.spx_tab_squad -> if (squad == null) squad = runCatching { ApiClient.sports.squad(spAuth(), id).data }.getOrNull() ?: SpPart(state = "pending")
            R.string.spx_tab_stats -> if (stats == null) stats = runCatching { ApiClient.sports.teamStatistics(spAuth(), id).data }.getOrNull() ?: SpTeamStatsDto()
            R.string.spx_tab_transfers -> if (transfers == null) transfers = runCatching { ApiClient.sports.transfers(spAuth(), id).data }.getOrNull() ?: SpPart(state = "pending")
        }
    }

    WaPage(title = p?.name ?: stringResource(R.string.sp_title), onBack = onBack) {
        if (p == null) {
            WaSkeleton(Modifier.fillMaxWidth().height(230.dp), RoundedCornerShape(28.dp))
            Spacer(Modifier.height(12.dp))
            SkeletonRows()
            return@WaPage
        }
        val color = teamColor(p.color, p.id)
        TeamHeader(p, color, go) { scope.launch { toggleFollow("team", p.id, p.follow == null); reload++ } }
        Spacer(Modifier.height(14.dp))
        ScrollTabs(tabs.map { stringResource(it) }, tabs.indexOf(current)) { tab = it }
        Spacer(Modifier.height(12.dp))
        AnimatedContent(current, transitionSpec = { fadeIn(tween(220)) togetherWith fadeOut(tween(120)) }, label = "teamTab") { t ->
            Column {
                when (t) {
                    R.string.spx_tab_overview -> TeamOverview(p, color, now, go) { reload++ }
                    R.string.sp_tab_matches -> TeamMatches(p, now, go)
                    R.string.spx_tab_squad -> TeamSquad(squad, color, go)
                    R.string.spx_tab_stats -> TeamStats(stats, color) { cid -> scope.launch { stats = null; stats = runCatching { ApiClient.sports.teamStatistics(spAuth(), id, cid).data }.getOrNull() ?: SpTeamStatsDto() } }
                    else -> TransfersList(transfers, go)
                }
            }
        }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
private fun TeamHeader(p: SpTeamPageDto, color: Color, go: (SpPage) -> Unit, onFollow: () -> Unit) {
    val deep = Color(color.red * 0.35f, color.green * 0.35f, color.blue * 0.35f)
    Box(Modifier.fillMaxWidth().waRise(0).clip(RoundedCornerShape(28.dp)).background(Brush.linearGradient(listOf(color, deep)))) {
        // The stadium behind, darkened into the team's colour.
        p.venue?.image?.let { AsyncImage(model = ApiClient.mediaUrl(it), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.matchParentSize(), alpha = 0.42f) }
        Box(Modifier.matchParentSize().background(Brush.verticalGradient(listOf(Color.Black.copy(alpha = 0.15f), deep.copy(alpha = 0.92f)))))
        Column(Modifier.fillMaxWidth().padding(18.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Crest(SpTeamDto(id = p.id, name = p.name, logo = p.logo, color = p.color), 88.dp, Modifier.background(Color.White, CircleShape))
            Text(p.name.orEmpty(), color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 10.dp))
            Text(
                listOfNotNull(p.country, p.founded?.let { stringResource(R.string.spx_founded, it) }).joinToString(" · "),
                color = Color.White.copy(alpha = 0.82f), fontSize = 12.5.sp,
            )
            Row(Modifier.padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                val following = p.follow != null
                Text(
                    if (following) "★  " + stringResource(R.string.sp_following) else "☆  " + stringResource(R.string.sp_follow),
                    color = if (following) color else Color.White, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold,
                    modifier = Modifier.clip(CircleShape).background(if (following) Color.White else Color.White.copy(alpha = 0.18f)).clickable(onClick = onFollow).padding(horizontal = 18.dp, vertical = 9.dp),
                )
                p.coach?.let { c ->
                    Row(
                        Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.16f)).clickable { c.id?.let { go(SpPage.Coach(it)) } }.padding(start = 4.dp, end = 12.dp, top = 4.dp, bottom = 4.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        PhotoAvatar(c.photo, c.name, 30.dp)
                        Spacer(Modifier.width(6.dp))
                        Text(c.name.orEmpty(), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                    }
                }
            }
        }
    }
}

// ------------------------------------------------------------------ overview

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun TeamOverview(p: SpTeamPageDto, color: Color, now: Long, go: (SpPage) -> Unit, onChanged: () -> Unit) {
    val scope = rememberCoroutineScope()
    if (p.live.isNotEmpty()) {
        WaSectionTitle(stringResource(R.string.sp_live), topPadding = 0.dp)
        p.live.forEach { m -> LiveCard(m, now, Modifier.fillMaxWidth().padding(bottom = 8.dp)) { go(SpPage.Match(m.id)) } }
    }
    p.next.firstOrNull()?.let { m -> NextMatchCard(m, p.id, color, now) { go(SpPage.Match(m.id)) } }

    if (p.form.isNotEmpty()) {
        SectionCard(stringResource(R.string.spx_last_five)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                p.form.reversed().forEachIndexed { i, f ->
                    Column(Modifier.weight(1f).waRise(i).clip(RoundedCornerShape(14.dp)).background(formColor(f.result.firstOrNull() ?: 'D').copy(alpha = 0.12f)).clickable { go(SpPage.Match(f.id)) }.padding(vertical = 8.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Text(formLetter(f.result.firstOrNull() ?: 'D'), color = formColor(f.result.firstOrNull() ?: 'D'), fontSize = 16.sp, fontWeight = FontWeight.Black)
                        Text(f.score.orEmpty(), color = Wa.Mut, fontSize = 11.sp)
                    }
                }
            }
        }
    }
    p.tables.forEach { t ->
        SectionCard(null) {
            Row(Modifier.fillMaxWidth().clickable { t.competition?.id?.let { go(SpPage.Competition(it)) } }, verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(52.dp).clip(RoundedCornerShape(16.dp)).background(color.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                    Text("${t.rank}", color = color, fontSize = 24.sp, fontWeight = FontWeight.Black)
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(t.competition?.name.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(stringResource(R.string.spx_table_line, t.points, t.played, (if (t.goalDiff > 0) "+" else "") + t.goalDiff), color = Wa.Mut, fontSize = 12.sp)
                    t.description?.let { Text(it, color = zoneColorOf(it) ?: Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1) }
                }
                FormStrip(t.form, size = 16.dp)
            }
        }
    }
    p.venue?.let { v ->
        SectionCard(stringResource(R.string.spx_stadium)) {
            v.image?.let { AsyncImage(model = ApiClient.mediaUrl(it), contentDescription = v.name, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxWidth().height(150.dp).clip(RoundedCornerShape(16.dp))) }
            Spacer(Modifier.height(8.dp))
            InfoLine("🏟️", stringResource(R.string.spx_name), v.name)
            InfoLine("📍", stringResource(R.string.spx_city), listOfNotNull(v.city, v.address).joinToString(" · ").ifBlank { null })
            InfoLine("👥", stringResource(R.string.spx_capacity), v.capacity?.let { "%,d".format(it) })
            InfoLine("🌱", stringResource(R.string.spx_surface), v.surface)
        }
    }
    // Following: what to be told about.
    p.follow?.let { f ->
        SectionCard(stringResource(R.string.sp_alerts_for_team)) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                AlertLabels.forEach { (key, label) ->
                    val on = f.alerts[key] == true
                    Text(
                        (if (on) "🔔 " else "🔕 ") + stringResource(label), color = if (on) Color.White else Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(CircleShape).background(if (on) Wa.Red else Wa.Field).clickable {
                            scope.launch {
                                runCatching { ApiClient.sports.follow(spAuth(), JsonObject().apply { addProperty("kind", "team"); addProperty("target_id", p.id); add("alerts", JsonObject().apply { addProperty(key, !on) }) }) }
                                onChanged()
                            }
                        }.padding(horizontal = 11.dp, vertical = 7.dp),
                    )
                }
            }
            ToggleLine(stringResource(R.string.sp_no_spoilers_team), f.noSpoilers, stringResource(R.string.sp_no_spoilers_sub)) { on ->
                scope.launch {
                    runCatching { ApiClient.sports.follow(spAuth(), JsonObject().apply { addProperty("kind", "team"); addProperty("target_id", p.id); addProperty("no_spoilers", on) }) }
                    onChanged()
                }
            }
        }
    }
    if (p.live.isEmpty() && p.next.isEmpty() && p.form.isEmpty() && p.tables.isEmpty()) StateNote(null, Modifier.padding(top = 12.dp), empty = R.string.sp_no_comp_matches)
}

/** The next match, big, with the time left. */
@Composable
private fun NextMatchCard(m: SpMatchDto, teamId: Int, color: Color, now: Long, onClick: () -> Unit) {
    val left = m.startsAt?.let { runCatching { Duration.between(Instant.ofEpochMilli(now), Instant.parse(it)) }.getOrNull() }
    Column(
        Modifier.fillMaxWidth().padding(top = 4.dp).waRise(1).clip(RoundedCornerShape(24.dp)).background(Brush.linearGradient(listOf(Color(0xFF0B1220), color.copy(alpha = 0.85f)))).clickable(onClick = onClick).padding(16.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.spx_next_match), color = Color.White.copy(alpha = 0.75f), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            Text(m.competition?.name.orEmpty(), color = Color.White.copy(alpha = 0.75f), fontSize = 11.5.sp, maxLines = 1)
        }
        Row(Modifier.fillMaxWidth().padding(top = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                Crest(m.home, 54.dp, Modifier.background(Color.White, CircleShape))
                Text(m.home?.name.orEmpty(), color = Color.White, fontSize = 12.5.sp, fontWeight = if (m.home?.id == teamId) FontWeight.ExtraBold else FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 6.dp))
            }
            Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.width(110.dp)) {
                if (left != null && !left.isNegative) {
                    val d = left.toDays()
                    val h = left.toHours() % 24
                    val min = left.toMinutes() % 60
                    Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                        listOf(d to R.string.spx_days, h to R.string.spx_hours, min to R.string.spx_minutes).forEach { (v, l) ->
                            Column(Modifier.clip(RoundedCornerShape(10.dp)).background(Color.White.copy(alpha = 0.14f)).padding(horizontal = 6.dp, vertical = 4.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                                Text("$v", color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.Black)
                                Text(stringResource(l), color = Color.White.copy(alpha = 0.7f), fontSize = 8.5.sp)
                            }
                        }
                    }
                }
                Text(listOfNotNull(m.localDate?.let { shortDate(it) }, m.localTime).joinToString(" · "), color = Color.White.copy(alpha = 0.8f), fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp))
            }
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                Crest(m.away, 54.dp, Modifier.background(Color.White, CircleShape))
                Text(m.away?.name.orEmpty(), color = Color.White, fontSize = 12.5.sp, fontWeight = if (m.away?.id == teamId) FontWeight.ExtraBold else FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 6.dp))
            }
        }
    }
}

// ------------------------------------------------------------------ matches

@Composable
private fun TeamMatches(p: SpTeamPageDto, now: Long, go: (SpPage) -> Unit) {
    var which by remember { mutableStateOf(if (p.next.isNotEmpty() || p.live.isNotEmpty()) "next" else "last") }
    ChoiceChips(listOf("next" to stringResource(R.string.sp_next), "last" to stringResource(R.string.sp_last)), which) { which = it }
    Spacer(Modifier.height(10.dp))
    val list = if (which == "next") p.live + p.next else p.last
    if (list.isEmpty()) StateNote(null, empty = R.string.sp_no_comp_matches)
    // By competition, each with its logo.
    list.forEach { m -> MatchDayRow(m, now) { go(SpPage.Match(m.id)) } }
}

// ------------------------------------------------------------------ squad

private val Lines = mapOf("Goalkeeper" to R.string.spx_goalkeepers, "Defender" to R.string.spx_defenders, "Midfielder" to R.string.spx_midfielders, "Attacker" to R.string.spx_attackers)

@Composable
private fun TeamSquad(squad: SpPart<List<SpSquadLineDto>>?, color: Color, go: (SpPage) -> Unit) {
    when {
        squad == null -> SkeletonRows(6)
        squad.data.isNullOrEmpty() -> StateNote(squad.state)
        else -> squad.data.forEach { line ->
            WaSectionTitle(Lines[line.position]?.let { stringResource(it) } ?: line.position.orEmpty(), topPadding = 8.dp, trailing = { Text("${line.players.size}", color = Wa.Soft, fontSize = 12.sp, fontWeight = FontWeight.Bold) })
            line.players.chunked(3).forEachIndexed { r, row ->
                Row(Modifier.fillMaxWidth().padding(bottom = 8.dp).waRise(r.coerceAtMost(6)), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    row.forEach { pl ->
                        Column(
                            Modifier.weight(1f).clip(RoundedCornerShape(20.dp)).background(Wa.Surface).clickable { pl.id?.let { go(SpPage.Player(it)) } }.padding(vertical = 12.dp, horizontal = 6.dp),
                            horizontalAlignment = Alignment.CenterHorizontally,
                        ) {
                            Box {
                                PhotoAvatar(pl.photo, pl.name, 60.dp)
                                pl.number?.let {
                                    Box(Modifier.align(Alignment.BottomEnd).size(22.dp).clip(CircleShape).background(color), contentAlignment = Alignment.Center) {
                                        Text("$it", color = Color.White, fontSize = 10.5.sp, fontWeight = FontWeight.Black)
                                    }
                                }
                            }
                            Text(pl.name.orEmpty(), color = Wa.Ink, fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 6.dp))
                            pl.age?.let { Text(stringResource(R.string.spx_age, it), color = Wa.Soft, fontSize = 10.5.sp) }
                        }
                    }
                    repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
                }
            }
        }
    }
}

// ------------------------------------------------------------------ statistics

private fun JsonObject?.at(path: String): JsonElement? {
    var cur: JsonElement? = this ?: return null
    for (k in path.split('.')) {
        cur = (cur as? JsonObject)?.get(k) ?: return null
        if (cur.isJsonNull) return null
    }
    return cur
}

private fun JsonObject?.int(path: String): Int? = runCatching { this.at(path)?.asInt }.getOrNull()

private fun JsonObject?.str(path: String): String? = runCatching { this.at(path)?.asString }.getOrNull()

@Composable
private fun TeamStats(stats: SpTeamStatsDto?, color: Color, onCompetition: (Int) -> Unit) {
    if (stats == null) { SkeletonRows(6); return }
    if (stats.competitions.size > 1) {
        ChoiceChips(stats.competitions.map { it.id.toString() to it.name.orEmpty() }, stats.competition?.id?.toString()) { onCompetition(it.toInt()) }
        Spacer(Modifier.height(10.dp))
    }
    val d = stats.data
    if (d == null) { StateNote(stats.state); return }
    FormStrip(d.form, Modifier.padding(bottom = 10.dp), size = 20.dp, last = 10)
    val fx = d.fixtures
    StatGrid(listOf(
        Triple("${fx.int("played.total") ?: 0}", stringResource(R.string.spx_played), "🏟️"),
        Triple("${fx.int("wins.total") ?: 0}", stringResource(R.string.spx_wins), "✅"),
        Triple("${fx.int("draws.total") ?: 0}", stringResource(R.string.spx_draws), "🤝"),
        Triple("${fx.int("loses.total") ?: 0}", stringResource(R.string.spx_losses), "❌"),
        Triple("${d.cleanSheet.int("total") ?: 0}", stringResource(R.string.spx_clean_sheets), "🧤"),
        Triple("${d.failedToScore.int("total") ?: 0}", stringResource(R.string.spx_failed_to_score), "🚫"),
    ))
    val gf = d.goals?.get("for")
    val ga = d.goals?.get("against")
    SectionCard(stringResource(R.string.spx_goals)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            StatTile("${gf?.total.int("total") ?: 0}", stringResource(R.string.spx_scored) + " · " + (gf?.average.str("total") ?: "-"), Modifier.weight(1f), SpGreen)
            StatTile("${ga?.total.int("total") ?: 0}", stringResource(R.string.spx_conceded) + " · " + (ga?.average.str("total") ?: "-"), Modifier.weight(1f), SpLive)
        }
        if (!gf?.minute.isNullOrEmpty()) {
            Text(stringResource(R.string.spx_when_scored), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
            MinuteChart(gf!!.minute, SpGreen)
        }
        if (!ga?.minute.isNullOrEmpty()) {
            Text(stringResource(R.string.spx_when_conceded), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 14.dp, bottom = 6.dp))
            MinuteChart(ga!!.minute, SpLive)
        }
    }
    // At home and away.
    SectionCard(stringResource(R.string.spx_home_away)) {
        listOf("wins" to R.string.spx_wins, "draws" to R.string.spx_draws, "loses" to R.string.spx_losses).forEachIndexed { i, (k, l) ->
            CompareRow(stringResource(l), (fx.int("$k.home") ?: 0).toDouble(), (fx.int("$k.away") ?: 0).toDouble(), "${fx.int("$k.home") ?: 0}", "${fx.int("$k.away") ?: 0}", color, Color(0xFF64748B), i)
        }
        CompareRow(stringResource(R.string.spx_scored), (gf?.total.int("home") ?: 0).toDouble(), (gf?.total.int("away") ?: 0).toDouble(), "${gf?.total.int("home") ?: 0}", "${gf?.total.int("away") ?: 0}", color, Color(0xFF64748B), 3)
        Row(Modifier.fillMaxWidth().padding(top = 4.dp)) {
            Text("🏠 " + stringResource(R.string.spx_at_home), color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.weight(1f))
            Text(stringResource(R.string.spx_away) + " ✈️", color = Wa.Soft, fontSize = 11.sp)
        }
    }
    // Records.
    val big = d.biggest
    SectionCard(stringResource(R.string.spx_records)) {
        InfoLine("🔥", stringResource(R.string.spx_win_streak), big.int("streak.wins")?.toString())
        InfoLine("🏆", stringResource(R.string.spx_biggest_win), listOfNotNull(big.str("wins.home"), big.str("wins.away")).joinToString(" · ").ifBlank { null })
        InfoLine("💔", stringResource(R.string.spx_biggest_loss), listOfNotNull(big.str("loses.home"), big.str("loses.away")).joinToString(" · ").ifBlank { null })
        InfoLine("🎯", stringResource(R.string.spx_penalties), d.penalty?.let { "${it.int("scored.total") ?: 0} / ${it.int("total") ?: 0}" })
    }
    if (d.lineups.isNotEmpty()) {
        SectionCard(stringResource(R.string.spx_formations)) {
            val top = d.lineups.maxOf { it.played }.coerceAtLeast(1)
            d.lineups.take(5).forEach { f ->
                Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(f.formation.orEmpty(), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.width(70.dp))
                    Box(Modifier.weight(1f).height(10.dp).clip(CircleShape).background(Wa.Field)) {
                        Box(Modifier.fillMaxWidth(f.played.toFloat() / top).height(10.dp).clip(CircleShape).background(color))
                    }
                    Text("${f.played}", color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.width(32.dp), textAlign = TextAlign.End)
                }
            }
        }
    }
    val yellow = d.cards?.get("yellow").orEmpty()
    val red = d.cards?.get("red").orEmpty()
    if (yellow.any { it.total > 0 } || red.any { it.total > 0 }) {
        SectionCard(stringResource(R.string.spx_cards_by_minute)) {
            if (yellow.any { it.total > 0 }) MinuteChart(yellow, Color(0xFFF59E0B))
            if (red.any { it.total > 0 }) { Spacer(Modifier.height(10.dp)); MinuteChart(red, SpLive) }
        }
    }
    stats.fetchedAt?.let { Text(stringResource(R.string.sp_updated_at, it.take(16).replace('T', ' ')), color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.padding(top = 8.dp)) }
}

// ------------------------------------------------------------------ transfers

@Composable
internal fun TransfersList(transfers: SpPart<List<SpTransferDto>>?, go: (SpPage) -> Unit) {
    when {
        transfers == null -> SkeletonRows(6)
        transfers.data.isNullOrEmpty() -> StateNote(transfers.state)
        else -> transfers.data.forEachIndexed { i, t ->
            WaCard(Modifier.fillMaxWidth().padding(bottom = 8.dp).waRise(i.coerceAtMost(8)), padding = 12.dp) {
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.clickable { t.player?.id?.let { go(SpPage.Player(it)) } }) {
                    PhotoAvatar(t.player?.photo, t.player?.name, 42.dp)
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(t.player?.name.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                        Text(listOfNotNull(t.date?.let { shortDate(it) + " " + it.take(4) }, t.type?.takeIf { it.isNotBlank() && it != "N/A" }).joinToString(" · "), color = Wa.Mut, fontSize = 11.5.sp)
                    }
                    t.direction?.let { dir ->
                        Text(
                            stringResource(if (dir == "in") R.string.spx_in else R.string.spx_out), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Black,
                            modifier = Modifier.clip(CircleShape).background(if (dir == "in") SpGreen else SpLive).padding(horizontal = 10.dp, vertical = 4.dp),
                        )
                    }
                }
                Row(Modifier.fillMaxWidth().padding(top = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                    TransferClub(t.from, Modifier.weight(1f), go)
                    Text("➜", color = Wa.Soft, fontSize = 18.sp, modifier = Modifier.padding(horizontal = 8.dp))
                    TransferClub(t.to, Modifier.weight(1f), go)
                }
            }
        }
    }
}

@Composable
private fun TransferClub(team: SpTeamDto?, modifier: Modifier, go: (SpPage) -> Unit) {
    Row(modifier.clip(RoundedCornerShape(12.dp)).background(Wa.Field).clickable { team?.id?.takeIf { it > 0 }?.let { go(SpPage.Team(it)) } }.padding(8.dp), verticalAlignment = Alignment.CenterVertically) {
        Crest(team, 24.dp)
        Spacer(Modifier.width(6.dp))
        Text(team?.name.orEmpty(), color = Wa.Ink, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}
