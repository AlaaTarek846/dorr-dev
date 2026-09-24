package com.dorr.app.ui.screens

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.theme.AppColors
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
    val alphaAnim = remember { Animatable(0f) }
    val scale = remember { Animatable(0.85f) }

    LaunchedEffect(Unit) {
        launch { alphaAnim.animateTo(1f, tween(900)) }
        launch {
            scale.animateTo(
                1f,
                tween(900, easing = { fraction -> overshoot(fraction) }),
            )
        }
        delay(1600)
        onFinished()
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(listOf(AppColors.primary, AppColors.primaryDark)),
            ),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(24.dp)
                .graphicsLayer { alpha = alphaAnim.value }
                .scale(scale.value),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Spacer(Modifier.weight(3f))
            Box(
                modifier = Modifier
                    .background(Color.White.copy(alpha = 0.08f), RoundedCornerShape(24.dp))
                    .border(1.dp, Color.White.copy(alpha = 0.15f), RoundedCornerShape(24.dp))
                    .padding(28.dp),
            ) {
                Icon(
                    imageVector = Icons.Rounded.Build,
                    contentDescription = null,
                    tint = Color.White,
                    modifier = Modifier.size(72.dp),
                )
            }
            Spacer(Modifier.height(24.dp))
            Text(
                text = stringResource(R.string.app_name).uppercase(),
                color = Color.White,
                fontSize = 32.sp,
                fontWeight = FontWeight.Black,
                letterSpacing = 6.sp,
            )
            Spacer(Modifier.height(60.dp))
            CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            Spacer(Modifier.weight(3f))
            Text(
                text = stringResource(R.string.app_tagline),
                color = Color.White.copy(alpha = 0.5f),
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
