package com.dorr.driver.ui.kit

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.windowInsetsBottomHeight
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.driver.R
import com.dorr.driver.ui.theme.CairoFamily
import com.dorr.driver.ui.theme.Pal

/** The page behind every screen: the page colour with the brand colour glowing in from the top — as in the DORR app. */
@Composable
fun PinkBackdrop(modifier: Modifier = Modifier) {
    val glow = Pal.accent
    val base = Pal.bg
    Canvas(modifier) {
        drawRect(base)
        drawCircle(
            brush = Brush.radialGradient(
                colors = listOf(glow.copy(alpha = 0.35f), glow.copy(alpha = 0.10f), Color.Transparent),
                center = Offset(size.width * 0.5f, size.height * -0.08f),
                radius = size.width * 0.85f,
            ),
            radius = size.width * 0.85f,
            center = Offset(size.width * 0.5f, size.height * -0.08f),
        )
        drawCircle(
            brush = Brush.radialGradient(
                colors = listOf(glow.copy(alpha = 0.20f), Color.Transparent),
                center = Offset(size.width * 1.05f, size.height * 0.02f),
                radius = size.width * 0.55f,
            ),
            radius = size.width * 0.55f,
            center = Offset(size.width * 1.05f, size.height * 0.02f),
        )
    }
}

/** The DORR wordmark: the navy one on light pages, the white one at night. */
@Composable
fun DorrLogo(width: Dp, modifier: Modifier = Modifier) {
    Image(
        painter = painterResource(if (Pal.night) R.drawable.dorr_logo_dark else R.drawable.dorr_logo_light),
        contentDescription = null,
        contentScale = ContentScale.Fit,
        modifier = modifier.width(width),
    )
}

/**
 * The header of every inner screen, as in the DORR app: the title in the brand colour and a round back
 * button. [actions] sit between them.
 */
@Composable
fun ScreenHeader(title: String, onBack: (() -> Unit)?, modifier: Modifier = Modifier, actions: (@Composable () -> Unit)? = null) {
    Row(
        modifier
            .fillMaxWidth()
            .statusBarsPadding()
            .padding(horizontal = 14.dp)
            .padding(top = 14.dp, bottom = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Text(
            title, color = Pal.accent, fontSize = 22.sp, fontWeight = FontWeight.Black, fontFamily = CairoFamily,
            maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f),
        )
        actions?.invoke()
        if (onBack != null) RoundIconButton(Icons.AutoMirrored.Rounded.ArrowBack, stringResource(R.string.back), onBack)
    }
}

/** A round white (or night-card) button with an icon in the brand colour. */
@Composable
fun RoundIconButton(icon: ImageVector, description: String?, onClick: () -> Unit, tint: Color = Pal.accent, badge: Boolean = false) {
    Box(
        Modifier
            .size(36.dp)
            .then(if (Pal.night) Modifier.border(1.dp, Pal.line, CircleShape) else Modifier.shadow(6.dp, CircleShape, ambientColor = Color(0x14001B53), spotColor = Color(0x14001B53)))
            .clip(CircleShape)
            .background(Pal.card)
            .bounceClick(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, contentDescription = description, tint = tint, modifier = Modifier.size(18.dp))
        if (badge) Box(Modifier.align(Alignment.TopEnd).padding(7.dp).size(8.dp).clip(CircleShape).background(Pal.danger))
    }
}

enum class BannerKind { Error, Info, Success, Warning }

/** A message in a tinted box, sliding in and out — instead of a bare red line of text. */
@Composable
fun Banner(message: String?, modifier: Modifier = Modifier, kind: BannerKind = BannerKind.Error) {
    AnimatedVisibility(
        visible = message != null,
        enter = fadeIn(tween(200)) + expandVertically(tween(240)),
        exit = fadeOut(tween(160)) + shrinkVertically(tween(200)),
        modifier = modifier,
    ) {
        val tint = when (kind) {
            BannerKind.Error -> Pal.danger
            BannerKind.Info -> Pal.accent
            BannerKind.Success -> Pal.success
            BannerKind.Warning -> Pal.warning
        }
        val icon = when (kind) {
            BannerKind.Error -> Icons.Rounded.ErrorOutline
            BannerKind.Info -> Icons.Rounded.Info
            BannerKind.Success -> Icons.Rounded.CheckCircle
            BannerKind.Warning -> Icons.Rounded.WarningAmber
        }
        val shape = RoundedCornerShape(14.dp)
        Row(
            Modifier
                .fillMaxWidth()
                .clip(shape)
                .background(tint.copy(alpha = if (Pal.night) 0.16f else 0.10f))
                .border(1.dp, tint.copy(alpha = if (Pal.night) 0.4f else 0.28f), shape)
                .padding(horizontal = 14.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp))
            Text(message.orEmpty(), color = if (Pal.night) Pal.ink else tint, fontSize = 13.sp, fontWeight = FontWeight.Medium, lineHeight = 18.sp, modifier = Modifier.weight(1f))
        }
    }
}

