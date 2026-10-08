package com.dorr.app.ui.screens.sports

import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.SpInsightsDto
import com.dorr.app.network.SpLineupDto
import com.dorr.app.network.SpLineupPlayerDto
import com.dorr.app.network.SpMatchDto
import com.dorr.app.network.SpPersonDto
import com.dorr.app.network.SpPosterDto
import com.dorr.app.network.SpSheetPlayerDto
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.waRise

/*
 * The match page's extras (docs/sports-plan.md §10.3.6): the poster (stadium, captains,
 * coaches, the referee), line-ups on the pitch with photos and ratings, the players' sheet, the
 * provider's prediction, head to head, who's missing, and odds — information only, where allowed.
 */

// ------------------------------------------------------------------ the poster

/** Under the hero: the two captains face each other across the stadium, the referee between them. */
@Composable
internal fun MatchPoster(m: SpMatchDto, poster: SpPosterDto?, go: (SpPage) -> Unit) {
    val p = poster ?: return
    if (p.home?.captain == null && p.away?.captain == null && p.home?.coach == null && p.referee == null && p.venue?.image == null) return
    val home = teamColor(m.home?.color, m.home?.id ?: 0)
    val away = teamColor(m.away?.color, m.away?.id ?: 1)
    // The stadium drifts slowly, like a film poster.
    val drift = rememberInfiniteTransition(label = "poster")
    val zoom by drift.animateFloat(1f, 1.09f, infiniteRepeatable(tween(14000), RepeatMode.Reverse), label = "zoom")
    Box(Modifier.fillMaxWidth().padding(top = 10.dp).waRise(1).height(210.dp).clip(RoundedCornerShape(26.dp)).background(Color(0xFF0B1220))) {
        p.venue?.image?.let {
            AsyncImage(model = ApiClient.mediaUrl(it), contentDescription = null, contentScale = ContentScale.Crop, alpha = 0.55f,
                modifier = Modifier.matchParentSize().graphicsLayer { scaleX = zoom; scaleY = zoom })
        }
        Box(Modifier.matchParentSize().background(Brush.horizontalGradient(listOf(home.copy(alpha = 0.85f), Color.Transparent, away.copy(alpha = 0.85f)))))
        Box(Modifier.matchParentSize().background(Brush.verticalGradient(listOf(Color.Transparent, Color.Black.copy(alpha = 0.75f)))))
        Row(Modifier.fillMaxSize().padding(horizontal = 14.dp, vertical = 12.dp), verticalAlignment = Alignment.Bottom) {
            PosterSide(p.home?.captain, p.home?.coach, Modifier.weight(1f), Alignment.Start, go)
            Column(Modifier.weight(1f).padding(bottom = 4.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                p.referee?.name?.let { ref ->
                    Box(Modifier.size(46.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.55f)).border(2.dp, Color(0xFFFACC15), CircleShape), contentAlignment = Alignment.Center) { Text("🧑‍⚖️", fontSize = 22.sp) }
                    Text(ref, color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 4.dp))
                    Text(stringResource(R.string.spx_referee) + (p.referee.country?.let { " · $it" } ?: ""), color = Color.White.copy(alpha = 0.7f), fontSize = 10.sp, maxLines = 1)
                }
                p.venue?.name?.let {
                    Text("🏟️ $it", color = Color.White.copy(alpha = 0.85f), fontSize = 10.5.sp, textAlign = TextAlign.Center, maxLines = 2, modifier = Modifier.padding(top = 8.dp))
                }
            }
            PosterSide(p.away?.captain, p.away?.coach, Modifier.weight(1f), Alignment.End, go)
        }
    }
}

