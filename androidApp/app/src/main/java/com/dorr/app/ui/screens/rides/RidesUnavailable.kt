package com.dorr.app.ui.screens.rides

import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.LocationOff
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk

/**
 * "Rides aren't available here" — and why. The server says which of the two it is (`reason` of
 * `GET rides/features`): we can't tell where you are, or nobody has switched rides on for your country / city.
 */
@Composable
internal fun RidesUnavailablePanel(reason: String?, hasLocation: Boolean, onAskLocation: () -> Unit, onRetry: () -> Unit) {
    val pulse = rememberInfiniteTransition(label = "unavailable-pulse").animateFloat(
        initialValue = 1f, targetValue = 1.08f,
        animationSpec = infiniteRepeatable(tween(1100), RepeatMode.Reverse), label = "scale",
    )
    val noCountry = reason == "no_country"
    PanelCard {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(
                Modifier.size(72.dp).scale(pulse.value).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Rounded.LocationOff, null, tint = settingsAccent(), modifier = Modifier.size(34.dp))
            }
            Text(stringResource(R.string.rd_na_title), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 18.sp, textAlign = TextAlign.Center)
            Text(
                stringResource(if (noCountry) R.string.rd_na_no_country else R.string.rd_na_not_enabled),
                color = settingsInk().copy(alpha = 0.65f), fontSize = 14.sp, textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(6.dp))
            if (noCountry && !hasLocation) {
                AccentButton(stringResource(R.string.rd_allow), onClick = onAskLocation)
            } else {
                AccentButton(stringResource(R.string.rd_retry), onClick = onRetry)
            }
        }
    }
}