/** One tab of the bottom bar. */
class NavTab(
    val route: String,
    val label: Int,
    val outlined: ImageVector,
    val filled: ImageVector,
    val badge: Int = 0,
)

private val InactiveTab = Color(0xFF8E9BAE)

/**
 * The bottom bar of the DORR app: a white bar with a hairline on top, outlined icons that fill and rise
 * when chosen, a soft pill behind the chosen one, and the label in the brand colour.
 */
@Composable
fun DriverBottomBar(tabs: List<NavTab>, selected: String, onSelect: (String) -> Unit) {
    val line = Pal.line
    Column(
        Modifier
            .fillMaxWidth()
            .shadow(16.dp)
            .background(Pal.card),
    ) {
        Box(Modifier.fillMaxWidth().height(1.dp).background(line))
        Row(Modifier.fillMaxWidth().height(64.dp)) {
            tabs.forEach { tab ->
                val active = tab.route == selected
                val tint by animateColorAsState(if (active) Pal.accent else InactiveTab, tween(200), label = "tab-tint")
                val lift by animateDpAsState(if (active) (-2).dp else 0.dp, spring(stiffness = Spring.StiffnessMedium), label = "tab-lift")
                val pill by animateFloatAsState(if (active) 1f else 0f, tween(240), label = "tab-pill")
                val pop by animateFloatAsState(if (active) 1.08f else 1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy), label = "tab-pop")
                Column(
                    Modifier
                        .weight(1f)
                        .fillMaxSize()
                        .bounceClick { onSelect(tab.route) },
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center,
                ) {
                    Box(
                        Modifier.height(28.dp).width(52.dp).graphicsLayer { translationY = lift.toPx(); scaleX = pop; scaleY = pop },
                        contentAlignment = Alignment.Center,
                    ) {
                        Box(
                            Modifier
                                .height(28.dp)
                                .width(52.dp)
                                .graphicsLayer { alpha = pill; scaleX = 0.5f + 0.5f * pill }
                                .clip(RoundedCornerShape(50))
                                .background(Pal.accent.copy(alpha = if (Pal.night) 0.2f else 0.12f)),
                        )
                        Icon(if (active) tab.filled else tab.outlined, null, tint = tint, modifier = Modifier.size(22.dp))
                        if (tab.badge > 0) {
                            Text(
                                if (tab.badge > 9) "9+" else tab.badge.toString(),
                                color = Color.White, fontSize = 9.sp, fontWeight = FontWeight.Bold,
                                modifier = Modifier.align(Alignment.TopEnd).clip(CircleShape).background(Pal.danger).padding(horizontal = 5.dp, vertical = 1.dp),
                            )
                        }
                    }
                    Text(stringResource(tab.label), color = tint, fontSize = 11.sp, fontWeight = if (active) FontWeight.Bold else FontWeight.Medium, maxLines = 1)
                }
            }
        }
        Spacer(Modifier.windowInsetsBottomHeight(WindowInsets.navigationBars))
    }
}

/** A small soft circle behind a decorative icon (empty states, hero spots). */
@Composable
fun HeroBadge(icon: ImageVector, modifier: Modifier = Modifier, tint: Color = Pal.accent, size: Dp = 72.dp, scale: Float = 1f) {
    Box(
        modifier.size(size).scale(scale).clip(CircleShape).background(tint.copy(alpha = if (Pal.night) 0.18f else 0.12f)),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(size * 0.48f))
    }
}

/**
 * The frame of an inner screen: the glowing page, the header with its back button, then the content under it.
 * `inline` so a screen can still `return` early from its content, as it could inside a plain `Column`.
 */
@Composable
inline fun ScreenScaffold(
    title: String,
    noinline onBack: (() -> Unit)?,
    modifier: Modifier = Modifier,
    noinline actions: (@Composable () -> Unit)? = null,
    content: @Composable androidx.compose.foundation.layout.ColumnScope.() -> Unit,
) {
    Box(modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        Column(Modifier.fillMaxSize()) {
            ScreenHeader(title, onBack, actions = actions)
            content()
        }
    }
}
