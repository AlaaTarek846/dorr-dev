package com.dorr.app.ui.screens.sports

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.SportsSoccer
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.SpMatchDto
import com.dorr.app.network.SpTeamDto
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.rememberPressScale

// =============================================================================== small pieces

/** A team's crest on a soft disc of its colour (or its initials when there's no logo). */
@Composable
internal fun Crest(team: SpTeamDto?, size: Dp, modifier: Modifier = Modifier) {
    val color = teamColor(team?.color, team?.id ?: 0)
    Box(modifier.size(size).clip(CircleShape).background(color.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
        if (team?.logo != null) {
            AsyncImage(model = ApiClient.mediaUrl(team.logo), contentDescription = team.name, contentScale = ContentScale.Fit, modifier = Modifier.size(size * 0.74f))
        } else {
            Text((team?.code ?: team?.name?.take(2) ?: "?").uppercase(), color = color, fontWeight = FontWeight.ExtraBold, fontSize = (size.value * 0.32f).sp)
        }
    }
}

/** A score digit that flips up when it changes, with a little bounce. */
@Composable
internal fun FlipScore(value: Int?, size: TextUnit, color: Color, modifier: Modifier = Modifier, bold: Boolean = true) {
    AnimatedContent(
        targetState = value,
        transitionSpec = {
            (slideInVertically(tween(380, easing = FastOutSlowInEasing)) { it } + fadeIn(tween(260))) togetherWith
                (slideOutVertically(tween(300)) { -it } + fadeOut(tween(200)))
        },
        label = "flip",
        modifier = modifier,
    ) { v ->
        val pop = remember(v) { Animatable(if (v == null) 1f else 1.35f) }
        LaunchedEffect(v) { pop.animateTo(1f, spring(dampingRatio = 0.38f, stiffness = 380f)) }
        Text(v?.toString() ?: "–", color = color, fontSize = size, fontWeight = if (bold) FontWeight.Black else FontWeight.Bold, modifier = Modifier.scale(pop.value))
    }
}

/** The red "live" dot that breathes. */
@Composable
internal fun LiveDot(size: Dp = 8.dp, color: Color = SpLive) {
    val pulse = rememberInfiniteTransition(label = "live")
    val a by pulse.animateFloat(1f, 0.25f, infiniteRepeatable(tween(850, easing = LinearEasing), RepeatMode.Reverse), label = "a")
    val s by pulse.animateFloat(1f, 1.6f, infiniteRepeatable(tween(850, easing = LinearEasing), RepeatMode.Reverse), label = "s")
    Box(contentAlignment = Alignment.Center) {
        Box(Modifier.size(size).scale(s).clip(CircleShape).background(color.copy(alpha = 0.25f * a)))
        Box(Modifier.size(size).clip(CircleShape).background(color.copy(alpha = 0.6f + 0.4f * a)))
    }
}

/** The status line of a match: a live minute with its dot, half time, full time, postponed… */
@Composable
internal fun StatusChip(m: SpMatchDto, now: Long, modifier: Modifier = Modifier, onDark: Boolean = false) {
    val ht = stringResource(R.string.sp_ht)
    val ft = stringResource(R.string.sp_ft)
    val label = when (m.status) {
        "postponed" -> stringResource(R.string.sp_postponed)
        "cancelled" -> stringResource(R.string.sp_cancelled)
        "suspended" -> stringResource(R.string.sp_suspended)
        else -> clockText(m, now, ht, ft)
    }
    val live = m.status == "live"
    val color = when {
        live -> SpLive
        m.status == "break" -> Color(0xFFD97706)
        m.status in setOf("postponed", "cancelled", "suspended") -> Color(0xFF6B7280)
        onDark -> Color.White.copy(alpha = 0.85f)
        else -> Wa.Mut
    }
    Row(modifier, verticalAlignment = Alignment.CenterVertically) {
        if (live) {
            LiveDot(7.dp)
            Spacer(Modifier.width(5.dp))
        }
        Text(label, color = color, fontSize = 12.sp, fontWeight = if (live) FontWeight.ExtraBold else FontWeight.Bold, maxLines = 1)
    }
}

/** A thin ring of the match played, around the minute (football). */
@Composable
internal fun MinuteRing(m: SpMatchDto, now: Long, size: Dp, color: Color, content: @Composable () -> Unit) {
    val target = progressOf(m, now)
    val p by animateFloatAsState(target, tween(900), label = "ring")
    Box(Modifier.size(size), contentAlignment = Alignment.Center) {
        Canvas(Modifier.fillMaxSize()) {
            val stroke = 4.dp.toPx()
            drawArc(color.copy(alpha = 0.18f), -90f, 360f, false, topLeft = Offset(stroke / 2, stroke / 2), size = Size(this.size.width - stroke, this.size.height - stroke), style = Stroke(stroke))
            drawArc(color, -90f, 360f * p, false, topLeft = Offset(stroke / 2, stroke / 2), size = Size(this.size.width - stroke, this.size.height - stroke), style = Stroke(stroke, cap = StrokeCap.Round))
        }
        content()
    }
}

// =============================================================================== a match in a list

/**
 * One match: crest and name each side, the score in the middle (flips when it changes; the row
 * glows for a moment), or the kick-off time. The winner reads bolder.
 */
@Composable
internal fun MatchRow(match: SpMatchDto, now: Long, mine: Set<Int>, modifier: Modifier = Modifier, hideScore: Boolean = false, onClick: () -> Unit) {
    val m = SportsLive.fresh(match)
    if (m.home == null) {
        RaceRow(m, now, modifier, onClick)
        return
    }
    val changed = SportsLive.changedAt[m.id] ?: 0L
    val glow = remember(changed) { Animatable(if (changed > 0 && System.currentTimeMillis() - changed < 4000) 1f else 0f) }
    LaunchedEffect(changed) { if (glow.value > 0f) glow.animateTo(0f, tween(2200)) }
    val started = m.status in setOf("live", "break", "finished")
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.98f)

    Row(
        modifier.fillMaxWidth().scale(press).clip(RoundedCornerShape(18.dp))
            .background(Wa.Surface)
            .background(SpLive.copy(alpha = 0.10f * glow.value))
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        TeamSide(m.home, mine, winner = m.winner == "home", alignEnd = false, modifier = Modifier.weight(1f))
        Column(Modifier.width(86.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            if (m.fight != null && m.status == "finished" && !hideScore) {
                // A fight: how it ended, not a score.
                Text(fightResult(m), color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, maxLines = 2)
            } else if (m.fight != null) {
                Text(if (started) "VS" else m.localTime.orEmpty(), color = if (started) SpLive else Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.Black)
            } else if (started && !hideScore) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    FlipScore(m.homeScore, 20.sp, if (m.winner == "away") Wa.Mut else Wa.Ink)
                    Text("  :  ", color = Wa.Soft, fontSize = 15.sp, fontWeight = FontWeight.Bold)
                    FlipScore(m.awayScore, 20.sp, if (m.winner == "home") Wa.Mut else Wa.Ink)
                }
            } else if (started) {
                Text("• •", color = Wa.Soft, fontSize = 18.sp, fontWeight = FontWeight.Black)
            } else {
                Text(m.localTime.orEmpty(), color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
            }
            StatusChip(m, now, Modifier.padding(top = 2.dp))
        }
        TeamSide(m.away, mine, winner = m.winner == "away", alignEnd = true, modifier = Modifier.weight(1f))
    }
}

