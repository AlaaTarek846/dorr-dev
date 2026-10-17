package com.dorr.driver.ui.kit

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.composed
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.driver.ui.theme.Pal
import kotlinx.coroutines.delay

/**
 * A card surface in the DORR app's way: a soft navy-tinted shadow by day, a hairline border at night
 * (a shadow doesn't show on a dark page).
 */
fun Modifier.surface(shape: Shape = RoundedCornerShape(20.dp), elevation: Dp = 6.dp): Modifier =
    if (Pal.night) {
        this.clip(shape).background(Pal.card).border(1.dp, Pal.line, shape)
    } else {
        this.shadow(elevation, shape, ambientColor = Color(0x12001B53), spotColor = Color(0x12001B53))
            .clip(shape)
            .background(Pal.card)
    }

/** A tap that answers with a small press-in, instead of a flat ripple. */
fun Modifier.bounceClick(enabled: Boolean = true, onClick: () -> Unit): Modifier = composed {
    val source = remember { MutableInteractionSource() }
    val pressed by source.collectIsPressedAsState()
    val scale by animateFloatAsState(if (pressed) 0.97f else 1f, spring(stiffness = Spring.StiffnessMedium), label = "press")
    this
        .graphicsLayer { scaleX = scale; scaleY = scale }
        .clickable(interactionSource = source, indication = null, enabled = enabled, onClick = onClick)
}

/** Rises and fades in the first time it is shown, one after another by [index] — lists arrive instead of popping. */
fun Modifier.staggerIn(index: Int = 0): Modifier = composed {
    val progress = remember { Animatable(0f) }
    LaunchedEffect(Unit) {
        delay(index.coerceAtMost(8) * 45L)
        progress.animateTo(1f, tween(380, easing = FastOutSlowInEasing))
    }
    this.graphicsLayer {
        alpha = progress.value
        translationY = (1f - progress.value) * 22.dp.toPx()
    }
}

/** The main action: rounded, in the brand colour, with a glow of its own colour by day. */
@Composable
fun PrimaryButton(
    text: String,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    busy: Boolean = false,
    color: Color = Pal.accent,
    icon: ImageVector? = null,
    onClick: () -> Unit,
) {
    val shape = RoundedCornerShape(18.dp)
    val fill by animateColorAsState(if (enabled) color else color.copy(alpha = 0.4f), tween(200), label = "button")
    Box(
        modifier
            .fillMaxWidth()
            .height(54.dp)
            .then(if (enabled && !busy && !Pal.night) Modifier.shadow(10.dp, shape, ambientColor = color.copy(alpha = 0.3f), spotColor = color.copy(alpha = 0.38f)) else Modifier)
            .clip(shape)
            .background(fill)
            .bounceClick(enabled = enabled && !busy, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (busy) {
            CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
        } else {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (icon != null) Icon(icon, null, tint = Color.White, modifier = Modifier.size(20.dp))
                Text(text, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 16.sp)
            }
        }
    }
}

/** The quieter action: an outline in the brand colour. As wide as its words; the caller stretches it with `Modifier.fillMaxWidth()`. */
@Composable
fun OutlineButton(text: String, modifier: Modifier = Modifier, enabled: Boolean = true, color: Color = Pal.accent, onClick: () -> Unit) {
    val shape = RoundedCornerShape(18.dp)
    Box(
        modifier
            .height(46.dp)
            .clip(shape)
            .border(1.5.dp, if (enabled) color else color.copy(alpha = 0.4f), shape)
            .bounceClick(enabled = enabled, onClick = onClick)
            .padding(horizontal = 18.dp),
        contentAlignment = Alignment.Center,
    ) {
        Text(text, color = if (enabled) color else color.copy(alpha = 0.4f), fontWeight = FontWeight.Bold, fontSize = 14.sp)
    }
}

/** An icon on a soft brand-coloured tile — the icon of every list row and card heading. */
@Composable
fun TileIcon(icon: ImageVector, tint: Color = Pal.accent, box: Dp = 40.dp) {
    Box(
        Modifier.size(box).clip(RoundedCornerShape(12.dp)).background(if (Pal.night) tint.copy(alpha = 0.16f) else tint.copy(alpha = 0.12f)),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(box * 0.5f))
    }
}

