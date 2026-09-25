package com.dorr.app.ui.components

import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.dorr.app.ui.theme.AppColors

/** Growing-pill page indicator — same shape/timing as the reference app's banner + carousel dots. */
@Composable
fun DotIndicator(count: Int, activeIndex: Int, modifier: Modifier = Modifier) {
    Row(modifier = modifier, horizontalArrangement = Arrangement.Center) {
        repeat(count) { index ->
            val active = index == activeIndex
            val width by animateDpAsState(if (active) 22.dp else 8.dp, tween(300), label = "dotWidth")
            Box(
                modifier = Modifier
                    .padding(horizontal = 3.dp)
                    .width(width)
                    .height(8.dp)
                    .background(
                        if (active) AppColors.waRed else AppColors.border,
                        RoundedCornerShape(4.dp),
                    ),
            )
        }
    }
}
