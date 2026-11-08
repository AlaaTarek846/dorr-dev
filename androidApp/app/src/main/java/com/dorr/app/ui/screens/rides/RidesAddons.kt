package com.dorr.app.ui.screens.rides

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowLeft
import androidx.compose.material.icons.rounded.Accessible
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.ChildCare
import androidx.compose.material.icons.rounded.HourglassBottom
import androidx.compose.material.icons.rounded.Luggage
import androidx.compose.material.icons.rounded.Pets
import androidx.compose.material.icons.rounded.Remove
import androidx.compose.material.icons.rounded.Speed
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.WorkOutline
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.RideAddonDto
import com.dorr.app.network.RideCurrencyDto
import com.dorr.app.network.RideQuoteDto
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonObject

/** R-233..R-237, R-NEW-06: what the rider adds to the trip — sent with the quote request (`options`). */
data class TripOptions(
    val speed: String = "standard", // standard / wait_save / priority
    val childSeats: Int = 0,
    val pet: Boolean = false,
    val serviceAnimal: Boolean = false,
    val bagsSmall: Int = 0,
    val bagsLarge: Int = 0,
) {
    val isEmpty get() = this == TripOptions()

    fun json(): JsonObject = JsonObject().apply {
        if (speed == "wait_save") addProperty("wait_save", true)
        if (speed == "priority") addProperty("priority", true)
        if (childSeats > 0) addProperty("child_seats", childSeats)
        if (pet) addProperty("pet", true)
        if (serviceAnimal) addProperty("service_animal", true)
        if (bagsSmall > 0) addProperty("bags_small", bagsSmall)
        if (bagsLarge > 0) addProperty("bags_large", bagsLarge)
    }
}

/** One row on the booking sheet: what's added (or «add to your trip»), opening the sheet to change it. */
@Composable
internal fun AddonsRow(state: RidesState, quote: RideQuoteDto) {
    val offer = quote.addonsOffer ?: return
    if (offer.isEmpty) return
    var open by remember { mutableStateOf(false) }
    val accent = settingsAccent()
    Row(
        Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(16.dp)).background(settingsBackground())
            .border(BorderStroke(1.dp, accent.copy(alpha = 0.18f)), RoundedCornerShape(16.dp))
            .clickable { open = true }.padding(horizontal = 12.dp, vertical = 11.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(accent.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Tune, null, tint = accent, modifier = Modifier.size(20.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.ad_title), color = settingsMut(), fontSize = 11.sp)
            if (quote.options.isEmpty()) {
                Text(stringResource(R.string.ad_none), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
            } else {
                Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    quote.options.forEach { o -> AddonChip(o) }
                }
            }
            state.addonsError?.let { Text(it, color = AppColors.danger, fontSize = 11.sp) }
        }
        Text(stringResource(R.string.rd_change), color = accent, fontWeight = FontWeight.Bold, fontSize = 13.sp)
        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowLeft, null, tint = accent, modifier = Modifier.size(18.dp))
    }
    // O-88: what the choice does to the search — never a silent «nothing happens».
    val keys = quote.options.map { it.key }.toSet()
    val needs = listOfNotNull(
        stringResource(R.string.ad_needs_pet).takeIf { "pet" in keys },
        stringResource(R.string.ad_needs_child_seat).takeIf { "child_seats" in keys },
    )
    if (needs.isNotEmpty()) {
        val count = quote.equippedNearby
        val none = count == 0
        Text(
            if (none) stringResource(R.string.ad_none_equipped, needs.joinToString(" + ")) else stringResource(R.string.ad_only_equipped, needs.joinToString(" + "), count ?: 0),
            color = if (none) AppColors.danger else settingsMut(), fontSize = 12.sp, fontWeight = if (none) FontWeight.SemiBold else FontWeight.Normal,
            modifier = Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(12.dp))
                .background(if (none) AppColors.danger.copy(alpha = 0.08f) else accent.copy(alpha = 0.06f)).padding(horizontal = 10.dp, vertical = 8.dp),
        )
    }
    if ("wait_save" in keys) {
        Text(
            stringResource(R.string.ad_wait_save_starts, (offer.waitSave?.extraWaitS ?: 300) / 60), color = AppColors.success, fontSize = 12.sp,
            modifier = Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(12.dp)).background(AppColors.success.copy(alpha = 0.08f)).padding(horizontal = 10.dp, vertical = 8.dp),
        )
    }
    if (open) AddonsSheet(state, quote, onClose = { open = false })
}

