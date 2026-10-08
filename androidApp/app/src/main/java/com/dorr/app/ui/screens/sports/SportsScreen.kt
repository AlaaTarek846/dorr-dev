package com.dorr.app.ui.screens.sports

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
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
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.EmojiEvents
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.SportsSoccer
import androidx.compose.material.icons.rounded.StarBorder
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.SpCompetitionDto
import com.dorr.app.network.SpCompetitionPageDto
import com.dorr.app.network.SpFollowDto
import com.dorr.app.network.SpHomeDto
import com.dorr.app.network.SpMatchDto
import com.dorr.app.network.SpPrefsDto
import com.dorr.app.network.SpStandingRowDto
import com.dorr.app.network.SpTeamDto
import com.dorr.app.network.SpTeamPageDto
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaCircleButton
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.rememberPressScale
import com.dorr.app.ui.screens.wallet.waRise
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.time.format.TextStyle
import java.util.Locale

internal sealed interface SpPage {
    data object Home : SpPage
    data class Match(val id: String) : SpPage
    data class Competition(val id: Int) : SpPage
    data class Team(val id: Int) : SpPage
    data object Follow : SpPage
    data object Settings : SpPage
    data object Contests : SpPage
    data class Contest(val id: String) : SpPage
    data object Prizes : SpPage
    data class Player(val id: Int) : SpPage
    data class Coach(val id: Int) : SpPage
    data object Search : SpPage
}

@Composable
fun SportsScreen(onExit: () -> Unit) {
    val context = LocalContext.current
    LaunchedEffect(Unit) { SportsLive.start(context) }
    var stack by remember { mutableStateOf(listOfNotNull<SpPage>(SpPage.Home, SportsLink.matchId?.let { SpPage.Match(it) })) }
    LaunchedEffect(SportsLink.matchId) {
        val id = SportsLink.matchId ?: return@LaunchedEffect
        if ((stack.lastOrNull() as? SpPage.Match)?.id != id) stack = stack + SpPage.Match(id)
        SportsLink.matchId = null
    }
    val back: () -> Unit = { if (stack.size > 1) stack = stack.dropLast(1) else onExit() }
    val go: (SpPage) -> Unit = { stack = stack + it }
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
            label = "sportsPages",
        ) { (_, page) ->
            when (page) {
                SpPage.Home -> SportsHome(back, go)
                is SpPage.Match -> MatchPage(page.id, back, go)
                is SpPage.Competition -> CompetitionPage(page.id, back, go)
                is SpPage.Team -> TeamPage(page.id, back, go)
                SpPage.Follow -> FollowPage(back, go)
                SpPage.Settings -> SportsSettings(back)
                SpPage.Contests -> ContestsPage(back, onOpen = { go(SpPage.Contest(it)) }, onPrizes = { go(SpPage.Prizes) })
                is SpPage.Contest -> ContestPage(page.id, back) { go(SpPage.Match(it)) }
                SpPage.Prizes -> PrizesPage(back)
                is SpPage.Player -> PlayerPage(page.id, back, go)
                is SpPage.Coach -> CoachPage(page.id, back, go)
                SpPage.Search -> SportsSearchPage(back, go)
            }
        }
    }
}

// =============================================================================== home (189, 190)

