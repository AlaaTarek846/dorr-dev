package com.dorr.app.ui.screens.rides

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.StarBorder
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideSavedPlaceDto
import com.dorr.app.network.SaveAddressRequest
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import kotlinx.coroutines.MainScope
import kotlinx.coroutines.launch
import kotlin.math.abs

/*
 * Shortcuts under "where to?" (R-5, R-174): Home and Work, favourite places and recent destinations.
 * Saved places are the account's own addresses (/mobile/v1/addresses), so they follow the rider to
 * every device; recent destinations come from the rides. One tap sets the destination and prices the trip.
 */

internal suspend fun loadPlaces(state: RidesState) {
    if (!state.feature("saved_places")) return
    state.places = runCatching { ApiClient.rides.places(ridesAuth()).data }.getOrNull() ?: state.places
}

/** Keep a place on the account: Home / Work replace the one there, a favourite is added. */
internal fun savePlace(state: RidesState, point: RidePoint, type: String) {
    MainScope().launch {
        val existing = when (type) {
            "home" -> state.places?.home?.id
            "work" -> state.places?.work?.id
            else -> null
        }
        val body = SaveAddressRequest(
            type = type,
            title = if (type == "other") point.address.substringBefore(',').take(60) else null,
            addressDetails = point.address.take(255),
            latitude = point.lat,
            longitude = point.lng,
        )
        runCatching {
            if (existing != null) ApiClient.addresses.update(ridesAuth(), existing, body) else ApiClient.addresses.store(ridesAuth(), body)
        }
            .onSuccess { loadPlaces(state) }
            .onFailure { state.error = it.apiFailure().message }
    }
}

private fun goTo(state: RidesState, place: RideSavedPlaceDto) {
    if (state.pickup == null) return
    state.openDestination = false
    state.destination = RidePoint(place.lat, place.lng, place.address ?: place.title.orEmpty())
    state.stops.clear()
    loadQuotes(state)
}

private fun setPlace(state: RidesState, type: String) {
    state.savingPlace = type
    state.editing = "destination"
    state.step = RideStep.Search
}

/** Home · Work · favourites as chips, then the last few destinations. */
@Composable
internal fun SavedPlacesRow(state: RidesState) {
    val places = state.places ?: return
    Spacer(Modifier.height(10.dp))
    Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        places.home?.let { PlaceChip(Icons.Rounded.Home, stringResource(R.string.rd_place_home)) { goTo(state, it) } }
            ?: PlaceChip(Icons.Rounded.Add, stringResource(R.string.rd_place_set_home), dashed = true) { setPlace(state, "home") }
        places.work?.let { PlaceChip(Icons.Rounded.Work, stringResource(R.string.rd_place_work)) { goTo(state, it) } }
            ?: PlaceChip(Icons.Rounded.Add, stringResource(R.string.rd_place_set_work), dashed = true) { setPlace(state, "work") }
        places.favorites.forEach { fav -> PlaceChip(Icons.Rounded.Star, fav.title ?: fav.address.orEmpty()) { goTo(state, fav) } }
    }
    places.recent.take(3).forEach { recent ->
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { goTo(state, recent) }.padding(vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.History, null, tint = settingsMut(), modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(10.dp))
            Text(recent.address.orEmpty(), color = settingsInk(), fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
private fun PlaceChip(icon: ImageVector, label: String, dashed: Boolean = false, onClick: () -> Unit) {
    val shape = RoundedCornerShape(50)
    Row(
        Modifier.clip(shape)
            .then(if (dashed) Modifier.border(1.dp, settingsAccent().copy(alpha = 0.5f), shape) else Modifier.background(settingsBackground()))
            .clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
        Spacer(Modifier.width(6.dp))
        Text(label, color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** On the prices sheet: keep this destination as Home, Work or a favourite — unless it already is one. */
@Composable
internal fun SaveDestinationRow(state: RidesState) {
    val destination = state.destination?.takeIf { !state.openDestination } ?: return
    val places = state.places ?: return
    val saved = listOfNotNull(places.home, places.work).plus(places.favorites)
        .any { abs(it.lat - destination.lat) < 0.001 && abs(it.lng - destination.lng) < 0.001 }
    if (saved) return
    Spacer(Modifier.height(6.dp))
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Rounded.StarBorder, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(8.dp))
        Text(stringResource(R.string.rd_place_save), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
    }
    Spacer(Modifier.height(4.dp))
    Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        PlaceChip(Icons.Rounded.Home, stringResource(R.string.rd_place_home)) { savePlace(state, destination, "home") }
        PlaceChip(Icons.Rounded.Work, stringResource(R.string.rd_place_work)) { savePlace(state, destination, "work") }
        PlaceChip(Icons.Rounded.Star, stringResource(R.string.rd_place_favorite)) { savePlace(state, destination, "other") }
    }
}
