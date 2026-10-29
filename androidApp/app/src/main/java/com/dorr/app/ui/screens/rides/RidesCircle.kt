package com.dorr.app.ui.screens.rides

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.RedToggle
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.launch
import java.util.UUID

/*
 * DORR Circle (R-INN-07): ask my favourite captains first. On the booking sheet a switch and, under it,
 * the favourites to pick from (none picked = all of them); while searching, a line saying so — and, if
 * none of them could take it, one question: search all captains? Nothing widens unless the rider says yes.
 */

internal suspend fun loadCircle(state: RidesState) {
    if (!state.feature("favorite_circle")) return
    // O-81: who of my favourites is free now, and how far from my pickup (nearest first).
    state.circleDrivers = runCatching { ApiClient.rides.circle(ridesAuth(), state.pickup?.lat, state.pickup?.lng).data?.drivers }.getOrNull() ?: state.circleDrivers
}

/** On the booking sheet: "Ask my favorite captains first", and which of them. */
@Composable
internal fun CircleRow(state: RidesState) {
    // O-81: fresh for this pickup — once when the sheet opens and whenever the pickup moves.
    androidx.compose.runtime.LaunchedEffect(state.pickup?.lat, state.pickup?.lng) { loadCircle(state) }
    val available = state.circleDrivers.filter { it.available != false }
    val hasFavorites = state.circleDrivers.isNotEmpty()
    val has = available.isNotEmpty()
    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Rounded.Favorite, null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.rd_circle_title), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
            Text(
                stringResource(if (has) R.string.rd_circle_hint else if (hasFavorites) R.string.rd_circle_none_available else R.string.rd_circle_none),
                color = if (!has && hasFavorites) AppColors.warning else settingsMut(), fontSize = 12.sp,
            )
        }
        RedToggle(state.useCircle && has, settingsNight()) { if (has) state.useCircle = !state.useCircle }
    }
    if (state.useCircle && has) {
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            available.forEach { driver ->
                val picked = driver.driverId in state.circlePicked
                val shape = RoundedCornerShape(50)
                Row(
                    Modifier.clip(shape)
                        .then(if (picked) Modifier.background(settingsAccent().copy(alpha = 0.14f)).border(1.dp, settingsAccent(), shape) else Modifier.background(settingsBackground()))
                        .clickable { if (picked) state.circlePicked.remove(driver.driverId) else state.circlePicked.add(driver.driverId) }
                        .padding(horizontal = 14.dp, vertical = 9.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(driver.name.orEmpty(), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                    driver.rating?.let { Text("  ★ ${String.format("%.1f", it)}", color = settingsMut(), fontSize = 12.sp) }
                    driver.distanceKm?.let { Text("  · " + stringResource(R.string.rd_circle_km, String.format(java.util.Locale.getDefault(), "%.1f", it)), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
                }
            }
        }
        Text(
            stringResource(if (state.circlePicked.isEmpty()) R.string.rd_circle_all else R.string.rd_circle_picked, state.circlePicked.size),
            color = settingsMut(), fontSize = 11.sp, modifier = Modifier.padding(top = 4.dp, bottom = 2.dp),
        )
    }
}

/** What the booking sends: `{driver_ids: [...]}` (empty = all favourites), or null for the usual search. */
internal fun circleBody(state: RidesState): com.google.gson.JsonObject? {
    val available = state.circleDrivers.filter { it.available != false }.map { it.driverId }
    if (!state.useCircle || available.isEmpty()) return null
    // None picked = every favourite who is free now.
    val ids = state.circlePicked.filter { it in available }.ifEmpty { available }
    return com.google.gson.JsonObject().apply {
        add("driver_ids", com.google.gson.JsonArray().apply { ids.forEach { add(it) } })
    }
}

/** While searching: "Asking your favorite captains" — and the one question if none of them could take it. */
@Composable
internal fun CircleNotice(state: RidesState, ride: RideDto) {
    val circle = ride.circle ?: return
    if (!circle.active) return
    val scope = rememberCoroutineScope()
    var keepWaiting by remember(ride.uuid) { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    Row(
        Modifier.padding(top = 6.dp).clip(RoundedCornerShape(50)).background(settingsAccent().copy(alpha = 0.1f)).padding(horizontal = 12.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.Favorite, null, tint = settingsAccent(), modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(6.dp))
        Text(stringResource(R.string.rd_circle_asking, circle.count), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
    if (circle.asked && !keepWaiting) {
        Column(Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(14.dp)).background(AppColors.warning.copy(alpha = 0.12f)).padding(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Groups, null, tint = AppColors.warning, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.rd_circle_ask), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
            }
            Text(stringResource(R.string.rd_circle_ask_hint), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
            Spacer(Modifier.height(10.dp))
            AccentButton(stringResource(R.string.rd_circle_widen), busy = busy) {
                busy = true
                error = null
                scope.launch {
                    runCatching { ApiClient.rides.widenCircle(ridesAuth(), UUID.randomUUID().toString(), ride.uuid).data }
                        .onSuccess { fresh -> fresh?.let { state.ride = it } }
                        .onFailure { error = it.apiFailure().message }
                    busy = false
                }
            }
            Text(
                stringResource(R.string.rd_circle_keep), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 13.sp,
                modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { keepWaiting = true }.padding(vertical = 12.dp),
                textAlign = androidx.compose.ui.text.style.TextAlign.Center,
            )
            error?.let { Text(it, color = AppColors.danger, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp)) }
        }
    }
}
