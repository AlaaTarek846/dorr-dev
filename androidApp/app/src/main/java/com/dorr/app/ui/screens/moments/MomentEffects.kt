package com.dorr.app.ui.screens.moments

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.StartOffset
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlin.math.PI
import kotlin.math.sin

/*
 * The look of an occasion (DORR Moments): its colours as a gradient, and its animation — ten
 * families, all drawn here so any occasion the admin adds gets one without an app update.
 */

/** "#1E3A8A" → Color (a bad value falls back). */
fun momentColor(hex: String?, fallback: Color): Color = runCatching {
    val clean = hex?.removePrefix("#") ?: return fallback
    val v = clean.toLong(16)
    if (clean.length == 6) Color(0xFF000000 or v) else Color(v)
}.getOrDefault(fallback)

fun momentBrush(primary: String?, secondary: String?): Brush =
    Brush.linearGradient(listOf(momentColor(primary, Color(0xFF001B53)), momentColor(secondary, Color(0xFFFA7552))))

/**
 * Particles over a card or banner. `effects`: full · light · off (the person's choice, spec 168) —
 * "off" draws nothing.
 */
@Composable
fun MomentEffect(animation: String?, emoji: String?, primary: String?, secondary: String?, effects: String, modifier: Modifier = Modifier) {
    if (effects == "off") return
    val count = if (effects == "light") 6 else 14
    val glyph = when (animation) {
        "hearts" -> "❤️"
        "petals" -> "🌸"
        "snow" -> "❄️"
        "balloons" -> "🎈"
        "lanterns" -> "🏮"
        "stars" -> "⭐"
        "sparkles", "fireworks" -> "✨"
        "flags" -> emoji ?: "🎉"
        "confetti" -> null
        else -> "✨"
    }
    val motion = when (animation) {
        "balloons", "lanterns" -> Motion.Rise
        "stars", "sparkles", "fireworks" -> Motion.Twinkle
        else -> Motion.Fall
    }
    val colors = listOf(momentColor(primary, Color(0xFF001B53)), momentColor(secondary, Color(0xFFFA7552)), Color.White, Color(0xFFFBBF24))
    BoxWithConstraints(modifier) {
        repeat(count) { i -> Particle(i, glyph, motion, colors[i % colors.size], maxWidth, maxHeight) }
    }
}

private enum class Motion { Fall, Rise, Twinkle }

@Composable
private fun Particle(i: Int, glyph: String?, motion: Motion, color: Color, width: Dp, height: Dp) {
    val transition = rememberInfiniteTransition(label = "particle$i")
    val duration = 3200 + (i * 397) % 2600
    val p by transition.animateFloat(
        0f, 1f,
        infiniteRepeatable(tween(duration, easing = LinearEasing), RepeatMode.Restart, initialStartOffset = StartOffset((i * 330) % duration)),
        label = "p$i",
    )
    val x = width * (((i * 37) % 100) / 100f)
    val baseY = height * (((i * 53) % 100) / 100f)
    val y = when (motion) {
        Motion.Fall -> -24.dp + (height + 48.dp) * p
        Motion.Rise -> height + 24.dp - (height + 48.dp) * p
        Motion.Twinkle -> baseY
    }
    val sway = (sin((p * 2 * PI) + i).toFloat() * 10).dp
    val alpha = if (motion == Motion.Twinkle) sin(p * PI).toFloat() else 0.85f
    val scale = if (motion == Motion.Twinkle) 0.6f + 0.7f * sin(p * PI).toFloat() else 1f
    val rotation = if (motion == Motion.Fall) p * 360f * (if (i % 2 == 0) 1 else -1) else 0f

    Box(Modifier.offset(x + sway, y).graphicsLayer { this.alpha = alpha; scaleX = scale; scaleY = scale; rotationZ = rotation }) {
        if (glyph == null) {
            Box(Modifier.size(width = 6.dp, height = 11.dp).clip(RoundedCornerShape(2.dp)).background(color))
        } else {
            Text(glyph, fontSize = (12 + (i % 4) * 3).sp)
        }
    }
}
