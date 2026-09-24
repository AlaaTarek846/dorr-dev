package com.dorr.app.ui.components

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.unit.dp
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay

/**
 * Auto-advancing promo carousel (5s interval, matches the reference app).
 * [slides] is a placeholder list of gradient cards with a title overlay —
 * wire it to a real banners endpoint later, same shape as [DotIndicator].
 */
@Composable
fun HeroBannerSlider(slides: List<String>, modifier: Modifier = Modifier) {
    if (slides.isEmpty()) return
    val pagerState = rememberPagerState(pageCount = { slides.size })

    LaunchedEffect(pagerState) {
        while (true) {
            delay(5000)
            val next = (pagerState.currentPage + 1) % slides.size
            pagerState.animateScrollToPage(next)
        }
    }

    Column(modifier = modifier) {
        HorizontalPager(state = pagerState, modifier = Modifier.height(160.dp)) { page ->
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 4.dp)
                    .clip(RoundedCornerShape(16.dp))
                    .background(Brush.linearGradient(listOf(AppColors.primary, AppColors.primaryLight))),
            ) {
                Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .background(
                            Brush.verticalGradient(
                                listOf(Color.Transparent, Color.Black.copy(alpha = 0.55f)),
                            ),
                        ),
                    contentAlignment = Alignment.BottomStart,
                ) {
                    Text(
                        text = slides[page],
                        color = Color.White,
                        modifier = Modifier.padding(16.dp),
                    )
                }
            }
        }
        if (slides.size > 1) {
            DotIndicator(
                count = slides.size,
                activeIndex = pagerState.currentPage,
                modifier = Modifier
                    .padding(top = 10.dp)
                    .fillMaxWidth(),
            )
        }
    }
}