/** The on / off switch of the DORR app's settings. */
@Composable
fun DorrToggle(on: Boolean, onChange: (Boolean) -> Unit) {
    val thumb by animateDpAsState(if (on) 18.dp else 0.dp, spring(stiffness = Spring.StiffnessMediumLow), label = "thumb")
    val track by animateColorAsState(if (on) Pal.accent else if (Pal.night) Color(0xFF3A3F48) else Color(0xFFE5E7EB), tween(200), label = "track")
    // The thumb slides the same way in Arabic and English.
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Box(
            Modifier
                .size(width = 42.dp, height = 24.dp)
                .clip(RoundedCornerShape(12.dp))
                .background(track)
                .clickable { onChange(!on) },
        ) {
            Box(
                Modifier
                    .padding(2.dp)
                    .size(20.dp)
                    .offset(x = thumb)
                    .shadow(1.dp, CircleShape)
                    .clip(CircleShape)
                    .background(Color.White),
            )
        }
    }
}

/** One row of a list on a card: icon tile, title, a line under it, and a chevron — or [trailing] instead. */
@Composable
fun ListRow(
    icon: ImageVector,
    title: String,
    modifier: Modifier = Modifier,
    subtitle: String? = null,
    tint: Color = Pal.accent,
    badge: String? = null,
    trailing: (@Composable () -> Unit)? = null,
    onClick: (() -> Unit)? = null,
) {
    Row(
        modifier
            .fillMaxWidth()
            .then(if (onClick != null) Modifier.bounceClick(onClick = onClick) else Modifier)
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        TileIcon(icon, tint)
        Column(Modifier.weight(1f)) {
            Text(title, color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (subtitle != null) Text(subtitle, color = Pal.mut, fontSize = 12.sp, lineHeight = 16.sp, modifier = Modifier.padding(top = 2.dp))
        }
        if (badge != null) {
            Text(
                badge, color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(CircleShape).background(Pal.danger).padding(horizontal = 7.dp, vertical = 2.dp),
            )
        }
        if (trailing != null) trailing() else if (onClick != null) {
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Pal.faint, modifier = Modifier.size(22.dp))
        }
    }
}

/** A thin rule between rows. */
@Composable
fun RowDivider() {
    Box(Modifier.fillMaxWidth().padding(start = 66.dp).height(1.dp).background(Pal.line.copy(alpha = if (Pal.night) 1f else 0.6f)))
}

/** A small caption above a group of rows. */
@Composable
fun SectionLabel(text: String, modifier: Modifier = Modifier) {
    Text(
        text, color = Pal.mut, fontSize = 12.sp, fontWeight = FontWeight.Bold,
        modifier = modifier.padding(horizontal = 6.dp).padding(top = 6.dp),
    )
}

/** A rounded chip (a filter, a duration, a status). */
@Composable
fun Chip(text: String, selected: Boolean = false, tint: Color = Pal.accent, onClick: (() -> Unit)? = null) {
    val fill by animateColorAsState(if (selected) tint else tint.copy(alpha = if (Pal.night) 0.16f else 0.08f), tween(200), label = "chip")
    Text(
        text,
        color = if (selected) Color.White else tint,
        fontWeight = FontWeight.SemiBold,
        fontSize = 13.sp,
        modifier = Modifier
            .clip(RoundedCornerShape(50))
            .background(fill)
            .then(if (onClick != null) Modifier.bounceClick(onClick = onClick) else Modifier)
            .padding(horizontal = 16.dp, vertical = 8.dp),
    )
}

@Composable
fun HSpace(width: Dp) = Spacer(Modifier.width(width))

@Composable
fun VSpace(height: Dp) = Spacer(Modifier.height(height))

/** The same card the whole app uses, usable where a `Column` scope isn't wanted. */
@Composable
fun SurfaceColumn(modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    Column(modifier.fillMaxWidth().surface()) { content() }
}
