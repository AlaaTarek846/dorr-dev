package com.dorr.app.ui.screens.sports

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.withFrameNanos
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import kotlinx.coroutines.delay
import kotlin.math.cos
import kotlin.math.sin
import kotlin.random.Random

/*
 * The goal moment (197) and the win celebration (198): only for a team I follow, only when I
 * allowed it and haven't hidden spoilers. calm = a card that bounces in; normal = the whole
 * screen, confetti in the team's colours; festive = plus fireworks. Anything else is a quiet banner.
 */

@Composable
fun SportsCelebrationHost() {
    val celebration = SportsLive.celebration
    val banner = SportsLive.banner

    celebration?.let { c ->
        LaunchedEffect(c.at) {
            delay(when (c.celebrate) { "calm" -> 2600L; "festive" -> 5200L; else -> 3800L })
            if (SportsLive.celebration?.at == c.at) SportsLive.celebration = null
        }
        if (c.celebrate == "calm") CalmCard(c) else BigMoment(c, festive = c.celebrate == "festive")
    }

    banner?.let { b ->
        LaunchedEffect(b.at) {
            delay(3800)
            if (SportsLive.banner?.at == b.at) SportsLive.banner = null
        }
    }
    AnimatedVisibility(
        visible = banner != null && celebration == null,
        enter = slideInVertically(spring(dampingRatio = 0.7f)) { -it } + fadeIn(),
        exit = slideOutVertically(tween(260)) { -it } + fadeOut(),
    ) {
        banner?.let { Banner(it) }
    }
}

private fun teamsOf(a: SportsAlert): Pair<Color, Color> {
    val m = SportsLive.latest[a.matchId]
    val home = teamColor(m?.home?.color, m?.home?.id ?: 0)
    val away = teamColor(m?.away?.color, m?.away?.id ?: 1)
    return if (a.side == "away") away to home else home to away
}

@Composable
private fun headline(a: SportsAlert): String = when (a.type) {
    "finished" -> stringResource(R.string.sp_win)
    "prize" -> stringResource(R.string.sp_prize_won)
    else -> stringResource(R.string.sp_goal)
}

@Composable
private fun subline(a: SportsAlert): String {
    val m = SportsLive.latest[a.matchId]
    val team = if (a.side == "away") m?.away?.name else m?.home?.name
    return listOfNotNull(
        team,
        a.scorer?.let { s -> a.minute?.let { "$s $it'" } ?: s },
    ).joinToString(" · ")
}

@Composable
private fun scoreLine(a: SportsAlert): String? {
    val m = SportsLive.latest[a.matchId]
    if (a.homeScore == null || a.awayScore == null) return null
    return "${m?.home?.name.orEmpty()}  ${a.homeScore} – ${a.awayScore}  ${m?.away?.name.orEmpty()}".trim()
}

@Composable
private fun BigMoment(a: SportsAlert, festive: Boolean) {
    val (main, other) = teamsOf(a)
    val pop = remember(a.at) { Animatable(0.2f) }
    val shake = remember(a.at) { Animatable(0f) }
    LaunchedEffect(a.at) {
        pop.animateTo(1f, spring(dampingRatio = 0.32f, stiffness = 260f))
    }
    LaunchedEffect(a.at) {
        repeat(6) { i ->
            shake.animateTo(if (i % 2 == 0) 9f else -9f, tween(55))
        }
        shake.animateTo(0f, tween(80))
    }
    Box(
        Modifier.fillMaxSize()
            .background(Brush.radialGradient(listOf(main.copy(alpha = 0.92f), Color(0xFF050814).copy(alpha = 0.94f)), radius = 1400f))
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { SportsLive.celebration = null },
        contentAlignment = Alignment.Center,
    ) {
        Confetti(a.at, listOf(main, other, Color.White, Color(0xFFFACC15)), count = if (festive) 170 else 110)
        if (festive) Fireworks(a.at, listOf(main, Color(0xFFFACC15), Color.White, other))
        Column(Modifier.graphicsLayer { translationX = shake.value }, horizontalAlignment = Alignment.CenterHorizontally) {
            Text(if (a.type == "finished" || a.type == "prize") "🏆" else "⚽", fontSize = 64.sp, modifier = Modifier.scale(pop.value).rotate((1 - pop.value) * -40f))
            Spacer(Modifier.height(6.dp))
            Text(
                headline(a), color = Color.White, fontSize = 54.sp, fontWeight = FontWeight.Black, textAlign = TextAlign.Center,
                modifier = Modifier.scale(pop.value),
            )
            Text(subline(a), color = Color.White.copy(alpha = 0.92f), fontSize = 18.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, modifier = Modifier.padding(horizontal = 24.dp).alpha(pop.value.coerceIn(0f, 1f)))
            scoreLine(a)?.let {
                Text(
                    it, color = Color.White, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.padding(top = 14.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.16f)).padding(horizontal = 18.dp, vertical = 8.dp),
                )
            }
            Text(
                stringResource(R.string.sp_view_match), color = main, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.padding(top = 22.dp).clip(CircleShape).background(Color.White).clickable {
                    SportsLive.celebration = null
                    SportsLink.show(a.matchId)
                }.padding(horizontal = 22.dp, vertical = 10.dp),
            )
        }
    }
}

