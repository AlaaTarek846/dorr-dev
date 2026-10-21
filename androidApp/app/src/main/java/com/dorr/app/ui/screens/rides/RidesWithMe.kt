package com.dorr.app.ui.screens.rides

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
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

/**
 * O-17: «by the distance | by the hour» under the categories, and the hours (− / +). Switching re-prices
 * that category (hours × its hour price); back to the distance brings every category back.
 */
@Composable
internal fun HourlyRow(state: RidesState, quote: com.dorr.app.network.RideQuoteDto) {
    val offer = quote.hourlyOffer ?: return
    val accent = settingsAccent()
    val hourly = state.hourlyHours != null
    fun price(hours: Int?) {
        state.hourlyHours = hours
        state.hourlySlug = if (hours == null) null else quote.category.slug
        loadQuotes(state, reprice = true)
    }
    Column(Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(16.dp)).background(accent.copy(alpha = 0.06f)).padding(10.dp)) {
        Row(Modifier.fillMaxWidth().clip(CircleShape).background(settingsCard()).padding(3.dp)) {
            listOf(false to R.string.hr_by_distance, true to R.string.hr_by_hour).forEach { (byHour, label) ->
                val on = hourly == byHour
                val bg by androidx.compose.animation.animateColorAsState(if (on) accent else Color.Transparent, androidx.compose.animation.core.tween(200), label = "hourly")
                Text(
                    stringResource(label), color = if (on) Color.White else settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp,
                    textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                    modifier = Modifier.weight(1f).clip(CircleShape).background(bg)
                        .clickable(enabled = !state.busy && !on) { price(if (byHour) offer.minHours else null) }.padding(vertical = 9.dp),
                )
            }
        }
        if (hourly) {
            val hours = state.hourlyHours ?: offer.minHours
            Row(Modifier.fillMaxWidth().padding(top = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.hr_hours), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    Text(stringResource(R.string.hr_hour_price, money(offer.hourPriceMinor, quote.currency)), color = settingsMut(), fontSize = 12.sp)
                }
                HourStep("−", hours > offer.minHours && !state.busy) { price(hours - 1) }
                Text("$hours", color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 18.sp, textAlign = androidx.compose.ui.text.style.TextAlign.Center, modifier = Modifier.width(40.dp))
                HourStep("+", hours < offer.maxHours && !state.busy) { price(hours + 1) }
            }
            if (offer.overtimePerMinMinor > 0) {
                Text(stringResource(R.string.hr_overtime, money(offer.overtimePerMinMinor, quote.currency)), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(top = 6.dp))
            }
        }
    }
}

@Composable
private fun HourStep(text: String, enabled: Boolean, onClick: () -> Unit) {
    val accent = settingsAccent()
    Box(
        Modifier.size(34.dp).clip(CircleShape).background(if (enabled) accent.copy(alpha = 0.14f) else settingsCard()).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Text(text, color = if (enabled) accent else settingsMut(), fontWeight = FontWeight.Black, fontSize = 18.sp) }
}

/** O-17, on the ride: «N hours booked — until HH:MM», from the device clock once it started (no server asks). */
@Composable
internal fun HourlyRideBanner(hourly: com.dorr.app.network.RideHourlyDto, currency: RideCurrencyDto?) {
    val accent = settingsAccent()
    val until = hourly.endsAt?.let { runCatching { java.time.OffsetDateTime.parse(it).atZoneSameInstant(java.time.ZoneId.systemDefault()).format(java.time.format.DateTimeFormatter.ofPattern("h:mm a")) }.getOrNull() }
    Row(
        Modifier.fillMaxWidth().padding(bottom = 10.dp).clip(RoundedCornerShape(14.dp)).background(accent.copy(alpha = 0.1f)).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(36.dp).clip(CircleShape).background(accent), contentAlignment = Alignment.Center) {
            Text("⏱", fontSize = 18.sp)
        }
        Spacer(Modifier.width(10.dp))
        Column {
            Text(
                if (until != null) stringResource(R.string.hr_ride_until, hourly.hours, until) else stringResource(R.string.hr_ride_hours, hourly.hours),
                color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp,
            )
            if (hourly.overtimePerMinMinor > 0) Text(stringResource(R.string.hr_overtime, money(hourly.overtimePerMinMinor, currency)), color = settingsMut(), fontSize = 12.sp)
        }
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
