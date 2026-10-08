package com.dorr.app.ui.screens.sports

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
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
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.SpFightDto
import com.dorr.app.network.SpMatchDto
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaCard

/*
 * Each sport in its own way (183): a race is its order and laps (F1), a fight its fighters and
 * how it ended (MMA), a game its periods (basketball quarters, volleyball sets, hockey periods…).
 */

private val RaceDark = Brush.linearGradient(listOf(Color(0xFF111827), Color(0xFF7F1D1D), Color(0xFF111827)))

/** A race in a list: the Grand Prix, its circuit, laps run, who leads. */
@Composable
internal fun RaceRow(m: SpMatchDto, now: Long, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Row(
        modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Wa.Surface).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(42.dp).clip(RoundedCornerShape(12.dp)).background(RaceDark), contentAlignment = Alignment.Center) { Text("🏎️", fontSize = 20.sp) }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(m.title ?: m.round.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(listOfNotNull(m.race?.type, m.race?.circuit).joinToString(" · "), color = Wa.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Column(horizontalAlignment = Alignment.End) {
            if (m.status == "scheduled") Text(m.localTime.orEmpty(), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            m.leader?.takeIf { m.status in setOf("live", "finished") && !SportsLive.prefs.noSpoilers }?.let { Text("P1 $it", color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Black) }
            StatusChip(m, now)
        }
    }
}

/** A race on my teams' row: the Grand Prix with the lap bar. */
@Composable
internal fun RaceCard(m: SpMatchDto, now: Long, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Column(modifier.width(300.dp).clip(RoundedCornerShape(26.dp)).background(RaceDark).clickable(onClick = onClick).padding(16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("🏁 " + (m.race?.type ?: ""), color = Color.White.copy(alpha = 0.8f), fontSize = 11.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            StatusChip(m, now, onDark = true)
        }
        Text(m.title ?: m.round.orEmpty(), color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.Black, maxLines = 2, modifier = Modifier.padding(top = 8.dp))
        Text(m.race?.circuit.orEmpty(), color = Color.White.copy(alpha = 0.75f), fontSize = 12.sp, maxLines = 1)
        LapBar(m, Modifier.padding(top = 12.dp))
    }
}

@Composable
private fun LapBar(m: SpMatchDto, modifier: Modifier = Modifier) {
    val total = m.race?.laps ?: return
    val lap = if (m.status == "finished") total else (m.minute ?: m.race.lap ?: 0)
    val p by animateFloatAsState((lap.toFloat() / total).coerceIn(0f, 1f), tween(900), label = "laps")
    Column(modifier.fillMaxWidth()) {
        Box(Modifier.fillMaxWidth().height(8.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.15f))) {
            Box(Modifier.fillMaxWidth(p).fillMaxSize().clip(CircleShape).background(Brush.horizontalGradient(listOf(Color(0xFFEF4444), Color(0xFFFACC15)))))
        }
        Text(stringResource(R.string.sp_laps, lap, total), color = Color.White.copy(alpha = 0.8f), fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp))
    }
}

/** The top of a race's page. */
@Composable
internal fun RaceHero(m: SpMatchDto, now: Long) {
    Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(28.dp)).background(RaceDark)) {
        m.race?.circuitImage?.let { AsyncImage(model = it, contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxWidth().height(150.dp).padding(16.dp).graphicsLayer { alpha = 0.18f }) }
        Column(Modifier.fillMaxWidth().padding(18.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("🏎️  " + (m.race?.type ?: ""), color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                StatusChip(m, now, onDark = true)
            }
            Text(m.title ?: m.round.orEmpty(), color = Color.White, fontSize = 24.sp, fontWeight = FontWeight.Black, modifier = Modifier.padding(top = 10.dp))
            Text(listOfNotNull(m.race?.circuit, m.city, m.race?.country).joinToString(" · "), color = Color.White.copy(alpha = 0.78f), fontSize = 12.5.sp)
            if (m.status == "scheduled") Text(listOfNotNull(m.localDate, m.localTime).joinToString("  "), color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 10.dp))
            LapBar(m, Modifier.padding(top = 14.dp))
            Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOfNotNull(m.race?.distance?.let { "📏 $it" }, m.race?.fastestLap?.let { "⚡ $it" }).forEach {
                    Text(it, color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.14f)).padding(horizontal = 10.dp, vertical = 5.dp))
                }
            }
        }
    }
}

