package com.dorr.app.ui.screens.wallet

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.LocalTextStyle
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.composed
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.platform.LocalInspectionMode
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.runtime.State
import androidx.compose.ui.text.TextStyle

/**
 * The wallet's look, ported 1:1 from the web preview (public/app/css/wallet.css) so the app and
 * the preview are the same design: red brand, tinted icon wells, big soft cards, staggered
 * entrances, glass chips. Everything wallet-specific reads its colours and shapes from here.
 */
object Wa {
    val Red = Color(0xFFE50914)
    val RedDark = Color(0xFFB30710)
    val RedBright = Color(0xFFF2202C)
    val Ink = Color(0xFF111928)
    val Mut = Color(0xFF6B7280)
    val Soft = Color(0xFF9CA3AF)
    val Line = Color(0xFFEEF0F3)
    val Bg = Color(0xFFF9FAFB)
    val Green = Color(0xFF16A34A)
    val Danger = Color(0xFFDC2626)
    val Field = Color(0xFFF5F6F8)
    val Key = Color(0xFFF4F5F7)

    val ButtonBrush = Brush.linearGradient(listOf(RedBright, Color(0xFFC40812)))
    val HeroBrush = Brush.linearGradient(
        0f to RedBright, 0.46f to Color(0xFFC40812), 1f to Color(0xFF7A0410),
        start = Offset(0f, 0f), end = Offset(900f, 700f),
    )
    val PageWash = Brush.verticalGradient(listOf(Color(0xFFFBD5DB), Color(0x00FBD5DB)), endY = 620f)

    val CardShape = RoundedCornerShape(22.dp)
    val ButtonShape = RoundedCornerShape(18.dp)
}

/** The tinted icon wells (background + glyph colour) — `tone-*` in the preview. */
enum class Tone(val bg: Color, val fg: Color) {
    Red(Color(0xFFFDE8EC), Color(0xFFE50914)),
    Green(Color(0xFFE5F6EC), Color(0xFF16A34A)),
    Amber(Color(0xFFFEF1DC), Color(0xFFD97706)),
    Pink(Color(0xFFFCE7F3), Color(0xFFDB2777)),
    Blue(Color(0xFFE4EDFD), Color(0xFF2563EB)),
    Gray(Color(0xFFEFF1F4), Color(0xFF6B7280)),
}

// ------------------------------------------------------------------------------- motion

/** Staggered entrance: each block fades and rises a little later than the one before. */
fun Modifier.waRise(index: Int = 0): Modifier = composed {
    val inspecting = LocalInspectionMode.current
    // In an Android Studio / screenshot preview nothing ticks, so show the finished state straight away.
    val progress = remember { Animatable(if (inspecting) 1f else 0f) }
    LaunchedEffect(Unit) {
        progress.animateTo(1f, tween(durationMillis = 500, delayMillis = index * 60 + 80, easing = FastOutSlowInEasing))
    }
    graphicsLayer {
        alpha = progress.value
        translationY = (1f - progress.value) * 16.dp.toPx()
    }
}

/** Presses shrink a little, like the preview's `:active { transform: scale(.97) }`. */
@Composable
fun rememberPressScale(source: MutableInteractionSource, pressed: Float = 0.96f): State<Float> {
    val isPressed by source.collectIsPressedAsState()
    return animateFloatAsState(if (isPressed) pressed else 1f, tween(120), label = "press")
}

@Composable
fun waShimmerBrush(): Brush {
    val transition = rememberInfiniteTransition(label = "shimmer")
    val shift by transition.animateFloat(
        initialValue = -400f, targetValue = 900f,
        animationSpec = infiniteRepeatable(tween(1300, easing = LinearEasing), RepeatMode.Restart), label = "shift",
    )
    return Brush.linearGradient(
        listOf(Color(0xFFEEF0F3), Color(0xFFF7F8FA), Color(0xFFEEF0F3)),
        start = Offset(shift, 0f), end = Offset(shift + 400f, 0f),
    )
}

@Composable
fun WaSkeleton(modifier: Modifier = Modifier, shape: androidx.compose.ui.graphics.Shape = RoundedCornerShape(10.dp)) {
    Box(modifier.clip(shape).background(waShimmerBrush()))
}

// ------------------------------------------------------------------------------- building blocks

@Composable
fun WaCard(modifier: Modifier = Modifier, padding: Dp = 0.dp, content: @Composable ColumnScope.() -> Unit) {
    Column(
        modifier
            .shadow(10.dp, Wa.CardShape, ambientColor = Color(0x1A111928), spotColor = Color(0x26111928))
            .clip(Wa.CardShape)
            .background(Color.White)
            .padding(padding),
        content = content,
    )
}

