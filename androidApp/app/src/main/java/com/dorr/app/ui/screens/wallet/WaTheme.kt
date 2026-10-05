package com.dorr.app.ui.screens.wallet

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import com.dorr.app.R
import com.dorr.app.ui.theme.LocalThemeState
import com.dorr.app.ui.theme.appearanceColor

/**
 * The wallet's look, ported 1:1 from the web preview (public/app/css/wallet.css) so the app and
 * the preview are the same design: red brand, tinted icon wells, big soft cards, staggered
 * entrances, glass chips. Everything wallet-specific reads its colours and shapes from here.
 */
object Wa {
    val Red: Color @Composable get() = com.dorr.app.ui.screens.profile.settingsAccent()
    val RedDark = Color(0xFF00113A)
    val RedBright = Color(0xFF1E3A7B)
    val Ink: Color @Composable get() = if (walletNight()) com.dorr.app.ui.screens.AccountDark.ink else appearanceColor("textPrimary", Color(0xFF111928), night = false)
    val Mut: Color @Composable get() = if (walletNight()) com.dorr.app.ui.screens.AccountDark.mut else appearanceColor("textSecondary", Color(0xFF6B7280), night = false)
    val Soft: Color @Composable get() = if (walletNight()) Color(0xFF8B93A0) else appearanceColor("textMuted", Color(0xFF9CA3AF), night = false)
    val Line: Color @Composable get() = if (walletNight()) com.dorr.app.ui.screens.AccountDark.line else appearanceColor("border", Color(0xFFEEF0F3), night = false)
    val Bg: Color @Composable get() = if (walletNight()) com.dorr.app.ui.screens.AccountDark.bg else appearanceColor("background", Color(0xFFF9FAFB), night = false)
    val Green = Color(0xFF16A34A)
    val Danger = Color(0xFFDC2626)
    val Field: Color @Composable get() = if (walletNight()) com.dorr.app.ui.screens.AccountDark.card else Color(0xFFF5F6F8)
    val Key = Color(0xFFF4F5F7)
    val Surface: Color @Composable get() = if (walletNight()) com.dorr.app.ui.screens.AccountDark.card else appearanceColor("surface", Color.White, night = false)

    val ButtonBrush: Brush
        @Composable get() {
            val color = Red
            val deep = Color(color.red * 0.72f, color.green * 0.72f, color.blue * 0.72f)
            return Brush.linearGradient(listOf(color, deep))
        }
    val HeroBrush: Brush
        @Composable get() {
            val color = Red
            val mid = Color(color.red * 0.78f, color.green * 0.78f, color.blue * 0.78f)
            val deep = Color(color.red * 0.45f, color.green * 0.45f, color.blue * 0.45f)
            return Brush.linearGradient(listOf(color, mid, deep))
        }
    val PageWash: Brush
        @Composable get() = Brush.verticalGradient(listOf(Red.copy(alpha = 0.22f), Color.Transparent), endY = 620f)

    val CardShape = RoundedCornerShape(22.dp)
    val ButtonShape = RoundedCornerShape(18.dp)
}

@Composable
internal fun walletNight(): Boolean = LocalThemeState.current.isDark ?: isSystemInDarkTheme()

/** The tinted icon wells (background + glyph colour) — `tone-*` in the preview. */
enum class Tone(val bg: Color, val fg: Color) {
    Red(Color(0xFFFFEEE8), Color(0xFF001B53)),
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
    val night = walletNight()
    return Brush.linearGradient(
        listOf(
            if (night) Color(0xFF2C313A) else Color(0xFFEEF0F3),
            if (night) Color(0xFF3A404A) else Color(0xFFF7F8FA),
            if (night) Color(0xFF2C313A) else Color(0xFFEEF0F3),
        ),
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
            .then(if (walletNight()) Modifier else Modifier.shadow(10.dp, Wa.CardShape, ambientColor = Color(0x1A111928), spotColor = Color(0x26111928)))
            .clip(Wa.CardShape)
            .background(Wa.Surface)
            .then(if (walletNight()) Modifier.border(1.dp, Wa.Line, Wa.CardShape) else Modifier)
            .padding(padding),
        content = content,
    )
}

@Composable
fun WaIconWell(icon: ImageVector, tone: Tone, size: Dp = 44.dp, iconSize: Dp = 22.dp, modifier: Modifier = Modifier) {
    val night = walletNight() && tone == Tone.Red
    val brand = Wa.Red
    val well = if (night) com.dorr.app.ui.screens.AccountDark.well else if (tone == Tone.Red) brand.copy(alpha = 0.14f) else tone.bg
    val glyph = if (night) com.dorr.app.ui.screens.AccountDark.accent else if (tone == Tone.Red) brand else tone.fg
    Box(
        modifier.size(size).clip(RoundedCornerShape(size * 0.34f)).background(well),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = glyph, modifier = Modifier.size(iconSize)) }
}

