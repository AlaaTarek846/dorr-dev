package com.dorr.app.ui.screens.wallet

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.CubicBezierEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlin.math.hypot
import kotlin.random.Random

/**
 * The round "done / failed" seal: a disc that pops in, a check (or cross) that draws itself, and a
 * soft ring that keeps pulsing outwards — `wa-seal` in the preview.
 */
@Composable
fun WaSeal(bad: Boolean = false, modifier: Modifier = Modifier) {
    val color = if (bad) Wa.Danger else Wa.Green
    val inspecting = androidx.compose.ui.platform.LocalInspectionMode.current
    val disc = remember { Animatable(if (inspecting) 1f else 0f) }
    val mark = remember { Animatable(if (inspecting) 1f else 0f) }
    val shake = remember { Animatable(0f) }
    LaunchedEffect(Unit) {
        disc.animateTo(1f, spring(dampingRatio = 0.55f, stiffness = Spring.StiffnessMedium))
    }
    LaunchedEffect(Unit) {
        kotlinx.coroutines.delay(450)
        mark.animateTo(1f, tween(450))
        if (bad) {
            repeat(3) { shake.animateTo(1f, tween(70)); shake.animateTo(-1f, tween(70)) }
            shake.animateTo(0f, tween(60))
        }
    }
    val ring by rememberInfiniteTransition(label = "seal").animateFloat(0f, 1f, infiniteRepeatable(tween(1600), RepeatMode.Restart), label = "ring")

    Canvas(modifier.size(104.dp).graphicsLayer { translationX = shake.value * 6.dp.toPx() }) {
        val unit = size.width / 104f
        val center = Offset(size.width / 2, size.height / 2)
        // Pulsing ring.
        val ringScale = 0.7f + ring * 0.55f
        drawCircle(color.copy(alpha = 0.4f * (1f - ring)), radius = 46f * unit * ringScale, center = center, style = Stroke(3f * unit))
        // Disc.
        drawCircle(color.copy(alpha = disc.value.coerceIn(0f, 1f)), radius = 46f * unit * (0.4f + 0.6f * disc.value), center = center)
        // Mark, drawn progressively along its two strokes.
        val stroke = Stroke(6f * unit, cap = StrokeCap.Round)
        if (!bad) {
            val a = Offset(34f * unit, 54f * unit)
            val b = Offset(47f * unit, 67f * unit)
            val c = Offset(71f * unit, 41f * unit)
            drawPolyline(listOf(a, b, c), mark.value, Color.White, stroke)
        } else {
            drawPolyline(listOf(Offset(38f * unit, 38f * unit), Offset(66f * unit, 66f * unit)), mark.value, Color.White, stroke)
            drawPolyline(listOf(Offset(66f * unit, 38f * unit), Offset(38f * unit, 66f * unit)), mark.value, Color.White, stroke)
        }
    }
}

private fun androidx.compose.ui.graphics.drawscope.DrawScope.drawPolyline(points: List<Offset>, progress: Float, color: Color, stroke: Stroke) {
    val lengths = points.zipWithNext { p, q -> hypot(q.x - p.x, q.y - p.y) }
    var remaining = lengths.sum() * progress
    for (i in lengths.indices) {
        if (remaining <= 0f) break
        val take = minOf(remaining, lengths[i])
        val p = points[i]
        val q = points[i + 1]
        val t = take / lengths[i]
        drawLine(color, p, Offset(p.x + (q.x - p.x) * t, p.y + (q.y - p.y) * t), strokeWidth = stroke.width, cap = stroke.cap)
        remaining -= take
    }
}