@Composable
fun WaIconWell(icon: ImageVector, tone: Tone, size: Dp = 44.dp, iconSize: Dp = 22.dp, modifier: Modifier = Modifier) {
    Box(
        modifier.size(size).clip(RoundedCornerShape(size * 0.34f)).background(tone.bg),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = tone.fg, modifier = Modifier.size(iconSize)) }
}

@Composable
fun WaCircleButton(
    icon: ImageVector,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    tint: Color = Wa.Ink,
    background: Color = Color.White,
    contentDescription: String? = null,
) {
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.9f)
    Box(
        modifier
            .size(38.dp)
            .scale(scale)
            .shadow(6.dp, CircleShape, ambientColor = Color(0x1FE50914), spotColor = Color(0x2EE50914))
            .clip(CircleShape)
            .background(background)
            .clickable(interactionSource = source, indication = null, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, contentDescription, tint = tint, modifier = Modifier.size(20.dp)) }
}

enum class WaButtonStyle { Primary, Ghost, Quiet }

@Composable
fun WaButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    style: WaButtonStyle = WaButtonStyle.Primary,
    icon: ImageVector? = null,
    loading: Boolean = false,
) {
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.97f)
    val active = enabled && !loading
    val height = if (style == WaButtonStyle.Quiet) 44.dp else 54.dp
    val base = modifier
        .fillMaxWidth()
        .height(height)
        .scale(scale)
        .alpha(if (enabled) 1f else 0.4f)

    val decorated = when (style) {
        WaButtonStyle.Primary -> base
            .shadow(if (enabled) 14.dp else 0.dp, Wa.ButtonShape, ambientColor = Color(0x59E50914), spotColor = Color(0x8CE50914))
            .clip(Wa.ButtonShape)
            .background(if (enabled) Wa.ButtonBrush else Brush.linearGradient(listOf(Color(0xFF9CA3AF), Color(0xFF9CA3AF))))
        WaButtonStyle.Ghost -> base
            .shadow(8.dp, Wa.ButtonShape, ambientColor = Color(0x14111928), spotColor = Color(0x1F111928))
            .clip(Wa.ButtonShape)
            .background(Color.White)
        WaButtonStyle.Quiet -> base.clip(Wa.ButtonShape)
    }
    val contentColor = when (style) {
        WaButtonStyle.Primary -> Color.White
        WaButtonStyle.Ghost -> Wa.Red
        WaButtonStyle.Quiet -> Wa.Mut
    }

    Row(
        decorated.clickable(interactionSource = source, indication = null, enabled = active, onClick = onClick),
        horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.CenterHorizontally),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (loading) {
            CircularProgressIndicator(color = contentColor, strokeWidth = 2.5.dp, modifier = Modifier.size(20.dp))
        } else {
            if (icon != null) Icon(icon, null, tint = contentColor, modifier = Modifier.size(18.dp))
            Text(
                text,
                color = contentColor,
                fontSize = if (style == WaButtonStyle.Quiet) 14.sp else 16.sp,
                fontWeight = FontWeight.ExtraBold,
            )
        }
    }
}

/** Amber heads-up box (`wa-note`). */
@Composable
fun WaNote(text: String, modifier: Modifier = Modifier, icon: ImageVector = Icons.Rounded.Info) {
    Row(
        modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color(0xFFFEF6E7)).padding(horizontal = 14.dp, vertical = 12.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(icon, null, tint = Color(0xFF92590B), modifier = Modifier.padding(top = 2.dp).size(16.dp))
        Text(text, color = Color(0xFF92590B), fontSize = 12.5.sp, lineHeight = 21.sp)
    }
}

/** Red inline error that shakes once when it appears (`wa-err`). */
@Composable
fun WaError(message: String?, modifier: Modifier = Modifier) {
    if (message.isNullOrBlank()) return
    val shake = remember(message) { Animatable(0f) }
    LaunchedEffect(message) {
        shake.snapTo(0f)
        repeat(3) { shake.animateTo(1f, tween(70)); shake.animateTo(-1f, tween(70)) }
        shake.animateTo(0f, tween(60))
    }
    Row(
        modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 6.dp).graphicsLayer { translationX = shake.value * 5.dp.toPx() },
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Icon(Icons.Rounded.Warning, null, tint = Wa.Danger, modifier = Modifier.padding(top = 2.dp).size(16.dp))
        Text(message, color = Wa.Danger, fontSize = 13.sp, lineHeight = 21.sp)
    }
}