@Composable
private fun SportsHome(onBack: () -> Unit, go: (SpPage) -> Unit) {
    var sport by remember { mutableStateOf<String?>(null) }
    var date by remember { mutableStateOf(LocalDate.now()) }
    var home by remember { mutableStateOf<SpHomeDto?>(null) }
    var off by remember { mutableStateOf(false) }
    val now = rememberTicker()
    LaunchedEffect(sport, date) {
        home = null
        runCatching { ApiClient.sports.home(spAuth(), spZone(), date.toString(), sport).data }
            .onSuccess { home = it; it?.preferences?.let { p -> SportsLive.prefs = p } }
            .onFailure { off = (it as? retrofit2.HttpException)?.code() == 403; if (!off) home = SpHomeDto() }
    }
    val hide = SportsLive.prefs.noSpoilers
    val mine = home?.following?.teams.orEmpty().toSet()

    WaPage(
        title = stringResource(R.string.sp_title),
        onBack = onBack,
        actions = {
            WaCircleButton(Icons.Rounded.Search, { go(SpPage.Search) }, contentDescription = stringResource(R.string.spx_search))
            WaCircleButton(Icons.Rounded.EmojiEvents, { go(SpPage.Contests) }, contentDescription = stringResource(R.string.sp_contests))
            WaCircleButton(Icons.Rounded.StarBorder, { go(SpPage.Follow) }, contentDescription = stringResource(R.string.sp_follow_title))
            WaCircleButton(Icons.Rounded.Tune, { go(SpPage.Settings) }, contentDescription = stringResource(R.string.sp_settings))
        },
    ) {
        if (off) {
            WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.SportsSoccer, Tone.Gray, stringResource(R.string.sp_off_title), stringResource(R.string.sp_off_text)) }
            return@WaPage
        }
        // Sports.
        val sports = home?.sports.orEmpty()
        if (sports.size > 1) {
            LazyRow(Modifier.waRise(0), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                item { SportChip("🏆", stringResource(R.string.sp_all), sport == null) { sport = null } }
                items(sports, key = { it.key }) { s -> SportChip(s.emoji ?: "🏅", s.name ?: s.key, sport == s.key) { sport = s.key } }
            }
            Spacer(Modifier.height(10.dp))
        }
        DateStrip(date, Modifier.waRise(1)) { date = it }

        val h = home
        if (h == null) {
            Spacer(Modifier.height(14.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) { repeat(2) { WaSkeleton(Modifier.width(300.dp).height(170.dp), RoundedCornerShape(26.dp)) } }
            Spacer(Modifier.height(14.dp))
            repeat(4) { WaSkeleton(Modifier.fillMaxWidth().height(64.dp).padding(bottom = 8.dp), RoundedCornerShape(18.dp)) }
            return@WaPage
        }

        if (h.liveCount > 0) {
            Row(Modifier.padding(top = 12.dp).waRise(2).clip(CircleShape).background(SpLive.copy(alpha = 0.1f)).padding(horizontal = 12.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                LiveDot()
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.sp_live_now, h.liveCount), color = SpLive, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
            }
        }

        if (h.mine.isNotEmpty()) {
            WaSectionTitle(stringResource(R.string.sp_my_teams), Modifier.waRise(3))
            LazyRow(Modifier.waRise(3), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                items(h.mine, key = { "mine" + it.id }) { m -> LiveCard(m, now, hideScore = hide) { go(SpPage.Match(m.id)) } }
            }
        } else if (h.following?.teams.isNullOrEmpty()) {
            Row(
                Modifier.fillMaxWidth().padding(top = 14.dp).waRise(3).clip(RoundedCornerShape(22.dp))
                    .background(Brush.linearGradient(listOf(SpGreen, Color(0xFF065F46)))).clickable { go(SpPage.Follow) }.padding(16.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text("⭐", fontSize = 28.sp)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.sp_pick_teams), color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.sp_pick_teams_sub), color = Color.White.copy(alpha = 0.88f), fontSize = 12.5.sp)
                }
            }
        }

        val scope = rememberCoroutineScope()
        h.competitions.forEachIndexed { i, g ->
            val c = g.competition ?: return@forEachIndexed
            var followed by remember(c.id, g.followed) { mutableStateOf(g.followed) }
            CompetitionHeading(c.name, c.logo, c.country, followed, Modifier.padding(top = 16.dp).waRise(4 + i.coerceAtMost(5)), onStar = {
                followed = !followed
                scope.launch { toggleFollow("competition", c.id, followed) }
            }) { go(SpPage.Competition(c.id)) }
            g.matches.forEach { m -> MatchRow(m, now, mine, Modifier.padding(top = 6.dp), hideScore = hide) { go(SpPage.Match(m.id)) } }
        }
        if (h.competitions.isEmpty()) {
            Spacer(Modifier.height(16.dp))
            WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.SportsSoccer, Tone.Green, stringResource(R.string.sp_no_matches), stringResource(R.string.sp_no_matches_text)) }
        }
        Spacer(Modifier.height(24.dp))
    }

    // Live scores for whatever is on screen.
    DisposableEffect(Unit) {
        com.dorr.app.chat.ChatRealtime.watchPublic("sports.live", listOf("sports.match.updated"))
        onDispose { }
    }
}

