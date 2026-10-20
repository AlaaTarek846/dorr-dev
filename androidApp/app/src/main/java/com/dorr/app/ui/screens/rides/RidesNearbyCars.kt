package com.dorr.app.ui.screens.rides

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.tween
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.snapshots.SnapshotStateMap
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.google.android.gms.maps.model.LatLng
import com.google.gson.JsonObject
import kotlinx.coroutines.Job
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlin.math.floor

/*
 * The free captains' cars around the rider, moving (docs/rides_tasks.md O-59), like inDrive: one picture
 * on open (`nearby-cars`), then each car's new position arrives on the area's public channel
 * (`rides-area.{cell}`, `area.car`) and the car slides there. A car not heard from for a while fades out
 * by the phone's own clock. Nothing asks the server on a timer.
 */

/** A car on the rider's map: where it's drawn now and where it's heading. */
internal data class NearbyCar(val at: LatLng, val heading: Float)

private const val CELL = 0.05
private const val STALE_MS = 90_000L

@Composable
internal fun rememberNearbyCars(near: LatLng?, enabled: Boolean): SnapshotStateMap<String, NearbyCar> {
    val cars = remember { mutableStateMapOf<String, NearbyCar>() }
    val seen = remember { HashMap<String, Long>() }
    val slides = remember { HashMap<String, Job>() }
    // Re-read only when the rider moves to another area cell.
    val cell = near?.let { "${floor(it.latitude / CELL).toInt()}_${floor(it.longitude / CELL).toInt()}" }

    LaunchedEffect(cell, enabled) {
        if (!enabled || near == null) { cars.clear(); return@LaunchedEffect }
        val snapshot = runCatching { ApiClient.rides.nearbyCars(ridesAuth(), near.latitude, near.longitude).data }.getOrNull()
        cars.clear()
        val now = System.currentTimeMillis()
        snapshot?.cars?.forEach { c -> cars[c.car] = NearbyCar(LatLng(c.lat, c.lng), (c.heading ?: 0).toFloat()); seen[c.car] = now }
        val channels = snapshot?.cells.orEmpty().map { "rides-area.$it" }
        channels.forEach { ChatRealtime.watchPublic(it, listOf("area.car")) }
        try {
            coroutineScope {
                // A car not heard from fades out (the phone's clock).
                launch {
                    while (true) {
                        delay(15_000)
                        val cut = System.currentTimeMillis() - STALE_MS
                        seen.filterValues { it < cut }.keys.toList().forEach { id -> seen.remove(id); cars.remove(id) }
                    }
                }
                ChatRealtime.events.collect { e ->
                    if (e.name != "area.car") return@collect
                    onCar(e.data, cars, seen, slides, this)
                }
            }
        } finally {
            channels.forEach { ChatRealtime.unwatchPublic(it) }
            slides.values.forEach { it.cancel() }
            slides.clear()
        }
    }
    return cars
}

private fun onCar(data: JsonObject, cars: SnapshotStateMap<String, NearbyCar>, seen: HashMap<String, Long>, slides: HashMap<String, Job>, scope: kotlinx.coroutines.CoroutineScope) {
    val id = data.get("car")?.takeIf { it.isJsonPrimitive }?.asString ?: return
    if (data.get("gone")?.takeIf { it.isJsonPrimitive }?.asBoolean == true) {
        slides.remove(id)?.cancel()
        seen.remove(id)
        cars.remove(id)
        return
    }
    val to = LatLng(data.get("lat")?.asDouble ?: return, data.get("lng")?.asDouble ?: return)
    val heading = data.get("heading")?.takeIf { it.isJsonPrimitive }?.asFloat
    seen[id] = System.currentTimeMillis()
    val from = cars[id]
    if (from == null) { cars[id] = NearbyCar(to, heading ?: 0f); return }
    // Slide from where it's drawn to the new place over the next few seconds (until the next position comes).
    slides.remove(id)?.cancel()
    slides[id] = scope.launch {
        androidx.compose.animation.core.animate(0f, 1f, animationSpec = tween(3800, easing = LinearEasing)) { f, _ ->
            cars[id] = NearbyCar(
                LatLng(from.at.latitude + (to.latitude - from.at.latitude) * f, from.at.longitude + (to.longitude - from.at.longitude) * f),
                heading ?: from.heading,
            )
        }
    }
}
