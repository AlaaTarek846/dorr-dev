package com.dorr.app.ui.screens

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalInspectionMode
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.theme.AppColors
import com.dorr.app.ui.theme.LocalAppearance
import com.dorr.app.ui.theme.appearanceColor
import kotlinx.coroutines.async
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull

/** Shortest and longest time on the splash: long enough for the motion, never waiting on a slow network. */
private const val MIN_SPLASH_MS = 800L
private const val MAX_SPLASH_MS = 1500L

/**
 * The app's own splash. It starts exactly where the system splash leaves off — the plain background (white, or the
 * app's dark one) with the Dorr mark in a 160dp circle (#FDF1EC by day, #1F2937 by night; the mark 96dp —
 * res/drawable-*dpi/splash_icon.png, splash_background.xml) — so the two read as one screen. Then the app's backdrop fades
 * in, the mark lifts a little and the name and tagline appear under it. It moves on once
 * the platform colours are here (at least [MIN_SPLASH_MS], at most [MAX_SPLASH_MS]). No spinner.
 */
@Composable
fun SplashScreen(onFinished: () -> Unit) {
    val inspecting = LocalInspectionMode.current
    val lift = remember { Animatable(if (inspecting) 1f else 0f) }
    val reveal = remember { Animatable(if (inspecting) 1f else 0f) }
    val appearance = LocalAppearance.current

    LaunchedEffect(Unit) {
        val started = System.currentTimeMillis()
        // The very first launch has no colours saved yet: the coloured part (backdrop, name) waits for the server's,
        // so the first colours seen are the real ones. Every later launch has them saved and shows them at once.
        val firstTime = com.dorr.app.network.AppearanceStore.loadPlatformLight().isNullOrEmpty()
        val defaults = async {
            runCatching { ApiClient.mobileAppearanceDefaults.show().data }.getOrNull()?.let { dto ->
                appearance.applyPlatform(dto.lightTokens, dto.darkTokens)
            }
        }
        launch { delay(120); lift.animateTo(1f, tween(650, easing = FastOutSlowInEasing)) }
        if (!firstTime) launch { delay(200); reveal.animateTo(1f, tween(550)) }
        withTimeoutOrNull(MAX_SPLASH_MS) { defaults.await() }
        if (firstTime) reveal.animateTo(1f, tween(450))
        val left = MIN_SPLASH_MS - (System.currentTimeMillis() - started)
        if (left > 0) delay(left)
        onFinished()
    }

    val night = settingsNight()
    // The system splash's colour (res/values[-night]/splash_colors.xml): the first frame matches it exactly.
    val plain = if (night) Color(0xFF111928) else Color.White
    Box(Modifier.fillMaxSize().background(plain), contentAlignment = Alignment.Center) {
        PinkBackdrop(Modifier.matchParentSize().graphicsLayer { alpha = reveal.value })
        // The mark in its circle (the system splash's colours, res/values[-night]/splash_colors.xml): light mark by day,
        // dark one (white R) by night.
        Box(
            modifier = Modifier
                .graphicsLayer { translationY = -lift.value * 44.dp.toPx() }
                .size(160.dp)
                .clip(CircleShape)
                .background(if (night) Color(0xFF1F2937) else Color(0xFFFDF1EC)),
            contentAlignment = Alignment.Center,
        ) {
            Image(
                painter = painterResource(if (night) R.drawable.dorr_logo_mark_dark else R.drawable.dorr_logo_mark_light),
                contentDescription = null,
                contentScale = ContentScale.Fit,
                // Both sides (the mark is 200×170): with the width alone the small image kept its own height and came
                // out ~66dp — smaller than the system splash's 96, so it shrank at the hand-over.
                modifier = Modifier.size(width = 96.dp, height = 82.dp),
            )
        }
        // The name under the circle once it has lifted.
        Text(
            text = stringResource(R.string.app_name),
            color = if (night) AccountDark.accent else settingsAccent(),
            fontSize = 26.sp,
            fontWeight = FontWeight.ExtraBold,
            modifier = Modifier.graphicsLayer {
                alpha = reveal.value
                translationY = (66.dp + 12.dp * (1f - reveal.value)).toPx()
            },
        )
        Text(
            text = stringResource(R.string.app_tagline),
            color = if (night) AccountDark.mut else appearanceColor("authTextMuted", AppColors.textSecondary, night = false),
            fontSize = 12.sp,
            modifier = Modifier.align(Alignment.BottomCenter).navigationBarsPadding().padding(bottom = 24.dp)
                .graphicsLayer { alpha = reveal.value },
        )
    }
}