/** An add-on as a small chip (the booking row, the request card, the ride). */
@Composable
internal fun AddonChip(o: RideAddonDto) {
    Row(
        Modifier.clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)).padding(horizontal = 8.dp, vertical = 3.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(addonIcon(o.key), null, tint = settingsAccent(), modifier = Modifier.size(13.dp))
        Spacer(Modifier.width(3.dp))
        Text(o.label + (o.count?.takeIf { it > 1 }?.let { " ×$it" } ?: ""), color = settingsInk(), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
    }
}

internal fun addonIcon(key: String): ImageVector = when (key) {
    "wait_save" -> Icons.Rounded.HourglassBottom
    "priority" -> Icons.Rounded.Speed
    "child_seats" -> Icons.Rounded.ChildCare
    "pet" -> Icons.Rounded.Pets
    "service_animal" -> Icons.Rounded.Accessible
    else -> Icons.Rounded.Luggage
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AddonsSheet(state: RidesState, quote: RideQuoteDto, onClose: () -> Unit) {
    val offer = quote.addonsOffer ?: return
    val currency = quote.currency
    var o by remember { mutableStateOf(state.addons) }
    val accent = settingsAccent()

    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = settingsCard()) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 18.dp).navigationBarsPadding()) {
            Text(stringResource(R.string.ad_title), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            Text(stringResource(R.string.ad_hint), color = settingsMut(), fontSize = 12.sp)
            Spacer(Modifier.height(12.dp))

            // R-233, R-234: how soon — the usual, cheaper for a longer wait, or first.
            if (offer.waitSave != null || offer.priority != null) {
                Text(stringResource(R.string.ad_speed), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Spacer(Modifier.height(6.dp))
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    SpeedTile(Icons.Rounded.WorkOutline, stringResource(R.string.ad_standard), null, o.speed == "standard", Modifier.weight(1f)) { o = o.copy(speed = "standard") }
                    offer.waitSave?.let { w ->
                        SpeedTile(Icons.Rounded.HourglassBottom, stringResource(R.string.ad_wait_save), stringResource(R.string.ad_wait_save_sub, w.discountBp / 100, w.extraWaitS / 60), o.speed == "wait_save", Modifier.weight(1f), AppColors.success) { o = o.copy(speed = "wait_save") }
                    }
                    offer.priority?.let { p ->
                        SpeedTile(Icons.Rounded.Speed, stringResource(R.string.ad_priority), "+" + money(p.feeMinor, currency), o.speed == "priority", Modifier.weight(1f), AppColors.brandOrange) { o = o.copy(speed = "priority") }
                    }
                }
                Spacer(Modifier.height(14.dp))
            }

            offer.childSeats?.let { c ->
                StepperLine(Icons.Rounded.ChildCare, stringResource(R.string.ad_child_seats), feeText(c.feeMinor, currency, perUnit = true), o.childSeats, 0..c.max) { o = o.copy(childSeats = it) }
            }
            offer.pet?.let { p ->
                ToggleLine(Icons.Rounded.Pets, stringResource(R.string.ad_pet), feeText(p.feeMinor, currency), o.pet) { o = o.copy(pet = it) }
            }
            offer.serviceAnimal?.let {
                ToggleLine(Icons.Rounded.Accessible, stringResource(R.string.ad_service_animal), stringResource(R.string.ad_service_animal_sub), o.serviceAnimal) { o = o.copy(serviceAnimal = it) }
            }
            offer.luggage?.let { l ->
                Spacer(Modifier.height(6.dp))
                Text(stringResource(R.string.ad_luggage), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
                val used = o.bagsSmall + 2 * o.bagsLarge
                Text(stringResource(R.string.ad_luggage_room, used, l.capacity), color = if (used > l.capacity) AppColors.danger else settingsMut(), fontSize = 12.sp)
                StepperLine(Icons.Rounded.Luggage, stringResource(R.string.ad_bags_small), null, o.bagsSmall, 0..10) { o = o.copy(bagsSmall = it) }
                StepperLine(Icons.Rounded.Luggage, stringResource(R.string.ad_bags_large), l.largeFeeMinor.takeIf { it > 0 }?.let { feeText(it, currency, perUnit = true) }, o.bagsLarge, 0..10) { o = o.copy(bagsLarge = it) }
            }

            Spacer(Modifier.height(14.dp))
            AccentButton(stringResource(R.string.ad_apply)) {
                if (o != state.addons) {
                    state.addons = o
                    state.addonsError = null
                    loadQuotes(state, reprice = true)
                }
                onClose()
            }
        }
    }
}

