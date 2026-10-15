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

    fun clearOffer(uuid: String? = null) {
        val current = _offer.value?.uuid
        if (uuid == null || current == uuid) {
            (uuid ?: current)?.let { dismissed.add(it); if (dismissed.size > 50) dismissed.remove(dismissed.first()) }
            _offer.value = null
        }
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
