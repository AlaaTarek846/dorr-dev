package com.dorr.app.ui.screens

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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.ui.components.DorrLogo
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.theme.AppColors
import com.dorr.app.ui.theme.LocalAppearance
import com.dorr.app.ui.theme.appearanceColor
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * 1:1 port of the reference app's splash: fade + scale-in (ease-out-back)
 * over a primary -> primaryDark gradient, then an auto-advance once the
 * animation settles. Real apps would resolve an auth/session check during
 * this hold instead of a fixed delay.
 */
@Composable
fun SplashScreen(onFinished: () -> Unit) {
    val inspecting = androidx.compose.ui.platform.LocalInspectionMode.current
    val alphaAnim = remember { Animatable(if (inspecting) 1f else 0f) }
    val scale = remember { Animatable(if (inspecting) 1f else 0.85f) }
    // The logo rises into place from below the centre while everything fades in.
    val rise = remember { Animatable(if (inspecting) 1f else 0f) }

    val appearance = LocalAppearance.current

    LaunchedEffect(Unit) {
        launch {
            runCatching { ApiClient.mobileAppearanceDefaults.show().data }.getOrNull()?.let { dto ->
                appearance.applyPlatform(dto.lightTokens, dto.darkTokens)
            }
        }
        launch { alphaAnim.animateTo(1f, tween(900)) }
        launch {
            scale.animateTo(
                1f,
                tween(900, easing = { fraction -> overshoot(fraction) }),
            )
        }
        launch { rise.animateTo(1f, tween(1100, easing = FastOutSlowInEasing)) }
        delay(1800)
        onFinished()
    }

    val night = settingsNight()
    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(24.dp)
                .graphicsLayer { alpha = alphaAnim.value }
                .scale(scale.value),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Spacer(Modifier.weight(3f))
            DorrLogo(
                width = 156.dp,
                onDark = night,
                modifier = Modifier.graphicsLayer {
                    translationY = (1f - rise.value) * 140.dp.toPx()
                    alpha = rise.value
                },
            )
            Spacer(Modifier.height(24.dp))
            Text(
                text = stringResource(R.string.app_name),
                color = if (night) AccountDark.accent else settingsAccent(),
                fontSize = 28.sp,
                fontWeight = FontWeight.ExtraBold,
            )
            Spacer(Modifier.height(12.dp))
            CircularProgressIndicator(
                color = if (night) AccountDark.accent else settingsAccent(),
                trackColor = (if (night) AccountDark.accent else settingsAccent()).copy(alpha = 0.18f),
                strokeWidth = 2.dp,
                modifier = Modifier.size(28.dp),
            )
            Spacer(Modifier.weight(3f))
            Text(
                text = stringResource(R.string.app_tagline),
                color = if (night) AccountDark.mut else appearanceColor("authTextMuted", AppColors.textSecondary, night = false),
                fontSize = 12.sp,
            )
            Spacer(Modifier.height(24.dp))
        }
    }
}


// Cubic approximation of Curves.easeOutBack from the reference animation.
private fun overshoot(x: Float): Float {
    val c1 = 1.70158f
    val c3 = c1 + 1f
    val t = x - 1f
    return 1f + c3 * t * t * t + c1 * t * t
}