@Composable
private fun SpeedTile(icon: ImageVector, title: String, sub: String?, on: Boolean, modifier: Modifier, tint: Color = settingsAccent(), onClick: () -> Unit) {
    val bg by animateColorAsState(if (on) tint.copy(alpha = 0.14f) else settingsBackground(), tween(200), label = "speed-bg")
    val pop by animateFloatAsState(if (on) 1.04f else 1f, spring(dampingRatio = 0.5f), label = "speed-pop")
    Column(
        modifier.scale(pop).clip(RoundedCornerShape(16.dp)).background(bg).border(1.5.dp, if (on) tint else Color.Transparent, RoundedCornerShape(16.dp))
            .clickable(onClick = onClick).padding(vertical = 10.dp, horizontal = 6.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(icon, null, tint = if (on) tint else settingsMut(), modifier = Modifier.size(22.dp))
        Text(title, color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 12.5.sp)
        sub?.let { Text(it, color = if (on) tint else settingsMut(), fontSize = 10.5.sp, fontWeight = FontWeight.SemiBold) }
    }
}

@Composable
private fun ToggleLine(icon: ImageVector, title: String, sub: String?, on: Boolean, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
            sub?.let { Text(it, color = settingsMut(), fontSize = 11.5.sp) }
        }
        Switch(on, onChange, colors = SwitchDefaults.colors(checkedTrackColor = settingsAccent()))
    }
}

@Composable
private fun StepperLine(icon: ImageVector, title: String, sub: String?, value: Int, range: IntRange, onChange: (Int) -> Unit) {
    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
            sub?.let { Text(it, color = settingsMut(), fontSize = 11.5.sp) }
        }
        StepCircle(Icons.Rounded.Remove, value > range.first) { onChange(value - 1) }
        Text("$value", color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 16.sp, modifier = Modifier.width(32.dp), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
        StepCircle(Icons.Rounded.Add, value < range.last) { onChange(value + 1) }
    }
}

@Composable
private fun StepCircle(icon: ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(32.dp).clip(CircleShape).background(if (enabled) settingsAccent().copy(alpha = 0.12f) else settingsBackground()).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = if (enabled) settingsAccent() else settingsMut().copy(alpha = 0.4f), modifier = Modifier.size(18.dp)) }
}

@Composable
private fun feeText(minor: Long, currency: RideCurrencyDto?, perUnit: Boolean = false): String =
    if (minor <= 0) stringResource(R.string.ad_free) else "+" + money(minor, currency) + if (perUnit) " " + stringResource(R.string.ad_each) else ""