/** The order: podium colours, team, time or gap, pit stops, places gained since the grid. */
@Composable
internal fun RaceResults(m: SpMatchDto) {
    val rows = m.results.orEmpty()
    if (rows.isEmpty()) {
        Text(stringResource(if (m.status == "scheduled") R.string.sp_not_started else R.string.sp_no_events), color = Wa.Mut, fontSize = 13.sp)
        return
    }
    WaCard(Modifier.fillMaxWidth(), padding = 8.dp) {
        rows.forEachIndexed { i, r ->
            val enter = remember(r.abbr, r.position) { Animatable(0f) }
            LaunchedEffect(r.abbr, r.position) { enter.animateTo(1f, tween(320, delayMillis = (i * 35).coerceAtMost(500))) }
            val podium = when (r.position) { 1 -> Color(0xFFF59E0B); 2 -> Color(0xFF94A3B8); 3 -> Color(0xFFB45309); else -> null }
            val gained = r.grid?.toIntOrNull()?.let { g -> r.position?.let { g - it } }
            Row(
                Modifier.fillMaxWidth().graphicsLayer { alpha = enter.value; translationX = (1 - enter.value) * 40f }.padding(horizontal = 6.dp, vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(Modifier.size(28.dp).clip(RoundedCornerShape(8.dp)).background(podium ?: Wa.Field), contentAlignment = Alignment.Center) {
                    Text("${r.position ?: "–"}", color = if (podium != null) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Black)
                }
                Spacer(Modifier.width(10.dp))
                Text(r.abbr ?: "", color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Black, fontFamily = FontFamily.Monospace, modifier = Modifier.width(44.dp))
                Column(Modifier.weight(1f)) {
                    Text(r.driver.orEmpty(), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(listOfNotNull(r.team, r.pits?.let { "🛞 $it" }).joinToString(" · "), color = Wa.Soft, fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text(if (r.position == 1) r.time.orEmpty() else r.gap ?: r.time.orEmpty(), color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, fontFamily = FontFamily.Monospace)
                    gained?.takeIf { it != 0 }?.let { Text(if (it > 0) "▲$it" else "▼${-it}", color = if (it > 0) SpGreen else SpLive, fontSize = 10.5.sp, fontWeight = FontWeight.Bold) }
                }
            }
        }
    }
}

/** How a fight ended: "KO/TKO · R2 3:05". */
@Composable
internal fun fightResult(m: SpMatchDto): String {
    val f = m.fight ?: return ""
    return listOfNotNull(f.wonBy, f.round?.let { stringResource(R.string.sp_round_n, it) + (f.time?.let { t -> " $t" } ?: "") }).joinToString(" · ").ifEmpty { stringResource(R.string.sp_ft) }
}

/** Under a fight's hero: the weight class, main event, and how it was won. */
@Composable
internal fun FightBanner(m: SpMatchDto, f: SpFightDto, hide: Boolean) {
    Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        listOfNotNull(
            f.category?.let { "🥊 $it" },
            if (f.main) stringResource(R.string.sp_main_event) else null,
            if (m.status == "finished" && !hide) "🏆 " + fightResult(m) else null,
        ).forEach {
            Text(it, color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(Wa.Surface).padding(horizontal = 12.dp, vertical = 7.dp))
        }
    }
}

/** Quarters, sets, periods, innings: a row per side, a column per period, the total last. */
@Composable
internal fun PeriodsTable(m: SpMatchDto) {
    if (m.periods.isEmpty()) {
        Text(stringResource(if (m.status == "scheduled") R.string.sp_not_started else R.string.sp_no_events), color = Wa.Mut, fontSize = 13.sp)
        return
    }
    WaCard(Modifier.fillMaxWidth(), padding = 12.dp) {
        Row(Modifier.fillMaxWidth()) {
            Spacer(Modifier.weight(1f))
            m.periods.forEach { Text(it.label, color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, modifier = Modifier.width(34.dp)) }
            Text("T", color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Black, textAlign = TextAlign.Center, modifier = Modifier.width(40.dp))
        }
        listOf(Triple(m.home, true, m.homeScore), Triple(m.away, false, m.awayScore)).forEach { (team, isHome, total) ->
            Row(Modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically) {
                    Crest(team, 26.dp)
                    Text(team?.name.orEmpty(), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(start = 8.dp))
                }
                m.periods.forEach { p ->
                    val mine = if (isHome) p.home else p.away
                    val other = if (isHome) p.away else p.home
                    val won = mine != null && other != null && mine > other
                    Text("${mine ?: "-"}", color = if (won) Wa.Ink else Wa.Mut, fontSize = 13.sp, fontWeight = if (won) FontWeight.ExtraBold else FontWeight.Normal, textAlign = TextAlign.Center, modifier = Modifier.width(34.dp))
                }
                Box(Modifier.width(40.dp), contentAlignment = Alignment.Center) { FlipScore(total, 17.sp, Wa.Ink) }
            }
        }
    }
}
