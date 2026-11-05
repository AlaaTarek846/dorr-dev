package com.dorr.freelance.ui.components

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.RectangleShape
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.theme.appearanceColor

/** One tab of the bottom bar: its [key] (see [Tabs]), and a count of what waits there ([badge], 0 = none). */
data class BottomTab(val key: String, val label: Int, val icon: ImageVector, val activeIcon: ImageVector, val badge: Int = 0)

private val InactiveGray = Color(0xFF8E9BAE)

/**
 * The flat bottom bar: a hairline on top; the open tab gets a soft pill of the app colour behind its icon and a bold label, the others are
 * grey. A tab with something waiting carries a small count.
 */
@Composable
fun BottomBar(tabs: List<BottomTab>, current: String, onSelect: (String) -> Unit, modifier: Modifier = Modifier) {
    val night = settingsNight()
    val haptics = androidx.compose.ui.platform.LocalHapticFeedback.current
    val density = androidx.compose.ui.platform.LocalDensity.current
    // A very large phone font would push the labels onto two lines: the bar keeps them at most 1.25× (the pages keep the full size).
    val capped = androidx.compose.ui.unit.Density(density.density, density.fontScale.coerceAtMost(1.25f))
    val accent = settingsAccent()
    val barColor = if (night) AccountDark.bg else appearanceColor("background", Color.White, night = false)
    val edge = if (barColor.luminance() > 0.6f) Color.Black.copy(alpha = 0.07f) else Color.White.copy(alpha = 0.10f)
    Row(
        modifier = modifier
            .fillMaxWidth()
            .shadow(16.dp, RectangleShape, ambientColor = Color.Black.copy(alpha = 0.10f), spotColor = Color.Black.copy(alpha = 0.10f))
            .background(barColor)
            .drawBehind { drawLine(edge, Offset(0f, 0f), Offset(size.width, 0f), strokeWidth = 1.dp.toPx()) }
            .navigationBarsPadding()
            .height(66.dp)
            .padding(horizontal = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        tabs.forEach { tab ->
            val selected = tab.key == current
            val tint by animateColorAsState(if (selected) accent else InactiveGray, tween(220), label = "tabTint")
            val pill by animateDpAsState(if (selected) 52.dp else 0.dp, tween(260), label = "tabPill")
            Box(
                Modifier.weight(1f).height(60.dp)
                    .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, role = androidx.compose.ui.semantics.Role.Tab) {
                        if (tab.key != current) haptics.performHapticFeedback(androidx.compose.ui.hapticfeedback.HapticFeedbackType.TextHandleMove)
                        onSelect(tab.key)
                    },
                contentAlignment = Alignment.Center,
            ) {
                Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                    Box(contentAlignment = Alignment.Center) {
                        Box(Modifier.width(pill).height(30.dp).clip(RoundedCornerShape(50)).background(accent.copy(alpha = if (night) 0.22f else 0.12f)))
                        Icon(if (selected) tab.activeIcon else tab.icon, contentDescription = stringResource(tab.label), tint = tint, modifier = Modifier.size(23.dp))
                        if (tab.badge > 0) CountBadge(
                            tab.badge, background = accent, ink = Color.White, edge = barColor,
                            modifier = Modifier.align(Alignment.TopEnd).offset(x = 12.dp, y = (-4).dp),
                        )
                    }
                    Spacer(Modifier.height(3.dp))
                    androidx.compose.runtime.CompositionLocalProvider(androidx.compose.ui.platform.LocalDensity provides capped) {
                        Text(stringResource(tab.label), fontSize = 11.sp, lineHeight = 13.sp, fontWeight = if (selected) FontWeight.ExtraBold else FontWeight.Medium, color = tint, maxLines = 1, softWrap = false)
                    }
                }
            }
        }
    }
}
