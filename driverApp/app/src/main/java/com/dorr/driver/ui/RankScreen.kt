package com.dorr.driver.ui

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.MilitaryTech
import androidx.compose.material.icons.rounded.ThumbUp
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
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
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.PerformanceNoteDto
import com.dorr.driver.net.RankDto
import com.dorr.driver.net.RankInfoDto
import com.dorr.driver.net.RankMissingDto
import com.dorr.driver.ui.kit.DorrField
import com.dorr.driver.ui.kit.ScreenScaffold
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.OffsetDateTime

/**
 * «My rank» (docs/rides_tasks.md D-5, D-6, D-239..D-259): the probation days left, the rank and the way to the
 * next one (what's missing, from the server's thresholds), my numbers, a pending fall with its date, every rank
 * side by side, and my record of notes / warnings with their appeals. One load on open; `driver.rank` /
 * `driver.note` / `driver.appeal` events reload it.
 */
@Composable
fun RankScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var info by remember { mutableStateOf<RankInfoDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var appealing by remember { mutableStateOf<PerformanceNoteDto?>(null) }
    suspend fun reload() {
        when (val r = apiCall { DriverBackend.api.rank() }) {
            is ApiResult.Ok -> info = r.data
            is ApiResult.Failed -> error = r.error.displayMessage
            is ApiResult.Offline -> error = context.getString(R.string.error_offline)
        }
    }
    LaunchedEffect(Unit) {
        reload()
        com.dorr.driver.live.DriverLive.events.collect { e -> if (e.name == "driver.rank" || e.name == "driver.note" || e.name == "driver.appeal") reload() }
    }

    ScreenScaffold(stringResource(R.string.rk_title), onBack) {
        val d = info
        Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            error?.let { Text(it, color = Pal.danger) }
            if (d == null) return@Column
            RankHero(d)
            d.falling?.let { f ->
                Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Pal.warning.copy(alpha = 0.12f)).border(1.dp, Pal.warning.copy(alpha = 0.5f), RoundedCornerShape(18.dp)).padding(14.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.Warning, null, tint = Pal.warning)
                        Spacer(Modifier.width(8.dp))
                        Text(stringResource(R.string.rk_falling, f.to?.name.orEmpty(), daysUntil(f.dueAt)), color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    }
                    f.missing.forEach { MissingLine(it) }
                }
            }
            // My numbers.
            Text(stringResource(R.string.rk_numbers, d.metrics.windowDays), color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 16.sp)
            val m = d.metrics
            val metrics = listOf(
                Triple(stringResource(R.string.rk_m_trips), "${m.trips}", 1f),
                Triple(stringResource(R.string.rk_m_rating), stars(m.rating), m.rating / 500f),
                Triple(stringResource(R.string.rk_m_cleanliness), stars(m.cleanliness), m.cleanliness / 500f),
                Triple(stringResource(R.string.rk_m_behavior), stars(m.behavior), m.behavior / 500f),
                Triple(stringResource(R.string.rk_m_commitment), pct(m.commitmentBp), m.commitmentBp / 10000f),
                Triple(stringResource(R.string.rk_m_completion), pct(m.completionBp), m.completionBp / 10000f),
                Triple(stringResource(R.string.rk_m_cancel), pct(m.cancelBp), 1f - m.cancelBp / 10000f),
                Triple(stringResource(R.string.rk_m_warnings), "${m.warnings}", if (m.warnings == 0) 1f else 0.3f),
            )
            metrics.chunked(2).forEach { pair ->
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    pair.forEach { (label, value, share) -> Box(Modifier.weight(1f)) { MetricTile(label, value, share) } }
                }
            }
            // Every rank side by side.
            Text(stringResource(R.string.rk_all), color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 16.sp)
            Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                d.ranks.forEach { RankCard(it) }
            }
            // The record.
            Text(stringResource(R.string.rk_record), color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 16.sp)
            if (d.notes.isEmpty()) Text(stringResource(R.string.rk_record_empty), color = Pal.mut, fontSize = 13.sp)
            d.notes.forEach { n -> NoteCard(n, d.appealAllowed) { appealing = n } }
        }
    }

    appealing?.let { note ->
        var text by remember { mutableStateOf("") }
        var busy by remember { mutableStateOf(false) }
        AlertDialog(
            onDismissRequest = { if (!busy) appealing = null },
            title = { Text(stringResource(R.string.rk_appeal_title), fontWeight = FontWeight.Bold) },
            text = {
                Column {
                    Text(note.title, fontSize = 13.sp, color = Pal.mut)
                    Spacer(Modifier.height(8.dp))
                    DorrField(text, { text = it.take(1000) }, placeholder = { Text(stringResource(R.string.rk_appeal_hint)) })
                }
            },
            confirmButton = {
                TextButton(enabled = text.trim().length >= 5 && !busy, onClick = {
                    busy = true
                    scope.launch {
                        when (val r = apiCall { DriverBackend.api.appealNote(note.id, JsonObject().apply { addProperty("text", text.trim()) }) }) {
                            is ApiResult.Ok -> { appealing = null; reload() }
                            is ApiResult.Failed -> error = r.error.displayMessage
                            is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                        }
                        busy = false
                    }
                }) { Text(stringResource(R.string.rk_appeal_send)) }
            },
            dismissButton = { TextButton(onClick = { appealing = null }) { Text(stringResource(R.string.cancel)) } },
        )
    }
}

