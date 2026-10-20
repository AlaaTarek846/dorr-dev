package com.dorr.driver.duty

import android.content.Context
import android.content.Intent
import androidx.core.content.ContextCompat
import com.dorr.driver.net.OfferDto
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * What the driver's shift shares between the background service and the screens: the request on
 * offer now, whether a ride is running (the location then goes every 5 s instead of 30 s), and a
 * tick when a ride was accepted from the notification (the app opens on it).
 */
object Duty {
    private val _offer = MutableStateFlow<OfferDto?>(null)
    val offer: StateFlow<OfferDto?> = _offer.asStateFlow()

    /** O-36: every request open for me now — the captain may hold a few and pick one. */
    private val _offers = MutableStateFlow<List<OfferDto>>(emptyList())
    val offers: StateFlow<List<OfferDto>> = _offers.asStateFlow()

    /** The request card put aside ("later"): the requests wait behind the inbox icon. */
    private val _minimized = MutableStateFlow(false)
    val minimized: StateFlow<Boolean> = _minimized.asStateFlow()

    private val _onTrip = MutableStateFlow(false)
    val onTrip: StateFlow<Boolean> = _onTrip.asStateFlow()

    private val _rideChanged = MutableStateFlow(0)
    val rideChanged: StateFlow<Int> = _rideChanged.asStateFlow()

    /** Offers already shown, so the same one never rings twice. */
    internal val seen = LinkedHashSet<String>()

    /** Offers the driver put away (declined, or let the card time out): not shown again. */
    internal val dismissed = LinkedHashSet<String>()

    internal fun showOffer(offer: OfferDto?) {
        if (offer != null && offer.uuid in dismissed) return
        _offer.value = offer
    }

    /** The open requests from the server: the one on screen stays (refreshed), else the first. */
    internal fun setOffers(list: List<OfferDto>) {
        val open = list.filter { it.uuid !in dismissed }
        _offers.value = open
        val current = _offer.value?.uuid
        _offer.value = open.firstOrNull { it.uuid == current } ?: open.firstOrNull()
        if (open.isEmpty()) _minimized.value = false
    }

    /** Show this request (picked from the list). */
    fun focus(uuid: String) {
        _offers.value.firstOrNull { it.uuid == uuid }?.let { _offer.value = it }
        _minimized.value = false
    }

    fun minimize(on: Boolean) {
        _minimized.value = on
    }

    fun clearOffer(uuid: String? = null) {
        val current = _offer.value?.uuid
        val gone = uuid ?: current ?: return
        dismissed.add(gone)
        if (dismissed.size > 50) dismissed.remove(dismissed.first())
        // The next request in line shows (O-36).
        _offers.value = _offers.value.filterNot { it.uuid == gone }
        if (current == gone) _offer.value = _offers.value.firstOrNull()
    }

    fun setOnTrip(onTrip: Boolean) {
        _onTrip.value = onTrip
    }

    fun rideChanged() {
        _rideChanged.value += 1
    }

    fun start(context: Context) {
        ContextCompat.startForegroundService(context, Intent(context, DriverDutyService::class.java).setAction(DriverDutyService.ACTION_START))
    }

    fun stop(context: Context) {
        context.startService(Intent(context, DriverDutyService::class.java).setAction(DriverDutyService.ACTION_STOP))
    }
}
