package com.dorr.provider.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.RequestQuote
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.MovingOfferDto
import com.dorr.provider.net.PagedRows
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.net.apiDone
import com.dorr.provider.net.can
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.net.included
import com.dorr.provider.ui.components.Card
import com.dorr.provider.ui.components.ChipRow
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.components.PagedFooter
import com.dorr.provider.ui.kit.ConfirmDialog
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import kotlinx.coroutines.launch

/**
 * The establishment's offers (C-04): all of them, or those waiting for the approver (`offers.approve`) — an offer above its sender's
 * limit waits there (§17). An offer the customer hasn't answered can be changed or taken back (`offers.manage`). Read on open, when
 * the connection returns and on every notice.
 */
@Composable
fun OffersScreen(startOnApproval: Boolean, onBack: () -> Unit, onOpenRequest: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val seat by Session.membership.collectAsState()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var filter by rememberSaveable { mutableStateOf(if (startOnApproval) "awaiting_approval" else "") }
    var editing by remember { mutableStateOf<MovingOfferDto?>(null) }
    var withdrawing by remember { mutableStateOf<MovingOfferDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var info by remember { mutableStateOf<String?>(null) }
    val approvedText = stringResource(R.string.mv_offer_approved_ok)

    val paged = remember(filter) { PagedRows<MovingOfferDto>(20, { it.uuid }) { page -> apiCall { ApiClient.moving.offers(filter.ifEmpty { null }, page) } } }
    LaunchedEffect(reconnect, filter) { paged.reload() }
    LaunchedEffect(filter) { Realtime.changed.collect { paged.reload() } }

    editing?.let { offer ->
        BackHandler { editing = null }
        OfferForm(move = null, offer = offer, onBack = { editing = null }, onDone = { message ->
            info = message
            editing = null
            scope.launch { paged.reload() }
        })
        return
    }

    fun approve(offer: MovingOfferDto) {
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.moving.approveOffer(offer.uuid) }
            busy = false
            if (result is ApiResult.Ok) {
                info = approvedText
                paged.reload()
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    fun withdraw(offer: MovingOfferDto) {
        busy = true
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.moving.withdrawOffer(offer.uuid) }
            busy = false
            withdrawing = null
            if (failed != null) error = failed else paged.reload()
        }
    }

    Page(title = stringResource(R.string.mv_offers_title), onBack = onBack, onNearEnd = { scope.launch { paged.loadMore() } }) {
        if (seat.can("offers.approve")) {
            ChipRow(
                options = listOf("" to stringResource(R.string.mv_filter_all), "awaiting_approval" to stringResource(R.string.mv_offer_awaiting_approval)),
                selected = setOf(filter),
                onToggle = { filter = it },
                modifier = Modifier.fillMaxWidth(),
            )
        }
        info?.let { Text(it, color = Green, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.fillMaxWidth()) }
        when {
            !paged.loaded -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
            paged.rows.isEmpty() -> EmptyState(Icons.Rounded.RequestQuote, stringResource(R.string.mv_offers_empty_title), stringResource(R.string.mv_offers_empty_body), Modifier.padding(top = 30.dp))
            else -> paged.rows.forEach { offer ->
                OfferCard(
                    offer, busy,
                    canManage = seat.can("offers.manage") && offer.status in setOf("sent", "awaiting_approval"),
                    canApprove = seat.can("offers.approve") && offer.status == "awaiting_approval",
                    onEdit = { editing = offer },
                    onWithdraw = { withdrawing = offer },
                    onApprove = { approve(offer) },
                    onOpenRequest = onOpenRequest,
                )
            }
        }
        PagedFooter(paged, onRetry = { paged.retry(); scope.launch { paged.loadMore() } })
        ErrorBanner(error ?: paged.failure?.errorText(offlineText), Modifier.fillMaxWidth())
    }

    withdrawing?.let { offer ->
        ConfirmDialog(
            icon = Icons.Rounded.Undo,
            title = stringResource(R.string.mv_withdraw_title),
            message = stringResource(R.string.mv_withdraw_message),
            confirmLabel = stringResource(R.string.mv_withdraw),
            loading = busy,
            onConfirm = { withdraw(offer) },
            onDismiss = { if (!busy) withdrawing = null },
        )
    }
}

@Composable
private fun OfferCard(
    offer: MovingOfferDto,
    busy: Boolean,
    canManage: Boolean,
    canApprove: Boolean,
    onEdit: () -> Unit,
    onWithdraw: () -> Unit,
    onApprove: () -> Unit,
    onOpenRequest: (String) -> Unit,
) {
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(formatMinor(offer.priceMinor, offer.currencyCode), color = settingsInk(), fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            StatusPill(offerStatusLabel(offer.status), offerStatusColor(offer.status))
        }
        InfoLine(stringResource(R.string.mv_arrival), dateTimeText(offer.arrivalAt))
        InfoLine(stringResource(R.string.mv_valid_until), dateTimeText(offer.validUntil))
        InfoLine(stringResource(R.string.mv_offer_vehicle), offer.vehicle?.type?.name)
        InfoLine(stringResource(R.string.mv_workers), offer.workersCount?.toString())
        InfoLine(stringResource(R.string.mv_trips), offer.tripsCount?.toString())
        InfoLine(stringResource(R.string.mv_branch), offer.branch)
        val includes = offer.included()
        if (includes.isNotEmpty()) Text(includes.map { includeLabel(it) }.joinToString(" · "), color = settingsMut(), fontSize = 12.sp)
        if (offer.status == "awaiting_approval") Text(stringResource(R.string.mv_awaiting_approval_hint), color = Amber, fontSize = 12.sp, lineHeight = 18.sp)
        offer.requestUuid?.let { request ->
            Text(stringResource(R.string.mv_open_request), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { onOpenRequest(request) })
        }
        if (canManage || canApprove) {
            Row {
                if (canApprove) {
                    Text(stringResource(R.string.mv_approve), color = Green, fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy, onClick = onApprove))
                    Spacer(Modifier.width(20.dp))
                }
                if (canManage) {
                    Text(stringResource(R.string.common_edit), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy, onClick = onEdit))
                    Spacer(Modifier.width(20.dp))
                    Text(stringResource(R.string.mv_withdraw), color = Red, fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy, onClick = onWithdraw))
                }
            }
        }
    }
}