/** Empty / failure block with a floating icon well. */
@Composable
fun WaEmpty(icon: ImageVector, tone: Tone, title: String, text: String, modifier: Modifier = Modifier, action: (@Composable () -> Unit)? = null) {
    val float = rememberInfiniteTransition(label = "float")
    val dy by float.animateFloat(0f, -7f, infiniteRepeatable(tween(1700, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "dy")
    Column(modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 30.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        WaIconWell(icon, tone, size = 74.dp, iconSize = 30.dp, modifier = Modifier.graphicsLayer { translationY = dy.dp.toPx() })
        Spacer(Modifier.height(14.dp))
        Text(title, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp, color = Wa.Ink, textAlign = TextAlign.Center)
        Spacer(Modifier.height(4.dp))
        Text(text, color = Wa.Mut, fontSize = 13.sp, lineHeight = 22.sp, textAlign = TextAlign.Center)
        if (action != null) {
            Spacer(Modifier.height(14.dp))
            action()
        }
    }
}

/** The glass pills on the hero card and confirmation card. */
@Composable
fun WaGlassChip(text: String, icon: ImageVector, modifier: Modifier = Modifier, dark: Boolean = false) {
    Row(
        modifier
            .clip(RoundedCornerShape(999.dp))
            .background(if (dark) Color(0xFFF3F4F6) else Color(0x2BFFFFFF))
            .padding(horizontal = 11.dp, vertical = 5.dp),
        horizontalArrangement = Arrangement.spacedBy(6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = if (dark) Wa.Mut else Color.White, modifier = Modifier.size(14.dp))
        Text(text, color = if (dark) Wa.Mut else Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}

/** Section title row (`wa-sec`). */
@Composable
fun WaSectionTitle(title: String, modifier: Modifier = Modifier, trailing: (@Composable RowScope.() -> Unit)? = null) {
    Row(modifier.fillMaxWidth().padding(start = 2.dp, end = 2.dp, top = 22.dp, bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(title, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, color = Wa.Ink, modifier = Modifier.weight(1f))
        trailing?.invoke(this)
    }
}

/**
 * The frame of every wallet page: soft red wash at the top, a round back button, a red title,
 * scrolling content and — when given — a pinned call-to-action fading in over the content.
 */
@Composable
fun WaPage(
    title: String,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    actions: @Composable RowScope.() -> Unit = {},
    cta: (@Composable ColumnScope.() -> Unit)? = null,
    scroll: Boolean = true,
    content: @Composable ColumnScope.() -> Unit,
) {
    Box(modifier.fillMaxSize().background(Wa.Bg)) {
        Box(Modifier.fillMaxWidth().height(320.dp).background(Wa.PageWash))
        Column(Modifier.fillMaxSize()) {
            Row(
                Modifier.fillMaxWidth().padding(start = 14.dp, end = 14.dp, top = 14.dp, bottom = 8.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                WaCircleButton(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
                Text(title, color = Wa.Red, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                actions()
            }
            val body = Modifier
                .weight(1f)
                .fillMaxWidth()
                .let { if (scroll) it.verticalScroll(rememberScrollState()) else it }
                .padding(start = 16.dp, end = 16.dp, top = 6.dp, bottom = if (cta != null) 110.dp else 28.dp)
            Column(body, content = content)
        }
        if (cta != null) {
            Column(
                Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth()
                    .background(Brush.verticalGradient(listOf(Color(0x00F9FAFB), Wa.Bg), startY = 0f, endY = 90f))
                    .padding(start = 16.dp, end = 16.dp, top = 26.dp, bottom = 16.dp),
                content = cta,
            )
        }
    }
}

/** Small grey caption under a CTA ("محمي بالرقم السري"). */
@Composable
fun WaCtaHint(text: String, icon: ImageVector) {
    Row(Modifier.fillMaxWidth().padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(5.dp, Alignment.CenterHorizontally), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = Wa.Soft, modifier = Modifier.size(14.dp))
        Text(text, color = Wa.Soft, fontSize = 11.5.sp)
    }
}

@Composable
fun WaKeyValue(label: String, value: String, modifier: Modifier = Modifier, bold: Boolean = false, valueColor: Color = Wa.Ink, ltr: Boolean = false) {
    Row(modifier.fillMaxWidth().padding(vertical = 11.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = Wa.Mut, fontSize = 13.5.sp, modifier = Modifier.weight(1f))
        // Amounts read left-to-right ("− 1.00 SAR") even inside an Arabic layout, like the preview's dir="ltr".
        val direction = if (ltr) androidx.compose.ui.unit.LayoutDirection.Ltr else LocalLayoutDirection.current
        androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides direction) {
            Text(value, color = valueColor, fontSize = 13.5.sp, fontWeight = if (bold) FontWeight.ExtraBold else FontWeight.Bold, textAlign = TextAlign.End)
        }
    }
}

@Composable
fun WaDivider() {
    Box(Modifier.fillMaxWidth().height(1.dp).background(Wa.Line))
}

/** Marker so screens can ask for the same big number style everywhere. */
val WaBigNumber: TextStyle @Composable get() = LocalTextStyle.current.copy(fontWeight = FontWeight.ExtraBold, letterSpacing = (-1).sp)
