package com.dorr.app.ui.screens.sports

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.foundation.border
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import coil.compose.AsyncImage
import androidx.compose.ui.layout.ContentScale
import com.dorr.app.network.ApiClient
import com.dorr.app.network.SpCompetitionPageDto
import com.dorr.app.network.SpEventDto
import com.dorr.app.network.SpLineupDto
import com.dorr.app.network.SpMatchDto
import com.dorr.app.network.SpStatDto
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** The match page (191): the live card, then events, statistics, line-ups and the table. */
@Composable
internal fun MatchPage(id: String, onBack: () -> Unit, go: (SpPage) -> Unit) {
    var match by remember { mutableStateOf<SpMatchDto?>(null) }
    var table by remember { mutableStateOf<SpCompetitionPageDto?>(null) }
    var tab by remember { mutableIntStateOf(0) }
    var tabKey by remember { mutableStateOf<Int?>(null) }
    var revealed by remember { mutableStateOf(false) }
    val now = rememberTicker()
    val scope = rememberCoroutineScope()

    suspend fun load() {
        runCatching { ApiClient.sports.match(spAuth(), id, spZone()).data }.getOrNull()?.let { match = it }
    }
    LaunchedEffect(id) { load() }
    // Realtime is the fast path; while the match is on, a reload every 45 s is the safety net.
    val onNow = match?.status in setOf("live", "break")
    LaunchedEffect(id, onNow) {
        while (onNow) {
            delay(45_000)
            load()
        }
    }
    // Its own channel while the page is open; a newer version reloads the details (events, stats).
    DisposableEffect(id) {
        ChatRealtime.watchPublic("sports.match.$id", listOf("sports.match.updated"))
        onDispose { ChatRealtime.unwatchPublic("sports.match.$id") }
    }
    val liveVersion = SportsLive.latest[id]?.version ?: 0
    LaunchedEffect(liveVersion) {
        if (liveVersion > (match?.version ?: Int.MAX_VALUE)) {
            delay(1200)
            load()
        }
    }
    // Prediction, head to head, who's missing (football): one request, kept on our server.
    var insights by remember { mutableStateOf<com.dorr.app.network.SpInsightsDto?>(null) }
    LaunchedEffect(id, match?.sport) {
        if (insights == null && match?.sport == "football" && match?.home != null) insights = runCatching { ApiClient.sports.insights(spAuth(), id).data }.getOrNull() ?: com.dorr.app.network.SpInsightsDto()
    }
    var wantTable by remember { mutableStateOf(false) }
    LaunchedEffect(match?.competition?.id, wantTable) {
        val cid = match?.competition?.id
        if (wantTable && cid != null && table == null) table = runCatching { ApiClient.sports.competition(spAuth(), cid, spZone()).data }.getOrNull()
    }

    val m = match?.let { SportsLive.fresh(it) }
    val hide = SportsLive.prefs.noSpoilers && !revealed

    WaPage(title = m?.competition?.name ?: stringResource(R.string.sp_title), onBack = onBack) {
        if (m == null) {
            WaSkeleton(Modifier.fillMaxWidth().height(230.dp), RoundedCornerShape(28.dp))
            Spacer(Modifier.height(12.dp))
            repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(48.dp).padding(bottom = 8.dp), RoundedCornerShape(16.dp)) }
            return@WaPage
        }
        if (m.home == null) {
            RaceHero(m, now)
        } else {
            Hero(m, now, hide, onReveal = { revealed = true }, onTeam = { tid -> go(SpPage.Team(tid)) }, onFollow = { side ->
                val team = if (side == "home") m.home else m.away
                val on = if (side == "home") m.following?.home != true else m.following?.away != true
                team?.let { t -> scope.launch { toggleFollow("team", t.id, on); load() } }
            })
            m.fight?.let { FightBanner(m, it, hide) }
            if (!hide) MatchPoster(m, m.poster ?: insights?.poster, go)
        }
        // Share, watch together (195).
        var sheet by remember { mutableStateOf<String?>(null) }
        val context = androidx.compose.ui.platform.LocalContext.current
        val roomMade = stringResource(R.string.sp_room_made)
        Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("share" to (R.string.sp_share to "📤"), "room" to (R.string.sp_watch_together to "🍿")).forEach { (key, v) ->
                Row(
                    Modifier.weight(1f).clip(RoundedCornerShape(16.dp)).background(Wa.Surface).clickable { sheet = key }.padding(vertical = 11.dp),
                    horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(v.second, fontSize = 16.sp)
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(v.first), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
        when (sheet) {
            "share" -> ShareMatchSheet(m) { sheet = null }
            "room" -> com.dorr.app.ui.screens.chat.PeoplePickerSheet(emptySet(), onDismiss = { sheet = null }) { ids ->
                scope.launch {
                    makeRoom(m, ids)?.let { conv ->
                        android.widget.Toast.makeText(context, roomMade, android.widget.Toast.LENGTH_SHORT).show()
                        SportsLink.close()
                        com.dorr.app.chat.ChatPush.open(com.dorr.app.chat.ChatDeepLink.Conversation(conv))
                    }
                }
            }
        }
        PlayCard(m) { cid -> go(SpPage.Contest(cid)) }
        Spacer(Modifier.height(12.dp))

        val tabs = buildList {
            when {
                m.home == null -> add(R.string.sp_tab_results)
                m.sport != "football" && m.sport != "mma" -> add(R.string.sp_tab_periods)
                m.sport == "football" -> add(R.string.sp_tab_events)
            }
            if (!m.statistics.isNullOrEmpty()) add(R.string.sp_tab_stats)
            if (!m.lineups.isNullOrEmpty()) add(R.string.sp_tab_lineups)
            if (!m.players.isNullOrEmpty()) add(R.string.spx_tab_players)
            if (m.sport == "football" && m.home != null) {
                add(R.string.spx_tab_forecast)
                add(R.string.spx_h2h)
                if (!insights?.injuries?.data.isNullOrEmpty()) add(R.string.spx_tab_missing)
            }
            if (m.sport != "mma") add(R.string.sp_tab_table)
        }
        // The picked tab by what it is: statistics or line-ups arriving later don't move it.
        val current = tabKey?.takeIf { it in tabs } ?: tabs[tab.coerceIn(0, tabs.lastIndex)]
        LaunchedEffect(current) { if (current == R.string.sp_tab_table) wantTable = true }
        ScrollTabs(tabs.map { stringResource(it) }, tabs.indexOf(current)) { i -> tab = i; tabKey = tabs[i] }
        Spacer(Modifier.height(12.dp))
        when (current) {
            R.string.sp_tab_events -> if (hide) Text(stringResource(R.string.sp_hidden_events), color = Wa.Mut, fontSize = 13.sp) else Timeline(m, go)
            R.string.sp_tab_results -> if (hide) Text(stringResource(R.string.sp_hidden_events), color = Wa.Mut, fontSize = 13.sp) else RaceResults(m)
            R.string.sp_tab_periods -> if (hide) Text(stringResource(R.string.sp_hidden_events), color = Wa.Mut, fontSize = 13.sp) else PeriodsTable(m)
            R.string.sp_tab_stats -> Stats(m.statistics.orEmpty(), teamColor(m.home?.color, m.home?.id ?: 0), teamColor(m.away?.color, m.away?.id ?: 1))
            R.string.sp_tab_lineups -> PitchLineups(m, go)
            R.string.spx_tab_players -> PlayersSheet(m, go)
            R.string.spx_tab_forecast -> ForecastTab(m, insights)
            R.string.spx_h2h -> H2hTab(m, insights, go)
            R.string.spx_tab_missing -> MissingTab(m, insights, go)
            else -> {
                val t = table
                if (t == null) WaSkeleton(Modifier.fillMaxWidth().height(200.dp), RoundedCornerShape(20.dp))
                else (if (m.home == null) t.standings.firstOrNull() else t.standings.firstOrNull { g -> g.rows.any { it.team?.id == m.home?.id } })?.let { g ->
                    StandingsTable(g.rows, setOfNotNull(m.home?.id, m.away?.id)) { team -> go(SpPage.Team(team.id)) }
                } ?: Text(stringResource(R.string.sp_no_table), color = Wa.Mut, fontSize = 13.sp)
            }
        }
        // Where and who.
        val info = listOfNotNull(m.venue?.let { "🏟️ $it" + (m.city?.let { c -> ", $c" } ?: "") }, m.referee?.let { "🧑‍⚖️ $it" }, m.round?.let { "🗓️ $it" })
        if (info.isNotEmpty()) {
            Column(Modifier.fillMaxWidth().padding(top = 16.dp).clip(RoundedCornerShape(18.dp)).background(Wa.Surface).padding(14.dp)) {
                info.forEach { Text(it, color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.padding(vertical = 2.dp)) }
            }
        }
        Text(stringResource(R.string.sp_official_data), color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.padding(top = 10.dp))
        Spacer(Modifier.height(24.dp))
    }
}