@Composable
private fun RankHero(d: RankInfoDto) {
    val color = parse(d.rank?.color) ?: Pal.accent
    val glow by rememberInfiniteTransition(label = "rank").animateFloat(0.85f, 1.08f, infiniteRepeatable(tween(1600), RepeatMode.Reverse), label = "rank-glow")
    val pop = remember { Animatable(0.6f) }
    LaunchedEffect(d.rank?.slug) { pop.animateTo(1f, spring(dampingRatio = 0.45f)) }
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(26.dp)).background(Brush.linearGradient(listOf(color, color.copy(alpha = 0.65f), Pal.accent))).padding(18.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(Modifier.size(84.dp).scale(pop.value * glow).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.MilitaryTech, null, tint = Color.White, modifier = Modifier.size(52.dp))
        }
        Spacer(Modifier.height(8.dp))
        Text(d.rank?.name.orEmpty(), color = Color.White, fontWeight = FontWeight.Black, fontSize = 26.sp)
        if (d.probation.active) {
            Text(stringResource(R.string.rk_probation, d.probation.daysLeft), color = Color.White.copy(alpha = 0.9f), fontSize = 13.sp, textAlign = TextAlign.Center)
        }
        d.next?.let { next ->
            val total = next.missing.size
            Spacer(Modifier.height(12.dp))
            Text(stringResource(R.string.rk_to_next, next.name.orEmpty()), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp)
            if (total == 0) {
                Text(stringResource(R.string.rk_next_ready), color = Color.White.copy(alpha = 0.9f), fontSize = 12.sp)
            } else {
                Column(Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.16f)).padding(10.dp)) {
                    next.missing.forEach { MissingLine(it, onDark = true) }
                }
            }
        } ?: Text(stringResource(R.string.rk_top), color = Color.White.copy(alpha = 0.9f), fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp))
    }
}

@Composable
private fun MissingLine(m: RankMissingDto, onDark: Boolean = false) {
    val ink = if (onDark) Color.White else Pal.ink
    val label = metricLabel(m.metric)
    val text = when (m.metric) {
        "trips", "warnings" -> stringResource(if (m.min) R.string.rk_need_min else R.string.rk_need_max, label, "${m.have}", "${m.need}")
        "rating", "cleanliness", "behavior" -> stringResource(R.string.rk_need_min, label, stars(m.have), stars(m.need))
        else -> stringResource(if (m.min) R.string.rk_need_min else R.string.rk_need_max, label, pct(m.have), pct(m.need))
    }
    Text("• $text", color = ink, fontSize = 12.5.sp, modifier = Modifier.padding(top = 3.dp))
}

@Composable
private fun MetricTile(label: String, value: String, share: Float) {
    val ring by animateFloatAsState(share.coerceIn(0f, 1f), tween(900, easing = FastOutSlowInEasing), label = "metric")
    val tint = when { share >= 0.85f -> Pal.success; share >= 0.6f -> Pal.warning; else -> Pal.danger }
    Row(Modifier.fillMaxWidth().surface(RoundedCornerShape(18.dp)).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(42.dp), contentAlignment = Alignment.Center) {
            Canvas(Modifier.size(42.dp)) {
                drawCircle(tint.copy(alpha = 0.15f), radius = size.minDimension / 2 - 3f, style = Stroke(5f))
                drawArc(tint, -90f, 360f * ring, false, style = Stroke(5f, cap = StrokeCap.Round))
            }
        }
        Spacer(Modifier.width(8.dp))
        Column {
            Text(value, color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 16.sp)
            Text(label, color = Pal.mut, fontSize = 11.sp)
        }
    }
}