internal suspend fun toggleFollow(kind: String, id: Int, on: Boolean): List<SpFollowDto>? {
    return if (on) {
        runCatching { ApiClient.sports.follow(spAuth(), JsonObject().apply { addProperty("kind", kind); addProperty("target_id", id) }).data }.getOrNull()
    } else {
        val list = runCatching { ApiClient.sports.follows(spAuth()).data }.getOrNull().orEmpty()
        list.firstOrNull { it.kind == kind && it.target?.id == id }?.let { f -> runCatching { ApiClient.sports.unfollow(spAuth(), f.id).data }.getOrNull() }
    }
}

@Composable
private fun SportChip(emoji: String, label: String, selected: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) Wa.Red else Wa.Surface, label = "chip")
    Row(
        Modifier.clip(CircleShape).background(bg).border(1.dp, if (selected) Color.Transparent else Wa.Line, CircleShape).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(emoji, fontSize = 15.sp)
        Spacer(Modifier.width(6.dp))
        Text(label, color = if (selected) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
}

/** Seven days around today: yesterday, today, tomorrow… */
@Composable
private fun DateStrip(selected: LocalDate, modifier: Modifier = Modifier, onPick: (LocalDate) -> Unit) {
    val today = LocalDate.now()
    val days = remember(today) { (-2..4).map { today.plusDays(it.toLong()) } }
    Row(modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Wa.Surface).padding(4.dp)) {
        days.forEach { d ->
            val on = d == selected
            val bg by animateColorAsState(if (on) Wa.Red else Color.Transparent, label = "day")
            Column(
                Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(bg).clickable { onPick(d) }.padding(vertical = 7.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text(
                    when (d) { today -> stringResource(R.string.sp_today); today.plusDays(1) -> stringResource(R.string.sp_tomorrow); today.minusDays(1) -> stringResource(R.string.sp_yesterday); else -> d.dayOfWeek.getDisplayName(TextStyle.SHORT, Locale.getDefault()) },
                    color = if (on) Color.White else Wa.Mut, fontSize = 10.5.sp, fontWeight = FontWeight.Bold, maxLines = 1,
                )
                Text(d.dayOfMonth.toString(), color = if (on) Color.White else Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
    }
}

// =============================================================================== competition (192)

/** The table, with qualification / relegation colours from the provider's description, and the trend. */
@Composable
internal fun StandingsTable(rows: List<SpStandingRowDto>, highlight: Set<Int> = emptySet(), onTeam: (SpTeamDto) -> Unit) {
    WaCard(Modifier.fillMaxWidth(), padding = 6.dp) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 6.dp)) {
            Text("#", color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.width(30.dp))
            Text(stringResource(R.string.sp_team), color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            listOf(R.string.sp_col_p, R.string.sp_col_gd, R.string.sp_col_pts).forEach { Text(stringResource(it), color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.width(36.dp), textAlign = TextAlign.Center) }
        }
        rows.forEach { r ->
            val zone = zoneColor(r.description)
            val me = r.team?.id in highlight
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(if (me) Wa.Red.copy(alpha = 0.08f) else Color.Transparent).clickable { r.team?.let(onTeam) }.padding(horizontal = 8.dp, vertical = 7.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Row(Modifier.width(30.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.width(3.dp).height(18.dp).clip(CircleShape).background(zone ?: Color.Transparent))
                    Spacer(Modifier.width(4.dp))
                    Text("${r.rank}", color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
                Crest(r.team, 24.dp)
                Spacer(Modifier.width(8.dp))
                Text(r.team?.name.orEmpty(), color = Wa.Ink, fontSize = 13.sp, fontWeight = if (me) FontWeight.ExtraBold else FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                Text(when (r.trend) { "up" -> "▲"; "down" -> "▼"; else -> "" }, color = if (r.trend == "up") SpGreen else SpLive, fontSize = 9.sp, modifier = Modifier.padding(horizontal = 2.dp))
                Text("${r.played}", color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.width(36.dp), textAlign = TextAlign.Center)
                Text((if (r.goalDiff > 0) "+" else "") + r.goalDiff, color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.width(36.dp), textAlign = TextAlign.Center)
                Text("${r.points}", color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Black, modifier = Modifier.width(36.dp), textAlign = TextAlign.Center)
            }
        }
    }
}

private fun zoneColor(description: String?): Color? {
    val d = description?.lowercase() ?: return null
    return when {
        "relegation" in d -> Color(0xFFDC2626)
        "champions" in d || "promotion" in d && "play" !in d -> Color(0xFF2563EB)
        "europa" in d || "conference" in d || "afc" in d || "caf" in d -> Color(0xFFF59E0B)
        "play" in d -> Color(0xFF7C3AED)
        else -> Color(0xFF16A34A)
    }
}

/** A match with its date, for team and competition lists. */
@Composable
internal fun MatchDayRow(m: SpMatchDto, now: Long, onClick: () -> Unit) {
    val date = m.localDate?.let { runCatching { LocalDate.parse(it) }.getOrNull() }
    Column(Modifier.padding(bottom = 6.dp)) {
        date?.let { Text(it.format(DateTimeFormatter.ofPattern("EEE d MMM", Locale.getDefault())), color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 6.dp, bottom = 2.dp)) }
        MatchRow(m, now, emptySet(), hideScore = SportsLive.prefs.noSpoilers, onClick = onClick)
    }
}

@Composable
internal fun Tabs(labels: List<Int>, selected: Int, onPick: (Int) -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Wa.Surface).padding(4.dp)) {
        labels.forEachIndexed { i, label ->
            val on = i == selected
            val bg by animateColorAsState(if (on) Wa.Red else Color.Transparent, label = "tab")
            Text(
                stringResource(label), color = if (on) Color.White else Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, maxLines = 1,
                modifier = Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(bg).clickable { onPick(i) }.padding(vertical = 10.dp),
            )
        }
    }
}

// =============================================================================== team (189, 193, 194)

internal val AlertLabels = listOf(
    "reminder" to R.string.sp_al_reminder, "kickoff" to R.string.sp_al_kickoff, "goal" to R.string.sp_al_goal, "red_card" to R.string.sp_al_red,
    "half_time" to R.string.sp_al_ht, "finished" to R.string.sp_al_ft, "schedule" to R.string.sp_al_schedule, "lineups" to R.string.sp_al_lineups,
)

@Composable
internal fun ToggleLine(title: String, on: Boolean, sub: String?, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(title, color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold)
            sub?.let { Text(it, color = Wa.Mut, fontSize = 12.sp) }
        }
        Switch(on, onChange, colors = SwitchDefaults.colors(checkedTrackColor = Wa.Red))
    }
}

