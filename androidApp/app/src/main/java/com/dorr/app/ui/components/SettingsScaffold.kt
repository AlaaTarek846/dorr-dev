package com.dorr.app.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.theme.AppColors

/**
 * The soft pink page wash every design screen sits on (login, account, profile sub-screens):
 * three overlapping radial glows fading into white.
 */
fun Modifier.dorrPageWash(): Modifier = drawBehind {
    drawRect(Color.White)
    drawRect(
        Brush.radialGradient(
            colors = listOf(AppColors.otpGlowDeep, AppColors.otpGlowSoft, Color.Transparent),
            center = Offset(size.width * -0.08f, size.height * -0.12f),
            radius = size.width * 1.3f,
        ),
    )
    drawRect(
        Brush.radialGradient(
            colors = listOf(AppColors.otpPinkBorder, Color.Transparent),
            center = Offset(size.width * 0.5f, size.height * -0.18f),
            radius = size.width * 1.1f,
        ),
    )
    drawRect(
        Brush.radialGradient(
            colors = listOf(AppColors.otpGlowMist, Color.Transparent),
            center = Offset(size.width * 1.12f, size.height * -0.08f),
            radius = size.width * 0.9f,
        ),
    )
}

/**
 * Shared "sub-screen with a back button" shell for everything pushed from Account — the design's
 * profile sub-screen: the pink page wash, a big bold red title at the start, and a small round white
 * back button (red chevron, soft red shadow) at the end. No app bar, no divider.
 *
 * The status bar inset is handled by the enclosing main screen, so none is added here.
 */
@Composable
fun SettingsScaffold(
    title: String,
    onBack: () -> Unit,
    content: @Composable (PaddingValues) -> Unit,
) {
    Column(Modifier.fillMaxSize().dorrPageWash()) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(start = 14.dp, end = 14.dp, top = 14.dp, bottom = 8.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                text = title,
                color = AppColors.waRed,
                fontSize = 22.sp,
                fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.weight(1f),
            )
            DorrBackButton(onBack)
        }
        Box(Modifier.weight(1f).fillMaxWidth()) { content(PaddingValues(0.dp)) }
    }
}

/** The round white back button of the design (chevron points to the end edge, like the reference). */
@Composable
fun DorrBackButton(onClick: () -> Unit, modifier: Modifier = Modifier) {
    Box(
        modifier
            .size(34.dp)
            .shadow(8.dp, CircleShape, ambientColor = AppColors.waRed.copy(alpha = 0.10f), spotColor = AppColors.waRed.copy(alpha = 0.16f))
            .clip(CircleShape)
            .background(Color.White)
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(
            Icons.AutoMirrored.Rounded.KeyboardArrowRight,
            contentDescription = stringResource(R.string.common_back),
            tint = AppColors.waRed,
            modifier = Modifier.size(20.dp),
        )
    }
}
