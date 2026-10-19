package com.dorr.app.ui.screens.rides

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.material.icons.rounded.Female
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Male
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.RideCurrencyDto
import com.dorr.app.network.RidePoolDto
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut

private val Teal = Color(0xFF00897B)

/**
 * «أنا معك» on the booking sheet (O-30..O-33): riders going the same way share the car and the fare;
 * the rider chooses who may share with them — anyone, men only, or women only.
 */
@Composable
internal fun ShareWithRow(state: RidesState) {
    Column(Modifier.fillMaxWidth().padding(vertical = 6.dp).clip(RoundedCornerShape(16.dp)).background(Teal.copy(alpha = 0.07f)).padding(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Groups, null, tint = Teal, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.wm_share_title), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
        }
        Text(stringResource(R.string.wm_share_hint), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp, bottom = 8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Option(Icons.Rounded.Groups, stringResource(R.string.wm_share_any), state.shareWith == "any", Modifier.weight(1f)) { state.shareWith = "any" }
            Option(Icons.Rounded.Male, stringResource(R.string.wm_share_male), state.shareWith == "male", Modifier.weight(1f)) { state.shareWith = "male" }
            Option(Icons.Rounded.Female, stringResource(R.string.wm_share_female), state.shareWith == "female", Modifier.weight(1f)) { state.shareWith = "female" }
        }
    }
}

@Composable
private fun Option(icon: ImageVector, label: String, on: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val bg by animateColorAsState(if (on) Teal else Color.Transparent, tween(200), label = "share")
    Column(
        modifier.clip(RoundedCornerShape(12.dp)).background(bg).pressable(onClick = onClick).padding(vertical = 8.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(icon, null, tint = if (on) Color.White else Teal, modifier = Modifier.size(20.dp))
        Text(label, color = if (on) Color.White else settingsInk(), fontSize = 12.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Normal)
    }
}

/** On the ride: «a shared ride — N in the car — you saved X». */
@Composable
internal fun SharedRideBanner(pool: RidePoolDto, currency: RideCurrencyDto?) {
    Row(
        Modifier.fillMaxWidth().padding(bottom = 10.dp).clip(RoundedCornerShape(14.dp)).background(Teal.copy(alpha = 0.1f)).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(36.dp).clip(CircleShape).background(Teal), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Groups, null, tint = Color.White, modifier = Modifier.size(20.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column {
            Text(stringResource(R.string.wm_shared_ride, pool.riders), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp)
            if (pool.discountMinor > 0) Text(stringResource(R.string.wm_saved, money(pool.discountMinor, currency)), color = Teal, fontWeight = FontWeight.SemiBold, fontSize = 12.sp)
        }
    }
}