@Composable
private fun PosterSide(captain: SpPersonDto?, coach: SpPersonDto?, modifier: Modifier, align: Alignment.Horizontal, go: (SpPage) -> Unit) {
    Column(modifier, horizontalAlignment = align) {
        captain?.let { c ->
            Box(Modifier.clickable { c.id?.let { go(SpPage.Player(it)) } }) {
                PhotoAvatar(c.photo, c.name, 82.dp, ring = Color.White)
                // The armband.
                Box(Modifier.align(Alignment.BottomEnd).size(24.dp).clip(CircleShape).background(Color(0xFFFACC15)), contentAlignment = Alignment.Center) {
                    Text("C", color = Color(0xFF0B1220), fontSize = 12.sp, fontWeight = FontWeight.Black)
                }
            }
            Text(c.name.orEmpty(), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 5.dp))
        }
        coach?.let { c ->
            Row(Modifier.padding(top = 6.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.45f)).clickable { c.id?.let { go(SpPage.Coach(it)) } }.padding(start = 3.dp, end = 9.dp, top = 3.dp, bottom = 3.dp), verticalAlignment = Alignment.CenterVertically) {
                PhotoAvatar(c.photo, c.name, 22.dp)
                Spacer(Modifier.width(5.dp))
                Text(c.name.orEmpty(), color = Color.White, fontSize = 10.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

// ------------------------------------------------------------------ line-ups on the pitch

@Composable
internal fun PitchLineups(m: SpMatchDto, go: (SpPage) -> Unit) {
    val home = m.lineups?.get("home")
    val away = m.lineups?.get("away")
    val homeColor = teamColor(home?.colors?.get("primary") ?: m.home?.color, m.home?.id ?: 0)
    val awayColor = teamColor(away?.colors?.get("primary") ?: m.away?.color, m.away?.id ?: 1)
    // The best of the match (the provider's ratings).
    val best = (home?.start.orEmpty() + home?.subs.orEmpty() + away?.start.orEmpty() + away?.subs.orEmpty()).filter { it.rating != null }.maxByOrNull { it.rating ?: 0.0 }
    best?.let { b ->
        Row(
            Modifier.fillMaxWidth().padding(bottom = 10.dp).clip(RoundedCornerShape(20.dp)).background(Brush.linearGradient(listOf(Color(0xFFB45309), Color(0xFFF59E0B)))).clickable { b.id?.let { go(SpPage.Player(it)) } }.padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            PhotoAvatar(b.photo, b.name, 46.dp, ring = Color.White)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text("⭐ " + stringResource(R.string.spx_player_of_match), color = Color.White.copy(alpha = 0.9f), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                Text(b.name.orEmpty(), color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            }
            RatingBadge(b.rating ?: 0.0)
        }
    }
    Row(Modifier.fillMaxWidth().padding(bottom = 8.dp)) {
        Text(listOfNotNull(m.home?.name, home?.formation).joinToString(" · "), color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
        Text(listOfNotNull(away?.formation, m.away?.name).joinToString(" · "), color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.End, modifier = Modifier.weight(1f))
    }
    BoxWithConstraints(Modifier.fillMaxWidth().aspectRatio(0.62f).clip(RoundedCornerShape(24.dp)).background(Brush.verticalGradient(listOf(Color(0xFF166534), SpPitch, Color(0xFF166534))))) {
        Canvas(Modifier.fillMaxSize()) {
            val line = Color.White.copy(alpha = 0.5f)
            val s = Stroke(2.5f)
            val pad = 14f
            for (k in 0 until 10) if (k % 2 == 0) drawRect(Color.White.copy(alpha = 0.035f), Offset(pad, pad + k * (size.height - 2 * pad) / 10), Size(size.width - 2 * pad, (size.height - 2 * pad) / 10))
            drawRect(line, Offset(pad, pad), Size(size.width - 2 * pad, size.height - 2 * pad), style = s)
            drawLine(line, Offset(pad, size.height / 2), Offset(size.width - pad, size.height / 2), 2.5f)
            drawCircle(line, size.width * 0.14f, center, style = s)
            drawCircle(line, 4f, center)
            val boxW = size.width * 0.56f
            val boxH = size.height * 0.12f
            val small = size.width * 0.26f
            drawRect(line, Offset((size.width - boxW) / 2, pad), Size(boxW, boxH), style = s)
            drawRect(line, Offset((size.width - boxW) / 2, size.height - pad - boxH), Size(boxW, boxH), style = s)
            drawRect(line, Offset((size.width - small) / 2, pad), Size(small, boxH * 0.42f), style = s)
            drawRect(line, Offset((size.width - small) / 2, size.height - pad - boxH * 0.42f), Size(small, boxH * 0.42f), style = s)
        }
        val w = maxWidth
        val h = maxHeight
        away?.let { PlaceSide(it, awayColor, w, h, bottom = false, go) }
        home?.let { PlaceSide(it, homeColor, w, h, bottom = true, go) }
    }
    // Coaches and benches, with photos.
    Row(Modifier.fillMaxWidth().padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        listOf(home to homeColor, away to awayColor).forEach { (l, c) ->
            Column(Modifier.weight(1f).clip(RoundedCornerShape(18.dp)).background(Wa.Surface).padding(12.dp)) {
                l?.coach?.let { name ->
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.clickable { l.coachId?.let { go(SpPage.Coach(it)) } }) {
                        PhotoAvatar(l.coachPhoto, name, 30.dp, ring = c)
                        Spacer(Modifier.width(8.dp))
                        Column {
                            Text(name, color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(stringResource(R.string.spx_coach), color = Wa.Soft, fontSize = 10.5.sp)
                        }
                    }
                }
                Text(stringResource(R.string.sp_bench), color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 10.dp, bottom = 4.dp))
                l?.subs?.take(14)?.forEach { p ->
                    Row(Modifier.fillMaxWidth().clickable { p.id?.let { go(SpPage.Player(it)) } }.padding(vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) {
                        PhotoAvatar(p.photo, p.name, 24.dp)
                        Spacer(Modifier.width(6.dp))
                        Text("${p.number ?: ""} ${p.name.orEmpty()}", color = Wa.Mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                        p.rating?.let { RatingBadge(it, small = true) }
                    }
                }
            }
        }
    }
}

@Composable
private fun PlaceSide(l: SpLineupDto, color: Color, w: Dp, h: Dp, bottom: Boolean, go: (SpPage) -> Unit) {
    val players = l.start.mapNotNull { p -> p.grid?.split(':')?.let { g -> (g.getOrNull(0)?.toIntOrNull() ?: return@mapNotNull null) to (g.getOrNull(1)?.toIntOrNull() ?: 1) to p } }
    val rows = players.maxOfOrNull { it.first.first } ?: return
    val perRow = players.groupBy { it.first.first }.mapValues { it.value.size }
    players.forEachIndexed { i, (pos, p) ->
        val (row, col) = pos
        val count = perRow[row] ?: 1
        // From the goal (row 1) to just before the halfway line; columns spread across.
        val y = 0.055f + 0.39f * (row - 1) / maxOf(1, rows - 1)
        val fy = if (bottom) 1f - y else y
        val fx = col.toFloat() / (count + 1)
        val x = if (bottom) fx else 1f - fx
        Column(
            Modifier.offset(x = w * x - 30.dp, y = h * fy - 24.dp).width(60.dp).waRise(i).clickable { p.id?.let { go(SpPage.Player(it)) } },
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box {
                PitchPlayer(p, color)
                p.rating?.let { Box(Modifier.align(Alignment.TopEnd).offset(x = 8.dp, y = (-4).dp)) { RatingBadge(it, small = true) } }
                if (p.captain) Box(Modifier.align(Alignment.BottomStart).offset(x = (-4).dp).size(15.dp).clip(CircleShape).background(Color(0xFFFACC15)), contentAlignment = Alignment.Center) {
                    Text("C", color = Color(0xFF0B1220), fontSize = 8.5.sp, fontWeight = FontWeight.Black)
                }
                val marks = "⚽".repeat(p.goals.coerceAtMost(3)) + if (p.red > 0) "🟥" else if (p.yellow > 0) "🟨" else ""
                if (marks.isNotEmpty()) Text(marks, fontSize = 9.sp, modifier = Modifier.align(Alignment.BottomEnd).offset(x = 8.dp, y = 2.dp))
            }
            Text(
                p.name?.substringAfterLast(' ').orEmpty(), color = Color.White, fontSize = 9.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center,
                modifier = Modifier.padding(top = 2.dp).clip(RoundedCornerShape(5.dp)).background(Color.Black.copy(alpha = 0.35f)).padding(horizontal = 4.dp, vertical = 1.dp),
            )
        }
    }
}

/** A player's photo on the pitch, in a ring of his kit's colour; his number when there's no photo. */
@Composable
private fun PitchPlayer(p: SpLineupPlayerDto, color: Color) {
    Box(Modifier.size(38.dp).clip(CircleShape).background(color).border(2.dp, Color.White, CircleShape), contentAlignment = Alignment.Center) {
        Text("${p.number ?: ""}", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Black)
        p.photo?.let { AsyncImage(model = ApiClient.mediaUrl(it), contentDescription = p.name, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize().clip(CircleShape)) }
    }
}

// ------------------------------------------------------------------ the players' sheet

@Composable
internal fun PlayersSheet(m: SpMatchDto, go: (SpPage) -> Unit) {
    var side by remember { mutableStateOf("home") }
    ChoiceChips(listOf("home" to m.home?.name.orEmpty(), "away" to m.away?.name.orEmpty()), side) { side = it }
    Spacer(Modifier.height(10.dp))
    val list = m.players?.get(side).orEmpty()
    if (list.isEmpty()) { StateNote(null); return }
    list.forEachIndexed { i, p -> SheetRow(p, i, go) }
}

@Composable
private fun SheetRow(p: SpSheetPlayerDto, index: Int, go: (SpPage) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxWidth().padding(bottom = 6.dp).waRise(index.coerceAtMost(8)).clip(RoundedCornerShape(16.dp)).background(Wa.Surface).clickable { open = !open }.padding(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box {
                PhotoAvatar(p.photo, p.name, 40.dp)
                if (p.captain) Box(Modifier.align(Alignment.BottomEnd).size(15.dp).clip(CircleShape).background(Color(0xFFFACC15)), contentAlignment = Alignment.Center) { Text("C", fontSize = 8.5.sp, fontWeight = FontWeight.Black, color = Color(0xFF0B1220)) }
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(p.name.orEmpty(), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(
                    listOfNotNull(p.number?.let { "#$it" }, p.pos, p.minutes?.let { "$it'" }, "⚽".repeat(p.goals.coerceAtMost(4)).ifEmpty { null }, "🎯".repeat(p.assists.coerceAtMost(4)).ifEmpty { null },
                        if (p.red > 0) "🟥" else if (p.yellow > 0) "🟨" else null).joinToString("  "),
                    color = Wa.Mut, fontSize = 11.5.sp, maxLines = 1,
                )
            }
            p.rating?.let { RatingBadge(it) }
        }
        if (open) {
            Spacer(Modifier.height(8.dp))
            InfoLine("🥅", stringResource(R.string.spx_shots), p.shots?.let { "$it" + (p.shotsOn?.let { on -> " ($on)" } ?: "") })
            InfoLine("🅿️", stringResource(R.string.spx_passes), p.passes?.let { "$it" + (p.keyPasses?.let { k -> " · $k 🔑" } ?: "") + (p.passAccuracy?.takeIf { !it.isJsonNull }?.asString?.let { a -> " · $a%" } ?: "") })
            InfoLine("✨", stringResource(R.string.spx_dribbles), p.dribbles?.toString())
            InfoLine("🛡️", stringResource(R.string.spx_tackles), p.tackles?.toString())
            InfoLine("💪", stringResource(R.string.spx_duels_won), p.duelsWon?.toString())
            InfoLine("🧤", stringResource(R.string.spx_saves), p.saves?.takeIf { it > 0 }?.toString())
            InfoLine("⚠️", stringResource(R.string.spx_fouls), p.fouls?.toString())
            p.id?.let { Text(stringResource(R.string.spx_open_profile), color = Wa.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp).clickable { go(SpPage.Player(it)) }) }
        }
    }
}

// ------------------------------------------------------------------ prediction, head to head, missing, odds

@Composable
internal fun ForecastTab(m: SpMatchDto, insights: SpInsightsDto?) {
    val part = insights?.prediction
    val f = part?.data
    if (insights == null) { SkeletonRows(4); return }
    if (f == null) { StateNote(part?.state); return }
    val home = teamColor(m.home?.color, m.home?.id ?: 0)
    val away = teamColor(m.away?.color, m.away?.id ?: 1)
    SectionCard(stringResource(R.string.spx_win_chances), Modifier.padding(top = 0.dp)) {
        Row(Modifier.fillMaxWidth().padding(bottom = 8.dp)) {
            Text(m.home?.name.orEmpty(), color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), maxLines = 1)
            Text(stringResource(R.string.spx_draw), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.weight(1f), textAlign = TextAlign.Center)
            Text(m.away?.name.orEmpty(), color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), textAlign = TextAlign.End, maxLines = 1)
        }
        ProbBar(f.percent?.home ?: 0.0, f.percent?.draw ?: 0.0, f.percent?.away ?: 0.0, home, away)
        f.advice?.let {
            Row(Modifier.fillMaxWidth().padding(top = 12.dp).clip(RoundedCornerShape(14.dp)).background(Wa.Field).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                Text("💡", fontSize = 18.sp)
                Spacer(Modifier.width(8.dp))
                Text(it, color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
    if (f.comparison.isNotEmpty()) {
        SectionCard(stringResource(R.string.spx_comparison)) {
            f.comparison.forEachIndexed { i, c ->
                CompareRow(compareLabel(c.type), c.home ?: 0.0, c.away ?: 0.0, "${(c.home ?: 0.0).toInt()}%", "${(c.away ?: 0.0).toInt()}%", home, away, i)
            }
        }
    }
    val hf = f.home?.leagueForm
    val af = f.away?.leagueForm
    if (!hf.isNullOrBlank() || !af.isNullOrBlank()) {
        SectionCard(stringResource(R.string.spx_form)) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Crest(m.home, 26.dp); Spacer(Modifier.width(8.dp)); FormStrip(hf, size = 20.dp)
            }
            Spacer(Modifier.height(8.dp))
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Crest(m.away, 26.dp); Spacer(Modifier.width(8.dp)); FormStrip(af, size = 20.dp)
            }
        }
    }
    Text(stringResource(R.string.spx_forecast_source), color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.padding(top = 8.dp))
    insights.odds?.let { OddsCard(it.data, it.state) }
}

@Composable
private fun compareLabel(type: String): String = when (type) {
    "form" -> stringResource(R.string.spx_form)
    "att" -> stringResource(R.string.spx_attack)
    "def" -> stringResource(R.string.spx_defence)
    "poisson_distribution" -> stringResource(R.string.spx_poisson)
    "h2h" -> stringResource(R.string.spx_h2h)
    "goals" -> stringResource(R.string.spx_goals)
    "total" -> stringResource(R.string.spx_total)
    else -> type
}

@Composable
private fun OddsCard(odds: com.dorr.app.network.SpOddsDto?, state: String) {
    SectionCard(stringResource(R.string.spx_odds), trailing = { Text(stringResource(R.string.spx_info_only), color = Wa.Soft, fontSize = 10.5.sp, fontWeight = FontWeight.Bold) }) {
        if (odds == null || odds.bets.isEmpty()) { StateNote(state); return@SectionCard }
        odds.bets.forEach { b ->
            Text(b.name.orEmpty(), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 8.dp, bottom = 4.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                b.values.take(3).forEach { v ->
                    Column(Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(Wa.Field).padding(vertical = 8.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Text(v.value, color = Wa.Mut, fontSize = 11.sp, maxLines = 1)
                        Text(v.odd.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Black)
                    }
                }
            }
        }
        odds.bookmaker?.let { Text(it, color = Wa.Soft, fontSize = 10.5.sp, modifier = Modifier.padding(top = 8.dp)) }
    }
}

@Composable
internal fun H2hTab(m: SpMatchDto, insights: SpInsightsDto?, go: (SpPage) -> Unit) {
    if (insights == null) { SkeletonRows(4); return }
    val h = insights.h2h?.data
    if (h == null || h.matches.isEmpty()) { StateNote(insights.h2h?.state, empty = R.string.spx_no_h2h); return }
    val s = h.summary
    val home = teamColor(m.home?.color, m.home?.id ?: 0)
    val away = teamColor(m.away?.color, m.away?.id ?: 1)
    if (s != null) {
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(Brush.linearGradient(listOf(home, Color(0xFF0B1220), away))).padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            listOf(Triple(s.home, m.home?.name, true), Triple(s.draw, stringResource(R.string.spx_draws), false), Triple(s.away, m.away?.name, true)).forEachIndexed { i, (n, label, team) ->
                Column(Modifier.weight(1f).waRise(i), horizontalAlignment = Alignment.CenterHorizontally) {
                    if (team) Crest(if (i == 0) m.home else m.away, 40.dp, Modifier.background(Color.White, CircleShape)) else Text("🤝", fontSize = 26.sp)
                    Text("$n", color = Color.White, fontSize = 28.sp, fontWeight = FontWeight.Black, modifier = Modifier.padding(top = 4.dp))
                    Text(label.orEmpty(), color = Color.White.copy(alpha = 0.8f), fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
        }
        Text(stringResource(R.string.spx_h2h_goals, s.played, s.homeGoals, s.awayGoals), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp))
    }
    WaSectionTitle(stringResource(R.string.spx_last_meetings))
    h.matches.forEachIndexed { i, x ->
        Row(Modifier.fillMaxWidth().padding(bottom = 6.dp).waRise(i.coerceAtMost(8)).clip(RoundedCornerShape(16.dp)).background(Wa.Surface).padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.width(62.dp)) {
                Text(x.date?.let { shortDate(it) }.orEmpty(), color = Wa.Ink, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                Text(x.date?.take(4).orEmpty(), color = Wa.Soft, fontSize = 10.5.sp)
            }
            Row(Modifier.weight(1f), verticalAlignment = Alignment.CenterVertically) {
                Text(x.home?.name.orEmpty(), color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.End, modifier = Modifier.weight(1f))
                Spacer(Modifier.width(6.dp)); Crest(x.home, 20.dp)
                Text("${x.homeScore ?: "-"} : ${x.awayScore ?: "-"}", color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Black, modifier = Modifier.padding(horizontal = 8.dp))
                Crest(x.away, 20.dp); Spacer(Modifier.width(6.dp))
                Text(x.away?.name.orEmpty(), color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            }
        }
    }
}

@Composable
internal fun MissingTab(m: SpMatchDto, insights: SpInsightsDto?, go: (SpPage) -> Unit) {
    if (insights == null) { SkeletonRows(4); return }
    val list = insights.injuries?.data.orEmpty()
    if (list.isEmpty()) { StateNote(insights.injuries?.state, empty = R.string.spx_no_missing); return }
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        listOf("home" to m.home, "away" to m.away).forEach { (side, team) ->
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(bottom = 8.dp)) {
                    Crest(team, 24.dp); Spacer(Modifier.width(6.dp))
                    Text(team?.name.orEmpty(), color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                list.filter { it.side == side }.forEachIndexed { i, x ->
                    Column(Modifier.fillMaxWidth().padding(bottom = 6.dp).waRise(i).clip(RoundedCornerShape(16.dp)).background(Wa.Surface).clickable { x.player?.id?.let { go(SpPage.Player(it)) } }.padding(10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        PhotoAvatar(x.player?.photo, x.player?.name, 44.dp, ring = if (x.type == "Questionable") Color(0xFFF59E0B) else SpLive)
                        Text(x.player?.name.orEmpty(), color = Wa.Ink, fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 4.dp))
                        Text(x.reason.orEmpty(), color = Wa.Mut, fontSize = 10.5.sp, maxLines = 2, textAlign = TextAlign.Center)
                        Text(
                            stringResource(if (x.type == "Questionable") R.string.spx_doubtful else R.string.spx_out_of_match), color = if (x.type == "Questionable") Color(0xFFD97706) else SpLive,
                            fontSize = 10.5.sp, fontWeight = FontWeight.Black, modifier = Modifier.padding(top = 3.dp),
                        )
                    }
                }
            }
        }
    }
}