@Composable
private fun TeamSide(team: SpTeamDto?, mine: Set<Int>, winner: Boolean, alignEnd: Boolean, modifier: Modifier) {
    Row(modifier, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = if (alignEnd) Arrangement.End else Arrangement.Start) {
        if (!alignEnd) Crest(team, 34.dp)
        Text(
            (if (team?.id != null && team.id in mine) "★ " else "") + team?.name.orEmpty(),
            color = Wa.Ink, fontSize = 13.5.sp, fontWeight = if (winner) FontWeight.ExtraBold else FontWeight.SemiBold,
            maxLines = 2, overflow = TextOverflow.Ellipsis, textAlign = if (alignEnd) TextAlign.End else TextAlign.Start,
            modifier = Modifier.padding(horizontal = 8.dp).weight(1f, fill = false),
        )
        if (alignEnd) Crest(team, 34.dp)
    }
}

/**
 * A big card for my teams' matches: the two kits as a split gradient, large crests, the score
 * (flipping), the minute in a ring.
 */
@Composable
internal fun LiveCard(match: SpMatchDto, now: Long, modifier: Modifier = Modifier, hideScore: Boolean = false, onClick: () -> Unit) {
    val m = SportsLive.fresh(match)
    if (m.home == null) {
        RaceCard(m, now, modifier, onClick)
        return
    }
    val home = teamColor(m.home?.color, m.home?.id ?: 0)
    val away = teamColor(m.away?.color, m.away?.id ?: 1)
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val brush = Brush.linearGradient(if (rtl) listOf(away, Color(0xFF0B1220), home) else listOf(home, Color(0xFF0B1220), away))
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.97f)
    val started = m.status in setOf("live", "break", "finished")

    Column(
        modifier.width(300.dp).scale(press).clip(RoundedCornerShape(26.dp)).background(brush)
            .clickable(interactionSource = source, indication = null, onClick = onClick).padding(16.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(m.competition?.name.orEmpty(), color = Color.White.copy(alpha = 0.85f), fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            StatusChip(m, now, onDark = true)
        }
        Spacer(Modifier.height(12.dp))
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                Crest(m.home, 54.dp, Modifier.background(Color.White.copy(alpha = 0.9f), CircleShape))
                Text(m.home?.name.orEmpty(), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 6.dp))
            }
            if (started && !hideScore) {
                MinuteRing(m, now, 92.dp, Color.White) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        FlipScore(m.homeScore, 28.sp, Color.White)
                        Text(":", color = Color.White.copy(alpha = 0.7f), fontSize = 22.sp, fontWeight = FontWeight.Black, modifier = Modifier.padding(horizontal = 4.dp))
                        FlipScore(m.awayScore, 28.sp, Color.White)
                    }
                }
            } else {
                Column(Modifier.width(92.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(Icons.Rounded.SportsSoccer, null, tint = Color.White.copy(alpha = 0.8f), modifier = Modifier.size(20.dp))
                    Text(if (started) "• •" else m.localTime.orEmpty(), color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.Black)
                }
            }
            Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally) {
                Crest(m.away, 54.dp, Modifier.background(Color.White.copy(alpha = 0.9f), CircleShape))
                Text(m.away?.name.orEmpty(), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 6.dp))
            }
        }
        if (m.periods.isNotEmpty() && m.sport != "football" && started && !hideScore) {
            PeriodStrip(m, Modifier.padding(top = 10.dp), onDark = true)
        }
    }
}

