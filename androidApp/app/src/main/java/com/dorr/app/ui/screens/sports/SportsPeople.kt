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
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.SpCareerDto
import com.dorr.app.network.SpCoachDto
import com.dorr.app.network.SpPlayerDto
import com.dorr.app.network.SpPlayerSeasonDto
import com.dorr.app.network.SpTrophyDto
import com.dorr.app.network.SpPart
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import java.time.Year

/*
 * A player and a coach (docs/sports-plan.md §10.3.4–5): who they are, the season's numbers by
 * competition (any season), the clubs they played for, trophies, transfers, injuries.
 */

private val PlayerTabs = listOf(R.string.spx_tab_stats, R.string.spx_tab_career, R.string.spx_tab_trophies, R.string.spx_tab_transfers, R.string.spx_tab_injuries)

@Composable
internal fun PlayerPage(id: Int, onBack: () -> Unit, go: (SpPage) -> Unit) {
    var player by remember { mutableStateOf<SpPlayerDto?>(null) }
    var failed by remember { mutableStateOf<String?>(null) }
    var career by remember { mutableStateOf<SpCareerDto?>(null) }
    var season by remember { mutableStateOf<String?>(null) }
    var tab by remember { mutableIntStateOf(0) }
    LaunchedEffect(id, season) {
        runCatching { ApiClient.sports.player(spAuth(), id, season).data }
            .onSuccess { r -> if (r != null) player = r }
            .onFailure { e -> if (player == null) failed = if ((e as? retrofit2.HttpException)?.code() == 503) "pending" else "unavailable" }
    }
    val current = PlayerTabs[tab]
    LaunchedEffect(current) { if (current != R.string.spx_tab_stats && career == null) career = runCatching { ApiClient.sports.playerCareer(spAuth(), id).data }.getOrNull() ?: SpCareerDto() }
    val p = player

    WaPage(title = p?.name ?: stringResource(R.string.spx_player), onBack = onBack) {
        if (p == null) {
            if (failed != null) StateNote(failed) else { WaSkeleton(Modifier.fillMaxWidth().height(220.dp), RoundedCornerShape(28.dp)); Spacer(Modifier.height(12.dp)); SkeletonRows() }
            return@WaPage
        }
        val color = teamColor(p.team?.color, p.team?.id ?: p.id)
        PersonHeader(p.photo, p.name, listOfNotNull(p.firstname?.let { f -> listOfNotNull(f, p.lastname).joinToString(" ") }, p.nationality).joinToString(" · "), color, p.number) {
            p.team?.let { t ->
                Row(Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.16f)).clickable { t.id.takeIf { it > 0 }?.let { go(SpPage.Team(it)) } }.padding(horizontal = 10.dp, vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                    Crest(t, 22.dp, Modifier.background(Color.White, CircleShape))
                    Spacer(Modifier.width(6.dp))
                    Text(t.name.orEmpty(), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                }
            }
        }
        // The quick facts.
        Row(Modifier.fillMaxWidth().padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOfNotNull(
                p.age?.let { stringResource(R.string.spx_age_short) to "$it" },
                p.position?.let { stringResource(R.string.spx_position) to positionLabel(it) },
                p.height?.let { stringResource(R.string.spx_height) to it.replace(" cm", "") },
                p.weight?.let { stringResource(R.string.spx_weight) to it.replace(" kg", "") },
            ).forEachIndexed { i, (l, v) ->
                Column(Modifier.weight(1f).waRise(i).clip(RoundedCornerShape(16.dp)).background(Wa.Surface).padding(vertical = 10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Text(v, color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.Black, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(l, color = Wa.Soft, fontSize = 10.5.sp)
                }
            }
        }
        if (p.injured) Text("🤕  " + stringResource(R.string.spx_injured_now), color = SpLive, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 10.dp))
        Spacer(Modifier.height(14.dp))
        ScrollTabs(PlayerTabs.map { stringResource(it) }, tab) { tab = it }
        Spacer(Modifier.height(12.dp))
        AnimatedContent(current, transitionSpec = { fadeIn(tween(220)) togetherWith fadeOut(tween(120)) }, label = "playerTab") { t ->
            Column {
                when (t) {
                    R.string.spx_tab_stats -> {
                        val shown = p.season ?: Year.now().value.toString()
                        val year = shown.toIntOrNull() ?: Year.now().value
                        ChoiceChips((0..3).map { (year + 1 - it).toString() }.distinct().map { it to seasonLabel(it) }, shown) { season = it }
                        Spacer(Modifier.height(10.dp))
                        if (p.seasons.isEmpty()) StateNote(p.statsState) else p.seasons.forEachIndexed { i, s -> SeasonCard(s, color, i, go) }
                    }
                    R.string.spx_tab_career -> CareerTeams(career?.teams, go)
                    R.string.spx_tab_trophies -> Trophies(career?.trophies)
                    R.string.spx_tab_transfers -> TransfersList(career?.transfers, go)
                    else -> Sidelined(career)
                }
            }
        }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
private fun positionLabel(p: String): String = when (p) {
    "Goalkeeper", "G" -> stringResource(R.string.spx_pos_gk)
    "Defender", "D" -> stringResource(R.string.spx_pos_df)
    "Midfielder", "M" -> stringResource(R.string.spx_pos_mf)
    "Attacker", "F" -> stringResource(R.string.spx_pos_fw)
    else -> p
}

/** A big photo on a deep gradient, with a number behind it like a shirt. */
@Composable
private fun PersonHeader(photo: String?, name: String?, sub: String, color: Color, number: Int?, extra: @Composable () -> Unit) {
    val deep = Color(color.red * 0.3f, color.green * 0.3f, color.blue * 0.3f)
    Box(Modifier.fillMaxWidth().waRise(0).clip(RoundedCornerShape(28.dp)).background(Brush.linearGradient(listOf(color, deep, Color(0xFF0B1220))))) {
        number?.let { Text("$it", color = Color.White.copy(alpha = 0.08f), fontSize = 150.sp, fontWeight = FontWeight.Black, modifier = Modifier.align(Alignment.CenterEnd).padding(end = 10.dp)) }
        Column(Modifier.fillMaxWidth().padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            PhotoAvatar(photo, name, 112.dp, ring = Color.White)
            Text(name.orEmpty(), color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 10.dp))
            if (sub.isNotBlank()) Text(sub, color = Color.White.copy(alpha = 0.78f), fontSize = 12.5.sp, textAlign = TextAlign.Center)
            Spacer(Modifier.height(10.dp))
            extra()
        }
    }
}

/** One competition in a season: the big numbers first, the rest under them. */
@Composable
private fun SeasonCard(s: SpPlayerSeasonDto, color: Color, index: Int, go: (SpPage) -> Unit) {
    SectionCard(null, Modifier.waRise(index.coerceAtMost(6))) {
        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.clickable { s.team?.id?.takeIf { it > 0 }?.let { go(SpPage.Team(it)) } }) {
            Crest(s.team, 34.dp)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(s.competition?.name.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(listOfNotNull(s.team?.name, s.competition?.season?.let { seasonLabel(it) }).joinToString(" · "), color = Wa.Mut, fontSize = 11.5.sp, maxLines = 1)
            }
            s.rating?.let { RatingBadge(it) }
        }
        Spacer(Modifier.height(10.dp))
        val keeper = (s.saves ?: 0) > 0 || (s.conceded ?: 0) > 0 && (s.goals ?: 0) == 0
        StatGrid(listOfNotNull(
            Triple("${s.appearances ?: 0}", stringResource(R.string.spx_apps), "👕"),
            if (keeper) Triple("${s.saves ?: 0}", stringResource(R.string.spx_saves), "🧤") else Triple("${s.goals ?: 0}", stringResource(R.string.spx_goals), "⚽"),
            if (keeper) Triple("${s.conceded ?: 0}", stringResource(R.string.spx_conceded), "🥅") else Triple("${s.assists ?: 0}", stringResource(R.string.spx_assists), "🎯"),
        ), accent = color)
        InfoLine("⏱️", stringResource(R.string.spx_minutes_played), s.minutes?.let { "%,d".format(it) })
        InfoLine("🧑‍🤝‍🧑", stringResource(R.string.spx_starts), s.lineups?.toString())
        InfoLine("🥅", stringResource(R.string.spx_shots), s.shots?.let { "$it" + (s.shotsOn?.let { on -> " ($on)" } ?: "") })
        InfoLine("🅿️", stringResource(R.string.spx_passes), s.passes?.let { "%,d".format(it) + (s.keyPasses?.let { k -> " · $k 🔑" } ?: "") })
        InfoLine("✨", stringResource(R.string.spx_dribbles), s.dribbles?.let { "$it" + (s.dribblesTried?.let { t -> " / $t" } ?: "") })
        InfoLine("🛡️", stringResource(R.string.spx_tackles), s.tackles?.let { "$it" + (s.interceptions?.let { x -> " · $x ✂️" } ?: "") })
        InfoLine("💪", stringResource(R.string.spx_duels_won), s.duelsWon?.let { "$it" + (s.duels?.let { t -> " / $t" } ?: "") })
        InfoLine("🎯", stringResource(R.string.spx_penalties), s.penaltiesScored?.let { "$it" + (s.penaltiesMissed?.takeIf { m -> m > 0 }?.let { m -> " (−$m)" } ?: "") })
        InfoLine("🟨", stringResource(R.string.spx_cards), listOfNotNull(s.yellow?.let { "🟨 $it" }, s.red?.takeIf { it > 0 }?.let { "🟥 $it" }).joinToString("  ").ifBlank { null })
    }
}

/** A rating on a coloured pill (green when it's good). */
@Composable
internal fun RatingBadge(rating: Double, small: Boolean = false) {
    val c = when {
        rating >= 8.0 -> Color(0xFF0E9F6E)
        rating >= 7.0 -> Color(0xFF16A34A)
        rating >= 6.5 -> Color(0xFFF59E0B)
        else -> Color(0xFFEA580C)
    }
    Text(
        "%.1f".format(rating), color = Color.White, fontSize = if (small) 10.sp else 13.sp, fontWeight = FontWeight.Black,
        modifier = Modifier.clip(RoundedCornerShape(if (small) 6.dp else 9.dp)).background(c).padding(horizontal = if (small) 4.dp else 8.dp, vertical = if (small) 1.dp else 4.dp),
    )
}

@Composable
private fun CareerTeams(teams: SpPart<List<com.dorr.app.network.SpCareerTeamDto>>?, go: (SpPage) -> Unit) {
    when {
        teams == null -> SkeletonRows(5)
        teams.data.isNullOrEmpty() -> StateNote(teams.state)
        else -> teams.data.forEachIndexed { i, c ->
            Row(
                Modifier.fillMaxWidth().padding(bottom = 8.dp).waRise(i.coerceAtMost(8)).clip(RoundedCornerShape(18.dp)).background(Wa.Surface)
                    .clickable { c.team?.id?.takeIf { it > 0 }?.let { go(SpPage.Team(it)) } }.padding(12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                // A line down the side, like a timeline.
                Box(Modifier.size(10.dp).clip(CircleShape).background(if (i == 0) Wa.Red else Wa.Line))
                Spacer(Modifier.width(10.dp))
                Crest(c.team, 36.dp)
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(c.team?.name.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                    val years = c.seasons.sorted()
                    if (years.isNotEmpty()) Text(if (years.size == 1) "${years.first()}" else "${years.first()} – ${years.last()}", color = Wa.Mut, fontSize = 12.sp)
                }
                Text(stringResource(R.string.spx_seasons_n, c.seasons.size), color = Wa.Soft, fontSize = 11.5.sp)
            }
        }
    }
}

@Composable
private fun Trophies(trophies: SpPart<List<SpTrophyDto>>?) {
    when {
        trophies == null -> SkeletonRows(5)
        trophies.data.isNullOrEmpty() -> StateNote(trophies.state)
        else -> {
            val won = trophies.data.filter { it.place == "Winner" }
            if (won.isNotEmpty()) {
                Row(Modifier.fillMaxWidth().padding(bottom = 10.dp).clip(RoundedCornerShape(22.dp)).background(Brush.linearGradient(listOf(Color(0xFFB45309), Color(0xFFF59E0B)))).padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("🏆", fontSize = 34.sp)
                    Spacer(Modifier.width(12.dp))
                    Column {
                        Text("${won.size}", color = Color.White, fontSize = 28.sp, fontWeight = FontWeight.Black)
                        Text(stringResource(R.string.spx_titles_won), color = Color.White.copy(alpha = 0.9f), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
            trophies.data.forEachIndexed { i, t ->
                Row(Modifier.fillMaxWidth().padding(bottom = 6.dp).waRise(i.coerceAtMost(8)).clip(RoundedCornerShape(16.dp)).background(Wa.Surface).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(if (t.place == "Winner") "🥇" else if (t.place?.startsWith("2") == true) "🥈" else "🎖️", fontSize = 20.sp)
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(t.competition.orEmpty(), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(listOfNotNull(t.country, t.season).joinToString(" · "), color = Wa.Mut, fontSize = 11.5.sp)
                    }
                    Text(placeLabel(t.place), color = if (t.place == "Winner") Color(0xFFD97706) else Wa.Soft, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

@Composable
private fun placeLabel(place: String?): String = when (place) {
    "Winner" -> stringResource(R.string.spx_winner)
    "2nd Place" -> stringResource(R.string.spx_runner_up)
    else -> place.orEmpty()
}

@Composable
private fun Sidelined(career: SpCareerDto?) {
    val s = career?.sidelined
    when {
        s == null -> SkeletonRows(5)
        s.data.isNullOrEmpty() -> StateNote(s.state, empty = R.string.spx_no_injuries)
        else -> s.data.forEachIndexed { i, x ->
            Row(Modifier.fillMaxWidth().padding(bottom = 6.dp).waRise(i.coerceAtMost(8)).clip(RoundedCornerShape(16.dp)).background(Wa.Surface).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(if (x.type?.contains("Suspend", true) == true || x.type?.contains("Card", true) == true) "🟥" else "🩹", fontSize = 18.sp)
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(x.type.orEmpty(), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
                    Text(listOfNotNull(x.start?.let { shortDate(it) + " " + it.take(4) }, x.end?.let { shortDate(it) + " " + it.take(4) }).joinToString(" → "), color = Wa.Mut, fontSize = 11.5.sp)
                }
            }
        }
    }
}

// ------------------------------------------------------------------ the coach

@Composable
internal fun CoachPage(id: Int, onBack: () -> Unit, go: (SpPage) -> Unit) {
    var coach by remember { mutableStateOf<SpCoachDto?>(null) }
    var failed by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(id) {
        runCatching { ApiClient.sports.coach(spAuth(), id).data }
            .onSuccess { coach = it }
            .onFailure { e -> failed = if ((e as? retrofit2.HttpException)?.code() == 503) "pending" else "unavailable" }
    }
    val c = coach
    WaPage(title = c?.name ?: stringResource(R.string.spx_coach), onBack = onBack) {
        if (c == null) {
            if (failed != null) StateNote(failed) else { WaSkeleton(Modifier.fillMaxWidth().height(220.dp), RoundedCornerShape(28.dp)); Spacer(Modifier.height(12.dp)); SkeletonRows() }
            return@WaPage
        }
        val color = teamColor(c.team?.color, c.team?.id ?: c.id)
        PersonHeader(c.photo, c.name, listOfNotNull(c.nationality, c.age?.let { stringResource(R.string.spx_age, it) }).joinToString(" · "), color, null) {
            c.team?.let { t ->
                Row(Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.16f)).clickable { t.id.takeIf { it > 0 }?.let { go(SpPage.Team(it)) } }.padding(horizontal = 10.dp, vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                    Crest(t, 22.dp, Modifier.background(Color.White, CircleShape))
                    Spacer(Modifier.width(6.dp))
                    Text(t.name.orEmpty(), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
        SectionCard(stringResource(R.string.spx_about)) {
            InfoLine("🎂", stringResource(R.string.spx_born), listOfNotNull(c.birth?.date, c.birth?.place, c.birth?.country).joinToString(" · ").ifBlank { null })
            InfoLine("🌍", stringResource(R.string.spx_nationality), c.nationality)
        }
        if (c.career.isNotEmpty()) {
            WaSectionTitle(stringResource(R.string.spx_tab_career))
            c.career.forEachIndexed { i, j ->
                WaCard(Modifier.fillMaxWidth().padding(bottom = 8.dp).waRise(i.coerceAtMost(8)), padding = 12.dp) {
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.clickable { j.team?.id?.takeIf { it > 0 }?.let { go(SpPage.Team(it)) } }) {
                        Crest(j.team, 36.dp)
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(j.team?.name.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                            Text("${j.start?.take(4) ?: "?"} – ${j.end?.take(4) ?: stringResource(R.string.spx_now)}", color = Wa.Mut, fontSize = 12.sp)
                        }
                        if (j.end == null) Text(stringResource(R.string.spx_now), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Black, modifier = Modifier.clip(CircleShape).background(SpGreen).padding(horizontal = 9.dp, vertical = 3.dp))
                    }
                }
            }
        }
        WaSectionTitle(stringResource(R.string.spx_tab_trophies))
        Trophies(c.trophies)
        Spacer(Modifier.height(24.dp))
    }
}
