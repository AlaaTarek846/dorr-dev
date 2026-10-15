package com.dorr.app.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.layout
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import com.dorr.app.ui.theme.LocalThemeState
import com.dorr.app.ui.theme.appearanceColor

/** Stretch past WaPage's 16.dp side padding. Compose forbids negative padding and crashes. */
fun Modifier.bleedHorizontal(amount: Dp): Modifier = layout { measurable, constraints ->
    if (!constraints.hasBoundedWidth) {
        val placeable = measurable.measure(constraints)
        layout(placeable.width, placeable.height) { placeable.place(0, 0) }
    } else {
        val bleed = amount.roundToPx()
        val width = (constraints.maxWidth + bleed * 2).coerceAtLeast(0)
        val placeable = measurable.measure(constraints.copy(minWidth = width, maxWidth = width))
        layout(constraints.maxWidth, placeable.height) { placeable.place(-bleed, 0) }
    }
}

/** Navy + brand-red panel with a rounded bottom — calendar, Moments, and the same family of pages. Follows the in-app light/dark tokens (same source as Home). */
@Composable
fun DarkHeroPanel(content: @Composable ColumnScope.() -> Unit) {
    val night = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    val brush = if (night) {
        val accent = appearanceColor("primary", Color(0xFFFA7552), night = true)
        val mid = Color(accent.red * 0.78f, accent.green * 0.78f, accent.blue * 0.78f)
        val deep = Color(accent.red * 0.45f, accent.green * 0.45f, accent.blue * 0.45f)
        Brush.verticalGradient(listOf(accent, mid, deep))
    } else {
        val primary = appearanceColor("primary", Color(0xFF001B53), night = false)
        val primaryLight = appearanceColor("primaryLight", Color(0xFF1E3A7B), night = false)
        val primaryDark = appearanceColor("primaryDark", Color(0xFF00113A), night = false)
        val accent = appearanceColor("accent", Color(0xFFFA7552), night = false)
        val top = Color(
            red = (primaryLight.red * 0.78f + accent.red * 0.22f).coerceIn(0f, 1f),
            green = (primaryLight.green * 0.78f + accent.green * 0.22f).coerceIn(0f, 1f),
            blue = (primaryLight.blue * 0.78f + accent.blue * 0.22f).coerceIn(0f, 1f),
        )
        Brush.verticalGradient(listOf(top, primary, primaryDark))
    }
    Box(
        Modifier
            .fillMaxWidth()
            .bleedHorizontal(16.dp)
            .clip(RoundedCornerShape(bottomStart = 36.dp, bottomEnd = 36.dp))
            .background(brush),
    ) {
        Column(Modifier.padding(start = 16.dp, end = 16.dp, top = 8.dp, bottom = 12.dp), content = content)
    }
}

@Composable
fun DarkHeroButton(icon: ImageVector, onClick: () -> Unit, contentDescription: String, flip: Boolean = false) {
    Box(
        Modifier
            .size(34.dp)
            .clip(CircleShape)
            .background(Color.White.copy(alpha = 0.12f))
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(
            icon,
            contentDescription,
            tint = Color.White,
            modifier = Modifier
                .size(16.dp)
                .then(if (flip) Modifier.graphicsLayer { scaleX = -1f } else Modifier),
        )
    }
}
