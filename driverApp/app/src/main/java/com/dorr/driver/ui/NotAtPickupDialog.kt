package com.dorr.driver.ui

import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.PinDrop
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import com.dorr.driver.R
import com.dorr.driver.ui.theme.Pal

/**
 * O-76: «Arrived» pressed while still away from the pickup — a clear dialog in the middle of the screen
 * (not a line at the bottom): how far, within how much it works, and the way there.
 */
@Composable
internal fun NotAtPickupDialog(distanceM: Int?, radiusM: Int?, message: String, pickupLat: Double?, pickupLng: Double?, onClose: () -> Unit) {
    val context = LocalContext.current
    val pulse = rememberInfiniteTransition(label = "pickup-pulse")
    val ring by pulse.animateFloat(1f, 1.25f, infiniteRepeatable(tween(900, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "ring")
    Dialog(onDismissRequest = onClose) {
        Column(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(28.dp)).background(Pal.card).padding(22.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Box(contentAlignment = Alignment.Center) {
                Box(Modifier.size(84.dp).scale(ring).clip(CircleShape).background(Pal.warning.copy(alpha = 0.18f)))
                Box(Modifier.size(60.dp).clip(CircleShape).background(Pal.warning), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.PinDrop, null, tint = androidx.compose.ui.graphics.Color.White, modifier = Modifier.size(32.dp))
                }
            }
            Text(stringResource(R.string.np_title), color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 20.sp, textAlign = TextAlign.Center)
            if (distanceM != null && radiusM != null) {
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Stat(stringResource(R.string.np_you_are), stringResource(R.string.np_metres, distanceM), Pal.warning, Modifier.weight(1f))
                    Stat(stringResource(R.string.np_works_within), stringResource(R.string.np_metres, radiusM), Pal.success, Modifier.weight(1f))
                }
                // How much of the way is left, as a bar.
                val done = (radiusM.toFloat() / distanceM.coerceAtLeast(1)).coerceIn(0.05f, 1f)
                Box(Modifier.fillMaxWidth().height(8.dp).clip(CircleShape).background(Pal.line)) {
                    Box(Modifier.fillMaxWidth(done).height(8.dp).clip(CircleShape).background(Pal.success))
                }
            } else {
                Text(message, color = Pal.mut, fontSize = 14.sp, textAlign = TextAlign.Center)
            }
            Spacer(Modifier.height(2.dp))
            if (pickupLat != null && pickupLng != null) {
                BigButton(stringResource(R.string.np_navigate), color = Pal.accent) { openNavigation(context, pickupLat, pickupLng); onClose() }
            }
            Text(
                stringResource(R.string.np_ok), color = Pal.accent, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(CircleShape).clickable(onClick = onClose).padding(horizontal = 18.dp, vertical = 8.dp),
            )
        }
    }
}

@Composable
private fun Stat(label: String, value: String, tint: androidx.compose.ui.graphics.Color, modifier: Modifier) {
    Column(modifier.clip(RoundedCornerShape(16.dp)).background(tint.copy(alpha = 0.1f)).padding(vertical = 10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Text(value, color = tint, fontWeight = FontWeight.Black, fontSize = 18.sp)
        Text(label, color = Pal.mut, fontSize = 11.sp)
    }
}