@Composable
private fun CalmCard(a: SportsAlert) {
    val (main, other) = teamsOf(a)
    val pop = remember(a.at) { Animatable(0.6f) }
    LaunchedEffect(a.at) { pop.animateTo(1f, spring(dampingRatio = 0.45f)) }
    Box(Modifier.fillMaxWidth().statusBarsPadding().padding(16.dp), contentAlignment = Alignment.TopCenter) {
        Row(
            Modifier.fillMaxWidth().scale(pop.value).clip(RoundedCornerShape(24.dp)).background(Brush.linearGradient(listOf(main, other)))
                .clickable { SportsLive.celebration = null; SportsLink.show(a.matchId) }.padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(if (a.type == "finished") "🏆" else "⚽", fontSize = 30.sp)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(headline(a), color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.Black)
                Text(listOfNotNull(subline(a).ifBlank { null }, scoreLine(a)).joinToString("  ·  "), color = Color.White.copy(alpha = 0.92f), fontSize = 13.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

@Composable
private fun Banner(a: SportsAlert) {
    val m = SportsLive.latest[a.matchId]
    val title = listOfNotNull(m?.home?.name, m?.away?.name).joinToString(" – ").ifBlank { stringResource(R.string.sp_title) }
    val text = when (a.type) {
        "goal", "goal_detail" -> stringResource(R.string.sp_banner_goal, listOfNotNull(a.scorer, a.minute?.let { "$it'" }).joinToString(" "))
        "red_card" -> stringResource(R.string.sp_banner_red, a.scorer.orEmpty())
        "kickoff" -> stringResource(R.string.sp_banner_kickoff)
        "half_time" -> stringResource(R.string.sp_ht)
        "finished" -> stringResource(R.string.sp_ft)
        "lineups" -> stringResource(R.string.sp_banner_lineups)
        "reminder" -> stringResource(R.string.sp_banner_reminder)
        else -> stringResource(R.string.sp_banner_update)
    }
    val icon = when (a.type) { "red_card" -> "🟥"; "kickoff", "half_time", "finished" -> "⏱️"; "lineups" -> "📋"; "reminder" -> "⏰"; else -> "⚽" }
    Box(Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 14.dp, vertical = 8.dp)) {
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Color(0xFF0B1220).copy(alpha = 0.94f))
                .clickable { SportsLive.banner = null; SportsLink.show(a.matchId) }.padding(horizontal = 14.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(icon, fontSize = 22.sp)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(title, color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(text, color = Color.White.copy(alpha = 0.85f), fontSize = 12.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            if (!a.hidden && a.homeScore != null && a.awayScore != null) {
                Text("${a.homeScore} – ${a.awayScore}", color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.Black)
            }
        }
    }
}

// =============================================================================== particles

private class Bit(var x: Float, var y: Float, var vx: Float, var vy: Float, var rot: Float, val spin: Float, val color: Color, val w: Float, val h: Float)

/** Confetti bursting up from the bottom and fluttering down. */
@Composable
internal fun Confetti(key: Any, colors: List<Color>, count: Int = 110) {
    var frame by remember(key) { mutableStateOf(0L) }
    val bits = remember(key) {
        List(count) {
            val left = it % 2 == 0
            Bit(
                x = if (left) 0f else 1f, y = 1.02f,
                vx = (if (left) 1 else -1) * Random.nextFloat() * 0.55f + (if (left) 0.08f else -0.08f),
                vy = -(0.9f + Random.nextFloat() * 0.75f),
                rot = Random.nextFloat() * 360f, spin = (Random.nextFloat() - 0.5f) * 720f,
                color = colors[it % colors.size], w = 6f + Random.nextFloat() * 8f, h = 10f + Random.nextFloat() * 12f,
            )
        }
    }
    LaunchedEffect(key) {
        var last = 0L
        while (true) {
            withFrameNanos { t ->
                val dt = if (last == 0L) 0.016f else ((t - last) / 1e9f).coerceAtMost(0.05f)
                last = t
                bits.forEach { b ->
                    b.vy += 1.1f * dt
                    b.vx *= (1f - 0.6f * dt)
                    b.x += b.vx * dt
                    b.y += b.vy * dt
                    b.rot += b.spin * dt
                }
                frame = t
            }
        }
    }
    Canvas(Modifier.fillMaxSize()) {
        frame.let { }
        bits.forEach { b ->
            if (b.y < 1.1f) {
                val cx = b.x * size.width
                val cy = b.y * size.height
                rotate(b.rot, Offset(cx, cy)) {
                    drawRect(b.color, topLeft = Offset(cx - b.w / 2, cy - b.h / 2), size = Size(b.w, b.h))
                }
            }
        }
    }
}

/** A few bursts of sparks (festive). */
@Composable
private fun Fireworks(key: Any, colors: List<Color>) {
    val t = remember(key) { Animatable(0f) }
    LaunchedEffect(key) { t.animateTo(1f, tween(4200, easing = FastOutSlowInEasing)) }
    val bursts = remember(key) { List(5) { Triple(0.15f + Random.nextFloat() * 0.7f, 0.12f + Random.nextFloat() * 0.35f, it * 0.16f) } }
    Canvas(Modifier.fillMaxSize()) {
        bursts.forEachIndexed { i, (bx, by, start) ->
            val p = ((t.value - start) / 0.45f).coerceIn(0f, 1f)
            if (p <= 0f || p >= 1f) return@forEachIndexed
            val c = colors[i % colors.size]
            val r = p * size.minDimension * 0.22f
            repeat(18) { k ->
                val a = (k / 18f) * 2 * Math.PI
                drawCircle(c.copy(alpha = 1f - p), radius = 5f * (1f - p * 0.5f), center = Offset(bx * size.width + (cos(a) * r).toFloat(), by * size.height + (sin(a) * r).toFloat() + p * p * 60f))
            }
        }
    }
}