@Composable
fun WaCircleButton(
    icon: ImageVector,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    tint: Color = Wa.Ink,
    background: Color = Wa.Surface,
    contentDescription: String? = null,
) {
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.9f)
    Box(
        modifier
            .size(38.dp)
            .scale(scale)
            .shadow(6.dp, CircleShape, ambientColor = Color(0x1F001B53), spotColor = Color(0x2E001B53))
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
            .shadow(if (enabled) 14.dp else 0.dp, Wa.ButtonShape, ambientColor = Color(0x59001B53), spotColor = Color(0x8C001B53))
            .clip(Wa.ButtonShape)
            .background(if (enabled) Wa.ButtonBrush else Brush.linearGradient(listOf(Color(0xFF9CA3AF), Color(0xFF9CA3AF))))
        WaButtonStyle.Ghost -> base
            .shadow(8.dp, Wa.ButtonShape, ambientColor = Color(0x14111928), spotColor = Color(0x1F111928))
            .clip(Wa.ButtonShape)
            .background(Wa.Surface)
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
    val night = walletNight()
    val ink = if (night) Color(0xFFF6C98A) else Color(0xFF92590B)
    Row(
        modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(if (night) com.dorr.app.ui.screens.AccountDark.well else Color(0xFFFEF6E7)).padding(horizontal = 14.dp, vertical = 12.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(icon, null, tint = ink, modifier = Modifier.padding(top = 2.dp).size(16.dp))
        Text(text, color = ink, fontSize = 12.5.sp, lineHeight = 21.sp)
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
    val night = walletNight() && dark
    val bg = when {
        night -> com.dorr.app.ui.screens.AccountDark.well
        dark -> Color(0xFFF3F4F6)
        else -> Color(0x2BFFFFFF)
    }
    val fg = when {
        night -> com.dorr.app.ui.screens.AccountDark.ink
        dark -> Wa.Mut
        else -> Color.White
    }
    Row(
        modifier
            .clip(RoundedCornerShape(999.dp))
            .background(bg)
            .padding(horizontal = 11.dp, vertical = 5.dp),
        horizontalArrangement = Arrangement.spacedBy(6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = fg, modifier = Modifier.size(14.dp))
        Text(text, color = fg, fontSize = 12.sp, fontWeight = FontWeight.Bold)
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
    dark: Boolean = false,
    content: @Composable ColumnScope.() -> Unit,
) {
    val night = dark || walletNight()
    val pageGlow = Wa.Red
    Box(modifier.fillMaxSize().background(if (night) com.dorr.app.ui.screens.AccountDark.bg else Wa.Bg)) {
        Canvas(Modifier.fillMaxSize()) {
            drawCircle(
                brush = Brush.radialGradient(
                    colors = listOf(pageGlow.copy(alpha = 0.35f), pageGlow.copy(alpha = 0.10f), Color.Transparent),
                    center = Offset(size.width * 0.5f, size.height * -0.08f),
                    radius = size.width * 0.85f,
                ),
                radius = size.width * 0.85f,
                center = Offset(size.width * 0.5f, size.height * -0.08f),
            )
        }
        Column(Modifier.fillMaxSize()) {
            Row(
                Modifier.fillMaxWidth().padding(start = 14.dp, end = 14.dp, top = 14.dp, bottom = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(title, color = Wa.Red, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
                    actions()
                    Box(
                        Modifier
                            .size(34.dp)
                            .then(
                                if (night) Modifier
                                else Modifier.shadow(6.dp, CircleShape, ambientColor = Color(0x14001B53), spotColor = Color(0x14001B53)),
                            )
                            .clip(CircleShape)
                            .background(if (night) com.dorr.app.ui.screens.AccountDark.card else Color.White)
                            .clickable(onClick = onBack),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(
                            Icons.AutoMirrored.Rounded.ArrowBack,
                            contentDescription = stringResource(R.string.common_back),
                            tint = Wa.Red,
                            modifier = Modifier.size(16.dp).graphicsLayer { scaleX = -1f },
                        )
                    }
                }
            }
            val body = Modifier
                .weight(1f)
                .fillMaxWidth()
                .let { if (scroll) it.verticalScroll(rememberScrollState()) else it }
                .padding(start = 16.dp, end = 16.dp, top = 6.dp, bottom = if (cta != null) 110.dp else if (!scroll) 0.dp else 28.dp)
            Column(body, content = content)
        }
        if (cta != null) {
            Column(
                Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth()
                    .background(Brush.verticalGradient(listOf(Wa.Bg.copy(alpha = 0f), Wa.Bg), startY = 0f, endY = 90f))
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