/** Confetti burst for a successful payment (`wa-confetti`): 26 pieces fall, spin and fade. */
@Composable
fun WaConfetti(modifier: Modifier = Modifier) {
    val pieces = remember {
        val colors = listOf(Color(0xFFE50914), Color(0xFFF59E0B), Color(0xFF16A34A), Color(0xFF2563EB), Color(0xFFDB2777), Color(0xFFFFFFFF))
        List(26) {
            ConfettiPiece(
                dx = (Random.nextFloat() - 0.5f) * 300f,
                spin = Random.nextFloat() * 720f - 360f,
                delay = Random.nextFloat() * 0.35f,
                color = colors[Random.nextInt(colors.size)],
            )
        }
    }
    val progress = remember { Animatable(0f) }
    LaunchedEffect(Unit) { progress.animateTo(1f, tween(2050, easing = LinearEasing)) }
    val ease = remember { CubicBezierEasing(0.2f, 0.7f, 0.4f, 1f) }

    Canvas(modifier.fillMaxSize()) {
        val startY = 90.dp.toPx()
        pieces.forEach { piece ->
            val local = ((progress.value - piece.delay * 0.8f) / 0.8f).coerceIn(0f, 1f)
            if (local <= 0f || local >= 1f) return@forEach
            val e = ease.transform(local)
            val x = size.width / 2 + piece.dx.dp.toPx() * e
            val y = startY + 420.dp.toPx() * e
            rotate(piece.spin * e, pivot = Offset(x, y)) {
                drawRect(piece.color.copy(alpha = 1f - local), topLeft = Offset(x - 4.dp.toPx(), y - 7.dp.toPx()), size = Size(8.dp.toPx(), 14.dp.toPx()))
            }
        }
    }
}

private class ConfettiPiece(val dx: Float, val spin: Float, val delay: Float, val color: Color)

/** The pulsing "waiting…" badge: three rings ping outwards from a red core with an icon. */
@Composable
fun WaPulse(icon: ImageVector, modifier: Modifier = Modifier) {
    val transition = rememberInfiniteTransition(label = "pulse")
    Box(modifier.size(104.dp), contentAlignment = Alignment.Center) {
        repeat(3) { index ->
            val t by transition.animateFloat(
                0f, 1f,
                infiniteRepeatable(tween(2200, delayMillis = 0, easing = LinearEasing), RepeatMode.Restart, initialStartOffset = androidx.compose.animation.core.StartOffset(index * 700)),
                label = "ring$index",
            )
            Canvas(Modifier.fillMaxSize()) {
                val s = 0.75f + 0.75f * t
                drawCircle(Wa.Red.copy(alpha = 0.5f * (1f - t)), radius = size.minDimension / 2 * s, style = Stroke(2.dp.toPx()))
            }
        }
        Box(
            Modifier
                .size(78.dp)
                .shadow(14.dp, CircleShape, ambientColor = Color(0x66E50914), spotColor = Color(0x99E50914))
                .clip(CircleShape)
                .background(Wa.ButtonBrush),
            contentAlignment = Alignment.Center,
        ) { Icon(icon, null, tint = Color.White, modifier = Modifier.size(34.dp)) }
    }
}

/** Centered column used by every result screen (done, failed, waiting, OTP). */
@Composable
fun WaStatusColumn(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    // Centred when it fits, scrollable when it doesn't (small screens, large fonts).
    BoxWithConstraints(modifier.fillMaxSize()) {
        Column(
            Modifier
                .fillMaxWidth()
                .heightIn(min = maxHeight)
                .verticalScroll(rememberScrollState())
                .padding(start = 24.dp, end = 24.dp, top = 20.dp, bottom = 28.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
            content = content,
        )
    }
}

@Composable
fun WaStatusTitle(text: String) {
    Text(text, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 18.dp, bottom = 6.dp))
}

@Composable
fun WaStatusText(text: String) {
    Text(text, fontSize = 14.sp, lineHeight = 25.sp, color = Wa.Mut, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth(0.85f))
}

/** Three little dots that bounce after a title ("…"). */
@Composable
fun WaLoadingDots(color: Color = Wa.Ink) {
    val transition = rememberInfiniteTransition(label = "dots")
    Row(Modifier.padding(start = 4.dp), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        repeat(3) { index ->
            val t by transition.animateFloat(
                0f, 1f,
                infiniteRepeatable(tween(1200), RepeatMode.Restart, initialStartOffset = androidx.compose.animation.core.StartOffset(index * 150)),
                label = "dot$index",
            )
            val lift = if (t < 0.3f) t / 0.3f else if (t < 0.6f) 1f - (t - 0.3f) / 0.3f else 0f
            Box(
                Modifier
                    .size(5.dp)
                    .graphicsLayer { translationY = -4.dp.toPx() * lift; alpha = 0.4f + 0.6f * lift }
                    .background(color, CircleShape),
            )
        }
    }
}