/** The top of the match page: both kits, crests, a big flipping score, the minute in a ring. */
@Composable
private fun Hero(m: SpMatchDto, now: Long, hide: Boolean, onReveal: () -> Unit, onTeam: (Int) -> Unit, onFollow: (String) -> Unit) {
    val home = teamColor(m.home?.color, m.home?.id ?: 0)
    val away = teamColor(m.away?.color, m.away?.id ?: 1)
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val started = m.status in setOf("live", "break", "finished")
    // A goal on screen: the score area glows once.
    val changed = SportsLive.changedAt[m.id] ?: 0L
    val glow = remember(changed) { Animatable(if (changed > 0 && System.currentTimeMillis() - changed < 5000) 1f else 0f) }
    LaunchedEffect(changed) { if (glow.value > 0f) glow.animateTo(0f, tween(2600, easing = FastOutSlowInEasing)) }

    Box(
        Modifier.fillMaxWidth().waRise(0).clip(RoundedCornerShape(28.dp))
            .background(Brush.linearGradient(if (rtl) listOf(away, Color(0xFF0B1220), home) else listOf(home, Color(0xFF0B1220), away))),
    ) {
        // The stadium behind (its photo), and each side's captain, faint — a poster.
        val poster = m.poster
        poster?.venue?.image?.let { AsyncImage(model = ApiClient.mediaUrl(it), contentDescription = null, contentScale = ContentScale.Crop, alpha = 0.30f, modifier = Modifier.matchParentSize()) }
        if (!hide) {
            poster?.home?.captain?.photo?.let { AsyncImage(model = ApiClient.mediaUrl(it), contentDescription = null, contentScale = ContentScale.Crop, alpha = 0.18f, modifier = Modifier.size(150.dp).align(if (rtl) Alignment.BottomEnd else Alignment.BottomStart)) }
            poster?.away?.captain?.photo?.let { AsyncImage(model = ApiClient.mediaUrl(it), contentDescription = null, contentScale = ContentScale.Crop, alpha = 0.18f, modifier = Modifier.size(150.dp).align(if (rtl) Alignment.BottomStart else Alignment.BottomEnd)) }
        }
        Box(Modifier.matchParentSize().background(Brush.verticalGradient(listOf(Color.Black.copy(alpha = 0.10f), Color.Black.copy(alpha = 0.35f)))))
        // Pitch lines, faint.
        Canvas(Modifier.matchParentSize().alpha(0.10f)) {
            drawCircle(Color.White, radius = size.minDimension * 0.22f, center = center, style = Stroke(3f))
            drawLine(Color.White, Offset(center.x, 0f), Offset(center.x, size.height), 3f)
        }
        Column(Modifier.fillMaxWidth().padding(18.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(m.round ?: "", color = Color.White.copy(alpha = 0.75f), fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                StatusChip(m, now, onDark = true)
            }
            Spacer(Modifier.height(14.dp))
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                HeroTeam(m.home, m.following?.home == true, Modifier.weight(1f), onTeam, { onFollow("home") })
                Box(Modifier.size(136.dp), contentAlignment = Alignment.Center) {
                    // A soft halo behind (it flares on a goal), a dark disc, the ring on the disc's edge.
                    Box(
                        Modifier.matchParentSize().graphicsLayer { val k = 1f + glow.value * 0.1f; scaleX = k; scaleY = k }
                            .background(Brush.radialGradient(listOf(Color.White.copy(alpha = 0.16f + glow.value * 0.3f), Color.Transparent)), CircleShape),
                    )
                    Box(Modifier.size(112.dp).clip(CircleShape).background(Color(0xFF0B1220).copy(alpha = 0.55f)).border(1.dp, Color.White.copy(alpha = 0.10f), CircleShape))
                    if (started && !hide) {
                        val live = m.status == "live"
                        MinuteRing(m, now, 112.dp, if (live) Color(0xFF4ADE80) else Color.White) {
                            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    FlipScore(m.homeScore, 30.sp, Color.White)
                                    Text(":", color = Color.White.copy(alpha = 0.55f), fontSize = 24.sp, fontWeight = FontWeight.Black, modifier = Modifier.padding(horizontal = 4.dp))
                                    FlipScore(m.awayScore, 30.sp, Color.White)
                                }
                                Text(
                                    clockText(m, now, stringResource(R.string.sp_ht), stringResource(R.string.sp_ft)),
                                    color = if (live) Color(0xFF4ADE80) else Color.White.copy(alpha = 0.7f), fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1,
                                )
                            }
                        }
                    } else if (started) {
                        Text(
                            stringResource(R.string.sp_reveal), color = Color(0xFF0B1220), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold,
                            modifier = Modifier.clip(CircleShape).background(Color.White).clickable(onClick = onReveal).padding(horizontal = 14.dp, vertical = 8.dp),
                        )
                    } else {
                        Column(horizontalAlignment = Alignment.CenterHorizontally) {
                            Text(m.localTime.orEmpty(), color = Color.White, fontSize = 26.sp, fontWeight = FontWeight.Black)
                            m.localDate?.let { Text(it, color = Color.White.copy(alpha = 0.7f), fontSize = 11.sp, maxLines = 1) }
                        }
                    }
                }
                HeroTeam(m.away, m.following?.away == true, Modifier.weight(1f), onTeam, { onFollow("away") })
            }
            if (started && !hide) {
                // Goal scorers under each side (football).
                val goals = m.events.orEmpty().filter { it.type in setOf("goal", "penalty", "own_goal") }
                if (goals.isNotEmpty()) {
                    Row(Modifier.fillMaxWidth().padding(top = 12.dp)) {
                        listOf("home", "away").forEach { side ->
                            Column(Modifier.weight(1f), horizontalAlignment = if (side == "home") Alignment.Start else Alignment.End) {
                                goals.filter { it.side == side }.forEach { g ->
                                    Text("⚽ ${g.player.orEmpty()} ${g.minute ?: ""}'" + if (g.type == "penalty") " (P)" else if (g.type == "own_goal") " (OG)" else "", color = Color.White.copy(alpha = 0.88f), fontSize = 11.5.sp, maxLines = 1)
                                }
                            }
                        }
                    }
                }
                if (m.periods.isNotEmpty()) PeriodStrip(m, Modifier.padding(top = 12.dp), onDark = true)
            }
        }
    }
}