// =============================================================================== follow (189)

@Composable
private fun FollowPage(onBack: () -> Unit, go: (SpPage) -> Unit) {
    var query by remember { mutableStateOf("") }
    var teams by remember { mutableStateOf<List<SpTeamDto>>(emptyList()) }
    var competitions by remember { mutableStateOf<List<SpCompetitionDto>?>(null) }
    var follows by remember { mutableStateOf<List<SpFollowDto>>(emptyList()) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(Unit) {
        follows = runCatching { ApiClient.sports.follows(spAuth()).data }.getOrNull().orEmpty()
        competitions = runCatching { ApiClient.sports.competitions(spAuth()).data }.getOrNull().orEmpty()
    }
    LaunchedEffect(query) {
        kotlinx.coroutines.delay(300)
        teams = if (query.trim().length >= 2) runCatching { ApiClient.sports.teams(spAuth(), query.trim()).data }.getOrNull().orEmpty() else emptyList()
    }
    val followedTeams = follows.filter { it.kind == "team" }.mapNotNull { it.target?.id }.toSet()
    val followedComps = follows.filter { it.kind == "competition" }.mapNotNull { it.target?.id }.toSet()
    val toggle: (String, Int, Boolean) -> Unit = { kind, id, on -> scope.launch { toggleFollow(kind, id, on)?.let { follows = it } ?: run { follows = runCatching { ApiClient.sports.follows(spAuth()).data }.getOrNull().orEmpty() } } }

    WaPage(title = stringResource(R.string.sp_follow_title), onBack = onBack) {
        DorrTextField(query, { query = it.take(60) }, placeholder = stringResource(R.string.sp_search_team), icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth().waRise(0))
        teams.forEach { t ->
            FollowRow(t.name.orEmpty(), t.country, { Crest(t, 40.dp) }, t.id in followedTeams, Modifier.padding(top = 8.dp), onOpen = { go(SpPage.Team(t.id)) }) { toggle("team", t.id, t.id !in followedTeams) }
        }
        if (follows.isNotEmpty() && query.isBlank()) {
            WaSectionTitle(stringResource(R.string.sp_my_follows))
            follows.forEach { f ->
                val t = f.target ?: return@forEach
                FollowRow(t.name.orEmpty(), t.country, {
                    if (f.kind == "team") Crest(SpTeamDto(id = t.id, name = t.name, logo = t.logo, color = t.color), 40.dp)
                    else Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(Wa.Field), contentAlignment = Alignment.Center) { if (t.logo != null) AsyncImage(model = t.logo, contentDescription = null, modifier = Modifier.size(28.dp)) else Text("🏆") }
                }, true, Modifier.padding(top = 8.dp), onOpen = { if (f.kind == "team") go(SpPage.Team(t.id)) else go(SpPage.Competition(t.id)) }) { toggle(f.kind, t.id, false) }
            }
        }
        if (query.isBlank()) {
            WaSectionTitle(stringResource(R.string.sp_competitions))
            competitions?.forEach { c ->
                CompetitionHeading(c.name, c.logo, c.country, c.id in followedComps, Modifier.padding(top = 4.dp), onStar = { toggle("competition", c.id, c.id !in followedComps) }) { go(SpPage.Competition(c.id)) }
            } ?: repeat(4) { WaSkeleton(Modifier.fillMaxWidth().height(44.dp).padding(bottom = 6.dp), RoundedCornerShape(14.dp)) }
        }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
private fun FollowRow(title: String, sub: String?, icon: @Composable () -> Unit, on: Boolean, modifier: Modifier = Modifier, onOpen: () -> Unit, onToggle: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.98f)
    Row(modifier.fillMaxWidth().scale(press).clip(RoundedCornerShape(18.dp)).background(Wa.Surface).clickable(interactionSource = source, indication = null, onClick = onOpen).padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
        icon()
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            sub?.let { Text(it, color = Wa.Soft, fontSize = 11.5.sp, maxLines = 1) }
        }
        Text(
            if (on) "★" else "☆", color = if (on) Color(0xFFF59E0B) else Wa.Soft, fontSize = 24.sp,
            modifier = Modifier.clip(CircleShape).clickable(onClick = onToggle).padding(horizontal = 8.dp),
        )
    }
}

