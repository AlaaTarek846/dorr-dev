package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.EventAvailable
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.PullToRefreshHost
import kotlinx.coroutines.CancellationException

/**
 * «My activity» (the client redesign, batch F): what I bought or booked, in one page — my orders of ready-made services (with the monthly
 * subscriptions), my consultations, and the special offers freelancers sent me (the count of those waiting on its part). Each part keeps
 * its own statuses as chips; pulling down reads the part on show again. [start] is the part it opens on (0 orders, 1 consultations, 2
 * special offers). Nothing on a timer.
 */
@Composable
internal fun ActivityPage(
    start: Int, onBack: () -> Unit,
    onOpenOrder: (String) -> Unit, onBrowse: () -> Unit, onOpenConsultation: (String) -> Unit, onOpenSpecial: (String) -> Unit,
) {
    var part by rememberSaveable { mutableStateOf(start) }
    val reconnect = collectReconnectTick()
    // FL-L09: how many special offers wait for me (the count on their part).
    var waiting by remember { mutableIntStateOf(0) }
    LaunchedEffect(reconnect) {
        waiting = try { ApiClient.freelanceSpecialOffers.waiting(auth()).data?.count ?: 0 } catch (e: CancellationException) { throw e } catch (e: Exception) { waiting }
    }
    Column(Modifier.fillMaxSize()) {
        FlPageHeader(stringResource(R.string.fla_activity), onBack, subtitle = stringResource(R.string.fla_activity_line))
        Box(Modifier.padding(horizontal = 16.dp)) {
            FlTabs(
                listOf(
                    FlTab(Icons.Rounded.Receipt, stringResource(R.string.mk_my_orders)),
                    FlTab(Icons.Rounded.EventAvailable, stringResource(R.string.cs_mine_title)),
                    FlTab(Icons.Rounded.LocalOffer, stringResource(R.string.spc_chip), waiting),
                ),
                part,
            ) { part = it }
        }
        PullToRefreshHost(Modifier.weight(1f).fillMaxWidth()) {
            when (part) {
                0 -> MyOrdersPage(onBack = onBack, onOpen = onOpenOrder, onBrowse = onBrowse, embedded = true)
                1 -> MyConsultationsPage(onBack = onBack, onOpen = onOpenConsultation, embedded = true)
                else -> SpecialOffersPage(onBack = onBack, onOpen = onOpenSpecial, embedded = true)
            }
        }
    }
}
