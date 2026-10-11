package com.dorr.app.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.dorr.app.ui.screens.profile.settingsAccent

/**
 * Banner page dots — selected looks like a radio: a filled centre inside a ring.
 * Inactive are small grey circles.
 */
@Composable
fun DotIndicator(count: Int, activeIndex: Int, modifier: Modifier = Modifier) {
    val accent = settingsAccent()
    Row(
        modifier = modifier,
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        repeat(count) { index ->
            if (index == activeIndex) {
                Box(
                    modifier = Modifier
                        .padding(horizontal = 5.dp)
                        .size(12.dp)
                        .border(1.6.dp, accent, CircleShape),
                    contentAlignment = Alignment.Center,
                ) {
                    Box(
                        modifier = Modifier
                            .size(6.dp)
                            .clip(CircleShape)
                            .background(accent),
                    )
                }
            } else {
                Box(
                    modifier = Modifier
                        .padding(horizontal = 5.dp)
                        .size(6.dp)
                        .clip(CircleShape)
                        .background(Color(0xFFD1D5DB)),
                )
            }
        }
    }
}
