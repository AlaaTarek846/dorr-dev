package com.dorr.app.ui.components

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
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
 * Gradients are a 1:1 port of the preview's .banner-slide backgrounds.
 */
private fun bannerGradient(page: Int): List<Color> = when (page % 3) {
    1 -> listOf(Color(0xFFC40812), AppColors.waRed)
    2 -> listOf(Color(0xFF111928), Color(0xFF374151))
    else -> listOf(AppColors.waRed, Color(0xFFFF4D55))
}

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
        HorizontalPager(state = pagerState, modifier = Modifier.height(148.dp)) { page ->
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 4.dp)
                    .shadow(8.dp, RoundedCornerShape(14.dp), spotColor = AppColors.waRed.copy(alpha = 0.12f))
                    .clip(RoundedCornerShape(14.dp))
                    .background(Brush.linearGradient(bannerGradient(page))),
            ) {
                Box(
                    modifier = Modifier
                        .fillMaxSize(),
                    contentAlignment = Alignment.BottomStart,
                ) {
                    Text(
                        text = slides[page],
                        color = Color.White,
                        fontWeight = FontWeight.ExtraBold,
                        fontSize = 16.sp,
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
