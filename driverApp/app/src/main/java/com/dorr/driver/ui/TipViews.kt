package com.dorr.driver.ui

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Favorite
import androidx.compose.material.icons.filled.VolunteerActivism
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.net.CurrencyDto

/**
 * D-204: the tips of the chosen period on their own card — all of them the captain's, no commission.
 * The amount counts up when the period changes.
 */
@Composable
internal fun TipsCard(tipsMinor: Long, currency: CurrencyDto?) {
    val shown by animateFloatAsState(tipsMinor.toFloat(), tween(700), label = "tips")
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(44.dp).clip(CircleShape).background(DorrBrand.orange.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Filled.VolunteerActivism, null, tint = DorrBrand.orange, modifier = Modifier.size(24.dp))
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.earn_tips), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                Text(money(shown.toLong(), currency), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp, color = DorrBrand.orange)
            }
            Text(
                stringResource(R.string.earn_tips_all_yours), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, color = DorrBrand.success,
                modifier = Modifier.clip(RoundedCornerShape(50)).background(DorrBrand.success.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
            )
        }
    }
}

/** D-182: "The rider tipped you" on the trip's receipt, apart from the fare — the heart pops in. */
@Composable
internal fun TipBanner(tipMinor: Long, currency: CurrencyDto?) {
    val pop = remember { Animatable(0.4f) }
    LaunchedEffect(tipMinor) { pop.animateTo(1f, spring(Spring.DampingRatioHighBouncy, Spring.StiffnessLow)) }
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(DorrBrand.orange.copy(alpha = 0.1f)).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Filled.Favorite, null, tint = DorrBrand.orange, modifier = Modifier.size(28.dp).scale(pop.value))
        Spacer(Modifier.width(10.dp))
        Column {
            Text(stringResource(R.string.trip_tip_received, money(tipMinor, currency)), fontWeight = FontWeight.Bold, fontSize = 15.sp)
            Text(stringResource(R.string.trip_tip_all_yours), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
        }
    }
}
