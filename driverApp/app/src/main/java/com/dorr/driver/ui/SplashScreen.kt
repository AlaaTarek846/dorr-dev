package com.dorr.driver.ui

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.ui.kit.DorrLogo
import com.dorr.driver.ui.kit.PinkBackdrop
import com.dorr.driver.ui.theme.DriverPrefs
import com.dorr.driver.ui.theme.Pal
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * The first thing on the screen, the same as the DORR app's: the logo rises and settles over the
 * glowing page while the platform's colours are read, then on to the sign-in or the shift.
 */
@Composable
fun SplashScreen(onFinished: () -> Unit) {
    val fade = remember { Animatable(0f) }
    val scale = remember { Animatable(0.85f) }
    val rise = remember { Animatable(0f) }

    LaunchedEffect(Unit) {
        launch {
            (apiCall { DriverBackend.api.appearanceDefaults() } as? ApiResult.Ok)?.data?.let { DriverPrefs.applyPlatform(it.lightTokens, it.darkTokens) }
        }
        launch { fade.animateTo(1f, tween(900)) }
        launch { scale.animateTo(1f, tween(900, easing = { overshoot(it) })) }
        launch { rise.animateTo(1f, tween(1100, easing = FastOutSlowInEasing)) }
        delay(1800)
        onFinished()
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        Column(
            Modifier
                .fillMaxSize()
                .padding(24.dp)
                .graphicsLayer { alpha = fade.value }
                .scale(scale.value),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Spacer(Modifier.weight(3f))
            DorrLogo(
                width = 156.dp,
                modifier = Modifier.graphicsLayer {
                    translationY = (1f - rise.value) * 140.dp.toPx()
                    alpha = rise.value
                },
            )
            Spacer(Modifier.height(24.dp))
            Text(stringResource(R.string.app_name), color = Pal.accent, fontSize = 28.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(12.dp))
            CircularProgressIndicator(color = Pal.accent, trackColor = Pal.accent.copy(alpha = 0.18f), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            Spacer(Modifier.weight(3f))
            Text(stringResource(R.string.app_tagline), color = Pal.mut, fontSize = 12.sp)
            Spacer(Modifier.height(24.dp))
        }
    }
}

/** Slightly overshoots 1 on the way in, then settles (the logo "lands"). */
private fun overshoot(x: Float): Float {
    val c1 = 1.70158f
    val c3 = c1 + 1f
    val t = x - 1f
    return 1f + c3 * t * t * t + c1 * t * t
}
