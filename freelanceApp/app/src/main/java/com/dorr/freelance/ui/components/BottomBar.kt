package com.dorr.freelance.ui.components

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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.ui.graphics.RectangleShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
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

/** One tab of the bottom bar. */
data class BottomTab(val label: Int, val icon: ImageVector, val activeIcon: ImageVector)

private val InactiveGray = Color(0xFF8E9BAE)

/** The DORR app flat bottom bar: a hairline on top, the app colour for the tab that is open, grey for the others. */
@Composable
fun BottomBar(tabs: List<BottomTab>, current: Int, onSelect: (Int) -> Unit, modifier: Modifier = Modifier) {
    val night = settingsNight()
    val barColor = if (night) AccountDark.bg else appearanceColor("background", Color.White, night = false)
    val edge = if (barColor.luminance() > 0.6f) Color.Black.copy(alpha = 0.07f) else Color.White.copy(alpha = 0.10f)
    Row(
        modifier = modifier
            .fillMaxWidth()
            .shadow(16.dp, RectangleShape, ambientColor = Color.Black.copy(alpha = 0.10f), spotColor = Color.Black.copy(alpha = 0.10f))
            .background(barColor)
            .drawBehind { drawLine(edge, Offset(0f, 0f), Offset(size.width, 0f), strokeWidth = 1.dp.toPx()) }
            .navigationBarsPadding()
            .height(64.dp)
            .padding(horizontal = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        tabs.forEachIndexed { index, tab ->
            val selected = index == current
            val tint = if (selected) settingsAccent() else InactiveGray
            Box(
                Modifier.weight(1f).height(58.dp).clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { onSelect(index) },
                contentAlignment = Alignment.Center,
            ) {
                Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                    Icon(if (selected) tab.activeIcon else tab.icon, contentDescription = stringResource(tab.label), tint = tint, modifier = Modifier.size(23.dp))
                    Spacer(Modifier.height(4.dp))
                    Text(stringResource(tab.label), fontSize = 11.sp, lineHeight = 13.sp, fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium, color = tint, maxLines = 1)
                }
            }
        }
    }
}