@Composable
private fun HeroTeam(team: com.dorr.app.network.SpTeamDto?, followed: Boolean, modifier: Modifier, onTeam: (Int) -> Unit, onFollow: () -> Unit) {
    Column(modifier, horizontalAlignment = Alignment.CenterHorizontally) {
        Crest(team, 66.dp, Modifier.background(Color.White, CircleShape).clickable { team?.id?.let(onTeam) })
        Text(team?.name.orEmpty(), color = Color.White, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, textAlign = TextAlign.Center, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 8.dp))
        Text(
            if (followed) "★" else "☆", color = if (followed) Color(0xFFFACC15) else Color.White.copy(alpha = 0.75f), fontSize = 20.sp,
            modifier = Modifier.clip(CircleShape).clickable(onClick = onFollow).padding(horizontal = 10.dp, vertical = 2.dp),
        )
    }
}

// =============================================================================== the timeline

/** Events down the middle: the home side on one side, the away side on the other, newest on top. */
@Composable
private fun Timeline(m: SpMatchDto, go: (SpPage) -> Unit) {
    val events = m.events.orEmpty().filter { it.type != "other" }.reversed()
    if (events.isEmpty()) {
        Text(stringResource(if (m.status == "scheduled") R.string.sp_not_started else R.string.sp_no_events), color = Wa.Mut, fontSize = 13.sp)
        return
    }
    WaCard(Modifier.fillMaxWidth(), padding = 12.dp) {
        events.forEachIndexed { i, e ->
            val enter = remember(e.minute, e.player, e.type) { Animatable(0f) }
            LaunchedEffect(e.minute, e.player, e.type) { enter.animateTo(1f, tween(380, delayMillis = (i * 40).coerceAtMost(400))) }
            Row(Modifier.fillMaxWidth().padding(vertical = 5.dp).graphicsLayer { alpha = enter.value; translationY = (1 - enter.value) * 24f }, verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.weight(1f), contentAlignment = Alignment.CenterEnd) { if (e.side == "home") EventText(e, alignEnd = true, go) }
                Box(Modifier.padding(horizontal = 8.dp).size(40.dp).clip(CircleShape).background(eventColor(e).copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                    Column(horizontalAlignment = Alignment.CenterHorizontally) {
                        Text(eventIcon(e), fontSize = 14.sp)
                        Text("${e.minute ?: ""}${e.extra?.let { "+$it" } ?: ""}'", color = Wa.Ink, fontSize = 9.5.sp, fontWeight = FontWeight.Bold)
                    }
                }
                Box(Modifier.weight(1f), contentAlignment = Alignment.CenterStart) { if (e.side == "away") EventText(e, alignEnd = false, go) }
            }
        }
    }
}