/** Scores per period (quarters, sets, periods) — for the sports that have them. */
@Composable
internal fun PeriodStrip(m: SpMatchDto, modifier: Modifier = Modifier, onDark: Boolean = false) {
    val ink = if (onDark) Color.White else Wa.Ink
    Row(modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp, Alignment.CenterHorizontally)) {
        m.periods.forEach { p ->
            Column(
                Modifier.clip(RoundedCornerShape(10.dp)).background(if (onDark) Color.White.copy(alpha = 0.14f) else Wa.Field).padding(horizontal = 8.dp, vertical = 4.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text(p.label, color = ink.copy(alpha = 0.7f), fontSize = 10.sp, fontWeight = FontWeight.Bold)
                Text("${p.home ?: "-"}–${p.away ?: "-"}", color = ink, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
    }
}

/** A competition's heading in a list: logo, name, country, and a star to follow it. */
@Composable
internal fun CompetitionHeading(name: String?, logo: String?, country: String?, followed: Boolean, modifier: Modifier = Modifier, onStar: (() -> Unit)? = null, onClick: () -> Unit) {
    Row(modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(onClick = onClick).padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(28.dp).clip(RoundedCornerShape(8.dp)).background(Wa.Surface).border(1.dp, Wa.Line, RoundedCornerShape(8.dp)), contentAlignment = Alignment.Center) {
            if (logo != null) AsyncImage(model = ApiClient.mediaUrl(logo), contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.size(20.dp))
            else Text("🏆", fontSize = 14.sp)
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(name.orEmpty(), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            country?.let { Text(it, color = Wa.Soft, fontSize = 11.5.sp, maxLines = 1) }
        }
        onStar?.let {
            Text(if (followed) "★" else "☆", color = if (followed) Color(0xFFF59E0B) else Wa.Soft, fontSize = 22.sp, modifier = Modifier.clip(CircleShape).clickable(onClick = it).padding(horizontal = 8.dp))
        }
    }
}

// =============================================================================== home card

/** On the home page: live now, or my team's next match — a tap opens Sports. Hidden when off. */
@Composable
fun SportsHomeCard(onOpen: () -> Unit, modifier: Modifier = Modifier) {
    val context = androidx.compose.ui.platform.LocalContext.current
    var home by androidx.compose.runtime.remember { androidx.compose.runtime.mutableStateOf<com.dorr.app.network.SpHomeDto?>(null) }
    LaunchedEffect(Unit) {
        SportsLive.start(context)
        runCatching { ApiClient.sports.home(spAuth(), spZone()).data }.onSuccess { home = it }
    }
    val now = rememberTicker()
    val next = home?.mine?.firstOrNull()?.let { SportsLive.fresh(it) }
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.98f)
    Row(
        modifier.fillMaxWidth().scale(press).clip(RoundedCornerShape(24.dp))
            .background(Brush.linearGradient(listOf(Color(0xFF065F46), SpGreen, Color(0xFF0B1220))))
            .clickable(interactionSource = source, indication = null) { onOpen() }.padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(52.dp).clip(RoundedCornerShape(18.dp)).background(Color.White.copy(alpha = 0.16f)), contentAlignment = Alignment.Center) { Text("⚽", fontSize = 26.sp) }
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.sp_title), color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                if ((home?.liveCount ?: 0) > 0) {
                    Spacer(Modifier.width(8.dp))
                    LiveDot(7.dp, Color.White)
                    Spacer(Modifier.width(4.dp))
                    Text(stringResource(R.string.sp_live_now, home?.liveCount ?: 0), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
            }
            Text(
                next?.let { m ->
                    val score = if (m.status in setOf("live", "break", "finished") && !SportsLive.prefs.noSpoilers) "${m.homeScore ?: 0}–${m.awayScore ?: 0}" else m.localTime.orEmpty()
                    "${m.home?.name.orEmpty()} $score ${m.away?.name.orEmpty()}" + if (m.status == "live") " · " + clockText(m, now, "HT", "FT") else ""
                } ?: stringResource(R.string.sp_home_sub),
                color = Color.White.copy(alpha = 0.9f), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
        }
    }
}