@Composable
private fun RankCard(r: RankDto) {
    val color = parse(r.color) ?: Pal.accent
    Column(
        Modifier.width(210.dp).clip(RoundedCornerShape(20.dp)).background(Pal.card)
            .border(if (r.current) 2.dp else 1.dp, if (r.current) color else color.copy(alpha = 0.3f), RoundedCornerShape(20.dp)).padding(14.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.MilitaryTech, null, tint = color, modifier = Modifier.size(24.dp))
            Spacer(Modifier.width(6.dp))
            Text(r.name.orEmpty(), color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 16.sp, modifier = Modifier.weight(1f))
            if (r.current) Text(stringResource(R.string.rk_you), color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(color).padding(horizontal = 8.dp, vertical = 2.dp))
        }
        if (r.probation) Text(stringResource(R.string.rk_probation_rank), color = Pal.mut, fontSize = 12.sp)
        r.thresholds.filter { (k, v) -> v > 0 && !(k == "cancel_bp" && v >= 10000) && !(k == "warnings" && v >= 99) }.forEach { (k, v) ->
            val need = when (k) { "rating", "cleanliness", "behavior" -> stars(v); "trips", "warnings" -> "$v"; else -> pct(v) }
            Text("• " + stringResource(if (k == "cancel_bp" || k == "warnings") R.string.rk_th_max else R.string.rk_th_min, metricLabel(k), need), color = Pal.mut, fontSize = 11.5.sp)
        }
        r.benefits.forEach { b ->
            Row(Modifier.padding(top = 3.dp)) {
                Icon(Icons.Rounded.CheckCircle, null, tint = Pal.success, modifier = Modifier.size(14.dp))
                Spacer(Modifier.width(4.dp))
                Text(b, color = Pal.ink, fontSize = 12.sp)
            }
        }
    }
}

@Composable
private fun NoteCard(n: PerformanceNoteDto, appealAllowed: Boolean, onAppeal: () -> Unit) {
    val (icon, tint) = when (n.kind) {
        "warning" -> Icons.Rounded.Warning to Pal.danger
        "praise" -> Icons.Rounded.ThumbUp to Pal.success
        "rank_change" -> Icons.Rounded.MilitaryTech to Pal.warning
        else -> Icons.Rounded.Info to Pal.accent
    }
    Column(Modifier.fillMaxWidth().surface(RoundedCornerShape(18.dp)).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Text(n.title, color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.weight(1f))
            Text(n.createdAt?.take(10).orEmpty(), color = Pal.mut, fontSize = 11.sp)
        }
        n.body?.takeIf { it.isNotBlank() }?.let { Text(it, color = Pal.mut, fontSize = 12.5.sp, modifier = Modifier.padding(top = 4.dp)) }
        when (n.appealStatus) {
            "pending" -> Text(stringResource(R.string.rk_appeal_pending), color = Pal.warning, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 6.dp))
            "accepted", "rejected" -> Text(
                stringResource(if (n.appealStatus == "accepted") R.string.rk_appeal_accepted else R.string.rk_appeal_rejected, n.appealAnswer.orEmpty()),
                color = if (n.appealStatus == "accepted") Pal.success else Pal.danger, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp),
            )
            else -> if (appealAllowed && n.kind != "praise") {
                Row(
                    Modifier.padding(top = 8.dp).clip(CircleShape).background(Pal.accent.copy(alpha = 0.1f)).clickable(onClick = onAppeal).padding(horizontal = 12.dp, vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Gavel, null, tint = Pal.accent, modifier = Modifier.size(16.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(R.string.rk_appeal), color = Pal.accent, fontWeight = FontWeight.Bold, fontSize = 12.5.sp)
                }
            }
        }
    }
}

@Composable
private fun metricLabel(key: String): String = stringResource(
    when (key) {
        "trips" -> R.string.rk_m_trips
        "rating" -> R.string.rk_m_rating
        "cleanliness" -> R.string.rk_m_cleanliness
        "behavior" -> R.string.rk_m_behavior
        "commitment_bp" -> R.string.rk_m_commitment
        "completion_bp" -> R.string.rk_m_completion
        "cancel_bp" -> R.string.rk_m_cancel
        else -> R.string.rk_m_warnings
    },
)

private fun stars(x100: Int): String = if (x100 <= 0) "—" else "%.2f★".format(x100 / 100.0)

private fun pct(bp: Int): String = "%d%%".format(bp / 100)

private fun daysUntil(iso: String?): Int = iso?.let { runCatching { maxOf(0, java.time.Duration.between(java.time.Instant.now(), OffsetDateTime.parse(it).toInstant()).toHours().toInt() / 24 + 1) }.getOrNull() } ?: 0

private fun parse(hex: String?): Color? = hex?.let { runCatching { Color(android.graphics.Color.parseColor(it)) }.getOrNull() }