@Composable
private fun EventText(e: SpEventDto, alignEnd: Boolean, go: (SpPage) -> Unit) {
    // The player opens his page.
    Column(Modifier.clickable(enabled = e.playerId != null) { e.playerId?.let { go(SpPage.Player(it)) } }, horizontalAlignment = if (alignEnd) Alignment.End else Alignment.Start) {
        Text(e.player.orEmpty(), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = if (e.type in setOf("goal", "penalty", "own_goal")) FontWeight.ExtraBold else FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        val sub = when (e.type) {
            "sub" -> e.assist?.let { "↩ $it" }
            "goal" -> e.assist?.let { "🅰 $it" }
            "penalty" -> stringResource(R.string.sp_ev_penalty)
            "own_goal" -> stringResource(R.string.sp_ev_own_goal)
            "missed_penalty" -> stringResource(R.string.sp_ev_missed)
            "var" -> e.detail
            else -> null
        }
        sub?.let { Text(it, color = Wa.Mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
    }
}

private fun eventIcon(e: SpEventDto): String = when (e.type) {
    "goal", "penalty" -> "⚽"
    "own_goal" -> "🥅"
    "missed_penalty" -> "❌"
    "yellow" -> "🟨"
    "second_yellow" -> "🟨🟥"
    "red" -> "🟥"
    "sub" -> "🔁"
    "var" -> "📺"
    else -> "•"
}

private fun eventColor(e: SpEventDto): Color = when (e.type) {
    "goal", "penalty" -> SpGreen
    "own_goal", "red", "second_yellow", "missed_penalty" -> SpLive
    "yellow" -> Color(0xFFEAB308)
    else -> Color(0xFF64748B)
}

// =============================================================================== statistics

/** Each statistic as two bars that fill in from the middle, in the teams' colours. */
@Composable
private fun Stats(stats: List<SpStatDto>, home: Color, away: Color) {
    WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
        stats.forEachIndexed { i, s ->
            val h = s.home?.takeIf { it.isJsonPrimitive }?.asString?.trimEnd('%')?.toDoubleOrNull() ?: 0.0
            val a = s.away?.takeIf { it.isJsonPrimitive }?.asString?.trimEnd('%')?.toDoubleOrNull() ?: 0.0
            val total = (h + a).takeIf { it > 0 } ?: 1.0
            val grow = remember(s.type) { Animatable(0f) }
            LaunchedEffect(s.type, h, a) { grow.animateTo(1f, tween(700, delayMillis = i * 60, easing = FastOutSlowInEasing)) }
            Column(Modifier.padding(vertical = 6.dp)) {
                Row(Modifier.fillMaxWidth()) {
                    Text(s.home?.takeIf { it.isJsonPrimitive }?.asString ?: "0", color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.width(56.dp))
                    Text(statName(s.type), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center, modifier = Modifier.weight(1f), maxLines = 1)
                    Text(s.away?.takeIf { it.isJsonPrimitive }?.asString ?: "0", color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.End, modifier = Modifier.width(56.dp))
                }
                Row(Modifier.fillMaxWidth().padding(top = 4.dp).height(7.dp), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    Box(Modifier.weight(1f).fillMaxSize().clip(CircleShape).background(Wa.Field), contentAlignment = Alignment.CenterEnd) {
                        Box(Modifier.fillMaxWidth((h / total).toFloat() * grow.value).fillMaxSize().clip(CircleShape).background(home))
                    }
                    Box(Modifier.weight(1f).fillMaxSize().clip(CircleShape).background(Wa.Field), contentAlignment = Alignment.CenterStart) {
                        Box(Modifier.fillMaxWidth((a / total).toFloat() * grow.value).fillMaxSize().clip(CircleShape).background(away))
                    }
                }
            }
        }
    }
}

@Composable
private fun statName(type: String): String = when (type) {
    "Ball Possession" -> stringResource(R.string.sp_st_possession)
    "Total Shots" -> stringResource(R.string.sp_st_shots)
    "Shots on Goal" -> stringResource(R.string.sp_st_on_target)
    "Corner Kicks" -> stringResource(R.string.sp_st_corners)
    "Fouls" -> stringResource(R.string.sp_st_fouls)
    "Offsides" -> stringResource(R.string.sp_st_offsides)
    "Yellow Cards" -> stringResource(R.string.sp_st_yellow)
    "Red Cards" -> stringResource(R.string.sp_st_red)
    "Goalkeeper Saves" -> stringResource(R.string.sp_st_saves)
    "Total passes" -> stringResource(R.string.sp_st_passes)
    "Passes %" -> stringResource(R.string.sp_st_pass_acc)
    "expected_goals" -> "xG"
    else -> type
}