// =============================================================================== my choices (193, 194, 197, 198)

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun SportsSettings(onBack: () -> Unit) {
    var prefs by remember { mutableStateOf(SportsLive.prefs) }
    val scope = rememberCoroutineScope()
    LaunchedEffect(Unit) { runCatching { ApiClient.sports.preferences(spAuth()).data }.getOrNull()?.let { prefs = it; SportsLive.prefs = it } }
    val save: (SpPrefsDto) -> Unit = { p ->
        prefs = p
        SportsLive.prefs = p
        scope.launch {
            val body = JsonObject().apply {
                addProperty("no_spoilers", p.noSpoilers); addProperty("celebration", p.celebration); addProperty("sounds", p.sounds)
                addProperty("vibrate", p.vibrate); addProperty("reminder_minutes", p.reminderMinutes); addProperty("goals_in_quiet", p.goalsInQuiet)
            }
            runCatching { ApiClient.sports.savePreferences(spAuth(), body).data }.getOrNull()?.let { prefs = it; SportsLive.prefs = it }
        }
    }

    WaPage(title = stringResource(R.string.sp_settings), onBack = onBack) {
        WaSectionTitle(stringResource(R.string.sp_celebration), topPadding = 4.dp)
        Text(stringResource(R.string.sp_celebration_sub), color = Wa.Mut, fontSize = 12.5.sp)
        Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("off" to "🔕", "calm" to "🙂", "normal" to "🎉", "festive" to "🎆").forEach { (key, emoji) ->
                val on = prefs.celebration == key
                Column(
                    Modifier.weight(1f).clip(RoundedCornerShape(18.dp)).background(if (on) Wa.Red else Wa.Surface).border(1.dp, if (on) Color.Transparent else Wa.Line, RoundedCornerShape(18.dp))
                        .clickable { save(prefs.copy(celebration = key)) }.padding(vertical = 12.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Text(emoji, fontSize = 24.sp)
                    Text(stringResource(when (key) { "off" -> R.string.sp_cel_off; "calm" -> R.string.sp_cel_calm; "normal" -> R.string.sp_cel_normal; else -> R.string.sp_cel_festive }), color = if (on) Color.White else Wa.Ink, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
        if (prefs.celebration != "off") {
            Text(
                "▶  " + stringResource(R.string.sp_try_it), color = Wa.Red, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.padding(top = 10.dp).clip(CircleShape).background(Wa.Red.copy(alpha = 0.08f)).clickable {
                    SportsLive.celebration = SportsAlert("preview", "goal", false, 1, 0, "home", "DORR", 90, prefs.celebration)
                    SportsSounds.play(SportsSounds.Kind.Goal, prefs, force = prefs.sounds)
                }.padding(horizontal = 16.dp, vertical = 9.dp),
            )
        }

        WaSectionTitle(stringResource(R.string.sp_sound_section))
        WaCard(Modifier.fillMaxWidth(), padding = 12.dp) {
            ToggleLine(stringResource(R.string.sp_sounds), prefs.sounds, stringResource(R.string.sp_sounds_sub)) { save(prefs.copy(sounds = it)) }
            ToggleLine(stringResource(R.string.sp_vibrate), prefs.vibrate, null) { save(prefs.copy(vibrate = it)) }
        }

        WaSectionTitle(stringResource(R.string.sp_alerts_section))
        WaCard(Modifier.fillMaxWidth(), padding = 12.dp) {
            ToggleLine(stringResource(R.string.sp_no_spoilers), prefs.noSpoilers, stringResource(R.string.sp_no_spoilers_sub)) { save(prefs.copy(noSpoilers = it)) }
            ToggleLine(stringResource(R.string.sp_goals_quiet), prefs.goalsInQuiet, stringResource(R.string.sp_goals_quiet_sub)) { save(prefs.copy(goalsInQuiet = it)) }
            Text(stringResource(R.string.sp_reminder), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 8.dp))
            FlowRow(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf(0, 5, 15, 30, 60).forEach { mins ->
                    val on = prefs.reminderMinutes == mins
                    Text(
                        if (mins == 0) stringResource(R.string.sp_reminder_off) else stringResource(R.string.sp_reminder_n, mins), color = if (on) Color.White else Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(CircleShape).background(if (on) Wa.Red else Wa.Field).clickable { save(prefs.copy(reminderMinutes = mins)) }.padding(horizontal = 12.dp, vertical = 7.dp),
                    )
                }
            }
        }
        WaNote(stringResource(R.string.sp_data_note), Modifier.padding(top = 14.dp), icon = Icons.Rounded.NotificationsActive)
        Spacer(Modifier.height(24.dp))
    }
}
